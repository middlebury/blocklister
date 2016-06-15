<?php
/**
 * @package blacklister
 *
 * @copyright Copyright &copy; 2014, Middlebury College
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License (GPL)
 */

/**
 * A controller class for the blacklist system. Handles loading of signature matches and
 * apply of updates to the destination database.
 *
 * @package blacklister
 *
 * @copyright Copyright &copy; 2014, Middlebury College
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License (GPL)
 */
class Blacklist {

	protected $destDB;
	protected $whitelistPatterns = array();
	protected $signatures = array();
	protected $verbose = FALSE;
	protected $alertThreshold = 0;
	protected $alertEmails = array();
	protected $alertFromEmail = null;

	/**
	 * Constructor.
	 *
	 * @return null
	 */
	public function __construct ($verbose = FALSE) {
		if ($verbose)
			$this->verbose = TRUE;
	}

	/**
	 * Add a destination database.
	 *
	 * @param PDO $destDB
	 * @return null
	 */
	public function setBlacklistDatabase (PDO $destDB) {
		$this->destDB = $destDB;
		$this->destDB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// Test if the table exists.
		try {
			$this->destDB->query('SELECT * FROM blacklist');
		} catch (PDOException $e) {
			$query = "
CREATE TABLE IF NOT EXISTS blacklist (
  time_added bigint(20) NOT NULL,
  ttl int(11) NOT NULL DEFAULT '60',
  ip varchar(15) NOT NULL,
  signature varchar(255) NOT NULL,
  PRIMARY KEY (ip)
);";
			$this->destDB->query($query);
		}
	}

	/**
	 * Add a regular expression to match IP ranges that should never be blacklisted.
	 *
	 * @param string $regex
	 * @return null
	 */
	public function addWhitelistPattern ($regex) {
		$res = @ preg_match($regex, '127.0.0.1');
		if ($res === FALSE)
			throw new InvalidArgumentException('Invalid regex supplied to addWhitelistPattern(): '.$regex, 1);
		$this->whitelistPatterns[] = $regex;
	}

	/**
	 * Add a signature to match events.
	 *
	 * @param string $displayName
	 * @param Signature $signature
	 * @param mixed $blacklistTime
	 *		An integer number of seconds or times in the following format:
	 *			'5m'  ...means 5 minutes
	 *			'5h'  ...means 5 hours
	 *			'5d'  ...means 5 days
	 * @return null
	 */
	public function addSignature ($displayName, Signature $signature, $blacklistTime = '1h') {
		$this->signatures[] = array(
			'displayName' => $displayName,
			'signature' => $signature,
			'blacklistTime' => self::getSecondsFromTime($blacklistTime),
		);
		if ($this->verbose)
			$signature->setVerbose(TRUE);
	}

	/**
	 * Set a threshold number of matches to alert on. If more than this number of
	 * IPs match in an execution, send an alert email. If set to 0, no alerts will be sent.
	 *
	 * @param int $threshold
	 * @return null
	 */
	public function setAlertThreshold ($threshold) {
		if (!is_int($threshold) || $threshold < 0)
			throw new InvalidArgumentException('$threshold must be an integer greater than or equal to 0.');

		$this->alertThreshold = $threshold;
	}

	/**
	 * Set the From email address for alert emails
	 *
	 * @param string $email
	 * @return null
	 */
	public function setAlertFromEmailAddress ($email) {
		if (!filter_var($email, FILTER_VALIDATE_EMAIL))
			throw new InvalidArgumentException('The $email specified doesn\'t look like a valid address.');

		$this->alertFromEmail = $email;
	}

	/**
	 * Add an email address to receive alerts when the threshold is exceeded.
	 *
	 * @param string $email
	 * @return null
	 */
	public function addAlertEmailAddress ($email) {
		if (!filter_var($email, FILTER_VALIDATE_EMAIL))
			throw new InvalidArgumentException('The $email specified doesn\'t look like a valid address.');

		$this->alertEmails[] = $email;
	}

