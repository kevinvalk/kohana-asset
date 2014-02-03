<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Will populate the asset symlinks to real files.
 * It will symlink the following files:
 * - css/*.css
 * - js/*.js
 * - images/*
 *
 * The files can be in subdirectories
 *
 * @package	 Asset
 * @category Asset
 * @author   Kevin Valk <kevin@kevinvalk.nl
 */
class Task_Asset_Populate extends Minion_Task
{
	protected $_options = [
		'duplicates' => false 
	];

	/**
	 * This is a demo task
	 *
	 * @return null
	 */
	protected function _execute(array $params)
	{
		$asset = new Asset();

		echo $asset->check('/cygdrive/d/Omniasoft/web-kevinvalk/asd.t/asd.d/');
	}
}