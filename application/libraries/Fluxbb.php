<?php

if (!defined('BASEPATH')) exit('No direct script access allowed');

// Don't forget to set the PUN_ROOT to the directory where the fluxbb is installed
if(!defined('PUN_ROOT')) define('PUN_ROOT', dirname("").'forums/');


/**
 * FluxBB
 *
 * Authentication library for FluxBB
 * Change PUN_ROOT to point to your forum installation
 * Tested with FluxBB 1.4.2 using a mysql db
 *
 * @package		FluxBB
 * @author		Elia Morling
 * @version		1.0.0
 */
class FluxBB{
	var $pun_user;
	var $db;
	var $db_prefix;
	var $db_type;
	var $cookie_name;
	var $cookie_seed;
	var $cookie_path;
	var $cookie_domain;
	var $cookie_secure;

	function __construct(){
		// load the config
		require_once PUN_ROOT.'config.php';
		// connect and store ref to the fluxbb database
		$CI =& get_instance();
		$dsn = "mysql://$db_username:$db_password@$db_host/$db_name";
		$this->db = $CI->load->database($dsn, true);
		// store db config
		$this->db_prefix = $db_prefix;
		$this->db_type = $db_type;
		// store cookie config
		$this->cookie_name = $cookie_name;
		$this->cookie_seed = $cookie_seed;
		$this->cookie_path = $cookie_path;
		$this->cookie_domain = $cookie_domain;
		$this->cookie_secure = $cookie_secure;
	}


	/**
	 * Auto-detects if there is a guest or a logged in user
	 * @return array	An array containing info about the user
	 */
	function getUser(){
		// We assume it's a guest
		$this->cookie = array('user_id' => 1, 'password_hash' => 'Guest', 'expiration_time' => 0);

		// If a cookie is set, we get the user_id and password hash from it
		if (isset($_COOKIE[$this->cookie_name]) && preg_match('/a:3:{i:0;s:\d+:"(\d+)";i:1;s:\d+:"([0-9a-f]+)";i:2;i:(\d+);}/', $_COOKIE[$this->cookie_name], $matches))
			list(, $this->cookie['user_id'], $this->cookie['password_hash'], $this->cookie['expiration_time']) = $matches;
		// get remote adress
		$remote_addr = $_SERVER['REMOTE_ADDR'];
		// get user
		$this->pun_user = array();
		if ($this->cookie['user_id'] > 1){
			// Check if there's a user with the user ID and password hash from the cookie
			$query = $this->db->query('SELECT u.*, g.*, o.logged, o.idle FROM '.$this->db_prefix.'users AS u INNER JOIN '.$this->db_prefix.'groups AS g ON u.group_id=g.g_id LEFT JOIN '.$this->db_prefix.'online AS o ON o.user_id=u.id WHERE u.id='.intval($this->cookie['user_id'])) or $this->error('Unable to fetch user information', __FILE__, __LINE__, $this->db->_error_message());
			if ($query->num_rows() > 0){
				$this->pun_user = $query->row_array();
				$this->pun_user['is_guest'] = false;
				// Update online list
				$sql = 'REPLACE INTO '.$this->db_prefix.'online (user_id, ident, logged) VALUES('.$this->pun_user['id'].', '.$this->db->escape($this->pun_user['username']).', '.time().')';
				$this->db->query($sql) or $this->error('Unable to insert into online list with sql:'.$sql, __FILE__, __LINE__, $this->db->_error_message());
			}
		}
		if(empty($this->pun_user)){
			// User is a guest
			$this->pun_user['is_guest'] = true;
			// Update online list
			$this->db->query('REPLACE INTO '.$this->db_prefix.'online (user_id, ident, logged) VALUES(1, '.$this->db->escape($remote_addr).', '.time().')') or $this->error('Unable to insert into online list', __FILE__, __LINE__, $this->db->_error_message());
		}
		//var_dump($this->pun_user);
		return $this->pun_user;
	}

