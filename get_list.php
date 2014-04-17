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
	
	if (!file_exists(dirname(__FILE__).'/config.php'))
		throw new Exception('To complete installation, please copy config.php.example to config.php and edit it to match your environment.', 4);
	
	require_once(dirname(__FILE__).'/config.php');

	// Update the blacklist based on matches to the signatures
	@header('Content-Type: text/plain');
	if (!empty($static_list_members) && is_array($static_list_members)) {
		print implode("\n", $static_list_members);
		print "\n";
	}
	$ips = $blacklist->getList();
	foreach ($ips as $ip) {
		print $ip."/32\n";
	}
	
	exit(0);
} catch (Exception $e) {
	print 'Error: '.$e->getMessage()."\n";
	exit($e->getCode());
}
