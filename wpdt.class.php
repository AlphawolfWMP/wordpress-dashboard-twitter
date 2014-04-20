<?php
/**
 * The WPDashboardTwitter class plugin file
 *
 * @package 	WordPress_Plugins
 * @subpackage 	WPDashboardTwitter
 */

/**
 * Define the plugin version
 */
define("WPDT_VERSION", "1.1.11");

/**
 * Define the global var WPDTISWP27, returning bool if at least WP 2.7 is running
 */
define('WPDTISWP27', version_compare($GLOBALS['wp_version'], '2.6.999', '>'));

/**
 * Define the global var WPDTHASPHP5, returning bool if PHP 5 is running
 */
define('WPDTHASPHP5', version_compare(phpversion(), '5.0.0', '>='));

/**
 * Define the plugin path slug
 */
define("WPDT_PLUGINPATH", "/" . plugin_basename( dirname(__FILE__) ) . "/");

/**
 * Define the plugin full directory
 */
define("WPDT_PLUGINFULLDIR", WP_PLUGIN_DIR . WPDT_PLUGINPATH );

/**
 * Define the spinning loading image
 */
define("WPDT_LOADINGIMG", admin_url('images/loading.gif') );

/** 
* The WPDashboardTwitter class
*
* @package 		WordPress_Plugins
* @subpackage 	WPDashboardTwitter
* @since 		0.8
* @author 		scripts@schloebe.de
*/
class WPDashboardTwitter {

	/**
	 * Our unique nonce key
	 * Beware: It will contain the evil w-word. :-(
	 * @access private
	 */
	private $nonce;
	
	
	/**
 	* The WPDashboardTwitter class constructor
 	* initializing required stuff for the plugin
 	* 
	* PHP 5 Constructor
 	*
 	* @since 		0.8
 	* @author 		scripts@schloebe.de
 	*/
	function __construct() {
			
		if ( !function_exists("add_action") ) return;
		
		if ( !WPDTISWP27 ) {
			add_action('admin_notices', array(&$this, 'wp_notice'));
			return;
		}
		if ( !WPDTHASPHP5 ) {
			add_action('admin_notices', array(&$this, 'php5_notice'));
			return;
		}
		
		/** 
 		* This file holds all of the general information and functions
 		*/
		require_once(WPDT_PLUGINFULLDIR . 'inc/wpdt.func.php');
		
		/** 
 		* This file holds all of the compatibility and helper methods
 		*/
		require_once(WPDT_PLUGINFULLDIR . 'inc/wpdt-helper.class.php');
			
		add_action('plugins_loaded', array(&$this, 'load_textdomain'));
		add_action('admin_init', array(&$this, 'admin_init'));
		add_action('wp_ajax_wpdt_load_replies', 'wpdt_load_replies' );
		add_action('wp_ajax_wpdt_load_direct_messages', 'wpdt_load_direct_messages' );
		add_action('wp_ajax_wpdt_load_sent_messages', 'wpdt_load_sent_messages' );
		add_action('wp_ajax_wpdt_load_favorites', 'wpdt_load_favorites' );
		add_action('wp_ajax_wpdt_load_retweets', 'wpdt_load_retweets' );
		add_action('wp_ajax_wpdt_load_timeline', 'wpdt_load_timeline' );
		add_action('wp_ajax_wpdt_send_update', 'wpdt_send_update' );
		add_action('wp_ajax_wpdt_shorten_url', 'wpdt_shorten_url' );
		add_action('wp_ajax_wpdt_shorten_imgurl', 'wpdt_shorten_imgurl' );
		add_action('wp_ajax_wpdt_verify_credentials', 'wpdt_verify_credentials' );
	}
	

