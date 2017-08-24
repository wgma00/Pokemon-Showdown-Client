<?php

include_once dirname(__FILE__).'/../config/config.inc.php';

class PSDatabase {
	var $db = null;

	var $server = null;
	var $username = null;
	var $password = null;
	var $database = null;
	var $prefix = null;
	var $charset = null;

	function __construct($dbconfig) {
		$this->server = $dbconfig['server'];
		$this->username = $dbconfig['username'];
		$this->password = $dbconfig['password'];
		$this->database = $dbconfig['database'];
		$this->prefix = $dbconfig['prefix'];
		$this->charset = $dbconfig['charset'];
	}

	function connect() {
		if (!$this->db) {
			$this->db = new PDO(
				"mysql:dbname={$this->database};host={$this->server};charset={$this->charset}",
				$this->username,
				$this->password
			);
			$this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
		}
	}
	function query($query, $params=false) {
		$this->connect();
		if ($params) {
			$stmt = $this->db->prepare($query);
			$stmt->execute($params);
			return $stmt;
		} else {
			return $this->db->query($query);
		}
	}
	function fetch_assoc($resource) {
		return $resource->fetch(PDO::FETCH_ASSOC);
	}
	function fetch($resource) {
		return $resource->fetch();
	}
	function escape($data) {
		$this->connect();
		$data = $this->db->quote($data);
		return substr($data, 1, -1);
	}
	function error() {
		if ($this->db) {
			return $this->db->errorInfo()[2];
		}
	}
	function insert_id() {
		if ($this->db) {
			return $this->db->lastInsertId();
		}
	}
}

$psdb = new PSDatabase($psconfig);
