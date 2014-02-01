<?php
function vendorLoad($class, $file, $ext)
{
	if ( ! class_exists($class))
	{
		$file = Kohana::find_file('vendor', $file, $ext);
		if ($file == null)
		{
			Kohana::$log->add(Log::ERROR, tr('Was not able to find vendor file: :file', [':file' => $file]));
		}
		else
		{
			include_once($file);
		}
	}
}

// Load extra classes
vendorLoad('lessc', 'lessc.inc', 'php');
vendorLoad('JSMinPlus', 'JSMinPlus', 'php');
