<?php /* This was written and updated by Doug Webster between 2014 and 2017. It handled a significant portion of core functionality for the login areas for advisors (agents). This was used by a group of related websites for a financial advisors services firm. It handles logging in, authorization, account management, and subscriptions with reoccurring payments integrated with Authorize.net's CRM API. */

if (!function_exists('curl_init')) {
    throw new Exception('AuthorizeNet SDK needs the CURL PHP extension.');
}
if (!function_exists('simplexml_load_file')) {
  throw new Exception('The AuthorizeNet SDK requires the SimpleXML PHP extension.');
}
require_once DIR_CLASSES_SHARED . 'Authorize_net_sdk/autoload.php';

class Agent
{
	// if true, the last discount used will be applied to renewals
	const DISCOUNT_PERSISTS = true;
	/* determines how a previous discount will be applied
		'code' - the discount code will be used, therefore this will only apply if the code is still valid and for the amount of the code at time of renewal
		'percent' - the previous discount percent will be applied to the renewal amount
		'amount' - the amount of the previous discount will be subtracted from the renewal amount
	*/
	const DISCOUNT_PERSIST_TYPE = 'percent';
	private $id;
	public $data;
	private $sites = array();
	private $max_width = 4000;
	private $max_height = 2000;
	private $max_thumb_width = 175;
	private $max_thumb_height = 175;
	private $profileImgTag;
	private $profileThumbImgTag;
	private $logoImgTag;
	private $logoThumbImgTag;
	private $companyAgentIds = array();
	public $addUserId; // boolean for determining if the agent themself is logged in, or if it is one of their staff / additional logins
	private $customer_profile_id = array();
	private $setCPIDAttempted = array();
	
	public function __construct( $id = '' )
	{
		if ( ! empty( $id ) ) {
			$this->setAgent( $id );
		}
	}
	
	public function setAgent( $id = 0 )
	{
		global $db;
		
		if ( empty( $id ) ) $id = $this->id;
		$id = intval( $id );
		
		$query = "SELECT *, a.`agent_id` AS agent_id
			FROM ".TABLE_AGENTS." AS a
				LEFT JOIN ".TABLE_AGENT_IMAGES." AS i
					ON a.agent_id = i.agent_id
				LEFT JOIN ".TABLE_AGENT_LOGOS." AS l
					ON a.agent_id = l.agent_id
			WHERE a.`agent_id` = '{$id}'
		";
		$data = $db->getRecord( $query );
		if ( ! empty( $data ) ) {
			$this->data = $data;
			$this->id = $data['agent_id'];
			$this->setProfileImgTag();
			$this->setLogoImgTag();
			$this->setSites();
			$this->setAgentsInCompany();
		}
	}
	
	public function agentID()
	{
		return $this->id;
	}
	
	public function setProfileImgTag()
	{
		if ( empty( $this->data['image_file'] ) ) return false;
		
		$this->profileImgTag = '';
		$file = DIR_AGENT_IMAGES . $this->data['image_file'];
		if ( is_file( $file ) ) {
			list( $width, $height ) = getimagesize( $file );
			if ( $width > $this->max_width || $height > $this->max_height )
				dh_resize_image( $file, $this->max_width, $this->max_height );
			$this->profileImgTag = "<img src='".str_replace( LOCAL_SHARED, URL_SHARED, DIR_AGENT_IMAGES )."{$this->data['image_file']}' />";
		} else {
			$this->deleteImage();
			return false;
		}
		$this->profileThumbImgTag = '';
		$file = DIR_AGENT_IMAGES . "th/{$this->data['image_file']}";
		if ( is_file( $file ) ) {
			list( $width, $height ) = getimagesize( $file );
			if ( $width > $this->max_thumb_width || $height > $this->max_thumb_height )
				dh_resize_image( $file, $this->max_thumb_width, $this->max_thumb_height );
			$this->profileThumbImgTag = "<img src='".str_replace( LOCAL_SHARED, URL_SHARED, DIR_AGENT_IMAGES )."th/{$this->data['image_file']}' />";
		}
	}
	
	public function setLogoImgTag()
	{
		if ( empty( $this->data['logo_file'] ) ) return false;
		
		$this->logoImgTag = '';
		$file = DIR_AGENT_IMAGES . $this->data['logo_file'];
		if ( is_file( $file ) ) {
			list( $width, $height ) = getimagesize( $file );
			if ( $width > $this->max_width || $height > $this->max_height )
				dh_resize_image( $file, $this->max_width, $this->max_height );
			$this->logoImgTag = "<img src='".str_replace( LOCAL_SHARED, URL_SHARED, DIR_AGENT_IMAGES )."{$this->data['logo_file']}' />";
		} else {
			$this->deleteLogo();
			return false;
		}
		$this->logoThumbImgTag = '';
		$file = DIR_AGENT_IMAGES . "th/{$this->data['logo_file']}";
		if ( is_file( $file ) ) {
			list( $width, $height ) = getimagesize( $file );
			if ( $width > $this->max_thumb_width || $height > $this->max_thumb_height )
				dh_resize_image( $file, $this->max_thumb_width, $this->max_thumb_height );
			$this->logoThumbImgTag = "<img src='".str_replace( LOCAL_SHARED, URL_SHARED, DIR_AGENT_IMAGES )."th/{$this->data['logo_file']}' />";
		}
	}
	
	public function setAgentsInCompany()
	{
		global $db;
		
		$query = "SELECT company_id
			FROM ".TABLE_AGENTS_TO_COMPANIES."
			WHERE agent_id = '{$this->id}'
		";
		$company_id = $db->getValue( $query );
		
		if ( ! empty( $company_id ) ) {
			$query = "SELECT agent_id
				FROM ".TABLE_AGENTS_TO_COMPANIES."
				WHERE company_id = '{$company_id}'
			";
			$agent_ids = $db->getColumn( $query );
		}
		
		if ( ! empty( $agent_ids ) ) {
			$this->companyAgentIds = $agent_ids;
		} else {
			$this->companyAgentIds = array( $this->id );
		}
	}
	
	public function getAgentsInCompany()
	{
		return $this->companyAgentIds;
	}
	
	public function getAgent( $id = '' )
	{
		if ( ! empty( $id ) ) {
			$this->setAgent( $id );
		}
		return $this->data();
	}
	
	public function getProfileImgTag()
	{
		return $this->profileImgTag;
	}
	
	public function getLogoImgTag()
	{
		return $this->logoImgTag;
	}
	
	public function getProfileThumbImgTag()
	{
		return $this->profileThumbImgTag;
	}
	
	public function getLogoThumbImgTag()
	{
		return $this->logoThumbImgTag;
	}
	
	public function getName( $html_escape = true )
	{
		$name = "{$this->data['agent_firstname']} {$this->data['agent_lastname']}";
		return ( $html_escape ) ? htmlspecialchars( $name ) : $name;
	}
	
	public function getAddress( $linebreaks = true )
	{
		$address = '';
		if ( ! empty( $this->data['agent_address'] ) )
			$address .= htmlspecialchars( $this->data['agent_address'] );
		if ( ! empty( $address ) && ! empty( $this->data['agent_address2'] ) ) 
			$address .= ( $linebreaks ) ? "<br />\n" : ' ';
		if ( ! empty( $this->data['agent_address2'] ) )
			$address .= htmlspecialchars( $this->data['agent_address2'] );
		if ( ! empty( $address ) && ! empty( $this->data['agent_city'] ) ) 
			$address .= ( $linebreaks ) ? "<br />\n" : ', ';
		if ( ! empty( $this->data['agent_city'] ) )
			$address .= htmlspecialchars( $this->data['agent_city'] );
		if ( ! empty( $address ) && ! empty( $this->data['agent_state'] ) ) 
			$address .= ( $linebreaks ) ? "<br />\n" : ', ';
		if ( ! empty( $this->data['agent_state'] ) )
			$address .= htmlspecialchars( $this->data['agent_state'] );
		$address .= ' ';
		if ( ! empty( $this->data['agent_zip'] ) )
			$address .= htmlspecialchars( $this->data['agent_zip'] );
		if ( ! empty( $address ) && ! empty( $this->data['agent_country'] ) ) 
			$address .= ( $linebreaks ) ? "<br />\n" : ' ';
		if ( ! empty( $this->data['agent_country'] ) )
			$address .= htmlspecialchars( $this->data['agent_country'] );
		
		return $address;
	}
	
	public function checkLogin()
	{
		return ! empty( $_SESSION['agent_id'] ) && $_SESSION['agent_id'] == $this->id;
	}
	
