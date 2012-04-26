<?php

/**
* A controller for testing the FluxBB library
* Tested with FluxBB 1.4.2 using a mysql db
 *
 * @author		Elia Morling
 * @version		1.0.0
 */
class Fluxbbtest extends CI_Controller {

	function __construct(){
		parent::__construct();
		$this->load->helper('url');
		$this->load->library('fluxbb');
	}

	/**
	 * Presents login status, login form and online list
	 */
	function index(){
		$pun_user = $this->fluxbb->getUser();
		if(!$pun_user['is_guest']){
			echo("<p>You are logged in as ".$pun_user['username']." with id ".$pun_user['id']."</p>");
			echo("<p><a href='".site_url("fluxbbtest/logoff")."'>Logoff</a>");
		}else{
			echo("<p>You are not logged in</p>");
			$formurl = site_url("fluxbbtest/login");
			echo("<form method='post' action='$formurl'>
			  <label>username
			    <input type='text' name='username' />
			  </label>
			  <label>password
			    <input type='password' name='password' />
			  </label>
			  <label>
			    <input type='submit' value='Login' />
			  </label>
			</form>");
		}
		// display online list
		$online = $this->fluxbb->getOnline();
		echo "<hr><b>online</b><br>";
		foreach($online['users'] as $user){
			echo($user['ident']."<br>");
		}
		echo(count($online['users'])." users and ".$online['guest_cnt']." guests online");
	}

	/**
	 * Logs in and either displays error or redirects to index
	 * Expects $_POST['username'] and $_POST['password'] from login form
	 */
	function login(){
		$this->load->library('input');
		$username = $this->input->post('username');
		$password = $this->input->post('password');
		$result = $this->fluxbb->login($username, $password);
		if($result['login']){
			// login successful!
			redirect('/fluxbbtest/', 'refresh');
			return;
		}
		switch($result['error']){
			case FluxBB::ERROR_USER_DOES_NOT_EXIST:
				echo("User does not exist");
				break;
			case FluxBB::ERROR_PASSWORD_DOES_NOT_MATCH:
				echo("Wrong password");
				break;
			default:
				echo("Unkown error");
				break;
		}
	}

	/**
	 * Logs off and redirects to index
	 */
	function logoff(){
		$this->fluxbb->logoff();
		redirect('/fluxbbtest/', 'refresh');
	}
}


?>
