<?php
/**
 * Elgg Actions
 *
 * Actions are the primary controllers (The C in MVC) in Elgg. The are
 * registered by {@link register_elgg_action()} and are called either by URL
 * http://elggsite.org/action/action_name or {@link action($action_name}.  For
 * URLs, rewrite a rule in .htaccess passes the action name to
 * engine/handlers/action_handler.php, which dispatches the action.
 *
 * An action name should be registered to exactly one file in the system, usually under
 * the actions/ directory.
 *
 * All actions require security tokens.  Using the {@elgg_view input/form} view
 * will automatically add tokens as hidden inputs.  To manually add hidden inputs,
 * use the {@elgg_view input/securitytoken} view.
 *
 * To include security tokens for actions called via GET, use
 * {@link elgg_add_security_tokens_to_url()}.
 *
 * Action tokens can be manually generated by using {@link generate_action_token()}.
 *
 * @tip When registered, actions can be restricted to logged in or admin users.
 *
 * @tip Action URLs should be called with a trailing / to prevent 301 redirects.
 *
 * @package Elgg.Core
 * @subpackage Actions
 * @link http://docs.elgg.org/Actions
 * @link http://docs.elgg.org/Actions/Tokens
 */

/**
* Perform an action.
*
* This function executes the action with name $action as
* registered by {@link register_action()}.
*
* The plugin hook action, $action_name will be emitted before
* the action is executed.  If a handler returns false, it will
* prevent the action from being called.
*
* @note If an action isn't registered in the system or is registered
* to an unavailable file the user will be forwarded to the site front
* page and an error will be emitted via {@link register_error()}.
*
* @warning All actions require {@link http://docs.elgg.org/Actions/Tokens Action Tokens}.
* @warning Most plugin shouldn't call this manually.
*
* @param string $action    The requested action
* @param string $forwarder Optionally, the location to forward to
*
* @link http://docs.elgg.org/Actions
* @see register_action()
*
* @return void
*/
function action($action, $forwarder = "") {
	global $CONFIG;

	$action = rtrim($action, '/');

	// @todo REMOVE THESE ONCE #1509 IS IN PLACE.
	// Allow users to disable plugins without a token in order to
	// remove plugins that are incompatible.
	// Login and logout are for convenience.
	// file/download (see #2010)
	$exceptions = array(
		'admin/plugins/disable',
		'logout',
		'login',
		'file/download',
	);

	if (!in_array($action, $exceptions)) {
		// All actions require a token.
		action_gatekeeper();
	}

	$forwarder = str_replace(elgg_get_site_url(), "", $forwarder);
	$forwarder = str_replace("http://", "", $forwarder);
	$forwarder = str_replace("@", "", $forwarder);

	if (substr($forwarder, 0, 1) == "/") {
		$forwarder = substr($forwarder, 1);
	}

	if (isset($CONFIG->actions[$action])) {
		if ((isadminloggedin()) || (!$CONFIG->actions[$action]['admin'])) {
			if ($CONFIG->actions[$action]['public'] || get_loggedin_userid()) {

				// Trigger action event
				// @todo This is only called before the primary action is called.
				$event_result = true;
				$event_result = elgg_trigger_plugin_hook('action', $action, null, $event_result);

				// Include action
				// Event_result being false doesn't produce an error
				// since i assume this will be handled in the hook itself.
				// @todo make this better!
				if ($event_result) {
					if (!include($CONFIG->actions[$action]['file'])) {
						register_error(elgg_echo('actionnotfound', array($action)));
					}
				}
			} else {
				register_error(elgg_echo('actionloggedout'));
			}
		} else {
			register_error(elgg_echo('actionunauthorized'));
		}
	} else {
		register_error(elgg_echo('actionundefined', array($action)));
	}

	forward($forwarder);
}