	public function login( $org_username, $org_password, $check_pwd_only = false )
	{
		global $db;
		$username = $db->escape( trim( $org_username ) );
		$password = $db->escape( trim( $org_password ) );
		
		/*$query = "SELECT a.agent_id
			FROM ".TABLE_AGENTS." AS a
			LEFT JOIN ".TABLE_AGENT_ADD_LOGINS." AS a2 ON a2.agent_id = a.agent_id
			LEFT JOIN ".TABLE_AGENTS_TO_SITES." AS s ON a.agent_id = s.agent_id
			LEFT JOIN ".TABLE_AGENT_ADD_LOGINS_TO_SITES." AS s2 ON a2.add_id = s2.add_id
			WHERE (
				(
					agent_email='{$username}' 
					AND agent_pass=password('{$password}')
				) OR (
					a2.email='{$username}' 
					AND a2.password=password('{$password}')
					AND s2.site_id = '".SITE_ID."'
				)
			)
			AND a.agent_removed=0 
			AND a.active=1
			AND s.site_id = '".SITE_ID."'
		";
		$agent_id = $db->getValue( $query );*/
		$query1 = "SELECT a.agent_id
			FROM ".TABLE_AGENTS." AS a
				LEFT JOIN ".TABLE_AGENTS_TO_SITES." AS s
					ON a.agent_id = s.agent_id
			WHERE agent_email='{$username}' 
				AND agent_pass=password('{$password}')
		";
		if ( ! $check_pwd_only )
			$query1 .= "
			AND site_id = '".SITE_ID."'
			AND agent_removed=0 
			AND active=1
			AND s.deleted = 0
			";
		$agent_id = $db->getValue( $query1 );
		if ( ! empty( $agent_id ) ) {
			$this->addUserId = 0;
		} else {
			$query2 = "SELECT a.agent_id, a2.add_id
				FROM ".TABLE_AGENT_ADD_LOGINS." AS a2
					LEFT JOIN ".TABLE_AGENTS." AS a
						ON a2.agent_id = a.agent_id
					LEFT JOIN ".TABLE_AGENTS_TO_SITES." AS s
						ON a.agent_id = s.agent_id
				WHERE a2.email='{$username}' 
					AND a2.password=password('{$password}') 
					AND a.agent_removed=0 
					AND a.active=1
					AND s.site_id = '".SITE_ID."'
					AND s.deleted = 0
			";
			$record = $db->getRecord( $query2 );
			if ( ! empty( $record ) ) {
				$agent_id = $record['agent_id'];
				$this->addUserId = $record['add_id'];
			}
		}
		if ( ! empty( $agent_id ) ) {
			$_SESSION['agent_id'] = $agent_id;
			$_SESSION['agent_pass'] = base64_encode( $org_password );
			$this->setAgent( $agent_id );
			$query = "UPDATE " . TABLE_AGENTS . " SET
				logons=(logons+1) WHERE agent_id='{$agent_id}'
			";
			$db->update( $query );
			
			/*if (isset($_POST['cookie']) && $_POST['cookie'] == 1) {
				setcookie("auth_user_cookie", $check[0]['agent_id'], (time() + (86400 * 365)));
			}*/
			return true;
		}
		// log failed login attempt
		$log_agent_query = "SELECT *, a.agent_id
			FROM ".TABLE_AGENTS." AS a
				LEFT JOIN ".TABLE_AGENTS_TO_SITES."
					AS s ON a.agent_id = s.agent_id
			WHERE agent_email='{$username}'
		";
		$agent = $db->getRecord( $log_agent_query );
		if ( empty( $agent ) || ! is_array( $agent ) )
			$agent = array( 'agent_pass' => '' );
		$log_query = "INSERT INTO `agents_failed_logins` SET
			`query1` = '{$db->escape($query1)}',
			`query2` = '{$db->escape($query2)}',
			`submitted_username` = '{$db->escape($org_username)}',
			`submitted_password` = '{$db->escape($org_password)}',
			`submitted_password_encrypted` = PASSWORD('{$db->escape($org_password)}'),
			`existing_password_encrypted` = '{$db->escape($agent['agent_pass'])}',
			`_SESSION` = '{$db->escape(json_encode($_SESSION))}',
			`_COOKIES` = '{$db->escape(json_encode(isset($_COOKIES)?$_COOKIES:''))}',
			`_GET` = '{$db->escape(json_encode($_GET))}',
			`_POST` = '{$db->escape(json_encode($_POST))}',
			`_REQUEST` = '{$db->escape(json_encode($_REQUEST))}',
			`_SERVER` = '{$db->escape(json_encode($_SERVER))}',
			`agent` = '{$db->escape(json_encode($agent))}'
		";
		$db->query( $log_query );
		return false;
	}
	
	public function logout()
	{
		//if (session_status() !== PHP_SESSION_ACTIVE) return; // not added until PHP 5.4
		@session_start();
		// Unset all of the session variables.
		$_SESSION = array();
		// If it's desired to kill the session, also delete the session cookie.
		// Note: This will destroy the session, and not just the session data!
		if ( ini_get( "session.use_cookies" ) ) {
			$params = @session_get_cookie_params();
			@setcookie( session_name(), '', time() - 42000,
				$params["path"], $params["domain"],
				$params["secure"], $params["httponly"]
			);
		}
		// Finally, destroy the session.
		@session_destroy();
		@session_start();
		
		$this->logout_related_sites();
	}

	public function logout_related_sites()
	{
		// logout of other domains
		$site_id = SITE_ID;
		$parent = get_parent_site( $site_id );
		if ( $parent ) {
			// if parent exists, show parent first
			$sites[] = $parent;
			// check for siblings
			$children = get_child_sites( $parent['site_id'] );
		} else {
			// if no parent, show current site first
			$sites[] = get_site( $site_id );
			// check for children
			$children = get_child_sites( $site_id );
		}
		
		if ( ! empty( $children ) && is_array( $children ) )
			$sites = array_merge( $sites, $children );
		
		if ( ! empty( $sites ) && is_array( $sites ) ) {
			// surprisingly, this actually works
			// we're basically looping through the list of sites,
			// keeping track of where we are in the loop through
			// redirections by using the i query parameter
			$i = count( $sites );
			if ( isset( $_REQUEST['i'] ) ) {
				$i = (int)$_REQUEST['i'];
			}
			$i--; // convert from count to 0 indexed
			if ( $sites[$i]['site_id'] == $site_id ) $i--;
			if ( $i < 0 ) return;
			if ( empty( $sites[$i] ) ) return;
			$site = $sites[$i];
			
			$domain = ( DEV_ENVIRONMENT ) ? 'http://' : 'https://';
			$domain .= get_site_domain( $site['site_id'] );
			$link = "{$domain}/login.php?action=logout&i={$i}";
			header( "Location: {$link}" );
			exit;
		}
	}

	public function create_new_agent()
	{
		global $db;
		$utc = time();
		
		$query = "INSERT INTO ".TABLE_AGENTS." SET
			active = 0,
			onboard_date = '{$utc}'
		";
		if ( ! $db->query( $query ) ) return false;
		
		$agent_id = $db->insertID();
		
		$query = "INSERT INTO ".TABLE_AGENTS_TO_SITES." SET
			agent_id = '{$agent_id}',
			site_id = '".SITE_ID."'
		";
		$db->query( $query );
		
		$this->setAgent( $agent_id );
		return $agent_id;
	}
	
	public function saveImage( $form )
	{
		global $db;
		
		if ( empty( $form->fields['image_file']->value ) || ! is_array( $form->fields['image_file']->value ) ) return false;
		
		// delete previous image if exists
		$this->deleteImage();
		
		// save image
		$files = $form->fields['image_file']->value;
		$return = $form->saveUploadedFiles( DIR_AGENT_IMAGES, $files );
		if ( ! empty( $return['errors'] ) ) {
			//$_SESSION['action_message'] = implode( '', $return['errors'] );
			return $return;
		}
		
		foreach ( $return['filenames'] as $file ) {
			// create thumbnail
			dh_resize_image( DIR_AGENT_IMAGES . $file, $this->max_width, $this->max_height );
			copy( DIR_AGENT_IMAGES . $file, DIR_AGENT_IMAGES . "th/{$file}" );
			dh_resize_image( DIR_AGENT_IMAGES . "th/{$file}", $this->max_thumb_width, $this->max_thumb_height );
			// save to database
			$file = $db->escape( $file );
			$query = "SELECT * 
				FROM ".TABLE_AGENT_IMAGES."
				WHERE agent_id = '{$this->id}'
			";
			$agent_image = $db->getRecord( $query );
			// presently we're only allowing one agent profile image, so overwrite if updating
			$query = ( empty( $agent_image ) ) ? 'INSERT INTO ' : 'UPDATE ';
			$query .= TABLE_AGENT_IMAGES." SET ";
			$query .= " image_file = '{$file}' ";
			if ( empty( $agent_image ) ) {
				$query .= ", agent_id = '{$this->id}' ";
			} else {
				$image_file = $db->escape( $agent_image['image_file'] );
				$query .= " WHERE agent_id = '{$this->id}' ";
				$query .= " AND image_file = '{$image_file}' ";
			}
			$db->update( $query );
		}
		
		return $return;
	}
	
