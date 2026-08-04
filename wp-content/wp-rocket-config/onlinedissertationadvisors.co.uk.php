<?php
defined( 'ABSPATH' ) || exit;

$rocket_cookie_hash = 'c6caf937b0a5da02792637edfe20cd22';
$rocket_logged_in_cookie = 'wordpress_logged_in_c6caf937b0a5da02792637edfe20cd22';
$rocket_cache_mobile_files_tablet = 'desktop';
$rocket_cache_reject_uri = '/(?:.+/)?feed(?:/(?:.+/?)?)?$|/(?:.+/)?embed/|/(index.php/)?(.*)wp-json(/.*|$)';
$rocket_cache_reject_cookies = 'wordpress_logged_in_.+|wp-postpass_|wptouch_switch_toggle|comment_author_|comment_author_email_';
$rocket_cache_reject_ua = 'facebookexternalhit|WhatsApp';
$rocket_cache_query_strings = array();
$rocket_secret_cache_key = '';
$rocket_cache_ssl = 1;
$rocket_cache_mobile = 0;
$rocket_do_caching_mobile_files = 0;
$rocket_cache_ignored_parameters = array();
$rocket_cache_mandatory_cookies = '';
$rocket_cache_dynamic_cookies = array();
$rocket_permalink_structure = '/%postname%/';
