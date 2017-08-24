<?php

require_once dirname(__FILE__) . '/ntbb-database.lib.php';
// require_once dirname(__FILE__) . '/password_compat/lib/password.php';

$curuser = false;

class NTBBSession {
	var $cookiedomain = 'pokemonshowdown.com';
	var $trustedproxies = array(
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'104.16.0.0/12',
		'108.162.192.0/18',
		'131.0.72.0/22',
		'141.101.64.0/18',
		'162.158.0.0/15',
		'172.64.0.0/13',
		'173.245.48.0/20',
		'188.114.96.0/20',
		'190.93.240.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'199.27.128.0/21',
	);

	var $sid = '';
	var $session = 0;

	function __construct() {
		global $psdb, $curuser;
		$ctime = time();
		$curuser = $this->getGuest();

		// see if we're logged in
		$osid = @$_COOKIE['sid'];
		if (!$osid) {
			// nope, not logged in
			return;
		}
		$osidsplit = explode('x', $osid, 2);
		if (count($osidsplit) !== 2) {
			// malformed `sid` cookie
			$this->killCookie();
			return;
		}
		$session = intval($osidsplit[0]);
		$res = $psdb->query(
			"SELECT sid, timeout, `{$psdb->prefix}users`.* " .
			"FROM `{$psdb->prefix}sessions`, `{$psdb->prefix}users` " .
			"WHERE `session` = ? " .
				"AND `{$psdb->prefix}sessions`.`userid` = `{$psdb->prefix}users`.`userid` " .
			"LIMIT 1", [$session]);
		if (!$res) {
			// query problem?
			$this->killCookie();
			return;
		}
		$sess = $psdb->fetch_assoc($res);
		if (!$sess || !password_verify(base64_decode($osidsplit[1]), $sess['sid'])) {
			// invalid session ID
			$this->killCookie();
			return;
		}
		if (intval($sess['timeout'])<$ctime) {
			// session expired
			// delete all sessions that will expire within 30 minutes
			$ctime += 60 * 30;
			$psdb->query("DELETE FROM `{$psdb->prefix}sessions` WHERE `timeout` < ?", [$ctime]);
			$this->killCookie();
			return;
		}

		// okay, legit session ID - you're logged in now.
		$curuser = $sess;
		$curuser['loggedin'] = true;
		// unset these values to avoid them being leaked accidentally
		$curuser['outdatedpassword'] = !!$curuser['password'];
		unset($curuser['password']);
		unset($curuser['nonce']);
		unset($curuser['passwordhash']);

		$this->sid = $osid;
		$this->session = $session;
	}

	// taken from http://stackoverflow.com/questions/594112/matching-an-ip-to-a-cidr-mask-in-php5
	function cidr_match($ip, $range) {
		list($subnet, $bits) = explode('/', $range);
		$ip = ip2long($ip);
		$subnet = ip2long($subnet);
		$mask = -1 << (32 - $bits);
		$subnet &= $mask; # nb: in case the supplied subnet wasn't correctly aligned
		return ($ip & $mask) == $subnet;
	}

	function getIp() {
		$ip = $_SERVER['REMOTE_ADDR'];
		foreach ($this->trustedproxies as &$proxyip) {
			if ($this->cidr_match($ip, $proxyip)) {
				$parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
				$ip = array_pop($parts);
				break;
			}
		}
		return $ip;
	}