	public function saveLogo( $form )
	{
		global $db;
		
		if ( empty( $form->fields['logo_file']->value ) || ! is_array( $form->fields['logo_file']->value ) ) return false;
		
		// delete previous image if exists
		$this->deleteLogo();
		
		// save image
		$files = $form->fields['logo_file']->value;
		$return = $form->saveUploadedFiles( DIR_AGENT_IMAGES, $files );
		if ( ! empty( $return['errors'] ) ) {
			//$_SESSION['action_message'] = implode( '', $return['errors'] );
			return $return;
		}
		
		foreach ( $return['filenames'] as $file ) {
			// create thumbnail
			dh_resize_image( DIR_AGENT_IMAGES . $file, $this->max_width, $this->max_height );
			copy( DIR_AGENT_IMAGES . $file, DIR_AGENT_IMAGES . "th/{$file}" );
			dh_resize_image( DIR_AGENT_IMAGES . "th/{$file}", $this->max_thumb_width, $this->max_thumb_height );
			// save to database
			$file = $db->escape( $file );
			$query = "SELECT * 
				FROM ".TABLE_AGENT_LOGOS."
				WHERE agent_id = '{$this->id}'
			";
			$agent_logo = $db->getRecord( $query );
			// presently we're only allowing one agent profile image, so overwrite if updating
			$query = ( empty( $agent_logo ) ) ? 'INSERT INTO ' : 'UPDATE ';
			$query .= TABLE_AGENT_LOGOS." SET ";
			$query .= " logo_file = '{$file}' ";
			if ( empty( $agent_logo ) ) {
				$query .= ", agent_id = '{$this->id}' ";
			} else {
				$logo_file = $db->escape( $agent_logo['logo_file'] );
				$query .= " WHERE agent_id = '{$this->id}' ";
				$query .= " AND logo_file = '{$logo_file}' ";
			}
			$db->update( $query );
		}
		
		return $return;
	}
	
	public function saveAgent( $form )
	{
		global $db, $Notifications;
		
		// save image ---------------------------------------------------------
		$agent_image_sql = '';
		if ( $return = $this->saveImage( $form ) ) {
			if ( ! empty( $return['errors'] ) ) {
				//$_SESSION['action_message'] = implode( '', $return['errors'] );
				$Notifications->add( $return['errors'] );
			} else {
				$agent_image = $db->escape( $return['filenames'][0] );
				$agent_image_sql = " `image_file` = '{$agent_image}'";
			}
		}
		
		// save logo ----------------------------------------------------------
		$agent_logo_sql = '';
		if ( $return = $this->saveLogo( $form ) ) {
			if ( ! empty( $return['errors'] ) ) {
				//$_SESSION['action_message'] = implode( '', $return['errors'] );
				$Notifications->add( $return['errors'] );
			} else {
				$agent_logo = $db->escape( $return['filenames'][0] );
				$agent_logo_sql = " `logo_file` = '{$agent_logo}'";
			}
		}
		
		// save to TABLE_AGENTS -----------------------------------------------
		$includes = array();
		if ( isset( $form->fields['onboard_date'] ) ) {
			$onboard_date = strtotime( $form->fields['onboard_date']->value );
			$includes[] = "`onboard_date` = '{$onboard_date}'";
		}
		// only update agent_disclaimer if included
		if ( isset( $form->fields['agent_disclaimer'] ) ) {
			$agent_disclaimer = $db->escape( $form->fields['agent_disclaimer']->value );
			$includes[] = "`agent_disclaimer` = '{$agent_disclaimer}'";
		}
		// only include password if not blank
		if ( ! empty( $form->fields['agent_pass']->value ) ) {
			$includes[] = "`agent_pass` = password('{$form->fields['agent_pass']->sqlSafeValue}')";
		}
		// attempt to make agent_url valid
		if ( ! empty( $form->fields['agent_url']->value ) ) {
			$form->fields['agent_url']->value = fix_url( $form->fields['agent_url']->value );
			$form->fields['agent_url']->htmlSafeValue = $form->fields['agent_url']->makeHtmlSafe( $form->fields['agent_url']->value );
			$form->fields['agent_url']->sqlSafeValue = $form->fields['agent_url']->makeSqlSafe( $form->fields['agent_url']->value );
		}
		// make sure slug is unique
		$slug = '';
		if ( ! empty( $form->fields['slug']->value ) ) {
			$slug = $form->fields['slug']->value;
		}
		// create slug if it is blank
		else if ( empty( $this->data['slug'] )
			&& empty( $slug )
			&& ! empty( $form->fields['agent_firstname']->value )
			&& ! empty( $form->fields['agent_lastname']->value )
		) {
			$slug = "{$form->fields['agent_firstname']->value}-{$form->fields['agent_lastname']->value}";
		}
		$slug = get_unique_slug( non_alpha_numeric_replace( $slug, '-' ), $this->id, TABLE_AGENTS, 'agent_id' );
		$slug = $db->escape( $slug );
		if ( ! empty( $slug ) )
			$includes[] = "`slug` = '{$slug}'";
		
		$excludes = array(
			'image_file',
			'logo_file',
			'image_caption',
			'logo_caption',
			'agent_pass',
			'agent_pass2',
			'sites',
			'onboard_date',
			'slug',
			'agent_disclaimer',
		);
		
		// exclude FINRA links from main query and run FINRA links query
		$finra_links = array();
		if ( isset( $_REQUEST['finra_url'] ) && is_array( $_REQUEST['finra_url'] ) ) {
			foreach ( $_REQUEST['finra_url'] as $id => $finra_url ) {
				$excludes[] = "finra_url[{$id}]";
				$excludes[] = "finra_title[{$id}]";
				
				$update = ( $id > 0 );
				// if the url is empty, simply delete the record
				if ( $update && empty( $finra_url ) ) {
					$query = "DELETE FROM ".TABLE_AGENT_FINRA_LINKS."
						WHERE link_id = {$id} AND agent_id = '{$this->id}'
					";
					$db->query( $query );
				} elseif ( ! empty( $finra_url ) ) {
					$query = ( $update ) ? "UPDATE " : "INSERT INTO ";
					$query .= TABLE_AGENT_FINRA_LINKS." SET ";
					$finra_title = ( $_REQUEST['finra_title'][$id] )
						? $_REQUEST['finra_title'][$id] : '';
					$finra_url = $db->escape( fix_url( $finra_url ) );
					$finra_title = $db->escape( $finra_title );
					$query .= "
						`agent_id` = '{$this->id}',
						`finra_url` = '{$finra_url}',
						`finra_title` = '{$finra_title}'
					";
					if ( $update )
						$query .= "WHERE link_id = {$id} AND agent_id = '{$this->id}'";
					$db->query( $query );
				}
			}
		}
		
		$query = $form->buildQuery( $excludes, $includes );
		$db->update( $query );
		
		// save to TABLE_AGENTS_TO_SITES --------------------------------------
		if ( defined( 'ADMINISTRATOR' ) && ADMINISTRATOR ) {
			// get list of sites which this agent is currently on
			$query = "SELECT `site_id` FROM ".TABLE_AGENTS_TO_SITES."
				WHERE agent_id = '{$this->id}'
					AND deleted = 0
			";
			$current_sites = $db->getColumn( $query );
			
			// get newly submitted sites for agent
			$submitted_sites = array();
			if ( ! empty( $form->fields['sites']->value ) && is_array( $form->fields['sites']->value ) ) {
				foreach ( $form->fields['sites']->value as $site_id ) {
					$submitted_sites[] = $site_id;
				}
			}
			
			// remove agent from sites if required
			$sites_to_delete = array_diff( $current_sites, $submitted_sites );
			$sites_to_delete = implode( ',', $sites_to_delete );
			if ( ! empty( $sites_to_delete ) ) {
				/*$query = "DELETE FROM ".TABLE_AGENTS_TO_SITES."
					WHERE agent_id = '{$this->id}'
					AND site_id IN ({$sites_to_delete})
				";*/
				$query = "UPDATE ".TABLE_AGENTS_TO_SITES." SET
						deleted = 1
					WHERE agent_id = '{$this->id}'
						AND site_id IN ({$sites_to_delete})
				";
				$db->update( $query );
			}
			
			// add agent to new sites if applicable
			$sites_to_add = array_diff( $submitted_sites, $current_sites );
			if ( ! empty( $sites_to_add ) && is_array( $sites_to_add ) ) {
				$values = array();
				foreach ( $sites_to_add as $site_id ) {
					$values[] = "('{$this->id}','{$site_id}')";
				}
				$values = implode( ', ', $values );
				
				$query = "INSERT IGNORE INTO ".TABLE_AGENTS_TO_SITES." (
						`agent_id`,
						`site_id`
					)
					VALUES
					{$values}
					ON DUPLICATE KEY UPDATE
						`deleted` = 0
				";
				$db->query( $query );
			}
		}
		// save to TABLE_AGENT_IMAGES -----------------------------------------
		$query = "SELECT * 
			FROM ".TABLE_AGENT_IMAGES."
			WHERE agent_id = '{$this->id}'
		";
		$agent_image = $db->getRecord( $query );
		// check if there is something to insert/update
		if ( ! empty( $agent_image_sql ) || isset( $form->fields['image_caption'] ) ) {
			// presently we're only allowing one agent profile image, so overwrite if updating
			$query = ( empty( $agent_image ) ) ? 'INSERT INTO ' : 'UPDATE ';
			$query .= TABLE_AGENT_IMAGES." SET ";
			if ( ! empty( $agent_image_sql ) ) $query .= $agent_image_sql;
			if ( ! empty( $agent_image_sql ) && isset( $form->fields['image_caption'] ) ) 
				$query .= ', ';
			if ( isset( $form->fields['image_caption'] ) )
				$query .= " image_caption = '{$form->fields['image_caption']->sqlSafeValue}' ";
			if ( empty( $agent_image ) ) {
				$query .= ", agent_id = '{$this->id}' ";
			} else {
				$query .= " WHERE agent_id = '{$this->id}' ";
				if ( empty( $agent_image_sql ) ) {
					$image_file = $db->escape( $agent_image['image_file'] );
					$query .= " AND image_file = '{$image_file}' ";
				}
			}
			$db->update( $query );
		}
		
