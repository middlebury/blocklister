#!/usr/bin/env php
<?php
/**
 * @package blacklister
 *
 * @copyright Copyright &copy; 2014, Middlebury College
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License (GPL)
 */

try {
	spl_autoload_register(function ($class) {
		include dirname(__FILE__).'/lib/'.$class.'.php';
	});
	require_once(dirname(__FILE__).'/vendor/autoload.php');

	require_once(dirname(__FILE__).'/lib/ArgumentParser.php');

	$args = getOptionArray(__FILE__, $argv);
	if (!empty($args['h']) || !empty($args['help'])) {
		echo "Usage:\n";
		echo "\t".$argv[0]." [-hv]\n";
		echo "\n";
		echo "\t-h	show this help\n";
		echo "\t-v	verbose mode\n";
		echo "\n";
		exit(1);
	}

	if (!empty($args['v']))
		$verbose = TRUE;
	else
		$verbose = FALSE;

	$blacklist = new Blacklist($verbose);

	if (!file_exists(dirname(__FILE__).'/config.php'))
		throw new Exception('To complete installation, please copy config.php.example to config.php and edit it to match your environment.', 4);

	require_once(dirname(__FILE__).'/config.php');

	// Update the blacklist based on matches to the signatures
	$blacklist->update();
	exit(0);
} catch (Exception $e) {
	print 'Error: '.$e->getMessage()."\n";
	exit($e->getCode());
}
