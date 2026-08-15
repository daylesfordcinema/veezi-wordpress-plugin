<?php
/**
 * Plugin Name:       Veezi for WordPress
 * Plugin URI:        https://github.com/daylesfordcinema/veezi-wordpress-plugin
 * Description:       Publishes a cinema's Veezi programme as WordPress content — films, sessions, posters and booking links.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            Daylesford Community Theatre
 * Author URI:        https://github.com/daylesfordcinema
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       veezi-wordpress-plugin
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.2.0';

define( 'Veezi\WordPress\PLUGIN_FILE', __FILE__ );
define( 'Veezi\WordPress\PLUGIN_DIR', __DIR__ );

/**
 * The plugin ships as plain PHP with no runtime dependencies, so it carries its
 * own PSR-4 autoloader rather than requiring a Composer one. Composer is used
 * for linting and testing only, and vendor/ is excluded from the release
 * archive.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

Plugin::boot();
