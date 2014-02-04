<?php defined('SYSPATH') OR die('No direct script access.');

class Asset_Asset
{
	// Routes
	const DIRECTORY_ASSET = 'assets';
	const DIRECTORY_CSS = 'css';
	const DIRECTORY_JS = 'js';
	const DIRECTORY_IMAGE = 'images';

	const EXT_CSS = 'css';
	const EXT_JS = 'js';

	// Other needed settings
	const GD_DPI = 96;

	// Default CHMOD settings
	const CHMOD_DIR = 0751; // Owner: rwx, Group: rx, Others: x
	const CHMOD_FILE = 0644; // Owner: rw, Group: r, Other: r

	private $assetPath;
	private $javaScripts = [];
	private $styleSheets = [];

	public function __construct()
	{
		// Try to load general asset directory
		try
		{
			$directory = Kohana::$config->load('general.asset.directory');
		}
		catch(Kohana_Exception $e)
		{
			$directory = null;
		}

		// If there is no config then use default directory
		if ($directory == null)
			$directory = self::DIRECTORY_ASSET;
		$this->assetPath = DOCROOT.$directory;
	}

	/**
	 * Checks if a path exists and if not creates directories
	 *
	 * The path can point to a directory or a file. There are a couple of rules:
	 * - Files get ignored
	 * - If there is a point somewhere in "/<name>" name then it is seen as a file.
	 *   If you need to have a directory with a point in the name, append it with a
	 *   slash so its seen as a directory
	 * 
	 * @param  [string|array] $path Absolute path to a directory or file
	 * @return string The path variable (unchanged)
	 */
	public function check($path)
	{
		// Recursive self variant
		if (is_array($path))
		{
			foreach ($path as &$p)
				$this->check($p);
			return true; // Always true, because else we threw an error
		}

		// Save the original path and explode the path
		$originalPath = $path;
		$directories = explode(DIRECTORY_SEPARATOR, $path);

		// If there is a dot in the most right part of the path then remove it
		if (strpos(end($directories), '.') !== false)
		{
			$path = substr($path, 0, -strlen(DIRECTORY_SEPARATOR.end($directories)));
			array_pop($directories);
		}

		// Remove possible empty last directory
		if (end($directories) == null)
			array_pop($directories);

		// Start making directories to create
		$i = count($directories) - 1;
		$createDirectories = [];
		$walk = rtrim($path, DIRECTORY_SEPARATOR);
		do
		{
			// Check state
			if ( ! ($exists = is_dir($walk)))
				$createDirectories[] = $walk;

			// Remove one layer
			$walk = rtrim($walk, DIRECTORY_SEPARATOR.$directories[$i--]);
		}
		while( ! $exists);

		// Create the directories (if needed)
		for ($i = count($createDirectories) - 1; $i >= 0; --$i)
			if (mkdir($createDirectories[$i], self::CHMOD_DIR) === false)
				throw new Exception('Was unable to create directory: '.$createDirectories[$i]);

		// Return original path for easy check($path) wrapping
		return $originalPath;
	}

	/** 
	 * Resolves a path
	 *
	 * Tries to resolve all .. and . in a path and returns the path with the .. and . resolved
	 * 
	 * @param  string $path Path
	 * @return string Resolved path
	 */
	private function resolvePath($path)
	{
		// We can not resolve ../ /../ or ./
		if (($path[0] == '.' && $path[1] == '.') || ($path[1] == '.' && $path[2] == '.'))
			throw new Exception('Path can not start with a . or ..');

		// Check if there is a point slash somewhere in the string, if not then we do not have to resolve
		if (strpos($path, '.'.DIRECTORY_SEPARATOR) === false)
			return $path;

		$directories = explode(DIRECTORY_SEPARATOR, $path);
		$directoryNo = count($directories); // No recounting
		for ($i = 0; $i < $directoryNo; ++$i)
		{
			if ($directories[$i] == '..')
				unset($directories[$i-1], $directories[$i]); // If we go back a directory then we remove this one, and the previous one
			elseif ($directories[$i] == '.')
				unset($directories[$i]); // If we stay a directory then we remove just this directory
		}

		// Nicely restore the slashes
		$path = implode(DIRECTORY_SEPARATOR, $directories);
		return ($path[0] != DIRECTORY_SEPARATOR ? DIRECTORY_SEPARATOR : null).$path;
	}