	function userid($username) {
		if (!$username) $username = '';
		$username = strtr($username, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz");
		return preg_replace('/[^A-Za-z0-9]+/','',$username);
	}
	function getGuest($username='') {
		$userid = $this->userid($username);
		if (!$userid)
		{
			$username = 'Guest';
			$userid = 'guest';
		}
		return array(
			'username' => $username,
			'userid' => $userid,
			'group' => 0,
			'loggedin' => false
		);
	}

	function qsid() { return $this->sid ? '?sid='.$this->sid : ''; }
	function asid() { return $this->sid ? '&sid='.$this->sid : ''; }
	function hasid() { return $this->sid ? '&amp;sid='.$this->sid : ''; }
	function fsid() { return $this->sid ? '<input type="hidden" name="sid" id="sid" value="'.$this->sid.'" />' : ''; }

	/**
	 * New SID and password hashing functions.
	 */
	function mksid() {
		global $psconfig;
		if (!function_exists('mcrypt_create_iv')) {
			error_log('mcrypt_create_iv is not defined!');
			die;
		}
		return mcrypt_create_iv($psconfig['sid_length'], MCRYPT_DEV_URANDOM);
	}
	function sidHash($sid) {
		global $psconfig;
		return password_hash($sid, PASSWORD_DEFAULT, array('cost' => $psconfig['sid_cost']));
	}
	function passwordNeedsRehash($hash) {
		global $psconfig;
		return password_needs_rehash($hash, PASSWORD_DEFAULT,
			array('cost' => $psconfig['password_cost'])
		);
	}
	function passwordHash($pass) {
		global $psconfig;
		return password_hash($pass, PASSWORD_DEFAULT,
			array('cost' => $psconfig['password_cost'])
		);
	}

	public function passwordVerify($name, $pass) {
		global $psdb;

		$userid = $this->userid($name);

		$res = $psdb->query(
			"SELECT `password`, `nonce`, `passwordhash` " .
			"FROM `{$psdb->prefix}users` " .
			"WHERE `userid` = ? " .
			"LIMIT 1",
			[$userid]
		);
		if (!$res) return false;

		$user = $psdb->fetch_assoc($res);
		return $this->passwordVerifyInner($userid, $pass, $user);
	}

	private function passwordVerifyInner($userid, $pass, $user) {
		global $psdb;

		// throttle
		$ip = $this->getIp();
		$res = $psdb->query(
			"SELECT `count`, `time` FROM `{$psdb->prefix}loginthrottle` WHERE `ip` = ? LIMIT 1",
			[$ip]
		);
		$loginthrottle = null;
		if ($res) $loginthrottle = $psdb->fetch_assoc($res);
		if ($loginthrottle) {
			if ($loginthrottle['count'] > 500) {
				$loginthrottle['count']++;
				$psdb->query("UPDATE `{$psdb->prefix}loginthrottle` SET count = ?, lastuserid = ?, `time` = ? WHERE ip = ?", [$loginthrottle['count'], $userid, time(), $ip]);
				return false;
			} else if ($loginthrottle['time'] + 24 * 60 * 60 < time()) {
				$loginthrottle = [
					'count' => 0,
					'time' => time(),
				];
			}
		}

		$rehash = false;
		if ($user['passwordhash']) {
			// new password hashes
			if (!password_verify($pass, $user['passwordhash'])) {
				// wrong password
				if ($loginthrottle) {
					$loginthrottle['count']++;
					$psdb->query("UPDATE `{$psdb->prefix}loginthrottle` SET count = ?, lastuserid = ?, `time` = ? WHERE ip = ?", [$loginthrottle['count'], $userid, time(), $ip]);
				} else {
					$psdb->query("INSERT INTO `{$psdb->prefix}loginthrottle` (ip, count, lastuserid, `time`) VALUES (?, 1, ?, ?)", [$ip, $userid, time()]);
				}
				return false;
			}
			$rehash = $this->passwordNeedsRehash($user['passwordhash']);
		} else if ($user['password'] && $user['nonce']) {
			// original ntbb-session password hashes
			return false;
		} else {
			// error
			return false;
		}
		if ($rehash) {
			// create a new password hash for the user
			$hash = $this->passwordHash($pass);
			if ($hash) {
				$psdb->query(
					"UPDATE `{$psdb->prefix}users` SET `passwordhash`=?, `password`=NULL, `nonce`=NULL WHERE `userid`=?",
					[$hash, $userid]
				);
			}
		}
		return true;
	}

	function login($name, $pass, $timeout = false, $debug = false) {
		global $psdb, $curuser;
		$ctime = time();

		$this->logout();
		$userid = $this->userid($name);
		$res = $psdb->query("SELECT * FROM `{$psdb->prefix}users` WHERE `userid` = ? LIMIT 1", [$userid]);
		if (!$res) {
			if ($debug) error_log('no such user');
			return $curuser;
		}
		$user = $psdb->fetch_assoc($res);
		if ($user['banstate'] >= 100) {
			return $curuser;
		}
		if (!$this->passwordVerifyInner($userid, $pass, $user)) {
			if ($debug) error_log('wrong password');
			return $curuser;
		}
		if (!$timeout) {
			// expire in a week and 30 minutes
			$timeout = (14*24*60+30)*60;
		}
		$timeout += $ctime;

		$nsid = $this->mksid();
		$nsidhash = $this->sidHash($nsid);
		$res = $psdb->query(
			"INSERT INTO `{$psdb->prefix}sessions` (`userid`,`sid`,`time`,`timeout`,`ip`) VALUES (?,?,?,?,?)",
			[$user['userid'], $nsidhash, $ctime, $timeout, $this->getIp()]
		);
		if (!$res) die;

		$this->session = $psdb->insert_id();
		$this->sid = $this->session . 'x' . base64_encode($nsid);

		$curuser = $user;
		$curuser['loggedin'] = true;
		// unset these values to avoid them being leaked accidentally
		$curuser['outdatedpassword'] = !!$curuser['password'];
		unset($curuser['password']);
		unset($curuser['nonce']);
		unset($curuser['passwordhash']);

		setcookie('sid', $this->sid, $timeout, '/', $this->cookiedomain, false, true);

		return $curuser;
	}

	function killCookie() {
		setcookie('sid', '', time()-60*60*24*2,
				'/', $this->cookiedomain, false, true);
	}

	function csrfData() {
		echo '<input type="hidden" name="csrf" value="'.$this->sid.'" />';
		return '';
	}

	function csrfCheck() {
		if (empty($_POST['csrf'])) return false;
		$csrf = $_POST['csrf'];
		if ($csrf === @$_COOKIE['sid']) return true;
		return false;
	}

	function logout() {
		global $psdb,$curuser;

		if (!$this->session) return $curuser;

		$curuser = $this->getGuest();
		$psdb->query("DELETE FROM `{$psdb->prefix}sessions` WHERE `session` = ? LIMIT 1", [$this->session]);
		$this->sid = '';
		$this->session = 0;

		$this->killCookie();

		return $curuser;
	}

	function createPasswordResetToken($name, $timeout=false) {
		global $psdb, $curuser;
		$ctime = time();

		$userid = $this->userid($name);
		$res = $psdb->query("SELECT * FROM `{$psdb->prefix}users` WHERE `userid` = ? LIMIT 1", [$userid]);
		if (!$res) // user doesn't exist
			return false;
		$user = $psdb->fetch_assoc($res);
		if (!$timeout) {
			$timeout = 7*24*60*60;
		}
		$timeout += $ctime;
		{
			$modlogentry = "Password reset token generated";
			$psdb->query(
				"INSERT INTO `{$psdb->prefix}usermodlog` (`userid`,`actorid`,`date`,`ip`,`entry`) VALUES (?, ?, ?, ?, ?)",
				[$user['userid'], $curuser['userid'], time(), $this->getIp(), $modlogentry]
			);

			// magical character string...
			$nsid = bin2hex($this->mksid());
			$res = $psdb->query(
				"INSERT INTO `{$psdb->prefix}sessions` (`userid`,`sid`,`time`,`timeout`,`ip`) VALUES (?, ?, ?, ?, ?)",
				[$user['userid'], $nsid, $ctime, $timeout, $this->getIp()]
			);
			if (!$res) die($psdb->error());
		}

		return $nsid;
	}

	function validatePasswordResetToken($token) {
		global $psdb, $psconfig;
		if (strlen($token) !== ($psconfig['sid_length'] * 2)) return false;
		$res = $psdb->query("SELECT * FROM `{$psdb->prefix}sessions` WHERE `sid` = ? LIMIT 1", [$token]);
		$session = $psdb->fetch_assoc($res);
		if (!$session) return false;

		if (intval($session['timeout'])<time()) {
			// session expired
			return false;
		}

		return $session['userid'];
	}

	function getUser($userid=false) {
		global $psdb, $curuser;

		if (is_array($userid)) $userid = $userid['userid'];
		$userid = $this->userid($userid);
		if (!$userid ||
				(($userid === $curuser['userid']) && !empty($user['loggedin']))) {
			return $curuser;
		}

		$res = $psdb->query("SELECT * FROM `{$psdb->prefix}users` WHERE `userid` = ? LIMIT 1", [$userid]);
		if (!$res) // query failed for weird reason
			return false;
		$user = $psdb->fetch_assoc($res);

		if (!$user)
		{
			return false;
		}

		// unset these values to avoid them being leaked accidentally
		$user['outdatedpassword'] = !!$user['password'];
		unset($user['password']);
		unset($user['nonce']);
		unset($user['passwordhash']);

		return $user;
	}

	function getGroupName($user=false) {
		global $ntbb_cache;
		$user = $this->getUser($user);
		return @$ntbb_cache['groups'][$user['group']]['name'];
	}

	function getGroupSymbol($user=false) {
		global $ntbb_cache;
		$user = $this->getUser($user);
		return @$ntbb_cache['groups'][$user['group']]['symbol'];
	}

	function getUserData($username) {
		$userdata = $this->getUser($username);

		if ($userdata) return $userdata;

		$userdata = $this->getGuest($username);

		return $userdata;
	}

	function getAssertion($userid, $serverhostname, $user = null, $challengekeyid = -1, $challenge = '', $challengeprefix = '') {
		global $psdb, $curuser, $psconfig;

		if (substr($userid, 0, 5) === 'guest') {
			return ';;Your username cannot start with \'guest\'.';
		} else if (strlen($userid) > 18) {
			return ';;Your username must be less than 19 characters long.';
		} else if (!$this->isUseridAllowed($userid)) {
			return ';;Your username contains disallowed text.';
		}

		$data = '';
		if (!$user) {
			$user = $curuser;
		}
		$ip = $this->getIp();
		$forceUsertype = false;

		if (($user['userid'] === $userid) && !empty($user['loggedin'])) {
			// already logged in
			$usertype = '2';
			if (in_array($userid, $psconfig['sysops'], true)) {
				$usertype = '3';
			} else {
				// check autoconfirmed
				if ($forceUsertype) {
					$usertype = $forceUsertype;
				} else if (intval(@$user['banstate']) <= -10) {
					$usertype = '4';
				} else if (@$user['banstate'] >= 100) {
					return ';;Your username is no longer available.';
				} else if (@$user['banstate'] >= 40) {
					if ($serverhostname === 'sim2.psim.us') {
						$usertype = '40';
					} else {
						$usertype = '2';
					}
				} else if (@$user['banstate'] >= 30) {
					$usertype = '6';
				} else if (@$user['banstate'] >= 20) {
					$usertype = '5';
				} else if (@$user['banstate'] == 0) {
					if (@$user['registertime'] && time() - $user['registertime'] > 7*24*60*60) {
						$res = $psdb->query("SELECT formatid FROM ntbb_ladder WHERE userid = ? LIMIT 1", [$userid]);
						if ($psdb->fetch_assoc($res)) {
							$usertype = '4';
							$psdb->query("UPDATE ntbb_users SET banstate = -10 WHERE userid = ? LIMIT 1", [$userid]);
						}
					}
				}
			}
			if (!@$user['logintime'] || time() - $user['logintime'] > 24*60*60) {
				$psdb->query(
					"UPDATE ntbb_users SET logintime = ?, loginip = ? WHERE userid = ? LIMIT 1",
					[time(), $ip, $userid]
				);
			}
			$data = $user['userid'].',' . $usertype . ','.time().','.$serverhostname;
		} else {
			if ((strlen($userid) < 1) || ctype_digit($userid)) {
				return ';;Your username must contain at least one letter.';
			}
			$res = $psdb->query("SELECT * FROM `{$psdb->prefix}users` WHERE `userid` = ? LIMIT 1", [$userid]);
			if (!$res) {
				// query failed for weird reason
				return ';;The login server is under heavy load, please try logging in again later';
			} else if ($row = $psdb->fetch_assoc($res)) {
				// Username exists, but the user isn't logged in: require authentication.
				if ($row['banstate'] >= 100) {
					return ';;Your username is no longer available.';
				}
				if ($row['password'] && $row['nonce']) {
					return ';;Your username is no longer available.';
				}
				return ';';
			} else {
				// Unregistered username.
				$usertype = '1';
				if ($forceUsertype) $usertype = $forceUsertype;
				$data = $userid.','.$usertype.','.time().','.$serverhostname;
			}
		}

		$splitChallenge = explode(';', $challenge);
		if (count($splitChallenge) == 1) {
			$splitChallenge = explode('|', $challenge);
		}
		if (count($splitChallenge) == 1) {
			$splitChallenge = explode('%7C', $challenge);
		}
		$challengetoken = @$_REQUEST['challengetoken'];
		if (count($splitChallenge) > 1) {
			$challengekeyid = intval($splitChallenge[0]);
			$challenge = $splitChallenge[1];
			if (@$splitChallenge[2] && !$challengetoken) $challengetoken = $splitChallenge[2];
			$_REQUEST['challengetoken'] = $challengetoken;
		}

		if ($challengekeyid < 1) {
			return ';;This server is requesting an invalid login key. This probably means that either you are not connected to a server, or the server is set up incorrectly.';
		} else if ($challengekeyid < 2) {
			// Compromised keys - no longer supported.
			return ';;This server is using login key ' . $challengekeyid . ', which is no longer supported. Please tell the server operator to update their config.js file.';
		} else if (empty($psconfig['privatekeys'][$challengekeyid])) {
			// Bogus key id.
			return ';;Unknown key ID';
		} else if (!preg_match('/^[0-9a-f]*$/', $challenge)) {
			// Bogus challenge.
			return ';;Corrupt challenge';
		} else {
			// Include the challenge in the assertion.
			$data = $challengeprefix . $challenge . ',' . $data;
		}

		if (strpos($challengeprefix . $challenge, ',') !== false) {
			trigger_error("challenge contains comma? ".$challengeprefix." // ".$challenge, E_USER_ERROR);
		}

		if (function_exists('psconfig_validate_assertion')) {
			psconfig_validate_assertion($data, $serverhostname, $user);
		}

		$sig = '';
		openssl_sign($data, $sig, openssl_get_privatekey($psconfig['privatekeys'][$challengekeyid]));
		return $data.';'.bin2hex($sig);
	}

	function modifyUser($user, $changes) {
		global $psdb, $curuser;
		$userid = $user;
		if (is_array($user)) $userid = $user['userid'];

		$res = $psdb->query("SELECT * FROM `{$psdb->prefix}users` WHERE `userid` = ? LIMIT 1", [$userid]);
		if (!$res) // query failed for weird reason
			return false;
		$user = $psdb->fetch_assoc($res);
		if (!$user['userid']) return false;

		if (@$changes['password']) {
			$modlogentry = "Password changed from: {$user['passwordhash']}";
			$psdb->query(
				"INSERT INTO `{$psdb->prefix}usermodlog` (`userid`,`actorid`,`date`,`ip`,`entry`) VALUES (?,?,?,?,?)",
				[$user['userid'], $curuser['userid'], time(), $this->getIp(), $modlogentry]
			);
			$passwordhash = $this->passwordHash($changes['password']);
			$psdb->query(
				"UPDATE `{$psdb->prefix}users` SET `passwordhash`=?, `password`=NULL, `nonce`=NULL WHERE `userid` = ?",
				[$passwordhash, $user['userid']]
			);
			if ($psdb->error()) {
				return false;
			}
			$psdb->query("DELETE FROM `{$psdb->prefix}sessions` WHERE `userid` = ?", [$user['userid']]);
			if ($curuser['userid'] === $userid) {
				$this->login($userid, $changes['password']);
			}
		}
		if (!empty($changes['group'])) {
			$group = intval($changes['group']);
			$psdb->query("UPDATE `{$psdb->prefix}users` SET `group` = $group WHERE `userid` = ?", [$user['userid']]);
			if ($psdb->error()) {
				return false;
			}
		}
		if (!empty($changes['username'])) {
			$newUsername = $changes['username'];
			if (strlen($newUsername) > 18) return false;
			$newUserid = $this->userid($newUsername);
			if ($userid !== $newUserid) return false;
			$psdb->query(
				"UPDATE `{$psdb->prefix}users` SET `username` = ? WHERE `userid` = ?",
				[$newUsername, $userid]
			);
			if ($psdb->error()) {
				return false;
			}
		}
		if (!empty($changes['userdata'])) {
			$psdb->query(
				"UPDATE `{$psdb->prefix}users` SET `userdata` = ? WHERE `userid` = ?",
				[$changes['userdata'], $user['userid']]
			);
			if ($psdb->error()) {
				return false;
			}
			$user['userdata'] = $changes['userdata'];
		}
		return true;
	}

	function getRecentRegistrationCount($ip = '', $timeperiod = 7200 /* 2 hours */) {
		global $psdb;
		if ($ip === '') {
			$ip = $this->getIp();
		}
		$timestamp = time() - $timeperiod;
		$res = $psdb->query(
			"SELECT COUNT(*) AS `registrationcount` FROM `{$psdb->prefix}users` WHERE `ip` = ? AND `registertime` > ?",
			[$ip, $timestamp]
		);
		if (!$res) {
			return false;
		}
		$user = $psdb->fetch_assoc($res);
		if ($user === NULL) {	// Should be impossible.
			return 0;
		}
		return $user['registrationcount'];
	}

	function addUser($user, $password) {
		global $psdb, $curuser;
		$ctime = time();

		$user['userid'] = $this->userid($user['username']);
		$user['passwordhash'] = $this->passwordHash($password);

		if (!$this->isUseridAllowed($user['userid'])) {
			return false;
		}

		$res = $psdb->query("SELECT `userid` FROM `{$psdb->prefix}users` WHERE `userid` = ? LIMIT 1", [$user['userid']]);
		if ($row = $psdb->fetch_assoc($res)) {
			return false;
		}

		$psdb->query(
			"INSERT INTO `{$psdb->prefix}users` (`userid`,`username`,`passwordhash`,`email`,`registertime`,`ip`) VALUES (?, ?, ?, ?, ?, ?)",
			[$user['userid'], $user['username'], $user['passwordhash'], @$user['email'], $ctime, $this->getIp()]
		);
		if ($psdb->error()) {
			return false;
		}

		$user['usernum'] = $psdb->insert_id();
		$this->login($user['username'], $password);
		if (!$curuser['loggedin'] || $curuser['userid'] !== $user['userid']) return false;
		return $curuser;
	}

	function wordfilter($text) {
		$text = str_ireplace('lolicon', '*', $text);
		$text = str_ireplace('roricon', '*', $text);
		return $text;
	}

	function isUseridAllowed($userid) {
		if (strpos($userid, 'nigger') !== false) return false;
		if (strpos($userid, 'nigga') !== false) return false;
		if (strpos($userid, 'faggot') !== false) return false;
		if (strpos($userid, 'lolicon') !== false) return false;
		if (strpos($userid, 'roricon') !== false) return false;
		if (strpos($userid, 'lazyafrican') !== false) return false;
		return true;
	}
}

$users = new NTBBSession();