		// save to TABLE_AGENT_LOGOS ------------------------------------------
		$query = "SELECT * 
			FROM ".TABLE_AGENT_LOGOS."
			WHERE agent_id = '{$this->id}'
		";
		$agent_logo = $db->getRecord( $query );
		// check if there is something to insert/update
		if ( ! empty( $agent_logo_sql ) || isset( $form->fields['logo_caption'] ) ) {
			// presently we're only allowing one agent profile logo, so overwrite if updating
			$query = ( empty( $agent_logo ) ) ? 'INSERT INTO ' : 'UPDATE ';
			$query .= TABLE_AGENT_LOGOS." SET ";
			if ( ! empty( $agent_logo_sql ) ) $query .= $agent_logo_sql;
			if ( ! empty( $agent_logo_sql ) && isset( $form->fields['logo_caption'] ) ) 
				$query .= ', ';
			if ( isset( $form->fields['logo_caption'] ) )
				$query .= " logo_caption = '{$form->fields['logo_caption']->sqlSafeValue}' ";
			if ( empty( $agent_logo ) ) {
				$query .= ", agent_id = '{$this->id}' ";
			} else {
				$query .= " WHERE agent_id = '{$this->id}' ";
				if ( empty( $agent_logo_sql ) ) {
					$logo_file = $db->escape( $agent_image['logo_file'] );
					$query .= " AND logo_file = '{$logo_file}' ";
				}
			}
			$db->update( $query );
		}
		
		// update tracking fields since the referrer fields are dependant on the referrer_id set here
		//$this->updateAgentTrackingCalculatedFields();
		
