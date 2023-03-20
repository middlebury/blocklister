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
class ElasticsearchSignature implements Signature {

	protected $elasticsearch;
	protected $query;
	protected $window;
	protected $threshold;
	protected $field;
	protected $verbose = FALSE;

	/**
	 * Constructor
	 *
	 * @param ElasticsearchDataSource $elasticsearch
	 * @return null
	 */
	public function __construct (ElasticsearchDataSource $elasticsearch) {
		$this->elasticsearch = $elasticsearch;
	}

	/**
	 * Set verbose mode.
	 *
	 * @param boolean $verbose
	 * @return null
	 */
	public function setVerbose ($verbose) {
		if ($verbose)
			$this->verbose = TRUE;
		else
			$this->verbose = FALSE;
	}

	/**
	 * Set the query for this signature to match, for example:
	 *		cluster:drupal AND type:varnishncsa AND verb:post AND response:403
	 *
	 * @param string $query
	 * @return null
	 */
	public function setQuery ($query) {
		if (empty($query))
			throw new InvalidArgumentException('$query can not be empty.');
		if (!is_string($query))
			throw new InvalidArgumentException('$query must be a string.');
		$this->query = $query;
	}

	/**
	 * Set the window of time that should be examined for matching events.
	 *
	 * @param mixed $window
	 *		An integer number of seconds or times in the following format:
	 *			'5m'  ...means 5 minutes
	 *			'5h'  ...means 5 hours
	 *			'5d'  ...means 5 days
	 * @return null
	 */
	public function setWindow ($window) {
		$this->window = Blocklist::getSecondsFromTime($window);
	}

	/**
	 * Set the threshold number of events at which point we should include an IP.
	 *
	 * @param int $threshold
	 * @return null
	 */
	public function setThreshold ($threshold) {
		if (!is_int($threshold) || $threshold < 1)
			throw new InvalidArgumentException('$threshold must be a positive integer.');
		$this->threshold = $threshold;
	}

	/**
	 * Set the IP address field in the Elasticsearch data
	 *
	 * @param string $field
	 * @return null
	 */
	public function setIPField ($field) {
		if (!strlen($field))
			throw new InvalidArgumentException('$field must be a non-zero-length string.');
		$this->field = $field;
	}

	/**
	 * Answer an array of IP addresses that match this signature.
	 *
	 * @return array of strings in the format '127.0.0.1'
	 */
	public function getMatchingIPs () {
		if (empty($this->query))
			throw new Exception('Please specify a query using '.get_class($this).'->setQuery()');
		if (empty($this->window))
			throw new Exception('Please specify a time window using '.get_class($this).'->setWindow()');
		if (empty($this->threshold))
			throw new Exception('Please specify a number-of-events threshold using '.get_class($this).'->setThreshold()');
		if (empty($this->field))
			throw new Exception('Please specify a IP address field using '.get_class($this).'->setIPField()');

		$to = time();
		$from = $to - $this->window;

		$request = [
			"query" => [
				"bool" => [
					"must" => [
						"query_string" => [
							"query" => $this->query,
						],
					],
					"filter" => [
						"bool" => [
							"must" => [
								[
									"range" => [
										"@timestamp" => [
											"from" => $from * 1000,
											"to" => $to * 1000,
										]
									],
								],
							],
						],
					]
				],
			],
			"size" => 10000,  // Hard-coding to 10,000 results. This is ElasticSearch's max.
		];

		if ($this->verbose) {
			$this->elasticsearch->setVerbose(TRUE);
		}

		$results = $this->elasticsearch->search($request, $from, $to);

		// Count the matches.
		$counts = array();
		$field = $this->field;
		foreach ($results as $result) {
			if (empty($result->_source->$field)) {
				echo "Matched record with no '$field' property.\n";
			} else {
				$ip = $result->_source->$field;
				if (!isset($counts[$ip]))
					$counts[$ip] = 0;
				$counts[$ip]++;
			}
		}

		if ($this->verbose) {
			print "Matching IPs with counts: ";
			print_r($counts);
		}

		// Filter to only those meeting the threshold.
		$matching = array();
		foreach ($counts as $ip => $count) {
			if ($count >= $this->threshold)
				$matching[] = $ip;
		}
		return $matching;
	}

}
