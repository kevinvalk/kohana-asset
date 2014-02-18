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
	const GD_DPI = 96;

	const CHMOD = 0750;

	private $assetPath;
	private $javaScripts = [];
	private $styleSheets = [];

	public function __construct()
	{
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
		$this->assetPath = DOCROOT.$directory.DIRECTORY_SEPARATOR;

		// If the directory does not exists create it (recursively)
		$this->makePath($this->assetPath);
		$this->makePath($this->assetPath.self::DIRECTORY_IMAGE);
	}

	private function makePath($path, $recursive = true)
	{
		if ( ! is_dir($this->assetPath.self::DIRECTORY_IMAGE))
			return mkdir($this->assetPath.self::DIRECTORY_IMAGE, self::CHMOD, $recursive);
		return true;
	}

	public function getTextImage($text, $font, $fontSize, $fontColor, $fontType = '')
	{
		// Path
		$name = 'tti_'.md5(json_encode(func_get_args())).'.png';
		$path = $this->assetPath.self::DIRECTORY_IMAGE.DIRECTORY_SEPARATOR.$name;
		
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
			imagepng($img, $path);
			imagedestroy($img);
		}

		return URL::site(self::DIRECTORY_ASSET.DIRECTORY_SEPARATOR.self::DIRECTORY_IMAGE.DIRECTORY_SEPARATOR.$name, true);
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

	public function image($file)
	{
		// If its something absolute just return it
		if (strpos($file, '://') !== false)
			return $file;

		// TODO: Caching do not find files so much

		// Find it and symlink it
		$info = pathinfo($file);

		// Set url info to nothing
		if ( ! array_key_exists('extension', $info))
			throw new Kohana_Exception(tr('Image withouth an extension: :file!', [':file' => $file]));

		// Figure out top directory, and file + directory
		$directories = explode(DIRECTORY_SEPARATOR, $file);
		array_pop($directories); // remove the file

		// If there is no directory search in images
		if (count($directories) <= 0)
		{
			$top = self::DIRECTORY_IMAGE;
			$filename = $info['filename'];
		}
		else
		{
			$top = array_shift($directories);
			$filename = implode(DIRECTORY_SEPARATOR, $directories).$info['filename'];
		}

		// Find the file in the Kohana structure
		$absoluteFile = Kohana::find_file($top, $filename, $info['extension']);
		if ( ! $absoluteFile)
			throw new Kohana_Exception(tr('Was not able to find file: :file in top directory: :top', [':file' => $filename.'.'.$info['extension'], ':top' => $top]));

		// Mirror structure in asset dir
		array_unshift($directories, $top);
		$path = '';
		foreach ($directories as $dir)
		{
			$path .= $dir;
			if ( ! is_dir($this->assetPath.$path))
				mkdir($this->assetPath.$path, self::CHMOD);
			$path .= DIRECTORY_SEPARATOR;
		}
		$path .= $info['basename'];

		// Make symlink
		if ( ! file_exists($this->assetPath.$path))
			symlink($absoluteFile, $this->assetPath.$path);

		return URL::site(self::DIRECTORY_ASSET.DIRECTORY_SEPARATOR.$path, true);
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
		$filePath = $this->assetPath.self::DIRECTORY_JS.DIRECTORY_SEPARATOR.$fileName;

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
		$filePath = $this->assetPath.self::DIRECTORY_CSS.DIRECTORY_SEPARATOR.$fileName;

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
			mkdir(dirname($outputFile), self::CHMOD, true);
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
		return file_put_contents($outputFile, $content);
	}

	private function renderStyleSheet($outputFile)
	{
		// Make directory if not exists
		if ( ! is_dir(dirname($outputFile)))
		{
			mkdir(dirname($outputFile), self::CHMOD, true);
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
		{
			$content .= file_get_contents($file)."\n"; // Just to be sure add an extra newline
		}

		// Save it
		return file_put_contents($outputFile, $this->doLessify($content));
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
 					$newPath .= $part;
 					if ( ! is_dir($newPath))
 						mkdir($newPath, self::CHMOD);
 					$newPath .= DIRECTORY_SEPARATOR;
 				}

 				// Make symlink
 				if ( ! file_exists($this->assetPath.$filePath))
					symlink($absolutePath, $this->assetPath.$filePath);

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