	const ERROR_USER_DOES_NOT_EXIST = "ERROR_USER_DOES_NOT_EXIST";
	const ERROR_PASSWORD_DOES_NOT_MATCH = "ERROR_PASSWORD_DOES_NOT_MATCH";

	/**
	 * Attempt to login
	 * @return array	An array describing if login was successful and any errors
	 */
	function login($username,$password,$sticky=false){
		$password_hash = sha1($password);
		$username_sql = ($this->db_type == 'mysql' || $this->db_type == 'mysqli' || $this->db_type == 'mysql_innodb' || $this->db_type == 'mysqli_innodb') ? 'username='.$this->db->escape($username) : 'LOWER(username)=LOWER('.$this->db->escape($username).')';
		$query = $this->db->query('SELECT * FROM '.$this->db_prefix.'users WHERE '.$username_sql) or $this->error('Unable to fetch user info', __FILE__, __LINE__, $this->db->_error_message());
		if (!$query->num_rows()){
			// user does not exist
			return array('login'=>false, 'error'=>Fluxbb::ERROR_USER_DOES_NOT_EXIST);
		}
		$user_arr = $query->row_array();

		if($password_hash!==$user_arr['password']){
			// pass doesn't match
			return array('login'=>false, 'error'=>Fluxbb::ERROR_PASSWORD_DOES_NOT_MATCH);
		}
		// set pun user
		$this->pun_user = $query->row_array();
		// Remove this users guest entry from the online list
		$remote_addr = $_SERVER['REMOTE_ADDR'];
		$this->db->query('DELETE FROM '.$this->db_prefix.'online WHERE ident='.$this->db->escape($remote_addr)) or $this->error('Unable to remove from online list', __FILE__, __LINE__, $this->db->_error_message());
		// set cookie
		$expire = ($sticky) ? time() + 31536000 : 0;
       	$this->pun_setcookie($this->pun_user['id'], $password_hash, $expire);

       	return array('login'=>true);
	}

	/**
	 * logoff current user
	 */
	function logoff(){
		// get current user if we didnt already
		if(!$this->pun_user){$this->getUser();}

		// Remove this users from the online list
		$this->db->query('DELETE FROM '.$this->db_prefix.'online WHERE ident='.$this->db->escape($this->pun_user['username'])) or $this->error('Unable to remove from online list', __FILE__, __LINE__, $this->db->_error_message());

		// reset user and cookie
	    $this->pun_user = array();
	    $this->pun_user['is_guest'] = true;
	    $this->pun_setcookie(1, md5(rand()), time() + 31536000);
  	}

  	/**
	 * Gets a list of online users and a guest count
	 * @return array	An array of online users and a guest count
	 */
	function getOnline(){
	  	$guest_cnt = 0;
	  	$users = array();
		$query = $this->db->query("SELECT user_id, ident FROM ".$this->db_prefix."online WHERE idle=0 ORDER BY ident");
		if ($query->num_rows() > 0){
			foreach ($query->result_array() as $row){
				if ($row['user_id'] > 1){
	          		$users[] = $row;
	          	}else{
	          		$guest_cnt++;
	          	}
			}
		}
		return array('users'=>$users, 'guest_cnt'=>$guest_cnt);
	}

	/**
	 * Displays error messages
	 */
  	private function error($message, $file = null, $line = null, $db_error = false){
  		echo($message.'<br>');
  		echo($file.', line '.$line.'<br>');
  		echo($db_error.'<br>');
  	}

  	/**
	 * Sets cookie
	 */
	private function pun_setcookie($user_id, $password_hash, $expire){
		// Enable sending of a P3P header
		header('P3P: CP="CUR ADM"');
		$value = serialize(array($user_id, md5($this->cookie_seed.$password_hash), $expire));
		setcookie($this->cookie_name, $value, $expire, $this->cookie_path, $this->cookie_domain, $this->cookie_secure, true);
	}
}

/* End of file FluxBB.php */
/* Location: ./application/libraries/FluxBB.php */