/**
 * Registers an action.
 *
 * Actions are registered to a single file in the system and are executed
 * either by the URL http://elggsite.org/action/action_name or by calling
 * {@link action()}.
 *
 * $file must be the full path of the file to register, or a path relative
 * to the core actions/ dir.
 *
 * Actions should be namedspaced for your plugin.  Example:
 * <code>
 * register_action('myplugin/save_settings', ...);
 * </code>
 *
 * @tip Put action files under the actions/ directory of your plugin.
 *
 * @tip You don't need to include engine/start.php, call {@link gatekeeper()},
 * or call {@link admin_gatekeeper()}.
 *
 * @internal Actions are saved in $CONFIG->actions as an array in the form:
 * <code>
 * array(
 * 	'file' => '/location/to/file.php',
 * 	'public' => BOOL If false, user must be logged in.
 * 	'admin' => BOOL If true, user must be admin (implies plugin = false)
 * )
 * </code>
 *
 * @param string  $action     The name of the action (eg "register", "account/settings/save")
 * @param boolean $public     Can this action be accessed by people not logged into the system?
 * @param string  $filename   Optionally, the filename where this action is located
 * @param boolean $admin_only Whether this action is only available to admin users.
 *
 * @see action()
 * @see http://docs.elgg.org/Actions
 *
 * @return true
 */
function register_action($action, $public = false, $filename = "", $admin_only = false) {
	global $CONFIG;

	// plugins are encouraged to call actions with a trailing / to prevent 301
	// redirects but we store the actions without it
	$action = rtrim($action, '/');

	if (!isset($CONFIG->actions)) {
		$CONFIG->actions = array();
	}

	if (empty($filename)) {
		$path = "";
		if (isset($CONFIG->path)) {
			$path = $CONFIG->path;
		}

		$filename = $path . "actions/" . $action . ".php";
	}

	$CONFIG->actions[$action] = array(
		'file' => $filename,
		'public' => $public,
		'admin' => $admin_only
	);
	return true;
}

/**
 * Validate an action token.
 *
 * Calls to actions will automatically validate tokens.
 * If tokens are not present or invalid, the action will be
 * denied and the user will be redirected to the front page.
 *
 * Plugin authors should never have to manually validate action tokens.
 *
 * @access private
 *
 * @param bool  $visibleerrors Emit {@link register_error()} errors on failure?
 * @param mixed $token         The token to test against. Default: $_REQUEST['__elgg_token']
 * @param mixed $ts            The time stamp to test against. Default: $_REQUEST['__elgg_ts']
 *
 * @return bool
 * @see generate_action_token()
 * @link http://docs.elgg.org/Actions/Tokens
 */
function validate_action_token($visibleerrors = TRUE, $token = NULL, $ts = NULL) {
	if (!$token) {
		$token = get_input('__elgg_token');
	}

	if (!$ts) {
		$ts = get_input('__elgg_ts');
	}

	$session_id = session_id();

	if (($token) && ($ts) && ($session_id)) {
		// generate token, check with input and forward if invalid
		$generated_token = generate_action_token($ts);

		// Validate token
		if ($token == $generated_token) {
			$hour = 60 * 60;
			$now = time();

			// Validate time to ensure its not crazy
			if (($ts > $now - $hour) && ($ts < $now + $hour)) {
				// We have already got this far, so unless anything
				// else says something to the contry we assume we're ok
				$returnval = true;

				$returnval = elgg_trigger_plugin_hook('action_gatekeeper:permissions:check', 'all', array(
					'token' => $token,
					'time' => $ts
				), $returnval);

				if ($returnval) {
					return true;
				} else if ($visibleerrors) {
					register_error(elgg_echo('actiongatekeeper:pluginprevents'));
				}
			} else if ($visibleerrors) {
				register_error(elgg_echo('actiongatekeeper:timeerror'));
			}
		} else if ($visibleerrors) {
			register_error(elgg_echo('actiongatekeeper:tokeninvalid'));
		}
	} else if ($visibleerrors) {
		register_error(elgg_echo('actiongatekeeper:missingfields'));
	}

	return FALSE;
}

/**
* Validates the presence of action tokens.
*
* This function is called for all actions.  If action tokens are missing,
* the user will be forwarded to the site front page and an error emitted.
*
* This function verifies form input for security features (like a generated token), and forwards
* the page if they are invalid.
*
* @access private
* @return mixed True if valid, or redirects to front page and exists.
*/
function action_gatekeeper() {
	if (validate_action_token()) {
		return TRUE;
	}

	forward();
	exit;
}