	/**
	 * Update the blacklist from signature matches.
	 *
	 * @return null
	 */
	public function update () {
		// Remove expired entries from the blacklist.
		$this->_removeExpired();

		$blacklist = array(); // Array of [IP => blacklist time].

		foreach ($this->signatures as $signatureArray) {
			$displayName = $signatureArray['displayName'];
			$signature = $signatureArray['signature'];
			$blacklistTime = $signatureArray['blacklistTime'];
			$ips = $this->_filterWhitelistedIPs($signature->getMatchingIPs(), $displayName);
			foreach ($ips as $ip) {
				echo "Client [".$ip."] matched signature [".$displayName."]\n";
				// IPs might match multiple signatures, so add IPs to the blacklist with
				// the largest time matched.
				if (!isset($blacklist[$ip])) {
					$blacklist[$ip] = array(
						'blacklistTime' => $blacklistTime,
						'signature' => $displayName,
						'matched_signatures' => array($displayName),
					);
				} else {
					// Add our signature to the list of all matched.
					$blacklist[$ip]['matched_signatures'][] = $displayName;

					// Replace the primary signature if this one has a longer block-time
					if ($blacklistTime > $blacklist[$ip]['blacklistTime']) {
						$blacklist[$ip]['blacklistTime'] = $blacklistTime;
						$blacklist[$ip]['signature'] = $displayName;
					}
				}
			}
		}

		$now = time();
		$insert = $this->destDB->prepare('INSERT INTO blacklist (time_added, ttl, ip, signature) VALUES (:time_added, :ttl, :ip, :signature)');
		$select = $this->destDB->prepare('SELECT ip FROM blacklist WHERE ip = :ip');
		foreach ($blacklist as $ip => $info) {
			$select->execute(array(':ip' => $ip));
			$existing = $select->fetchAll(PDO::FETCH_COLUMN);
			if (empty($existing)) {
				$insert->execute(array(
					':time_added' => $now,
					':ttl' => $info['blacklistTime'],
					':ip' => $ip,
					':signature' => $info['signature'],
				));
				echo "Client [".$ip."] added to blacklist for ".$this->_formatTime($info['blacklistTime'])." for matching signature [".$info['signature']."]\n";
			}
		}

		// Send alerts if needed.
		if ($this->alertThreshold > 0 && count($blacklist) >= $this->alertThreshold) {
			if (!count($this->alertEmails))
				print "Error: Alert threshold set to ".$this->alertThreshold.", but no alert email addresses are defined.\n";

			$hostname = gethostname();
			$subject = "Blacklister alert from ".$hostname.": ".$this->alertThreshold."+ clients matched.";
			ob_start();
			print "<html>\n";
			print "\t<head>\n";
			print "\t\t<title>".$subject."</title>\n";
			print "\t</head>\n";
			print "\t<body>\n";
			print "\t\t<p>From Blacklister on ".$hostname.":</p>\n";
			print "\t\t<p>".count($blacklist)." client IPs were matched in this run, exceeding the threshold of ".$this->alertThreshold.".</p>\n\n";
			print "\t\t<p>Matched clients:</p>\n";
			print "\t\t<pre style=\"font-family:courier new,monospace\">";
			foreach ($blacklist as $ip => $info) {
				print "\t".$ip."\tmatched\t[".implode('], [', $info['matched_signatures'])."]\n";
			}
			print "</pre>\n";
			print "\t</body>\n";
			print "</html>\n";
			$message = ob_get_clean();
			if (empty($this->alertFromEmail)) {
				// Set up a default From address for any alert emails.
				if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
					$processUser = posix_getpwuid(posix_geteuid());
					$this->alertFromEmail = $processUser['name'].'@'.gethostname();
				} else {
					print "Error: POSIX functions posix_getpwuid() and/or posix_geteuid() are not available. Please add POSIX support to PHP http://us3.php.net/manual/en/book.posix.php or use $blacklister->setAlertFromEmailAddress() to set the alert From address.";
				}
			}
			$additional_headers = array(
				'From: '.$this->alertFromEmail,
				'MIME-Version: 1.0',
				'Content-Type: text/html; charset="iso-8859-1"',
				'Content-Disposition: inline',
			);
			mail(implode(',', $this->alertEmails), $subject, str_replace("\n", "\r\n", $message), implode("\r\n", $additional_headers));
		}
	}

	/**
	 * Answer the blacklisted IPs
	 *
	 * @return array
	 */
	public function getList () {
		$select = $this->destDB->prepare('SELECT ip FROM blacklist WHERE time_added + ttl > :now');
		$select->bindValue(':now', time(), PDO::PARAM_INT);
		$select->execute();
		return $select->fetchAll(PDO::FETCH_COLUMN);
	}

	/**
	 * Return a nicely formatted time string given an integer number of seconds.
	 *
	 * @param int $seconds
	 * @return string
	 */
	protected function _formatTime ($seconds) {
		$seconds = intval($seconds);
		if ($seconds < 120)
			return $seconds.' seconds';
		if ($seconds < 3600)
			return round($seconds / 60).' minutes';
		if ($seconds < (3600 * 48))
			return round($seconds / 3600, 1).' hours';

		return round($seconds / (3600 * 24), 1).' days';
	}

	/**
	 * Removed expired entries from the blacklist.
	 *
	 * @return null
	 */
	protected function _removeExpired () {
		$now = time();

		$select = $this->destDB->prepare('SELECT * FROM blacklist WHERE time_added + ttl < :now');
		$select->bindValue(':now', $now, PDO::PARAM_INT);
		$select->execute();
		foreach ($select->fetchAll(PDO::FETCH_OBJ) as $row) {
			echo "Client [".$row->ip."] removed from blacklist after ".$this->_formatTime($now - $row->time_added)." for matching signature [".$row->signature."]\n";
		}

		$delete = $this->destDB->prepare('DELETE FROM blacklist WHERE time_added + ttl < :now');
		$delete->bindValue(':now', $now, PDO::PARAM_INT);
		$delete->execute();
	}

	/**
	 * Filter out whitelisted IPs
	 *
	 * @param array $ips
	 * @param string $displayName
	 * @return array
	 */
	protected function _filterWhitelistedIPs (array $ips, $displayName) {
		$filteredIPs = array();
		foreach ($ips as $ip) {
			$whitelisted = FALSE;
			foreach ($this->whitelistPatterns as $pattern) {
				if (preg_match($pattern, $ip)) {
					$whitelisted = TRUE;
					break;
				}
			}
			if ($whitelisted) {
				echo "Whitelisted client [".$ip."] matched signature [".$displayName."], ignoring.\n";
			} else {
				$filteredIPs[] = $ip;
			}
		}
		return $filteredIPs;
	}


	/**
	 * Answer an integer number of seconds given an integer or a time string.
	 *
	 * @param mixed $time
	 *		An integer number of seconds or times in the following format:
	 *			'5s'  ...means 5 seconds
	 *			'5m'  ...means 5 minutes
	 *			'5h'  ...means 5 hours
	 *			'5d'  ...means 5 days
	 *			'1w'  ...means 1 week
	 * @return int
	 */
	public static function getSecondsFromTime ($time) {
		if (is_int($time)) {
			if ($time < 1)
				throw new InvalidArgumentException('$time must be a positive integer.');
			return $time;
		} else {
			if (!preg_match('/^\s*([0-9]+)\s*([dhms]?)\s*$/i', $time, $m))
				throw new InvalidArgumentException('$time must be an integer or a string like these: "5m", "2h", "1d"');

			$multipliers = array(
				's' => 1,
				'm' => 60,
				'h' => 3600,
				'd' => (3600 * 24),
				'w' => (3600 * 24 * 7),
			);
			$unit = strtolower($m[2]);
			if (!isset($multipliers[$unit]))
				$unit = 's';

			return intval($m[1]) * $multipliers[$unit];
		}
	}
}
