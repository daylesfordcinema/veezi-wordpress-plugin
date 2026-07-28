<?php
/**
 * Runs when the plugin is deleted from wp-admin.
 *
 * WordPress includes this file on its own, with the plugin not loaded and
 * nothing else on the page, so it loads the plugin to get its autoloader and
 * then hands over. The guard below is all that stands between that and a
 * request made directly to this file.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/veezi-wordpress-plugin.php';

Uninstall::run();
