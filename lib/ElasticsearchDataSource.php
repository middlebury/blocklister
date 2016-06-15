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
	protected $index_cache = array();

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
	 * Answer an array of indices in which to search.
	 *
	 * @param int $from Timestamp to begin searching at.
	 * @param mixed $to Timestamp to end searching at or 'now'.
	 * @return array
	 */
	protected function getIndices($from, $to) {
		// Use the field-stats API to search for indices if we have a wild-card.
		// This is the method used by Kibana > 4.1.
		// https://www.elastic.co/guide/en/elasticsearch/reference/2.3/search-field-stats.html
		if (strpos($this->index_base, '*') !== FALSE) {
			return $this->getIndicesBySearch($from, $to);
		}
		// Make assumptions about the naming scheme of indices.
		else {
			return $this->getIndicesByPattern($from, $to);
		}
	}

	/**
	 * Answer an array of indices in which to search.
	 *
	 * Use the field-stats API to search for indices if we have a wild-card.
	 * This is the method used by Kibana > 4.1.
	 * https://www.elastic.co/guide/en/elasticsearch/reference/2.3/search-field-stats.html
	 *
	 * @param int $from Timestamp to begin searching at.
	 * @param mixed $to Timestamp to end searching at or 'now'.
	 * @return array
	 */
	protected function getIndicesBySearch($from, $to) {
		if (!isset($this->index_cache[$from.'-'.$to])) {
			$url = $this->base_url.$this->index_base.'/_field_stats?level=indices&pretty=true';
			$request_data = json_encode(array(
				'fields' => array('@timestamp'),
				'index_constraints' => array(
					'@timestamp' => array(
						"max_value" => array(
							"gte" => date('c', $from),
						),
						"min_value" => array(
							"lte" => date('c', $to),
						),
					),
				),
			));
			$message = @http_post_data($url, $request_data, $options, $info);
			if (empty($message)) {
				$error = $info['error'];
				$error .= ' total_time='.$info['total_time'];
				$error .= ' namelookup_time='.$info['namelookup_time'];
				$error .= ' connect_time='.$info['connect_time'];
				$error .= ' pretransfer_time='.$info['pretransfer_time'];
				$error .= ' num_connects='.$info['num_connects'];
				$error .= ' os_errno='.$info['os_errno'];
				throw new Exception('HTTP POST to '.$url.' failed. '.$error);
			}
			$response = http_parse_message($message);
			$result = json_decode($response->body, TRUE);
			$this->index_cache[$from.'-'.$to] = array_keys($result['indices']);
			if ($this->verbose) {
				print "Search for indices at $url with \n\t$request_data\nfound\n\t".implode(', ', $this->index_cache[$from.'-'.$to])."\n";
			}
		}
		return $this->index_cache[$from.'-'.$to];
	}

	/**
	 * Answer an array of indices in which to search.
	 *
	 * Assume regular date structure for the index names.
	 *
	 * @param int $from Timestamp to begin searching at.
	 * @param mixed $to Timestamp to end searching at or 'now'.
	 * @return array
	 */
	protected function getIndicesByPattern($from, $to) {
		$indices = array();
		$indices[] = $this->index_base.'-'.gmdate('Y.m.d', $to);
		$indices[] = $this->index_base.'-'.gmdate('Y.m.d', $from);
		for ($t = $from + 24 * 3600; $t < $to; $t = $t + 24 * 3600) {
			$indices[] = $this->index_base.'-'.gmdate('Y.m.d', $t);
		}
		return $indices;
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
		$indices = $this->getIndices($from, $to);
		$indices = array_unique($indices);
		sort($indices);

		$request_data = json_encode($data);
		if ($request_data === FALSE)
			throw new InvalidArgumentException('$data could not be encoded as JSON.');

		// Fetch the results.
		$results = array();
		$options = array(
			'useragent' => 'Blacklister',
			'connecttimeout' => 10,
			'timeout' => 30,
		);
		if (!empty($this->http_auth))
			$options['httpauth'] = $this->http_auth;

		foreach ($indices as $index) {
			try {
				$url = $this->base_url.$index.'/_search?pretty';
				$message = @http_post_data($url, $request_data, $options, $info);
				if (empty($message)) {
					$error = $info['error'];
					$error .= ' total_time='.$info['total_time'];
					$error .= ' namelookup_time='.$info['namelookup_time'];
					$error .= ' connect_time='.$info['connect_time'];
					$error .= ' pretransfer_time='.$info['pretransfer_time'];
					$error .= ' num_connects='.$info['num_connects'];
					$error .= ' os_errno='.$info['os_errno'];
					throw new Exception('HTTP POST to '.$url.' failed. '.$error);
				}
				$response = http_parse_message($message);
				$result = json_decode($response->body);
				if ($this->verbose) {
					print "Searching $url for \n\t$request_data\n";
				}
				if (!empty($result->error) || $info['response_code'] != 200) {
					if (!empty($result->error))
						if (is_object($result->error)) {
							$message = $result->error->type.": ".$result->error->reason;
							if (!empty($result->error->index)) {
								$message .= " in index ".$result->error->index;
							}
						} else {
							$message = $result->error;
						}
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