		// update object properties
		$this->setAgent( $this->id );
	}
	
	public function deleteImage()
	{
		global $db;
		if ( empty( $this->id ) ) return false;
		
		$image_file = $this->data['image_file'];
		$image_file_sql = $db->escape( $this->data['image_file'] );
		$query = "UPDATE ".TABLE_AGENT_IMAGES." SET
			image_file = NULL
			WHERE agent_id = '{$this->id}'
			AND image_file = '{$image_file_sql}'
		";
		$db->query( $query );
		$query = "SELECT COUNT(*)
			FROM ".TABLE_AGENT_LOGOS." 
			WHERE agent_id = '{$this->id}'
			AND logo_file = '{$image_file_sql}'
		";
		$check = $db->getValue( $query );
		if ( ! $check ) {
			if ( is_file( DIR_AGENT_IMAGES . $image_file ) ) {
				unlink( DIR_AGENT_IMAGES . $image_file );
			}
			if ( is_file( DIR_AGENT_IMAGES . "th/{$image_file}" ) ) {
				unlink( DIR_AGENT_IMAGES . "th/{$image_file}" );
			}
		}
		
		return true;
	}
	
	public function deleteLogo()
	{
		global $db;
		if ( empty( $this->id ) ) return false;
		
		$logo_file = $this->data['logo_file'];
		$logo_file_sql = $db->escape( $this->data['logo_file'] );
		$query = "UPDATE ".TABLE_AGENT_LOGOS." SET
			logo_file = NULL
			WHERE agent_id = '{$this->id}'
			AND logo_file = '{$logo_file_sql}'
		";
		$db->query( $query );
		$query = "SELECT COUNT(*)
			FROM ".TABLE_AGENT_IMAGES." 
			WHERE agent_id = '{$this->id}'
			AND image_file = '{$logo_file_sql}'
		";
		$check = $db->getValue( $query );
		if ( ! $check ) {
			if ( is_file( DIR_AGENT_IMAGES . $logo_file ) ) {
				unlink( DIR_AGENT_IMAGES . $logo_file );
			}
			if ( is_file( DIR_AGENT_IMAGES . "th/{$logo_file}" ) ) {
				unlink( DIR_AGENT_IMAGES . "th/{$logo_file}" );
			}
		}
		
		return true;
	}
	
	// if $remove == true, the removed flag will be set but record(s) will not be deleted
	public function deleteAgent( $remove = true )
	{
		global $db;
		if ( empty( $this->id ) ) return false;
		
		// check certain tables and force $remove = true if matches found
		if ( ! $remove ) {
			$query = "SELECT COUNT(*)
				FROM ".TABLE_AGENT_TRACKING."
				WHERE agent_id = '{$this->id}'
			";
			$check = $db->getValue( $query );
			if ( $check ) $remove = true;
		}
		if ( ! $remove ) {
			$query = "SELECT COUNT(*)
				FROM ".TABLE_EVENT_OVERVIEWS."
				WHERE agent_id = '{$this->id}'
				OR instructor_id = '{$this->id}'
			";
			$check = $db->getValue( $query );
			if ( $check ) $remove = true;
		}
		/*if ( ! $remove ) {
			$query = "SELECT COUNT(*)
				FROM ".TABLE_PDF_QUEUE_PROCESS."
				WHERE agent_id = '{$this->id}'
			";
			$check = $db->getValue( $query );
			if ( $check ) $remove = true;
		}*/
		if ( ! $remove ) {
			$agent_code = $db->escape( $this->data['agent_code'] );
			$query = "SELECT COUNT(*)
				FROM ".TABLE_WEALTH_INDEX_USERS."
				WHERE agent_id = '{$this->id}'
				OR agent_code = '{$agent_code}'
			";
			$check = $db->getValue( $query );
			if ( $check ) $remove = true;
		}
		if ( ! $remove ) {
			$agent_code = $db->escape( $this->data['agent_code'] );
			$query = "SELECT COUNT(*)
				FROM ".TABLE_SITE_SUBSCRIPTION_PAYMENTS."
				WHERE agent_id = '{$this->id}'
			";
			$check = $db->getValue( $query );
			if ( $check ) $remove = true;
		}
		if ( ! $remove ) {
			$agent_code = $db->escape( $this->data['agent_code'] );
			$query = "SELECT COUNT(*)
				FROM ".TABLE_ORDERS."
				WHERE agent_id = '{$this->id}'
			";
			$check = $db->getValue( $query );
			if ( $check ) $remove = true;
		}
		
		// PDF Creator
		$this->removeAgentFromQueues();
		
		// delete image(s)
		$this->deleteImage();
		$this->deleteLogo();
		
		// delete CIM profiles
		$query = "SELECT site_id
			FROM ".TABLE_AGENTS_TO_SITES."
			WHERE agent_id = '{$this->id}'
				AND customer_profile_id IS NOT NULL
		";
		$site_ids = $db->getColumn( $query );
		if ( ! empty( $site_ids ) && is_array( $site_ids ) ) {
			foreach ( $site_ids as $sid ) {
				$this->deleteCustomerProfile( $sid );
			}
		}
		
		if ( $remove ) {
			// set database record to removed
			$query = "UPDATE " . TABLE_AGENTS . " 
				SET `agent_removed` = 1
				WHERE `agent_id` = '{$this->id}'
			";
		} else {
			// delete from database
			$query = "DELETE a, s, c, al, i, l, f, ci, cic, ciso, pp, fl, qp
				FROM " . TABLE_AGENTS . " AS a
					LEFT JOIN ".TABLE_AGENTS_TO_SITES." AS s
						ON a.agent_id = s.agent_id
					LEFT JOIN ".TABLE_AGENTS_TO_COMPANIES." AS c
						ON a.agent_id = c.agent_id
					LEFT JOIN ".TABLE_AGENT_ADD_LOGINS." AS al
						ON a.agent_id = al.agent_id
					LEFT JOIN ".TABLE_AGENT_ADD_LOGINS_TO_SITES." AS als
						ON al.add_id = als.add_id
					LEFT JOIN ".TABLE_AGENT_IMAGES." AS i
						ON a.agent_id = i.agent_id
					LEFT JOIN ".TABLE_AGENT_LOGOS." AS l
						ON a.agent_id = l.agent_id
					LEFT JOIN ".TABLE_PRODUCT_FAVORITES." AS f
						ON a.agent_id = f.agent_id
					LEFT JOIN ".TABLE_CART_ITEMS." AS ci
						ON a.agent_id = ci.agent_id
					LEFT JOIN ".TABLE_CART_ITEM_CUSTOMIZATION." AS cic
						ON ci.cart_item_id = cic.cart_item_id
					LEFT JOIN ".TABLE_CART_ITEM_SELECTED_OPTIONS." AS ciso
						ON ci.cart_item_id = ciso.cart_item_id
					LEFT JOIN ".TABLE_AGENT_PAYMENT_PROFILES." AS pp
						ON a.agent_id = pp.agent_id
					LEFT JOIN ".TABLE_AGENT_FINRA_LINKS." AS fl
						ON a.agent_id = fl.agent_id
					LEFT JOIN ".TABLE_PDF_QUEUE_PROCESS." AS qp
						ON a.agent_id = qp.agent_id
				WHERE a.`agent_id` = '{$this->id}'
			";
		}
		$db->update( $query );
	}
	
	public function removeAgentFromQueues()
	{
		global $db;
		
		$agent_id = (int)$this->id;
		
		// remove advisor from any queues
		$queue_ids = $db->fetchAll("SELECT DISTINCT `queue_id` FROM `pdf_queue_process` WHERE `agent_id`='{$agent_id}'");
		foreach ($queue_ids as $q) {
			$id = $q['queue_id'];
			$res = $db->getValue("SELECT `agent_ids` FROM `pdf_queue` WHERE `id`='{$id}'");
			$res = json_decode($res,true);
			
			// if this advisor was the only one in the queue, delete the queue
			if (count($res) == 1) {
				deleteQueueZip($id);
				$db->query("DELETE FROM `pdf_queue` WHERE `id`='{$id}'");
				deleteProcessFiles(0, $id, 0);
				$db->query("DELETE FROM `pdf_queue_process` WHERE `queue_id`='{$id}'");
			} else {
				// if this agent was not the only one in the queue, remove their id from list
				$tids = Array();
				foreach ($res as $r) {
					if ($r != $agent_id) {
						$tids[] = $r;
					}
				}
				$db->query("UPDATE `pdf_queue` SET `agent_ids`='".json_encode($tids)."' WHERE `id`='{$id}'");
				deleteProcessFiles(0, $id, $agent_id);
				$db->query("DELETE FROM `pdf_queue_process` WHERE `agent_id`='{$agent_id}' AND `queue_id`='{$id}'");
			}
		}
	}
	
	public function setSites()
	{
		global $db;
		
		$query = "SELECT *
			FROM ".TABLE_AGENTS_TO_SITES." AS a
				LEFT JOIN ".TABLE_SITES." AS s
					ON a.site_id = s.site_id
			WHERE agent_id = '{$this->id}'
				AND a.deleted = 0
			ORDER BY s.parent, s.sort_order
		";
		$advisor_sites = $db->fetchAll( $query, array(), 'site_id' );
		
		if ( ! empty( $advisor_sites ) && is_array( $advisor_sites ) ) {
			$this->sites = $advisor_sites;
		} else {
			$this->sites = array();
		}
	}
	
	public function getSites()
	{
		return $this->sites;
	}
	
	public function getSiteIds()
	{
		$site_ids = array();
		
		if ( ! empty( $this->sites ) && is_array( $this->sites ) ) {
			foreach ( $this->sites as $site_id => $site ) {
				$site_ids[] = $site_id;
			}
		}
		
		return $site_ids;
	}
	
	public function toggleStatus()
	{
		if ( $this->data['active'] ) {
			$this->updateStatus(0);
		} else {
			$this->updateStatus(1);
		}
	}
	
	public function updateStatus( $status )
	{
		global $db;
		$new_status = ( $status ) ? 1 : 0;
		
		$query = "UPDATE ".TABLE_AGENTS." SET
			active = '{$new_status}'
			WHERE agent_id = '{$this->id}'
		";
		if ( $db->update( $query ) ) {
			$this->data['active'] = $new_status;
		}
	}
	
	// ------------------------------------------------------------------------
	// functions for subscriptions and additional logins ----------------------
	
	public function getAdditionalLogins( $site_id = null )
	{
		global $db;
		
		$clause = '';
		if ( ! empty( $site_id ) ) {
			$site_id = intval( $site_id );
			$clause = "AND site_id";
		}
		
		$query = "SELECT * FROM ".TABLE_AGENT_ADD_LOGINS." AS al
			LEFT JOIN ".TABLE_AGENT_ADD_LOGINS_TO_SITES." AS ls
				ON al.add_id = ls.add_id
			WHERE agent_id = '{$this->id}'
			{$clause}
		";
		$additional_logins = $db->fetchAll( $query );
		
		if ( ! empty( $additional_logins ) && is_array( $additional_logins ) ) {
			$additional_logins = array();
		}
		
		return $additional_logins;
	}
	
	// check if agent has subscription and if subscription is valid
	public function isSubscribed( $site_id = SITE_ID )
	{
		if ( $site_id == SITE_ID && defined( 'AGENT_SUBSCRIBED' ) )
			return AGENT_SUBSCRIBED;
		
		global $db;
		$site_id = intval( $site_id );
		$agent_subscribed = false;
		
		$query = "SELECT *
			FROM ".TABLE_AGENTS_TO_SITES." AS a2s
				LEFT JOIN ".TABLE_SITE_SUBSCRIPTIONS." AS sub
					ON a2s.subscription_id = sub.subscription_id
			WHERE a2s.agent_id = '{$this->id}'
				AND a2s.site_id = '{$site_id}'
				AND a2s.deleted = 0
		";
		$record = $db->getRecord( $query );
		$grace_period = ( ! empty( $record['grace_period'] ) ) ? (int)$record['grace_period'] : 0;
		if ( ! empty( $record['expiration_date'] ) 
			&& ( strtotime( $record['expiration_date'] ) > strtotime( "-{$grace_period} days" ) ) )
			$agent_subscribed = true;
		
		if ( $site_id == SITE_ID && ! defined( 'AGENT_SUBSCRIBED' ) )
			define( 'AGENT_SUBSCRIBED', $agent_subscribed );
		return $agent_subscribed;
	}
	
	// this checks to see if the agent has a subscription which allows for additional logins
	// it does not check how many logins are currently in use
	public function countAdditionalLoginsAllowed( $site_id = SITE_ID )
	{
		global $db;
		$site_id = intval( $site_id );
		
		// if subscriptions aren't set up, the default is to not allow extra logins
		if ( ! subscriptions_setup_for_site( $site_id ) ) return false;
		
		// if the agent doesn't have a valid subscription, the default is to not allow extra logins
		if ( ! $this->isSubscribed( $site_id ) ) return false;
		
		// check to see if additional logins are available as part of this subscription
		$query = "SELECT max_add_logins
			FROM ".TABLE_AGENTS_TO_SITES." as s
				LEFT JOIN ".TABLE_SITE_SUBSCRIPTIONS." AS f 
					ON f.subscription_id = s.subscription_id
				LEFT JOIN ".TABLE_SITE_SUBSCRIPTION_TYPES." AS t 
					ON t.type_id = f.subscription_type_id
			WHERE s.agent_id = '{$this->id}'
				AND s.site_id = '{$site_id}'
				AND s.deleted = 0
		";
		$max_add_logins = $db->getValue( $query );
		
		return $max_add_logins;
		//return ( ! empty( $max_add_logins ) && $max_add_logins > 0 );
	}
	
	// check to see if there is a subscription level with more logins available than what the agent currently has
	public function subscriptionWithMoreLoginsAvailable( $site_id = SITE_ID )
	{
		global $db;
		$site_id = intval( $site_id );
		
		if ( ! subscriptions_setup_for_site( $site_id ) ) return false;
		
		$num_logins_allowed = $this->countAdditionalLoginsAllowed();
		if ( ! is_numeric( $num_logins_allowed ) ) $num_logins_allowed = 0;
		
		$subscriptions = get_site_subscription_options( $site_id );
		
		if ( ! empty( $subscriptions ) && is_array( $subscriptions ) ) {
			foreach ( $subscriptions as $subscription ) {
				if ( $subscription['max_add_logins'] > $num_logins_allowed )
					return true;
			}
		}
		
		return false;
	}
	
	public function getSubscriptions( $site_id = null )
	{
		global $db;
		
		$site_clause = '';
		if ( ! empty( $site_id ) ) {
			$site_id = intval( $site_id );
			$site_clause = "AND a2s.site_id = '{$site_id}'";
		}
		
		$query = "SELECT *
			FROM ".TABLE_AGENTS_TO_SITES." AS a2s
				LEFT JOIN ".TABLE_SITE_SUBSCRIPTIONS." AS sub
					ON a2s.subscription_id = sub.subscription_id
				LEFT JOIN ".TABLE_SITE_SUBSCRIPTION_TYPES." AS t
					ON sub.subscription_type_id = t.type_id
				LEFT JOIN ".TABLE_SITES." AS s
					ON sub.site_id = s.site_id
			WHERE agent_id = '{$this->id}'
				AND a2s.subscription_id IS NOT NULL
				AND a2s.deleted = 0
			{$site_clause}
		";
		return $db->fetchAll( $query, array(), 'site_id' );
	}
	
	// ------------------------------------------------------------------------
	// functions for interacting with Authorize.net CIM API -------------------
	
	// gets value from agents table and intializes property
	public function setCustomerProfileId( $site_id = SITE_ID, $reset = false )
	{
		$site_id = (int)$site_id;
		if ( $reset ) $this->setCPIDAttempted[$site_id] = false;
		
		// this prevents many reattempts to set the id if the first attempt fails
		if ( ! empty( $this->setCPIDAttempted[$site_id] ) ) return;
		
		global $db;
		if ( empty( $this->id ) ) {
			$this->setCPIDAttempted[$site_id] = true;
			return false;
		}
		
		$query = "SELECT `customer_profile_id`
			FROM ".TABLE_AGENTS_TO_SITES."
			WHERE agent_id = '{$this->id}'
				AND site_id = '{$site_id}'
		";
		$this->customer_profile_id[$site_id] = $db->getValue( $query );
		
		// if the $customer_profile_id is not set, attempt to create profile
		if ( empty( $this->customer_profile_id[$site_id] ) ) {
			$this->createCustomerProfile( $site_id );
		}
		
		$this->setCPIDAttempted[$site_id] = true;
	}
	
	public function getCustomerProfileId( $site_id = SITE_ID )
	{
		$site_id = (int)$site_id;
		if ( empty( $this->customer_profile_id[$site_id] ) )
			$this->setCustomerProfileId( $site_id );
		return $this->customer_profile_id[$site_id];
	}
	
	public function createCustomerProfile( $site_id = SITE_ID )
	{
		$site_id = (int)$site_id;
		// don't create profile if it already exists
		if ( ! empty( $this->customer_profile_id[$site_id] ) )
			return $this->customer_profile_id[$site_id];
		
		global $db;
		if ( empty( $this->id ) ) return false;
		
		$customer = new AuthorizeNetCustomer;
		$customer->merchantCustomerId = $this->id;
		$customer->email = $this->data['agent_email'];
		$customer->description = "{$this->data['agent_firstname']} {$this->data['agent_lastname']}";
		
		$creds = get_anet_api_credentials( $site_id );
		$request = new AuthorizeNetCIM( $creds['authorizenet_api_login_id'], $creds['authorizenet_transaction_key'] );
		$request->setSandbox( $site_id === 0 );
		$response = $request->createCustomerProfile( $customer );
		if ( anet_cim_error_check( $response, $this->id ) ) {
			// this error code will be returned if the profile has already been created
			// we can pull the profile ID out of the error message
			if ( $response->xml->messages->message->code == 'E00039' ) {
				$err_msg = $response->xml->messages->message->text;
				$regex = '/^A duplicate record with ID ([0-9]*) already exists.$/';
				if ( preg_match( $regex, $err_msg, $matches ) ) {
					$customer_profile_id = ( ! empty( $matches[1] ) ) ? $matches[1] : '';
				}
			} else {
				return false;
			}
		}
		if ( empty( $customer_profile_id ) )
			$customer_profile_id = $response->getCustomerProfileId();
		if ( ! empty( $customer_profile_id ) ) {
			// don't cast to int because value could become too big for PHP int type
			$customer_profile_id = $db->escape( $customer_profile_id );
			$query = "UPDATE ".TABLE_AGENTS_TO_SITES." SET
				`customer_profile_id` = '{$customer_profile_id}'
				WHERE agent_id = '{$this->id}'
					AND site_id = '{$site_id}'
			";
			$result = $db->update( $query );
			$this->customer_profile_id[$site_id] = $customer_profile_id;
			return $result;
		}
		return false;
	}
	
	public function getCustomerProfile( $site_id = SITE_ID )
	{
		$site_id = (int)$site_id;
		$customer_profile_id = $this->getCustomerProfileId( $site_id );
		if ( ! empty( $customer_profile_id ) ) {
			$creds = get_anet_api_credentials( $site_id );
			$request = new AuthorizeNetCIM( $creds['authorizenet_api_login_id'], $creds['authorizenet_transaction_key'] );
			$request->setSandbox( $site_id === 0 );
			$response = $request->getCustomerProfile( $customer_profile_id );
			if ( anet_cim_error_check( $response, $this->id ) ) return false;
			$profile = $response->xml->profile;
			// double check to make sure ids match
			if ( $profile->merchantCustomerId != $this->id ) {
				error_log( 'Error: Agent ID returned from Authorize.net does not match the current Agent object\'s id.' );
				return false;
			}
			
			// update paymentProfiles, if any, with card expiration date
			global $db;
			if ( ! empty( $profile->paymentProfiles ) ) {
				foreach ( $profile->paymentProfiles as $paymentProfile ) {
					$paymentId = $paymentProfile->customerPaymentProfileId;
					$login_id_escaped = $db->escape( $creds['authorizenet_api_login_id'] );
					$query = "SELECT expiration_date
						FROM ".TABLE_AGENT_PAYMENT_PROFILES."
						WHERE payment_profile_id = '{$paymentId}'
						AND authorizenet_api_login_id = '{$login_id_escaped}'
					";
					$exp_date = $db->getValue( $query );
					if ( ! empty( $exp_date ) )
						$paymentProfile->payment->creditCard->expirationDate = $exp_date;
				}
			}
			
			return $profile;
		}
		return false;
	}

	public function deleteCustomerProfile( $site_id = SITE_ID )
	{
		$site_id = (int)$site_id;
		$customer_profile_id = $this->getCustomerProfileId( $site_id );
		if ( ! empty( $customer_profile_id ) ) {
			$creds = get_anet_api_credentials( $site_id );
			$request = new AuthorizeNetCIM( $creds['authorizenet_api_login_id'], $creds['authorizenet_transaction_key'] );
			$request->setSandbox( $site_id === 0 );
			$response = $request->deleteCustomerProfile( $customer_profile_id );
			if ( anet_cim_error_check( $response, $this->id ) ) return false;
			return $response;
		}
		return false;
	}
	
	// $address needs to be a AuthorizeNetAddress object
	public function createCustomerShippingAddress( $address, $site_id = SITE_ID )
	{
		$site_id = (int)$site_id;
		$customer_profile_id = $this->getCustomerProfileId( $site_id );
		if ( ! empty( $customer_profile_id ) ) {
			$creds = get_anet_api_credentials( $site_id );
			$request = new AuthorizeNetCIM( $creds['authorizenet_api_login_id'], $creds['authorizenet_transaction_key'] );
			$request->setSandbox( $site_id === 0 );
			$response = $request->createCustomerShippingAddress( $customer_profile_id, $address );
			if ( anet_cim_error_check( $response, $this->id ) ) return false;
			return $response->getCustomerAddressId();
		}
		return false;
	}
	
	// $address needs to be a AuthorizeNetAddress object
	public function updateCustomerShippingAddress( $addressId, $address, $site_id = SITE_ID )
	{
		$site_id = (int)$site_id;
		$customer_profile_id = $this->getCustomerProfileId( $site_id );
		if ( ! empty( $customer_profile_id ) && ! empty( $addressId ) ) {
			$creds = get_anet_api_credentials( $site_id );
			$request = new AuthorizeNetCIM( $creds['authorizenet_api_login_id'], $creds['authorizenet_transaction_key'] );
			$request->setSandbox( $site_id === 0 );
			$response = $request->updateCustomerShippingAddress( $customer_profile_id, $addressId, $address );
			if ( anet_cim_error_check( $response, $this->id ) ) return false;
			return $response;
		}
		return false;
	}
	
	public function deleteCustomerShippingAddress( $addressId, $site_id = SITE_ID )
	{
		$site_id = (int)$site_id;
		$customer_profile_id = $this->getCustomerProfileId( $site_id );
		if ( ! empty( $customer_profile_id ) && ! empty( $addressId ) ) {
			$creds = get_anet_api_credentials( $site_id );
			$request = new AuthorizeNetCIM( $creds['authorizenet_api_login_id'], $creds['authorizenet_transaction_key'] );
			$request->setSandbox( $site_id === 0 );
			$response = $request->deleteCustomerShippingAddress( $customer_profile_id, $addressId );
			if ( anet_cim_error_check( $response, $this->id ) ) return false;
			return $response;
		}
		return false;
	}
	
	// $payment_method needs to be a AuthorizeNetPaymentProfile object
	public function createCustomerPaymentProfile( $payment_method, $site_id = SITE_ID )
	{
		$site_id = (int)$site_id;
		$customer_profile_id = $this->getCustomerProfileId( $site_id );
		if ( ! empty( $customer_profile_id ) ) {
			$creds = get_anet_api_credentials( $site_id );
			$request = new AuthorizeNetCIM( $creds['authorizenet_api_login_id'], $creds['authorizenet_transaction_key'] );
			$request->setSandbox( $site_id === 0 );
			$response = $request->createCustomerPaymentProfile( $customer_profile_id, $payment_method );
			if ( anet_cim_error_check( $response, $this->id ) ) {
				// this error code will be returned if the payment profile has already been created
				if ( $response->xml->messages->message->code == 'E00039' 
					&& $response->xml->messages->message->text
					== 'A duplicate customer payment profile already exists.' ) {
					$paymentId = $response->getPaymentProfileId();
				} else {
					return false;
				}
			}
			
			// save to database in order to track expiration date
			global $db;
			if ( empty( $paymentId ) )
				$paymentId = $response->getPaymentProfileId();
			if ( ! empty( $paymentId ) ) {
				$exp_date = $payment_method->payment->creditCard->expirationDate;
				//$exp_date = date( 'm/Y', strtotime( $exp_date ) );
				$login_id_escaped = $db->escape( $creds['authorizenet_api_login_id'] );
				$query = "INSERT INTO ".TABLE_AGENT_PAYMENT_PROFILES." SET
					agent_id = '{$this->id}',
					authorizenet_api_login_id = '{$login_id_escaped}',
					payment_profile_id = '{$paymentId}',
					expiration_date = '{$exp_date}'
					ON DUPLICATE KEY UPDATE
					expiration_date = '{$exp_date}'
				";
				error_log( $query );
				$db->query( $query );
			}
			return $paymentId;
		}
		return false;
	}
	
	// $payment_method needs to be a AuthorizeNetPaymentProfile object
	public function updateCustomerPaymentProfile( $paymentId, $payment_method, $site_id = SITE_ID )
	{
		$site_id = (int)$site_id;
		$customer_profile_id = $this->getCustomerProfileId( $site_id );
		if ( ! empty( $customer_profile_id ) && ! empty( $paymentId ) ) {
			$creds = get_anet_api_credentials( $site_id );
			$request = new AuthorizeNetCIM( $creds['authorizenet_api_login_id'], $creds['authorizenet_transaction_key'] );
			$request->setSandbox( $site_id === 0 );
			$response = $request->updateCustomerPaymentProfile( $customer_profile_id, $paymentId, $payment_method );
			if ( anet_cim_error_check( $response, $this->id ) ) return false;
			
			// update database in order to track expiration date
			global $db;
			if ( ! empty( $paymentId ) ) {
				$exp_date = $payment_method->payment->creditCard->expirationDate;
				//$exp_date = date( 'm/Y', strtotime( $exp_date ) );
				$login_id_escaped = $db->escape( $creds['authorizenet_api_login_id'] );
				$query = "UPDATE ".TABLE_AGENT_PAYMENT_PROFILES." SET
					expiration_date = '{$exp_date}'
					WHERE payment_profile_id = '{$paymentId}'
					AND authorizenet_api_login_id = '{$login_id_escaped}'
				";
				$db->update( $query );
			}
			return $paymentId;
		}
		return false;
	}
	
	public function getCustomerPaymentProfile( $paymentId, $site_id = SITE_ID )
	{
		$site_id = (int)$site_id;
		$customer_profile_id = $this->getCustomerProfileId( $site_id );
		if ( ! empty( $customer_profile_id ) && ! empty( $paymentId ) ) {
			$creds = get_anet_api_credentials( $site_id );
			$request = new AuthorizeNetCIM( $creds['authorizenet_api_login_id'], $creds['authorizenet_transaction_key'] );
			$request->setSandbox( $site_id === 0 );
			$response = $request->getCustomerPaymentProfile( $customer_profile_id, $paymentId );
			if ( anet_cim_error_check( $response, $this->id ) ) return false;
			$paymentProfile =  $response->xml->paymentProfile;
			
			// try to determine expiration date
			global $db;
			$login_id_escaped = $db->escape( $creds['authorizenet_api_login_id'] );
			$paymentId = $db->escape( $paymentId );
			$query = "SELECT expiration_date
				FROM ".TABLE_AGENT_PAYMENT_PROFILES."
				WHERE payment_profile_id = '{$paymentId}'
				AND authorizenet_api_login_id = '{$login_id_escaped}'
			";
			$expiration_date = $db->getValue( $query );
			if ( ! empty( $expiration_date ) ) {
				$paymentProfile->payment->creditCard->expirationDate = $expiration_date;
			}
			
			return $paymentProfile;
		}
		return false;
	}
	
	public function deleteCustomerPaymentProfile( $paymentId, $site_id = SITE_ID )
	{
		global $db;
		$site_id = (int)$site_id;
		$customer_profile_id = $this->getCustomerProfileId( $site_id );
		if ( ! empty( $customer_profile_id ) && ! empty( $paymentId ) ) {
			$creds = get_anet_api_credentials( $site_id );
			$request = new AuthorizeNetCIM( $creds['authorizenet_api_login_id'], $creds['authorizenet_transaction_key'] );
			$request->setSandbox( $site_id === 0 );
			$response = $request->deleteCustomerPaymentProfile( $customer_profile_id, $paymentId );
			if ( anet_cim_error_check( $response, $this->id ) ) return false;
			$login_id_escaped = $db->escape( $creds['authorizenet_api_login_id'] );
			// delete expiration from database too
			$query = "DELETE FROM ".TABLE_AGENT_PAYMENT_PROFILES."
				WHERE payment_profile_id = '{$paymentId}'
					AND authorizenet_api_login_id = '{$login_id_escaped}'
			";
			$db->update( $query );
			return $response;
		}
		return false;
	}
	
	public function authorizeNetCharge( $amount, $paymentId, $customerAddressId = null, $site_id = SITE_ID )
	{
		$site_id = (int)$site_id;
		$customer_profile_id = $this->getCustomerProfileId( $site_id );
		if ( ! empty( $customer_profile_id ) && ! empty( $paymentId ) ) {
			// build the transaction
			$transaction = new AuthorizeNetTransaction;
			$transaction->amount = $amount;
			$transaction->customerProfileId = $customer_profile_id;
			$transaction->customerPaymentProfileId = $paymentId;
			if ( ! empty( $customerAddressId ) )
				$transaction->customerShippingAddressId = $customerAddressId;
			
			// submit the transaction request
			$creds = get_anet_api_credentials( $site_id );
			$request = new AuthorizeNetCIM( $creds['authorizenet_api_login_id'], $creds['authorizenet_transaction_key'] );
			$request->setSandbox( $site_id === 0 );
			// it doesn't appear to be possible to make a CustomerProfileTransactionRequest in testmode
			//$extraOptionsString = 'x_test_request=' . TEST_REQUEST;
			$response = $request->createCustomerProfileTransaction( 'AuthCapture', $transaction ); //, $extraOptionsString
			
			// check for errors in the request
			if ( anet_cim_error_check( $response, $this->id ) ) 
				return array( 'successful' => false, 
					'message' => 'There was an error in the Authorize.net CIM transaction.' );
			
			$transactionResponse = $response->getTransactionResponse();
			
			// take action based on the status of the transaction
			if ( $transactionResponse->approved ) {
				return array( 'successful' => true, 
					'response' => $transactionResponse );
			}
			if ( $transactionResponse->error ) {
				log_error( $transactionResponse->error_message );
				return array( 'successful' => false, 
					'message' => 'There was an error processing your request.' );
			}
			if ( $transactionResponse->declined ) {
				return array( 'successful' => false, 
					'declined' => true,
					'message' => 'Your payment was declined.',
					'response' => $transactionResponse );
			}
			if ( $transactionResponse->held ) {
				// what to do if held?
				return array( 'successful' => false, 
					'held' => true,
					'message' => 'Your payment is being held.',
					'response' => $transactionResponse );
			}
			
		}
		return array( 'successful' => false );
	}
	
	// adjusts the amount based on the discount, if any, and returns an array of discount values
	public function getRenewalDiscount( &$amount, $site_id = SITE_ID, $discount_code = '' )
	{
		global $db;
		$site_id = (int)$site_id;
		$discount = 0;
		$discount_percentage = 0;
		
		$query = "SELECT *
			FROM ".TABLE_AGENTS_TO_SITES."
			WHERE agent_id = '{$db->escape($this->agentID())}'
				AND site_id = '{$db->escape($site_id)}'
		";
		$subscription = $db->getRecord( $query );
		
		// calculate discount based on submitted code or,
		// if we are not persisting previous discounts,
		// recheck based on existing code, if any
		if ( empty( $discount_code ) && Agent::DISCOUNT_PERSISTS && Agent::DISCOUNT_PERSIST_TYPE == 'code' ) {
			$discount_code = $subscription['discount_code'];
		}
		if ( ! empty( $discount_code ) ) {
			$discount = get_subscription_discount( $amount, $discount_code, $site_id, true );
			$discount_percentage = ( ! empty( $discount['percentage'] ) ) ? $discount['percentage'] : 0;
			$discount = ( ! empty( $discount['amount'] ) ) ? $discount['amount'] : 0;
		} else if ( Agent::DISCOUNT_PERSISTS ) {
			$discount_code = $subscription['discount_code'];
			if ( Agent::DISCOUNT_PERSIST_TYPE == 'percent' ) {
				$discount_percentage = $subscription['discount_percentage'];
				$discount = $amount * ( $subscription['discount_percentage'] / 100 );
			} else if ( Agent::DISCOUNT_PERSIST_TYPE == 'amount' ) {
				if ( $amount < 0 || $amount > 0 )
					$discount_percentage = ( $subscription['discount_amt'] / $amount ) * 100;
				$discount = $subscription['discount_amt'];
			}
		}
		
		$amount -= $discount;
		return array(
			'discount_code' => $discount_code,
			'discount' => $discount,
			'discount_percentage' => $discount_percentage,
		);
	}
	
	public function renewMembership( $site_id = SITE_ID, $discount_code = null, $subscription_id = null, $paymentId = null )
	{
		global $db;
		$site_id = (int)$site_id;
		
		if ( ! empty( $subscription_id ) ) {
			$subscription_id = intval( $subscription_id );
		} else {
			$query = "SELECT next_subscription_id 
				FROM ".TABLE_AGENTS_TO_SITES."
				WHERE site_id = '{$site_id}'
					AND agent_id = '{$this->id}'
			";
			$subscription_id = $db->getValue( $query );
		}
		if ( empty( $subscription_id ) ) {
			return false;
		}
		
		// figure out what the payment amount and payment method should be
		$query = "SELECT *
			FROM ".TABLE_AGENTS_TO_SITES." AS si
				LEFT JOIN ".TABLE_SITE_SUBSCRIPTIONS." AS ss 
					ON si.next_subscription_id = ss.subscription_id
				LEFT JOIN ".TABLE_SITE_SUBSCRIPTION_TYPES." AS t
					ON ss.subscription_type_id = t.type_id
			WHERE agent_id = '{$this->id}'
				AND si.site_id = '{$site_id}'
				AND ss.subscription_id = '{$subscription_id}'
		";
		$subscription = $db->getRecord( $query );
		
		if ( empty( $subscription ) ) {
			return false;
		}
		
		// calculate price
		$amount = $subscription['amount'];
		
		// should return $discount, $discount_percentage, and $discount_code
		extract( $this->getRenewalDiscount( $amount, $site_id, $discount_code ) );
		
		if ( empty( $paymentId ) ) {
			$paymentId = $subscription['next_payment_profile_id'];
		}
		
		if ( $amount > 0 ) {
			$transaction = $this->authorizeNetCharge( $amount, $paymentId, null, $site_id );
			
			// log all subscriptions
			if ( ! empty( $transaction['response'] ) ) {
				log_authorize_net_transaction( $transaction['response'], array( 'agent_id' => $this->id ), $site_id );
			}
			
			// set up some variables
			$query = "SELECT * FROM ".TABLE_SITES." WHERE site_id = '{$site_id}'";
			$site = $db->getRecord( $query );
			$site_title = ( ! empty( $site['title'] ) ) ? $site['title'] : '';
			$email_from = ( ! empty( $site['email'] ) ) ? $site['email'] : SITE_CONTACT_EMAIL_ADDRESS;
			$billing_company = get_billing_company( $site_id );
			$manage_sub_link = 'https://';
			$manage_sub_link .= get_site_domain( $site_id );
			$manage_sub_link .= '/advisors/manage_subscription';
			$email_to = "{$this->data['agent_firstname']} {$this->data['agent_lastname']} <{$this->data['agent_email']}>";
			$recipients = array(
				$email_to,
				'mike@digitalhill.com',
				'glewit@sglfinancial.com',
				'jeberwein@sglfinancial.com',
			);
			$add_headers = array();
			
			if ( $transaction['successful'] ) {
				$transactionId = $transaction['response']->transaction_id;
				$description = $db->escape( "{$site_title} {$subscription['name']} {$subscription['period']}" );
				$billing_company = $db->escape( json_encode( $billing_company ) );
				// save info to payments table
				$query = "INSERT INTO ".TABLE_SITE_SUBSCRIPTION_PAYMENTS." SET
					transaction_id = '{$transactionId}',
					subscription_description = '{$description}',
					subscription_id = '{$subscription_id}',
					agent_id = '{$this->id}',
					billing_company_json = '{$billing_company}'
				";
				$db->query( $query );
				$payment_id = $db->insertID();
				
				// email receipt
				$subject = "{$site_title} Membership renewal processed.";
				$body = "The membership renewal payment of \${$amount} was successfully processed. See your receipt below. <a href='{$manage_sub_link}'>Click here</a> to login to your account and manage your membership.<br />\n<br />\n";
				$body .= get_subscription_payment_receipt( $payment_id );
				dh_send_mail( $recipients, $subject, $body, $email_from, true, $add_headers );
				
				//return $transactionId;
			}
			else {
				$this->incrementFailedSubscriptionPaymentCount( $site_id );
				if ( ! empty( $transaction['declined'] ) ) {
					// handle payment declined
					$subject = "{$site_title} Membership renewal declined.";
					$body = "The membership renewal payment of \${$amount} was declined. <a href='{$manage_sub_link}'>Click here</a> to login to your account and manage your membership.";
					dh_send_mail( $recipients, $subject, $body, $email_from, true, $add_headers );
					return false;
				} elseif ( ! empty( $transaction['held'] ) ) {
					// we might need to send a special notice in this instance
					$subject = "{$site_title} Membership renewal held.";
					$body = "The membership renewal payment of \${$amount} is being held. Your payment may or may not be processed. <a href='{$manage_sub_link}'>Click here</a> to login to your account and manage your membership.";
					dh_send_mail( $recipients, $subject, $body, $email_from, true, $add_headers );
					return false;
				} else {
					$subject = "{$site_title} Membership renewal problem.";
					$message = ( ! empty( $transaction['message'] ) ) ? $transaction['message'] : '';
					$body = "The membership renewal payment of \${$amount} was unsuccessful. {$message} <a href='{$manage_sub_link}'>Click here</a> to login to your account and manage your membership.";
					dh_send_mail( $recipients, $subject, $body, $email_from, true, $add_headers );
					return false;
				}
			}
		}
		
		// update agents_to_sites
		if ( $amount <= 0 || ! empty( $transaction['successful'] ) ) {
			/*$query = "SELECT expiration_date
				FROM ".TABLE_AGENTS_TO_SITES."
				WHERE agent_id = '{$this->id}'
				AND site_id = '{$site_id}'
			";
			$expiration_date = strtotime( $db->getValue( $query ) );
			switch( $subscription['period'] ) {
				case 'Monthly':
					$expiration_date = date( SQL_DATETIME_FORMAT, strtotime( '+1 month', $expiration_date ) );
					break;
				case 'Annually':
					$expiration_date = date( SQL_DATETIME_FORMAT, strtotime( '+1 year', $expiration_date ) );
					break;
				default:
					$expiration_date = '';
			} // end switch*/
			$expiration_date = '';
			switch( $subscription['period'] ) {
				case 'Monthly':
					$expiration_date = "DATE_ADD(expiration_date, INTERVAL 1 month)";
					break;
				case 'Annually':
					$expiration_date = "DATE_ADD(expiration_date, INTERVAL 1 year)";
					break;
			} // end switch
			
			$sql_discount_code = $db->escape( $discount_code );
			$query = "UPDATE ".TABLE_AGENTS_TO_SITES." SET
				subscription_id = '{$subscription_id}',
				expiration_date = {$expiration_date},
				discount_code = '{$sql_discount_code}',
				discount_percentage = '{$discount_percentage}',
				discount_amt = '{$discount}',
				next_subscription_id = '{$subscription_id}',
				next_payment_profile_id = '{$paymentId}',
				failed_payment_count = 0
				WHERE agent_id = '{$this->id}'
					AND site_id = '{$site_id}'
			";
			$db->update( $query );
			
			// if expiration date is still in the past, update to one subscription period from now
			$query = "SELECT COUNT(*)
				FROM ".TABLE_AGENTS_TO_SITES."
				WHERE agent_id = '{$this->id}'
					AND site_id = '{$site_id}'
					AND expiration_date < NOW()
			";
			if ( $db->getValue( $query ) ) {
				$expiration_date = str_replace( 'expiration_date', 'NOW()', $expiration_date );
				$query = "UPDATE ".TABLE_AGENTS_TO_SITES." SET
					expiration_date = {$expiration_date}
					WHERE agent_id = '{$this->id}'
						AND site_id = '{$site_id}'
				";
				$db->update( $query );
			}
			
			return true;
		}
	}
	
	protected function incrementFailedSubscriptionPaymentCount( $site_id )
	{
		global $db;
		$site_id = intval( $site_id );
		
		$query = "UPDATE ".TABLE_AGENTS_TO_SITES." SET
			failed_payment_count = failed_payment_count + 1
			WHERE site_id = '{$site_id}'
				AND agent_id = '{$this->id}'
		";
		$db->update( $query );
	}
	
	public function getSubscriptionPaymentsQuery( $order = '', $search = '', $site_id = null )
	{
		global $db;
		if ( ! empty( $site_id ) ) {
			$site_id = intval( $site_id );
			$clause = "AND l.site_id = '{$site_id}'";
		}
		if ( ! empty( $order ) ) {
			$order = "ORDER BY {$order}";
		}
		
		$query = "SELECT *
			FROM ".TABLE_SITE_SUBSCRIPTION_PAYMENTS." AS p
				LEFT JOIN ".TABLE_AUTHORIZE_NET_LOG." AS l
					ON p.transaction_id = l.transaction_id
			WHERE p.agent_id = '{$this->id}'
			{$clause}
			{$order}
		";
		//	LEFT JOIN ".TABLE_SITES." AS s
		//		ON l.site_id = s.site_id
		return $query;
	}
} // end class
