<?php
/*
Plugin Name: Stop Logging Me Out
Description: Stop a previously expired session's login page from forcibly logging you out of your current session.
Version: 1.0.0
Requires at least: 4.0.0
Requires PHP: 7.0.0
Author: Roy Orbitson
Author URI: https://profiles.wordpress.org/lev0/
License: GPLv2 or later
*/

defined('ABSPATH') or die;

(function() {
	$slmo_cookie_name = 'slmo_last_login_' . COOKIEHASH;
	$slmo_cookie_path = function() {
		static $path;
		if ($path === null) {
			list($path) = explode('?', preg_replace(
				'|https?://[^/]+|i' # same as used in COOKIEPATH definition, for performance
				, ''
				, wp_login_url()
			));
		}
		return $path;
	};
	# accumulate values from filters because not available at time we need to set cookie
	$slmo_secure_logged_in_cookie = null;
	$slmo_send_auth_cookies = null;
	$slmo_expire = null;
	$slmo_user_id = 0;
	$slmo_retain_cookies = false;
	$slmo_modify_login = 0;

	# change boolean reauth value to a timestamp
	add_filter(
		'login_url'
		, function($login_url, $redirect, $force_reauth) {
			if (
				$force_reauth
				&& ($query = wp_parse_url($login_url, PHP_URL_QUERY))
			) {
				parse_str($query, $params);
				# only modify if nothing else has
				if (
					$params
					&& array_key_exists('reauth', $params)
					&& $params['reauth'] === '1'
				) {
					$reauth = (string) time();
					$login_url = add_query_arg(compact('reauth'), $login_url);
				}
			}
			return $login_url;
		}
		, PHP_INT_MAX
		, 3
	);

	add_filter(
		'secure_logged_in_cookie'
		, function($secure_logged_in_cookie) use (&$slmo_secure_logged_in_cookie) {
			return $slmo_secure_logged_in_cookie = $secure_logged_in_cookie;
		}
		, PHP_INT_MAX
	);

	add_action(
		'set_logged_in_cookie'
		, function($logged_in_cookie, $expire, $expiration, $user_id, $scheme) use (&$slmo_expire, &$slmo_user_id) {
			if ($scheme === 'logged_in') {
				$slmo_expire = $expire;
				$slmo_user_id = $user_id;
			}
		}
		, PHP_INT_MAX
		, 5
	);

	add_action(
		'clear_auth_cookie'
		, function() use (&$slmo_retain_cookies, &$slmo_user_id) {
			$slmo_retain_cookies = false;
			$slmo_user_id = 0;
		}
		, PHP_INT_MAX
		, 0
	);

	add_filter(
		'send_auth_cookies'
		, function($send_auth_cookies) use (&$slmo_send_auth_cookies, &$slmo_user_id, &$slmo_retain_cookies, $slmo_cookie_name, $slmo_cookie_path) {
			if ($slmo_user_id) {
				$slmo_send_auth_cookies = $send_auth_cookies;
			}
			elseif ($slmo_retain_cookies) {
				$send_auth_cookies = false; # unnecessary session destruction stopped
			}
			elseif ($send_auth_cookies) {
				setcookie($slmo_cookie_name, ' ', time() - YEAR_IN_SECONDS, $slmo_cookie_path(), COOKIE_DOMAIN); # real logout
			}
			return $send_auth_cookies;
		}
		, PHP_INT_MAX
	);

	add_action(
		'wp_login'
		, function($user_login) use ($slmo_cookie_name, $slmo_cookie_path, &$slmo_secure_logged_in_cookie, &$slmo_expire, &$slmo_send_auth_cookies) {
			if ($slmo_send_auth_cookies) {
				setcookie($slmo_cookie_name, time(), $slmo_expire, $slmo_cookie_path(), COOKIE_DOMAIN, $slmo_secure_logged_in_cookie);
			}
		}
		, PHP_INT_MIN
	);

	add_filter(
		'wp_login_errors'
		, function($errors, $redirect_to) use ($slmo_cookie_name, &$slmo_retain_cookies, &$slmo_modify_login) {
			$min = 1680000000;
			if (
				(
					!$errors->has_errors()
					|| $errors->get_error_codes() === ['loggedout']
				)
				&& (
					($unforced = empty($_REQUEST['reauth']))
					|| $_REQUEST['reauth'] > $min # assume our modified value
				)
			) {
				$slmo_modify_login = $unforced ? $min : (int) $_REQUEST['reauth'];
				if (
					!empty($_COOKIE[$slmo_cookie_name])
					&& (int) $_COOKIE[$slmo_cookie_name] > $slmo_modify_login # not reauth, or logged in again since WP required reauth
				) {
					if (wp_parse_auth_cookie('', 'logged_in')) {
						if (
							($user = wp_get_current_user())
							&& $user->exists()
						) {
							# extra redirect logic duplicated from wp-login.php
							if ( ( empty( $redirect_to ) || 'wp-admin/' === $redirect_to || admin_url() === $redirect_to ) ) {
								// If the user doesn't belong to a blog, send them to user admin. If the user can't edit posts, send them to their profile.
								if ( is_multisite() && ! get_active_blog_for_user( $user->ID ) && ! is_super_admin( $user->ID ) ) {
									$redirect_to = user_admin_url();
								} elseif ( is_multisite() && ! $user->has_cap( 'read' ) ) {
									$redirect_to = get_dashboard_url( $user->ID );
								} elseif ( ! $user->has_cap( 'edit_posts' ) ) {
									$redirect_to = $user->has_cap( 'read' ) ? admin_url( 'profile.php' ) : home_url();
								}

								wp_redirect( $redirect_to );
								exit;
							}

							wp_safe_redirect( $redirect_to );
							exit;
						}
						$slmo_retain_cookies = true; # cookies may still be valid for grace-period, etc., but login page normally wipes them
					}
					$errors->add(
						'slmo_no_redir'
						, esc_html__('You could not be authenticated in order to return you to your session. You will need to log in again.', 'stop-logging-me-out')
					);
					$slmo_modify_login = time(); # hold off focus detection until after next login
				}
			}
			return $errors;
		}
		, PHP_INT_MIN
		, 2
	);

	add_action(
		'login_footer'
		, function() use (&$slmo_modify_login, $slmo_cookie_name) {
			if ($slmo_modify_login) {
				printf(
					<<<'EOHTML'
<script>
(function($) {
	if (!$)
		return;
	var busy = false,
		chillFor = 3000, // avoid recursion caused by confirm() dialogue-associated blur events
		chillAt = 0;
	$(window).on('focus', function() {
		if (busy) {
			return;
		}
		busy = true;
		if (
			document.cookie
			&& (
				!chillAt
				|| (chillAt + chillFor) <= Date.now()
			)
		) {
			var cookies = document.cookie.split(/\s*;\s*/),
				i,
				cookie;
			for (i = 0; i < cookies.length; i++) {
				cookie = cookies[i].split(/=(.*)/);
				if (cookie.shift() === %s) {
					if (
						cookie.length
						&& (cookie = cookie.shift())
						&& (cookie && parseInt(cookie) || 0) > %d
					) {
						var reload = confirm(%s);
						chillAt = Date.now();
						if (reload) {
							busy = false;
							location.reload(); // try to invoke server-side redirect
							return;
						}
					}
					break;
				}
			}
		}
		busy = false;
	});
})(window.jQuery);
</script>
EOHTML
					, json_encode($slmo_cookie_name)
					, $slmo_modify_login
					, json_encode(wp_strip_all_tags(
						empty($_REQUEST['reauth']) ?
							__('You have logged in again since this page loaded. Would you like to try returning to your session?', 'stop-logging-me-out') :
							__('You have logged in again since this page automatically logged you out. Would you like to try returning to it?', 'stop-logging-me-out')
					))
				);	
			}
		}
		, PHP_INT_MAX
		, 0
	);
})();