/**
 * Generate an action token.
 *
 * Action tokens are based on timestamps as returned by {@link time()}.
 * They are valid for one hour.
 *
 * Action tokens should be passed to all actions name __elgg_ts and __elgg_token.
 *
 * @warning Action tokens are required for all actions.
 *
 * @param int $timestamp Unix timestamp
 *
 * @see @elgg_view input/securitytoken
 * @see @elgg_view input/form
 * @example actions/manual_tokens.php
 *
 * @return string|false
 */
function generate_action_token($timestamp) {
	$site_secret = get_site_secret();
	$session_id = session_id();
	// Session token
	$st = $_SESSION['__elgg_session'];

	if (($site_secret) && ($session_id)) {
		return md5($site_secret . $timestamp . $session_id . $st);
	}

	return FALSE;
}

/**
 * Initialise the site secret hash.
 *
 * Used during installation and saves as a datalist.
 *
 * @return mixed The site secret hash or false
 * @access private
 * @todo Move to better file.
 */
function init_site_secret() {
	$secret = md5(rand() . microtime());
	if (datalist_set('__site_secret__', $secret)) {
		return $secret;
	}

	return FALSE;
}

/**
 * Returns the site secret.
 *
 * Used to generate difficult to guess hashes for sessions and action tokens.
 *
 * @return string Site secret.
 * @access private
 * @todo Move to better file.
 */
function get_site_secret() {
	$secret = datalist_get('__site_secret__');
	if (!$secret) {
		$secret = init_site_secret();
	}

	return $secret;
}

/**
 * Check if an action is registered and its file exists.
 *
 * @param string $action Action name
 *
 * @return BOOL
 * @since 1.8
 */
function elgg_action_exist($action) {
	global $CONFIG;

	return (isset($CONFIG->actions[$action]) && file_exists($CONFIG->actions[$action]['file']));
}

/**
 * Initialize some ajaxy actions features
 */
function actions_init()
{
	register_action('security/refreshtoken', TRUE);

	elgg_view_register_simplecache('js/languages/en');

	elgg_register_plugin_hook_handler('action', 'all', 'ajax_action_hook');
	elgg_register_plugin_hook_handler('forward', 'all', 'ajax_forward_hook');
}

/**
 * Checks whether the request was requested via ajax
 *
 * @return bool whether page was requested via ajax
 */
function elgg_is_xhr() {
	return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
		&& strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

/**
 * Catch calls to forward() in ajax request and force an exit.
 *
 * Forces response is json of the following form:
 * <pre>
 * {
 *     "current_url": "the.url.we/were/coming/from",
 *     "forward_url": "the.url.we/were/going/to",
 *     "system_messages": {
 *         "messages": ["msg1", "msg2", ...],
 *         "errors": ["err1", "err2", ...]
 *     },
 *     "status": -1 //or 0 for success if there are no error messages present
 * }
 * </pre>
 * where "system_messages" is all message registers at the point of forwarding
 *
 * @param string $hook
 * @param string $type
 * @param string $reason
 * @param array $params
 *
 */
function ajax_forward_hook($hook, $type, $reason, $params) {
	if (elgg_is_xhr()) {
		//grab any data echo'd in the action
		$output = ob_get_clean();

		//Avoid double-encoding in case data is json
		$json = json_decode($output);
		if (isset($json)) {
			$params['output'] = $json;
		} else {
			$params['output'] = $output;
		}

		//Grab any system messages so we can inject them via ajax too
		$params['system_messages'] = system_messages(NULL, "");

		if (isset($params['system_messages']['errors'])) {
			$params['status'] = -1;
		} else {
			$params['status'] = 0;
		}

		header("Content-type: application/json");
		echo json_encode($params);
		exit;
	}
}

/**
 * Buffer all output echo'd directly in the action for inclusion in the returned JSON.
 */
function ajax_action_hook() {
	if (elgg_is_xhr()) {
		ob_start();
	}
}

elgg_register_event_handler('init', 'system', 'actions_init');