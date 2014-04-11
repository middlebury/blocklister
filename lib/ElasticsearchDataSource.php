<?php
/**
 * @package blacklister
 *
 * @copyright Copyright &copy; 2014, Middlebury College
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License (GPL)
 */

/**
 * A data source for searching Elasticsearch indexes.
 *
 * @package blacklister
 *
 * @copyright Copyright &copy; 2014, Middlebury College
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License (GPL)
 */
class ElasticsearchDataSource {

	protected $base_url;
	protected $index_base;
	protected $verbose = FALSE;

	/**
	 * Constructor
	 *
	 * @param string $base_url
	 * @param string $index_base 
	 *	This string will be appended to the base_url as a subdirectory with $index_base
	 *	followed by '-', followed by the date in YYYY-MM-DD format
	 * @return null
	 */
	public function __construct ($base_url, $index_base) {
		if (!preg_match('/^https?:\/\/.+\/$/', $base_url))
			throw new InvalidArgumentException('$base_url must begin with \'http://\' or \'https://\' and end with a \'/\'.');
		if (!strlen($index_base))
			throw new InvalidArgumentException('$index_base must be specified, for example \'logstash\'');
		$this->base_url = $base_url;
		$this->index_base = $index_base;
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
	 * Perform a search
	 *
	 * @param mixed $data
	 * @param int $from Timestamp to begin searching at.
	 * @param mixed $to Timestamp to end searching at or 'now'.
	 * @return array
	 */
	public function search ($data, $from, $to) {
		if (empty($data))
			throw new InvalidArgumentException('No $data specified.');
		if (!is_int($from) || $from < 0)
			throw new InvalidArgumentException('A $from timestamp must be specified.');
		if ($to != 'now' && (!is_int($to) || $to < 0))
			throw new InvalidArgumentException('A $to timestamp must be specified.');
		
		if ($to == 'now') {
			$toString = 'now';
			$to = time();
		} else {
			$toString = null;
		}
		
		// Add an index for each day covered in the search time, will be filtered as unique.
		$indices = array();
		$indices[] = $this->index_base.'-'.gmdate('Y.m.d', $to);
		$indices[] = $this->index_base.'-'.gmdate('Y.m.d', $from);
		for ($t = $from + 24 * 3600; $t < $to; $t = $t + 24 * 3600) {
			$indices[] = $this->index_base.'-'.gmdate('Y.m.d', $t);
		}
		$indices = array_unique($indices);
		sort($indices);
		
		$request_data = json_encode($data);
		if ($request_data === FALSE)
			throw new InvalidArgumentException('$data could not be encoded as JSON.');
				
		// Fetch the results.
		$results = array();
		$options = array(
			'useragent' => 'Blacklister',
		);
		if (!empty($this->http_auth))
			$options['httpauth'] = $this->http_auth;
		
		foreach ($indices as $index) {
			try {
				$url = $this->base_url.$index.'/_search?pretty';
				$response = http_parse_message(http_post_data($url, $request_data, $options, $info));
				$result = json_decode($response->body);
				if ($this->verbose) {
					print "Searching $url for \n\t$request_data\n";
				}
				if (!empty($result->error) || $info['response_code'] != 200) {
					if (!empty($result->error))
						$message = $result->error;
					else
						$message = '';
					throw new Exception('Search to '.$info['effective_url'].' failed with response_code '.$info['response_code'].'. '.$message);
				}
				if (empty($result->hits))
					throw new Exception('Error decoding JSON response into hits.');
				$results = array_merge($results, $result->hits->hits);
			} catch (Exception $e) {
				// Continue other fetches even if we have a search error.
				echo "Error: ".$e->getMessage()."\n";
			}
		}
		return $results;
	}
}
