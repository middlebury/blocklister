<?php
/**
 * Blacklister configuration. Used by both update_list.php and get_list.php
 *
 * @package blacklister
 * 
 * @copyright Copyright &copy; 2014, Middlebury College
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License (GPL)
 */

/*********************************************************
 * Blacklist database
 *********************************************************/
$blacklist->setBlacklistDatabase(new PDO('mysql:host=127.0.0.1;dbname=afranco_blacklist', 'testuser', 'testpassword'));

/*********************************************************
 * Add a regular expressions that will match IP ranges
 * that we should never add to the blacklist.
 * This will prevent accidentally blacklisting certain
 * clients that are trusted.
 *********************************************************/
$blacklist->addWhitelistPattern('/^140\.233\./');
$blacklist->addWhitelistPattern('/^172\.16\./');


/*********************************************************
 * Configure the Elasticsearch data source that will be 
 * shared by the following signatures.
 *********************************************************/
$web_es = new ElasticsearchDataSource('http://logs.example.com:9200/', 'logstash');

/*********************************************************
 * Signatures to match
 *********************************************************/

// Match POST requests that result in 403 responses.
//
// Real people can get these if they leave a form open 
// overnight and their session expires. Let them refresh
// the page a few times before logging in. If they get
// more than a few of these, they are *very* likely malicious.
$signature = new ElasticsearchSignature($web_es);
$signature->setQuery("cluster:drupal AND type:varnishncsa AND verb:post AND response:403");
$signature->setWindow('5m');
$signature->setThreshold(10);
$signature->setIPField('orig_clientip');
$blacklist->addSignature('POST with 403', $signature, '6h');

// Match form submissions that include a honeypot field.
//
// These are almost never submitted by real people and are
// a very good indication of malicious behavior.
$signature = new ElasticsearchSignature($web_es);
$signature->setQuery("cluster:drupal AND type:drupal_watchdog AND drupal_action:uncaptchalous_hp");
$signature->setWindow('5m');
$signature->setThreshold(2);
$signature->setIPField('orig_clientip');
$blacklist->addSignature('form honeypot', $signature, '6h');

// Match form submissions that do not have javascript enabled.
//
// This is more common for valid clients, so allow clients
// to try a few submissions before getting around to turning
// on Javascript.
$signature = new ElasticsearchSignature($web_es);
$signature->setQuery("cluster:drupal AND type:drupal_watchdog AND drupal_action:uncaptchalous_js_val");
$signature->setWindow('5m');
$signature->setThreshold(10);
$signature->setIPField('orig_clientip');
$blacklist->addSignature('form missing js', $signature, '1h');