	/**
 	* The WPDashboardTwitter class constructor
 	* initializing required stuff for the plugin
 	* 
 	* We don't really need this since the plugin requires
 	* PHP5 to run, but well... ;-)
 	* 
	* PHP 4 Compatible Constructor
 	*
 	* @since 		0.8
 	* @author 		scripts@schloebe.de
 	*/
	function wpdashboardtwitter() {
		$this->__construct();
	}
	
	
	
	/**
 	* Initialize and load the plugin stuff for administration panel only
 	*
 	* @since 		0.8
 	* @uses 		$pagenow
 	* @author 		scripts@schloebe.de
 	*/
	function admin_init() {
		global $pagenow;
		$this->nonce = wp_create_nonce('wpdt_woelfi_nonce'); // Includes the evil w-word, errr :-(
		
		if( !class_exists('TwitterOAuth') ) {
			if( empty( $options['compat_mode'] ) || $options['compat_mode'] == 0 )
				require_once( dirname(__FILE__) . '/inc/twitteroauth.php');
			else
				require_once( dirname(__FILE__) . '/inc/twitteroauth_compat.php');
		}
		require_once( dirname(__FILE__) . '/inc/config.php');
		$options = $this->dashboard_widget_options();
		
		if( isset( $_GET['do'] ) && $_GET['do'] == 'wpdt_clearoauth' ) {
			$options['wpdt_oauth_token']  = '';
			$options['wpdt_oauth_secret'] = '';
			$options['wpdt_oauth_verified'] = 0;
			$options['wpdt_oauth_completed'] = 0;
			update_option( 'dashboard_twitter_widget_options', $options );
			wp_redirect( trailingslashit( get_bloginfo('url') ) . 'wp-admin/index.php' );
		}
		
		if( !empty( $_GET['oauth_token'] ) ) {
			session_start();
			
			$connection = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, $_SESSION['oauth_token'], $_SESSION['oauth_token_secret']);
			$access_token = $connection->getAccessToken($_REQUEST['oauth_verifier']);
			$_SESSION['access_token'] = $access_token;
			
			if (200 == $connection->http_code) {
				$_SESSION['status'] = 'verified';
				
			} else {
				session_start();
				session_destroy();
			}
		}
		
		if( !empty( $_GET['oauth_verifier'] ) && $_SESSION['status'] == 'verified' ) {
			$options['wpdt_oauth_token']  = $_SESSION['access_token']['oauth_token'];
			$options['wpdt_oauth_secret'] = $_SESSION['access_token']['oauth_token_secret'];
			$options['wpdt_oauth_verified'] = 1;
			$options['wpdt_oauth_completed'] = 1;
			update_option( 'dashboard_twitter_widget_options', $options );
			wp_redirect( trailingslashit( get_bloginfo('url') ) . 'wp-admin/index.php' );
		}
		
		if ( !function_exists("add_action") ) return;
		$options = $this->dashboard_widget_options();
		
		if( empty( $options['access_everyone'] ) || $options['access_everyone'] == 0 )
			$accesslevel = 'level_10';
		else
			$accesslevel = 'level_1';
		
