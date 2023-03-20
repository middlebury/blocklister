<?php
/**
 * @package blocklister
 *
 * @copyright Copyright &copy; 2014, Middlebury College
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License (GPL)
 */

/**
 * Interface for definition of activity signatures that will be identified for blocklisting.
 *
 * @package blocklister
 *
 * @copyright Copyright &copy; 2014, Middlebury College
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License (GPL)
 */
interface Signature {

	/**
	 * Answer an array of IP addresses that match this signature.
	 *
	 * @return array of strings in the format '127.0.0.1'
	 */
	public function getMatchingIPs ();

	/**
	 * Set verbose mode.
	 *
	 * @param boolean $verbose
	 * @return null
	 */
	public function setVerbose ($verbose);

}