	/**
	 * Creates a text image
	 *
	 * Creates an image from given text/font/color/type
	 * 
	 * @param  string $text       The text
	 * @param  string $font       Font name, the font has to reside in 'fonts' directory
	 * @param  int    $fontSize   Font size in pixels
	 * @param  string $fontColor  Color of the font in hex [#]FFFFFF
	 * @param  string $fontType   Can be, b/i/bi
	 * @return string             Absolute URL
	 */
	public function getTextImage($text, $font, $fontSize, $fontColor, $fontType = '')
	{
		// Path
		$name = 'tti_'.md5(json_encode(func_get_args())).'.png';
		$path = $this->assetPath.DIRECTORY_SEPARATOR.self::DIRECTORY_IMAGE.DIRECTORY_SEPARATOR.$name;
		
		if ( ! file_exists($path))
		{
			// Find the font
			$font = Kohana::find_file('fonts', $font.$fontType, 'ttf');
			$fontPoint = $fontSize * (72 / self::GD_DPI);

			// Calculate width/height
			$box = imagettfbbox($fontPoint, 0, $font, $text);
			$ascent = abs($box[7]);
			$descent = abs($box[1]);
			$width = abs($box[0]) + abs($box[2]);
			$height = $ascent + $descent;

			// Create the image
			$img = imagecreatetruecolor($width, $height);

			// Make it transparent
			imagesavealpha($img, true);
			$transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
			imagefill($img, 0, 0, $transparent);

			// We accept only hex colors
			$color = hexdec($fontColor);
			$color = imagecolorallocate($img, 0xFF & ($color >> 0x10), 0xFF & ($color >> 0x8), 0xFF & $color);

			// Build image
			imagettftext($img, $fontPoint, 0, 0, $ascent, $color, $font, $text);

			// Save the image
			imagepng($img, $this->check($path));
			imagedestroy($img);
		}

		return URL::site(self::DIRECTORY_ASSET.DIRECTORY_SEPARATOR.self::DIRECTORY_IMAGE.DIRECTORY_SEPARATOR.$name, true);
	}

	/**
	 * Gets the absolute URL for an image
	 *
	 * This function checks if the image really exists in the asset cache and if not it finds the best
	 * match through Kohana and symlinks it.
	 * 
	 * @param  string $file Path to file
	 * @return string Absolute URL to file
	 */
	public function image($file)
	{
		// If its something absolute just return it
		if (strpos($file, '://') !== false)
			return $file;

		// Get some information about the file
		$info = pathinfo($file);

		// If we have no extension then throw error
		if ( ! array_key_exists('extension', $info))
			throw new Kohana_Exception(tr('Image without an extension: :file!', [':file' => $file]));

		// Set up some variables
		$filename = ($info['dirname'] == '.' ? null : $info['dirname'].DIRECTORY_SEPARATOR).$info['filename'];
		$relativeTarget = $this->resolvePath(self::DIRECTORY_IMAGE.DIRECTORY_SEPARATOR.$filename.'.'.$info['extension']);
		$absoluteTarget = $this->assetPath.DIRECTORY_SEPARATOR.$relativeTarget;

		// Check if we already resolved this target
		if ( ! file_exists($absoluteTarget))
		{
			// Find the file in the Kohana structure
			$absoluteFile = Kohana::find_file(self::DIRECTORY_IMAGE, $filename, $info['extension']);
			if ( ! $absoluteFile)
				throw new Kohana_Exception('Was not able to find file: '.self::DIRECTORY_IMAGE.DIRECTORY_SEPARATOR.$filename.'.'.$info['extension']);

			// Make symlink
			symlink($absoluteFile, $this->check($absoluteTarget));
		}

		return URL::site(self::DIRECTORY_ASSET.DIRECTORY_SEPARATOR.$relativeTarget, true);
	}

