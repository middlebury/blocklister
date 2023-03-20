<?php
/**
 * @package blocklister
 *
 * @copyright Copyright &copy; 2014, Middlebury College
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License (GPL)
 */

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

/**
 * A data source for searching Elasticsearch indexes.
 *
 * @package blocklister
 *
 * @copyright Copyright &copy; 2014, Middlebury College
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License (GPL)
 */
class ElasticsearchDataSource {

	protected $base_url;
	protected $index_base;
	protected $verbose = FALSE;
	protected $index_cache = array();
	protected $http_auth = [];

	/**
	 * Constructor
	 *
	 * @param string $base_url
	 * @param string $index_base
	 *	This string will be appended to the base_url as a subdirectory with $index_base
	 *	followed by '-', followed by the date in YYYY-MM-DD format
	 * @return null
	 */
	public function __construct ($base_url, $index_base, $username = NULL, $password = NULL) {
		if (!preg_match('/^https?:\/\/.+\/$/', $base_url))
			throw new InvalidArgumentException('$base_url must begin with \'http://\' or \'https://\' and end with a \'/\'.');
		if (!strlen($index_base))
			throw new InvalidArgumentException('$index_base must be specified, for example \'logstash\'');
		$this->base_url = $base_url;
		$this->index_base = $index_base;

		if (!is_null($username) || !is_null($password)) {
			$this->http_auth = [$username, $password, 'basic'];
		}
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
			return [$this->index_base];
		}
		// Make assumptions about the naming scheme of indices.
		else {
			return $this->getIndicesByPattern($from, $to);
		}
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

	protected function post($url, $request_data) {
		$options = [
			'connect_timeout' => 10,
			'timeout' => 30,
		];
		if (!empty($this->http_auth)) {
			$options['auth'] = $this->http_auth;
		}
		$client = new Client($options);

		$headers = [
			'User-agent' => 'Blocklister',
			'Content-Type' => 'application/json',
		];
		$request = new Request('POST', $url, $headers, $request_data);
		$response = $client->send($request);

		$result = json_decode($response->getBody()->getContents());
		if ($this->verbose) {
			print "Searching $url for \n\t$request_data\n";
		}
		if (!empty($result->error) || $response->getStatusCode() != 200) {
			if (!empty($result->error))
				if (is_object($result->error)) {
					$message = $result->error->type.": ".$result->error->reason;
					if (!empty($result->error->index)) {
						$message .= " in index ".$result->error->index;
					}
					if (!empty($result->error->root_cause)) {
						$message .= "; root cause[s]: ";
						$i = 0;
						foreach ($result->error->root_cause as $error) {
							if ($i) {
								$message .= ", ";
							}
							$message .= $error->type.": ".$error->reason;
							$i++;
						}
					}
				} else {
					$message = $result->error;
				}
			else
				$message = '';
			throw new Exception('Search to '.$url.' failed with response_code '.$response->getStatusCode().'. '.$message);
		}
		return $result;
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
		try {
			$url = $this->base_url.implode(',', $indices).'/_search?pretty';
			$result = $this->post($url, $request_data);
			if (empty($result->hits))
				throw new Exception('Error decoding JSON response into hits.');
			$results = array_merge($results, $result->hits->hits);
		} catch (Exception $e) {
			// Continue other fetches even if we have a search error.
			echo "Error: ".$e->getMessage()."\n";
		}
		return $results;
	}
}
