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
	
	$blacklist = new Blacklist();
	require_once(dirname(__FILE__).'/config.php');

	// Update the blacklist based on matches to the signatures
	$blacklist->update();
	exit(0);
} catch (Exception $e) {
	print 'Error: '.$e->getMessage();
	exit($e->getCode());
}
