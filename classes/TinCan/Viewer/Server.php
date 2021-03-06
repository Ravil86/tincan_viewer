<?php

namespace TinCan\Viewer;

class Server {

	const READ = 'read';
	const WRITE = 'write';
	const READ_WRITE = 'readwrite';

	private $config;
	private $dbPrefix;
	private $dbLink;
	private $ts;
	private $guid;
	private $path;
	private $hmac;

	/**
	 * Constructor
	 *
	 * @param object $config Elgg config
	 */
	public function __construct($config) {
		$this->config = $config;
		$this->dbPrefix = $config->dbprefix;

		$uri = explode('/', $this->get('__uri')); // htaccess rewrite rule

		$this->guid = (int) array_shift($uri);
		$this->ts = array_shift($uri);
		$this->hmac = array_shift($uri);
		$this->path = implode('/', $uri);
	}

	/**
	 * Serves a file
	 * Terminates the script and sends headers on error
	 * @return void
	 */
	public function serve() {
		if (headers_sent()) {
			return;
		}

		if (!$this->guid || !$this->ts || !$this->path || !$this->hmac) {
			header("HTTP/1.1 400 Bad Request");
			exit;
		}

		$etag = $this->guid;
		if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) == "\"$etag\"") {
			header("HTTP/1.1 304 Not Modified");
			exit;
		}

		$this->openDbLink();
		$values = $this->getDatalistValue(array('dataroot', '__site_secret__'));
		$this->closeDbLink();

		if (empty($values)) {
			header("HTTP/1.1 404 Not Found");
			exit;
		}

		$data_root = $values['dataroot'];
		$key = $values['__site_secret__'];

		$hmac = hash_hmac('sha256', $this->guid . $this->ts . $_COOKIE['Elgg'], $key);
		if ($this->hmac !== $hmac) {
			header("HTTP/1.1 403 Forbidden");
			exit;
		}

		$locator = new \Elgg\EntityDirLocator($this->guid);
		$d = $locator->getPath();

		$filename = "{$data_root}{$d}{$this->path}";

		if (!file_exists($filename) || !is_readable($filename)) {
			header("HTTP/1.1 404 Not Found");
			exit;
		}

		$basename = pathinfo($filename, PATHINFO_BASENAME);

		$mime = '';
		if (function_exists('finfo_file') && defined('FILEINFO_MIME_TYPE')) {
			$resource = finfo_open(FILEINFO_MIME_TYPE);
			if ($resource) {
				$mime = finfo_file($resource, $filename);
			}
		}
		if (!$mime && function_exists('mime_content_type')) {
			$mime = mime_content_type($filename);
		}
		if (!$mime) {
			$mime = 'text/html';
		}

		$modules = array();
		if (function_exists('apache_get_modules')) {
			$modules = apache_get_modules();
		}

		if (in_array('mod_xsendfile', $modules)) {
			header('X-Sendfile: ' . $filename);
			header("Content-Type: $mime");
			header("Content-Disposition: inline; filename=\"$basename\"");
		} else {
			$filesize = filesize($filename);
			header("Content-Length: $filesize");
			header("Content-Type: $mime");
			header("Content-Disposition: inline; filename=\"$basename\"");
			while (ob_get_level()) {
				ob_end_clean();
			}
			flush();
			readfile($filename);
		}
	}

	/**
	 * Returns DB config
	 * @return array
	 */
	protected function getDbConfig() {
		if ($this->isDatabaseSplit()) {
			return $this->getConnectionConfig(self::READ);
		}
		return $this->getConnectionConfig(self::READ_WRITE);
	}

	/**
	 * Connects to DB
	 * @return void
	 */
	protected function openDbLink() {
		$dbConfig = $this->getDbConfig();
		$this->dbLink = @mysql_connect($dbConfig['host'], $dbConfig['user'], $dbConfig['password'], true);
	}

	/**
	 * Closes DB connection
	 * @return void
	 */
	protected function closeDbLink() {
		if ($this->dbLink) {
			mysql_close($this->dbLink);
		}
	}

	/**
	 * Retreive values from datalists table
	 *
	 * @param array $names Parameter names to retreive
	 * @return array
	 */
	protected function getDatalistValue(array $names = array()) {

		if (!$this->dbLink) {
			return array();
		}

		$dbConfig = $this->getDbConfig();
		if (!mysql_select_db($dbConfig['database'], $this->dbLink)) {
			return array();
		}

		if (empty($names)) {
			return array();
		}
		$names_in = array();
		foreach ($names as $name) {
			$name = mysql_real_escape_string($name);
			$names_in[] = "'$name'";
		}
		$names_in = implode(',', $names_in);

		$values = array();

		$q = "SELECT name, value
				FROM {$this->dbPrefix}datalists
				WHERE name IN ({$names_in})";

		$result = mysql_query($q, $this->dbLink);
		if ($result) {
			$row = mysql_fetch_object($result);
			while ($row) {
				$values[$row->name] = $row->value;
				$row = mysql_fetch_object($result);
			}
		}

		return $values;
	}

	/**
	 * Returns request query value
	 *
	 * @param string $name    Query name
	 * @param mixed  $default Default value
	 * @return mixed
	 */
	protected function get($name, $default = null) {
		if (isset($_GET[$name])) {
			return $_GET[$name];
		}
		return $default;
	}

	/**
	 * Are the read and write connections separate?
	 *
	 * @return bool
	 */
	public function isDatabaseSplit() {
		if (isset($this->config->db) && isset($this->config->db['split'])) {
			return $this->config->db['split'];
		}
		// this was the recommend structure from Elgg 1.0 to 1.8
		if (isset($this->config->db) && isset($this->config->db->split)) {
			return $this->config->db->split;
		}
		return false;
	}

	/**
	 * Get the connection configuration
	 *
	 * The parameters are in an array like this:
	 * array(
	 * 	'host' => 'xxx',
	 *  'user' => 'xxx',
	 *  'password' => 'xxx',
	 *  'database' => 'xxx',
	 * )
	 *
	 * @param int $type The connection type: READ, WRITE, READ_WRITE
	 * @return array
	 */
	public function getConnectionConfig($type = self::READ_WRITE) {
		$config = array();
		switch ($type) {
			case self::READ:
			case self::WRITE:
				$config = $this->getParticularConnectionConfig($type);
				break;
			default:
				$config = $this->getGeneralConnectionConfig();
				break;
		}
		return $config;
	}

	/**
	 * Get the read/write database connection information
	 *
	 * @return array
	 */
	protected function getGeneralConnectionConfig() {
		return array(
			'host' => $this->config->dbhost,
			'user' => $this->config->dbuser,
			'password' => $this->config->dbpass,
			'database' => $this->config->dbname,
		);
	}

	/**
	 * Get connection information for reading or writing
	 *
	 * @param string $type Connection type: 'write' or 'read'
	 * @return array
	 */
	protected function getParticularConnectionConfig($type) {
		if (is_object($this->config->db[$type])) {
			// old style single connection (Elgg < 1.9)
			$config = array(
				'host' => $this->config->db[$type]->dbhost,
				'user' => $this->config->db[$type]->dbuser,
				'password' => $this->config->db[$type]->dbpass,
				'database' => $this->config->db[$type]->dbname,
			);
		} else if (array_key_exists('dbhost', $this->config->db[$type])) {
			// new style single connection
			$config = array(
				'host' => $this->config->db[$type]['dbhost'],
				'user' => $this->config->db[$type]['dbuser'],
				'password' => $this->config->db[$type]['dbpass'],
				'database' => $this->config->db[$type]['dbname'],
			);
		} else if (is_object(current($this->config->db[$type]))) {
			// old style multiple connections
			$index = array_rand($this->config->db[$type]);
			$config = array(
				'host' => $this->config->db[$type][$index]->dbhost,
				'user' => $this->config->db[$type][$index]->dbuser,
				'password' => $this->config->db[$type][$index]->dbpass,
				'database' => $this->config->db[$type][$index]->dbname,
			);
		} else {
			// new style multiple connections
			$index = array_rand($this->config->db[$type]);
			$config = array(
				'host' => $this->config->db[$type][$index]['dbhost'],
				'user' => $this->config->db[$type][$index]['dbuser'],
				'password' => $this->config->db[$type][$index]['dbpass'],
				'database' => $this->config->db[$type][$index]['dbname'],
			);
		}
		return $config;
	}

}