	public function addStyleSheet($file, $priority = null)
	{
		$info = pathinfo($file);

		// Check for missing extension and if so add it
		if ( ! array_key_exists('extension', $info))
			$info['extension'] = self::EXT_CSS;

		// Add the stylesheet to the system
		$this->styleSheets[] = [
			'name' => rtrim($file, '.'.$info['extension']),
			'extension' => $info['extension'],
			'fileName' => $info['filename'],
			'priority' => $priority,
		];

		// Return this for object chaining
		return $this;
	}

	public function addJavaScript($file, $priority = null)
	{
		// Append extension if not already has
		if (strripos('.'.self::EXT_JS, $file) !== strlen($file) - (strlen(self::EXT_JS) + 1))
			$file .= '.'.self::EXT_JS;

		$info = pathinfo($file);

		// Add the stylesheet to the system
		$this->javaScripts[] = [
			'name' => rtrim($file, '.'.$info['extension']),
			'extension' => $info['extension'],
			'fileName' => $info['filename'],
			'priority' => $priority,
		];

		// Return this for object chaining
		return $this;
	}

	private static function sort($a, $b)
	{
		$ap = $a['priority'];
		$bp = $b['priority'];
		if ($ap === null || $ap < $bp)
			return -1;
		elseif ($ap > $bp)
			return 1;
		else
			return 0;
	}


	public function getHtmlStyleSheet()
	{
		$url = $this->getStyleSheet();
		return ($url == null ? null : HTML::style($url));
	}


	public function getHtmlJavaScript()
	{
		$url = $this->getJavaScript();
		return ($url == null ? null : HTML::script($url));
	}

	public function getJavaScript()
	{
		if (count($this->javaScripts) <= 0)
			return null;

		// Sort it by priority (if everything is null nothing should change)
		usort($this->javaScripts, "self::sort");

		// Figure out the MD5 of stylesheet set (make it environment related)
		$fileName = md5(URL::base(true).Kohana::$environment.json_encode($this->javaScripts)).'.'.self::EXT_JS;
		$filePath = $this->assetPath.DIRECTORY_SEPARATOR.self::DIRECTORY_JS.DIRECTORY_SEPARATOR.$fileName;

		// Check if file exists if not we have to make it else we can let apache serve it!
		// In non production environment also check timestamp of input output and update it if any input is newer then the output
		if ( ! is_file($filePath) || Kohana::$environment != Kohana::PRODUCTION)
		{
			$this->renderJavaScript($filePath);
		}

		// Return file name
		return URL::site(self::DIRECTORY_ASSET.DIRECTORY_SEPARATOR.self::DIRECTORY_JS.DIRECTORY_SEPARATOR.$fileName, true);
	}

	public function getStyleSheet()
	{
		if (count($this->styleSheets) <= 0)
			return null;

		// Sort it by priority (if everything is null nothing should change)
		usort($this->styleSheets, "self::sort");

		// Figure out the MD5 of stylesheet set (make it environment related)
		$fileName = md5(URL::base(true).Kohana::$environment.json_encode($this->styleSheets)).'.'.self::EXT_CSS;
		$filePath = $this->assetPath.DIRECTORY_SEPARATOR.self::DIRECTORY_CSS.DIRECTORY_SEPARATOR.$fileName;

		// Check if file exists if not we have to make it else we can let apache serve it!
		// In non production environment also check timestamp of input output and update it if any input is newer then the output
		if ( ! is_file($filePath) || Kohana::$environment != Kohana::PRODUCTION)
		{
			$this->renderStyleSheet($filePath);
		}

		// Return file name
		return URL::site(self::DIRECTORY_ASSET.DIRECTORY_SEPARATOR.self::DIRECTORY_CSS.DIRECTORY_SEPARATOR.$fileName, true);
	}

