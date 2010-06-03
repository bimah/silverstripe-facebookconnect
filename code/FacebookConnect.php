<?php

/**
 * Main controller class to handle facebook connect implementations. Extends the built in
 * SilverStripe controller to add addition template functionality.
 *
 *
 * @package facebookconnect
 */

class FacebookConnect extends Extension {
	
	/**
	 * When a user iteracts with the website should we create a
	 * {@link Member} on the site with their details and login that {@link Member}
	 * object via {@link Member->login()} 
	 *
	 * This is enabled by default since most apps (like forum) need valid
	 * {@link Member} objects. To disable this set {@link FacebookConnect::set_create_member(false)}
	 *
	 * By setting this to true we also require the 'email' permission from the
	 * open graph as members still need to be tided to emails as the identifier. 
	 */
	private static $create_member = true;
	
	/**
	 * If creating members is enabled then its a smart idea to set a group
	 * to save all the members too. For instance you might want to save all fbconnect
	 * members automatically to your mailing list.
	 *
	 * You must have {@link FacebookConnect::$create_member} set to true (as it is by default)
	 * for this to make any effect.
	 *
	 * @var Array array('groupcode', 'groupcode1')
	 */
	private static $member_groups = array();
	
	/**
	 * The permissions which you require for your application. The facebook api has a
	 * list of all the permissions. If you leave the $create_member option to true
	 * by default it adds the email permission no matter what you set here
	 *
	 * @see http://developers.facebook.com/docs/authentication/permissions
	 * @var Array
	 */
	private static $permissions = array();
	
	/**
	 * @var Facebook - facebook client
	 */
	private $facebook;
	
	/**
	 * @var Member The facebook member logged in 
	 */
	private $facebookmember;
	
	private static $api_key = "";
	
	private static $api_secret = "";
	
	private static $app_id = "";
	
	/**
	 * Sets whether a {@link Member} object should be created when a facebook member on the
	 * site authenicates. If this is set to false to access the member and its data you will
	 * have to use <% control CurrentFacebookMember %> rather than <% control CurrentMember %>
	 *
	 * @param bool
	 */
	public static function set_create_member($bool) {
		self::$create_member = $bool;
	}
	
	public static function create_member() {
		return self::$create_member;
	}
	
	public static function set_member_groups($group) {
		if(is_array($group)) {
			self::$member_groups = $group;
		}
		else {
			self::$member_groups[] = $group;
		}
	}
	
	public static function get_member_groups() {
		return (count(self::$member_groups) > 0) ? self::$member_groups : false;
	}
	
	public static function set_permissions($permissions = array()) {
		self::$permissions = $permissions;
	}
	
	public static function get_permissions() {
		return self::$permissions;
	}
	
	public static function set_api_key($key) {
		self::$api_key = $key;
	}
	
	public static function set_api_secret($secret) {
		self::$api_secret = $secret;
	}
	
	public static function set_app_id($id) {
		self::$app_id = $id;
	}
	
	public static function get_app_id() {
		return self::$app_id;
	}
	
	public static function get_api_secret() {
		return self::$api_secret;
	}
	
	public function getFacebook() {
		return $this->facebook;
	}
	
	/**
	 * Extends the built in {@link Controller::init()} function to load the 
	 * required files for facebook connect.
	 */
	public function onBeforeInit() {
		$this->facebook = new Facebook(array(
			'appId'  => self::get_app_id(),
			'secret' => self::get_api_secret(),
			'cookie' => true,
		));
		
		$session = $this->facebook->getSession();

		if($session) {
			try {
				$result = $this->facebook->api('/me');
				
				if($result) {
					
					// check to see if a member already with that email
					$email = (isset($result['email'])) ? $result['email'] : false;
					if($email) {
						$member = DataObject::get_one('Member', "Email = '". Convert::raw2sql($email) ."'");
						
						if(!$member) $member = new Member();
					}
					else {
						// create a new member object can cache it on this controller.
						// if we have create member object enabled then we can also write
						// it to the database and log that member in!
						$member = new Member();
					}

					$member->Email 		= (isset($result['email'])) ? $result['email'] : "";
					$member->FirstName	= (isset($result['first_name'])) ? $result['first_name'] : "";
					$member->Surname	= (isset($result['last_name'])) ? $result['last_name'] : "";
					$member->FacebookLink	= (isset($result['link'])) ? $result['link'] : "";
					$member->FacebookUID	= (isset($result['id'])) ? $result['id'] : "";
					$member->FacebookTimezone = (isset($result['timezone'])) ? $result['timezone'] : "";
					$this->facebookmember = $member;
					
					if(self::create_member()) {
						$member->write();
						$member->logIn();
						
						if($groups = self::get_member_groups()) {
							foreach($groups as $group) {
								Group::add_to_group_by_code($member, $group);
							}
						}
					}
				}
			} catch (FacebookApiException $e) {
				user_error($e, E_USER_WARNING);
			}
		}

		// add the javascript requirements 
		Requirements::customScript(<<<JS
(function() {
	var e = document.createElement('script');
	e.src = document.location.protocol + '//connect.facebook.net/en_US/all.js';
	e.async = true;
	document.getElementById('fb-root').appendChild(e);
}());			
JS
);
		
		$appID = self::get_app_id();
		$sessionJSON = json_encode($session);
		
		Requirements::customScript(<<<JS
window.fbAsyncInit = function() {
    FB.init({
		appId   : '$appID',
		session : $sessionJSON,
		status  : true,
		cookie  : true,
		xfbml   : true
    });

	FB.Event.subscribe('auth.login', function() {
		window.location.reload();
	});
};
		
JS
);
	}
	
	/**
	 * If {@link FacebookConnect::set_create_member()} is set to false then the build in
	 * CurrentMember functionality will not be functional. If the user has specifically 
	 * overridden the create member then they can update to use this function.
	 *
	 * It wraps the raw data fields from facebook.
	 *
	 * @return ArrayData
	 */
	public function CurrentFacebookMember() {
		return ($this->facebookmember) ? $this->facebookmember : false;
	}
	
	/**
	 * Logout link
	 * 
	 * @return String
	 */
	public function FacebookLogoutLink() {
		return $this->facebook->getLogoutUrl();
	}
	
	/**
	 * Permissions to require on the login button
	 *
	 * @return String
	 */
	public function FacebookPermissions() {
		
		$permissions = self::get_permissions();
		
		if(self::create_member()) {
			$permissions = $permissions + array('email');
		}	
			
		return implode(',', $permissions);
 	}
}