		if( current_user_can( $accesslevel ) ) {
			
			add_action('wp_dashboard_setup', array (&$this, 'init_dashboard_setup'));
			if( $pagenow == 'index.php' && !isset($_GET['page']) ) {
				add_action('admin_print_scripts', array(&$this, 'js_admin_header') );
				// will be loaded at runtime
				//wp_enqueue_script('wpdt-charcounter-js', WPDashboardTwitter_Helper::plugins_url('inc/js/charcounter.js', __FILE__), array(), WPDT_VERSION);
				if( isset( $_GET['edit'] ) ) {
					wp_enqueue_script('wpdt-js-helper', WPDashboardTwitter_Helper::plugins_url('inc/js/scripts_helper.js', __FILE__), array(), WPDT_VERSION);
				}
				wp_enqueue_script('wpdt-js', WPDashboardTwitter_Helper::plugins_url('inc/js/scripts_general.js', __FILE__), array( 'jquery', 'jquery-ui-tabs' ), WPDT_VERSION);
				wp_enqueue_style('jquery-ui-tabs-wpdt', WPDashboardTwitter_Helper::plugins_url('inc/css/tabs.style.css', __FILE__));
				wp_enqueue_style('misc-css-wpdt', WPDashboardTwitter_Helper::plugins_url('inc/css/misc.style.css', __FILE__));
			}
		}
	}
	
	
	/**
 	* Initialize and load the dashboard widget setup stuff
 	*
 	* @since 		0.8
 	* @author 		scripts@schloebe.de
 	*/
	function init_dashboard_setup() {
		wp_add_dashboard_widget( 'wp_dashboard_twitter', __('WordPress Dashboard Twitter', 'wp-dashboard-twitter'), array(&$this, 'init_dashboard_widget'), array(&$this, 'init_dashboard_widget_setup') );
	}
	
	
	
	/**
 	* Initialize and load the dashboard widget stuff
 	*
 	* @since 		0.8
 	* @author 		scripts@schloebe.de
 	*/
	function init_dashboard_widget() {
		if( !class_exists('TwitterOAuth') )
			require_once( dirname(__FILE__) . '/inc/twitteroauth.php');
		require_once( dirname(__FILE__) . '/inc/config.php');
			
		$errors = array();
		$twterror = false;
		$options = $this->dashboard_widget_options();
		
		if( $twterror != false ) :
			echo '<p class="account-info">' . __("Twitter is unavailable at the moment. Please try again later!", 'wp-dashboard-twitter') . '</p>';
		else:
			if( empty( $options['wpdt_oauth_verified'] ) || $options['wpdt_oauth_verified'] != 1 ) {
				if( empty( $options['compat_mode'] ) || $options['compat_mode'] == 0 )
					$connect_script = 'connect.php';
				else
					$connect_script = 'connect_compat.php';
				
				echo '<a href="' . WPDashboardTwitter_Helper::plugins_url('inc/' . $connect_script . '?_callback=' . urlencode( get_bloginfo('wpurl') . '/wp-admin/index.php'), __FILE__) . '"><img src="' . WPDashboardTwitter_Helper::plugins_url('inc/img/twitter_signin_badge.png', __FILE__) . '" border="0" alt="Sign in with Twitter" title="Sign in with Twitter" /></a><br /><br />';
				echo '<p class="account-info">' . __("This plugin version introduces OAuth Support for Twitter. Twitter announced in December of 2009 the deprecation of Basic Auth on August 16th 2010. Please login with Twitter by clicking the image above, and follow the on-screen instructions.", 'wp-dashboard-twitter') . '</p>';
			} else {
				$twitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, $options['wpdt_oauth_token'], $options['wpdt_oauth_secret']);
				$twitter->format = 'json';
				$usr = $twitter->get('account/verify_credentials');
				$ratelimit = $twitter->get('account/rate_limit_status');
				$shorteners = WPDashboardTwitter_Helper::get_url_shorteners();
				
				if( $twitter->http_info == '400' )
					$errors[] = __( '<strong>NOTE:</strong> The Twitter API only allows clients to make a limited number of calls in a given period. You just exceeded the rate limit.', 'wp-dashboard-twitter' );
				if( $options['wpdt_oauth_completed'] != 2 )
					$errors[] = sprintf(__( '<strong>NOTE:</strong> Please <a href="%s">save</a> the plugin options for the new options to take effect!', 'wp-dashboard-twitter' ), './index.php?edit=wp_dashboard_twitter#wp_dashboard_twitter');
				
				if( count($errors) == 0 )
					echo '<p class="account-info">' . $this->get_account_info( $usr ) . '</p>';
					
				foreach( $errors as $error ) {
					echo '<span class="error fade">' . $error . '</span>';
				}

				<span id="wpdt-charcount">140</span>
	}
	endif;
	}
	/**
	* Initialize and load the dashboard widget options stuff
	*
	* @since 		0.8
	* @return 		array
	* @author 		scripts@schloebe.de
	*/
	function dashboard_widget_options() {
		$defaults = array( 'items' => 5, 'wpdt_oauth_token' => '', 'wpdt_oauth_secret' => '', 'show_avatars' => 0, 'startup_tab' => 0, 'url_service' => 'bitly', 'wpdt_oauth_verified' => 0, 'wpdt_oauth_completed' => 0, 'access_everyone' => 0, 'compat_mode' => 0 );
		if( ( !$options = get_option( 'dashboard_twitter_widget_options' ) ) || !is_array($options) )
		$options = array();
		return array_merge( $defaults, $options );
	}
	/**
	* Initialize and load the dashboard widget options output
	*
	* @since 		0.8
	* @author 		scripts@schloebe.de
	*/
	function init_dashboard_widget_setup() {
		$options = $this->dashboard_widget_options();
		if ( 'post' == strtolower($_SERVER['REQUEST_METHOD']) && isset( $_POST['widget_id'] ) && 'wp_dashboard_twitter' == $_POST['widget_id'] ) {
			foreach ( array( 'items', 'wpdt_oauth_token', 'wpdt_oauth_secret', 'show_avatars', 'startup_tab', 'url_service', 'is_pwd_encrypted', 'wpdt_oauth_completed', 'access_everyone', 'compat_mode' ) as $key ) {
				$options[$key] = $_POST[$key];
				update_option( 'dashboard_twitter_widget_options', $options );
			}
			unset($_SESSION['oauth_token']);
			unset($_SESSION['oauth_token_secret']);
		}
	<p>
	<p>
	<p>
		<input id="compat_mode" name="compat_mode" type="checkbox" value="1"<?php
		if (1 == $options['compat_mode'])
			echo ' checked="checked"';
		?> />
		<label for="compat_mode"><?php _e('Compatibility Mode?', 'wp-dashboard-twitter'); ?> <em>(<?php _e('Enable this option if you cannot sign in with Twitter', 'wp-dashboard-twitter'); ?>)</em></label>
	</p>
	<p>
	}
	/**
	* Turns plain text links into hyperlinks
	*
	* @since 		0.8
	* @param 		string $text
	* @return 		string
	* @author 		scripts@schloebe.de
	*/
	function hyperlinkit( $text ) {
		// make URLs clickable
		$text = make_clickable($text);
		// #hashtags
		#$hashtag_expr = "/(^|\s)#(\w*)/i";
		$hashtag_expr = "/(^|\s)#([a-zA-ZÃ¶Ã¤Ã¼Ã–Ã„ÃœÃŸ_0-9]*)/i";
		$hashtag_replace = "$1<a href=\"http://twitter.com/search?q=%23$2\" target=\"_blank\">#$2</a>";
		$text = preg_replace($hashtag_expr, $hashtag_replace, $text);
		// @mentions
		$text = preg_replace('/([\.|\,|\:|\Â¡|\Â¿|\>|\{|\(]?)@{1}(\w*)([\.|\,|\:|\!|\?|\>|\}|\)]?)\s/i', "$1<a href=\"http://twitter.com/$2\" target=\"_blank\">@$2</a>$3 ", $text);
		return $text;
	}
	/**
	* Returns twitter account info
	* of the authenticated user
	*
	* @since 		0.8
	* @param 		array $usr
	* @return 		string
	* @author 		scripts@schloebe.de
	*/
	function get_account_info( $usr ) {
		return sprintf(__('Hello %s', 'wp-dashboard-twitter') . '! ' . __('You have %d followers', 'wp-dashboard-twitter') . '. ' . __('You wrote %d statuses so far and are listed on %d Twitter lists', 'wp-dashboard-twitter') . '. [<a href="./?do=wpdt_clearoauth">' . __('Log out') . '</a>]', $usr->screen_name, $usr->followers_count, $usr->statuses_count, $usr->listed_count);
	}
	/**
	* Determines the difference between two timestamps, output localized
	*
	* @since 		0.8
	* @param 		string $time
	* @return 		string
	* @author 		scripts@schloebe.de
	*/
	function human_diff_time_l10n( $time ) {
		if ( ( abs( time() - strtotime($time)) ) < 86400 )
		return sprintf( __('%s ago', 'wp-dashboard-twitter'), human_time_diff( strtotime($time) ) );
		else
		return date_i18n( sprintf('%s %s', get_option( 'date_format' ), get_option( 'time_format' )), strtotime($time));
	}
	/**
	* Changes url scheme from http to https
	* if constant FORCE_SSL_ADMIN is set to true
	* in wp-config.php
	*
	* @since 		0.8
	* @deprecated	Used for testing purposes only
	* @param 		string 	$url
	* @return 		string 	$url
	* @author 		scripts@schloebe.de
	*/
	function url_scheme( $url ) {
		if( force_ssl_admin() ) {
			$url = preg_replace('|^http://|', 'https://', $url);
		}
		return $url;
	}
	/**
	* Writes javascript stuff into page header needed for the plugin and prints the SACK library
	*
	* @since 		0.8
	* @author 		scripts@schloebe.de
	*/
	function js_admin_header() {
		wp_print_scripts( array( 'sack' ));
		$options = $this->dashboard_widget_options();
	<script type="text/javascript">
		wpdtAjaxL10n = {
			requestUrl: "<?php echo admin_url('admin-ajax.php'); ?>",
			uploadFileURI: "<?php echo WPDashboardTwitter_Helper::plugins_url('inc/', __FILE__); ?>",
			startupTab: <?php echo $options['startup_tab']; ?>,
			emptyTweetMsg: "<?php _e('An empty tweet would not make sense, eh?', 'wp-dashboard-twitter'); ?>",
			updateStatusMsg: "<?php _e('Send Tweet', 'wp-dashboard-twitter'); ?>",
			sendDMMsg: "<?php _e('Send Direct Message', 'wp-dashboard-twitter'); ?>",
			verifyCredentialsMsg: "<?php _e('Verify Credentials', 'wp-dashboard-twitter'); ?>",
			sendingTweetMsg: "<?php _e('Sending...', 'wp-dashboard-twitter'); ?>",
			emptyLongUrlMsg: "<?php _e('Please enter a long URL!', 'wp-dashboard-twitter'); ?>",
			_ajax_nonce: "<?php echo $this->nonce; ?>"
		}
	//]]>
	}
	/**
	* Initialize and load the plugin textdomain
	*
	* @since 		0.8
	* @author 		scripts@schloebe.de
	*/
	function load_textdomain() {
		load_plugin_textdomain('wp-dashboard-twitter', false, dirname(plugin_basename(__FILE__)) . '/languages');
	}
	/**
	* Checks for the version of WordPress,
	* and adds a message to inform the user
	* if required WP version is less than 2.7
	*
	* @since 		0.8
	* @author 		scripts@schloebe.de
	*/
	function wp_notice() {
		echo "<div id='wpversionfailedmessage' class='error fade'><p>" . __('WordPress Dashboard Twitter requires at least WordPress 2.7!', 'wp-dashboard-twitter') . "</p></div>";
	}
	/**
	* Checks for the version of PHP interpreter,
	* and adds a message to inform the user
	* if required PHP version is less than 5.0.0
	*
	* @since 		0.8
	* @author 		scripts@schloebe.de
	*/
	function php5_notice() {
		echo "<div id='phpversionfailedmessage' class='error fade'><p>" . __('WordPress Dashboard Twitter requires at least PHP5!', 'wp-dashboard-twitter') . "</p></div>";
	}
}
if ( class_exists('WPDashboardTwitter') ) {
	$WPDashboardTwitter = new WPDashboardTwitter();
}