	private function renderJavaScript($outputFile)
	{
		// Make directory if not exists
		if ( ! is_dir(dirname($outputFile)))
		{
			mkdir(dirname($outputFile), self::CHMOD_DIR, true);
		}

		// Check filetime of original time
		$isNewer = null;
		$outputFileTime = null;
		if (is_file($outputFile))
		{
			$isNewer = false;
			$outputFileTime = filemtime($outputFile);
		}

		// Find all needed files
		$files = [];
		foreach ($this->javaScripts as $javaScript)
		{
			if ($file = Kohana::find_file(self::DIRECTORY_JS, $javaScript['name'], $javaScript['extension']))
			{
				// If there is any file newer then the compiled one, recompile
				if (filemtime($file) > $outputFileTime)
					$isNewer = true;
				$files[] = $file;
			}
			else
			{
				Kohana::$log->add(Log::ERROR, tr('[JS] Was not able to find js file: :file', [':file' => $javaScript['name']]));
			}
		}

		// If we are not newer then just skip
		if ($isNewer === false)
			return;

		// Grab the contents of the files and append them to $content
		$content = '';
		foreach ($files as $file)
		{
			$content .= file_get_contents($file)."\n"; // Just to be sure add an extra newline
		}

		// Minify?
		if (Kohana::$environment != Kohana::DEVELOPMENT)
		{
			$content = JSMinPlus::minify($content, $outputFile);
		}

		// Save it
		return file_put_contents($this->check($outputFile), $content);
	}

	private function renderStyleSheet($outputFile)
	{
		// Check file time of original time
		$isNewer = null;
		$outputFileTime = null;
		if (is_file($outputFile))
		{
			$isNewer = false;
			$outputFileTime = filemtime($outputFile);
		}

		// Find all needed files
		$files = [];
		foreach ($this->styleSheets as $styleSheet)
		{
			if ($file = Kohana::find_file(self::DIRECTORY_CSS, $styleSheet['name'], $styleSheet['extension']))
			{
				// If there is any file newer then the compiled one, recompile
				if (filemtime($file) > $outputFileTime)
					$isNewer = true;
				$files[] = $file;
			}
			else
			{
				Kohana::$log->add(Log::ERROR, tr('[LESSC] Was not able to find css file: :file', [':file' => $styleSheet['name']]));
			}
		}

		// If we are not newer then just skip
		if ($isNewer === false)
			return;

		// Grab the contents of the files and append them to $content
		$content = '';
		foreach ($files as $file)
			$content .= file_get_contents($file)."\n"; // Just to be sure, add an extra newline

		// Save it
		return file_put_contents($this->check($outputFile), $this->doLessify($content));
	}

	private function lessFlatten($value)
	{
		if (($value[0] == "list" || $value[0] == "string") && count($value[2]) == 1)
			return $this->lessFlatten($value[2][0]);
		return $value;
	}

	private function lessGetValue($args)
	{
		switch($args[0])
		{
			case 'string':
				return $this->lessFlatten($args);
			case 'keyword':
				return $args[1];
		}
		return null;
	}

	private function lessSetValue($args, $value)
	{
		switch($args[0])
		{
			case 'string':
				$args[2] = [$value];
			break;
			case 'keyword':
				$args[1] = $value;
			break;
		}
		return $args;
	}

