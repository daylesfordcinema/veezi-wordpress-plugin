<?php
/**
 * Plugin wiring.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Composition root: builds the plugin's services and hangs them off WordPress.
 *
 * Load time does nothing but add hooks, so a front-end request pays for none of
 * the admin. The services themselves are built per call rather than held,
 * because the token can change between one request and the next.
 */
final class Plugin {

	private static ?self $instance = null;

	private Settings $settings;

	private function __construct() {
		$this->settings = new Settings();
	}

	/**
	 * Build the plugin and register its hooks. Idempotent — a second call
	 * returns the same instance without registering anything twice.
	 */
	public static function boot(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}

		return self::$instance;
	}

	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Whether a token is saved in the database, regardless of whether a
	 * constant is currently overriding it. The settings screen needs to know
	 * the difference: it offers to forget a saved token, and there is nothing
	 * to forget when the only token in play comes from wp-config.php.
	 */
	public function has_stored_token(): bool {
		return '' !== $this->settings->token();
	}

	public function token(): Token {
		return Token::resolve( $this->settings );
	}

	public function client(): Client {
		return new Client( $this->token() );
	}

	public function sync(): Sync {
		return new Sync( $this->client() );
	}

	private function register(): void {
		add_action( 'admin_init', array( $this->settings, 'register' ) );

		if ( is_admin() ) {
			( new Admin\SettingsPage( $this ) )->register();
		}
	}

	/**
	 * Runs on activation.
	 *
	 * Deliberately does no work: there is nothing to install yet, and an
	 * activation hook that writes options makes the plugin harder to reason
	 * about than one that reads defaults. Scheduling arrives with the sync.
	 */
	public static function activate(): void {
	}

	public static function deactivate(): void {
	}
}