	private function doLessify($css)
	{
		$less = new lessc;

		// Comporess it in production
		if (Kohana::$environment == Kohana::PRODUCTION)
			$less->setFormatter('compressed');
		$less->addImportDir(DOCROOT);

		// Fix all relative URL's to absolute ones
		$less->registerFunction('url', function($arg, $less)
		{
			$url = $this->lessGetValue($arg);
			if ($url == null)
			{
				Kohana::$log->add(Log::ERROR, tr('Something wrong with url function parser'));
				return;
			}

			// If it is not absolute or a data input make it absolute
			if (
				strpos($url, '://') === false &&
				stripos($url, 'data:') === false &&
				strpos($url, '/') !== 0 &&
				strripos($url, '.'.self::EXT_CSS) !== strlen($url) - 4 &&
				strripos($url, '.less') !== strlen($url) - 5
				)
			{

				// Check if we have to remove an url part
				$s_ = ($s = strpos($url, '?')) === false ? PHP_MAXPATHLEN : $s;
				$s__ = ($s = strpos($url, '#')) === false ? PHP_MAXPATHLEN : $s;
				$delim = ($s_ === PHP_MAXPATHLEN && $s__ === PHP_MAXPATHLEN) ? null : ($s_ > $s__ ? '#' : '?');

				// Remove it if needed
				if ($delim !== null)
				{
					$s = explode($delim, $url, 2);
					$file = $s[0];
					$argument = $delim.$s[1];
				}
				else
				{
					$file = $url;
					$argument = '';
				}
				
				// Grab info
				$info = pathinfo($file);

				// Set url info to nothing
				if ( ! array_key_exists('extension', $info))
					$info['extension'] = '';

				// Find the file where we think it is. Css is base (so .. is application/module/etc)
				if (strpos($info['dirname'], '..'.DIRECTORY_SEPARATOR) === 0)
				{
					// Build full path and split it on directory seperator
					$filename = trim($info['dirname'], '.'.DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$info['filename'];
					$parts = explode(DIRECTORY_SEPARATOR, $filename);

					// If there is just one part to this (just file then top dir is nothing) else pick first part as top rest as filepath
					if (count($parts) <= 1)
					{
						$top = '';
						$filename = current($parts);
					}
					else
					{
						$top = array_shift($parts);
						$filename = implode(DIRECTORY_SEPARATOR, $parts);
					}
				}
				else
				{
					// Else we are relative to css and we have to just look into css
					$filename = rtrim($info['dirname'], DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$info['filename'];
					$top = self::DIRECTORY_CSS;
				}

				// Find the file in the Kohana structure
				// NOTE: What to do with duplicates?? Just pick first one (see cascade file system)
				$absolutePath = Kohana::find_file($top, $filename, $info['extension']);
				
				if ( ! $absolutePath)
				{
					echo Debug::vars(tr('[LESSC] Was not able to find url file: :file in top directory: :top', [':file' => $filename.'.'.$info['extension'], ':top' => $top]));
					Kohana::$log->add(Log::ERROR, tr('[LESSC] Was not able to find url file: :file in top directory: :top', [':file' => $filename.'.'.$info['extension'], ':top' => $top]));
					return;
				}

				// Symlink it to the asset directory and build an URL for it
				$urlBasePath = $top.DIRECTORY_SEPARATOR;
				$urlPath = self::DIRECTORY_ASSET.DIRECTORY_SEPARATOR.$urlBasePath.$info['filename'].'.'.$info['extension'];
				$filePath = $urlBasePath.$info['filename'].'.'.$info['extension'];
				$urlParts = explode(DIRECTORY_SEPARATOR, $urlBasePath);
				array_pop($urlParts); // Pop the file from the path

				// Create each dir (all places we need 0755 so do not let mkdir do recursive)
				$newPath = $this->assetPath;
 				foreach ($urlParts as $part)
 				{
 					$newPath .= DIRECTORY_SEPARATOR.$part;
 					if ( ! is_dir($newPath))
 						mkdir($newPath, self::CHMOD_DIR);
 				}

 				// Make symlink
 				if ( ! file_exists($this->assetPath.DIRECTORY_SEPARATOR.$filePath))
					symlink($absolutePath, $this->assetPath.DIRECTORY_SEPARATOR.$filePath);

				// Return absolute path
				$arg = $this->lessSetValue($arg, URL::site($urlPath, true));
			}

			return [
				'function',
				'url',
				$arg
			];

		});

		// Compile it
		try
		{
			return $less->compile($css);
		}
		catch(Exception $e)
		{
			throw new HTTP_Exception_500('Error in less input: :msg', [':msg' => $e->getMessage()], $e);
		}
	}
}