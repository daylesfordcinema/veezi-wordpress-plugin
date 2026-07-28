<?php
/**
 * The plugin's settings screen.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Admin;

use Veezi\WordPress\ConnectionResult;
use Veezi\WordPress\Plugin;
use Veezi\WordPress\Settings;
use Veezi\WordPress\Token;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Veezi.
 *
 * Two forms rather than one. Saving goes through WordPress's Settings API to
 * `options.php`, which supplies the nonce and the capability check for free.
 * Testing the connection is a separate POST to `admin-post.php`, because it
 * changes nothing and must not be confused with saving — an administrator who
 * pastes a token, presses "Test connection" and sees a success has not saved
 * anything, and the screen says so.
 */
final class SettingsPage {

	public const MENU_SLUG    = 'veezi';
	public const CAPABILITY   = 'manage_options';
	public const CHECK_ACTION = 'veezi_check_connection';

	/** The starter card, relative to the plugin's main file. */
	public const FILM_CARD = 'templates/film-card.json';

	/**
	 * The answer to a connection check waits here for the redirect that
	 * follows it. Keyed by user, so two administrators working at once each
	 * see their own result rather than whichever landed last.
	 */
	private const NOTICE_TRANSIENT_PREFIX = 'veezi_connection_notice_';

	/** Set when a save changed the token, so the next page load verifies it. */
	private const PENDING_CHECK_TRANSIENT_PREFIX = 'veezi_check_pending_';

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'add_fields' ) );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( $this, 'handle_connection_check' ) );

		// Saving a new token schedules a check of it. WordPress writes a brand
		// new option through add_option(), so both hooks are needed.
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'note_token_added' ), 10, 2 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'note_token_changed' ), 10, 2 );
	}

	/**
	 * @param string $option Option name.
	 * @param mixed  $value  The value just written.
	 */
	public function note_token_added( $option, $value ): void {
		$this->schedule_check_if_token_changed( '', $value );
	}

	/**
	 * @param mixed $old_value The value before the save.
	 * @param mixed $value     The value just written.
	 */
	public function note_token_changed( $old_value, $value ): void {
		$this->schedule_check_if_token_changed(
			is_array( $old_value ) ? (string) ( $old_value['token'] ?? '' ) : '',
			$value
		);
	}

	/**
	 * The ticket's flow is paste, press one button, be told which cinema. So a
	 * save that introduces a new token verifies it, and the administrator does
	 * not have to know that "Test connection" is a separate step.
	 *
	 * A flag rather than the check itself, because this runs inside the save
	 * request: a slow or hanging Veezi would otherwise make saving look broken
	 * and invite a resubmit. The work happens on the page load that follows.
	 *
	 * @param string $old_token The token before the save, if any.
	 * @param mixed  $value     The settings value just written.
	 */
	private function schedule_check_if_token_changed( string $old_token, $value ): void {
		$new_token = is_array( $value ) ? (string) ( $value['token'] ?? '' ) : '';

		if ( '' === $new_token || $new_token === $old_token ) {
			return;
		}

		set_transient( $this->pending_check_key(), 1, MINUTE_IN_SECONDS );
	}

	public function add_page(): void {
		add_options_page(
			__( 'Veezi', 'veezi-wordpress-plugin' ),
			__( 'Veezi', 'veezi-wordpress-plugin' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	public function url(): string {
		return admin_url( 'options-general.php?page=' . self::MENU_SLUG );
	}

	public function add_fields(): void {
		add_settings_section(
			'veezi_connection',
			__( 'Veezi account', 'veezi-wordpress-plugin' ),
			array( $this, 'render_section_intro' ),
			self::MENU_SLUG
		);

		add_settings_field(
			'veezi_token',
			__( 'Access token', 'veezi-wordpress-plugin' ),
			array( $this, 'render_token_field' ),
			self::MENU_SLUG,
			'veezi_connection',
			array( 'label_for' => 'veezi-token' )
		);
	}

	public function render_section_intro(): void {
		echo '<p>';
		esc_html_e(
			'Veezi issues an access token per cinema, from Settings → Web in Veezi Back Office. The token is read-only: this plugin never writes to Veezi and never handles a ticket sale.',
			'veezi-wordpress-plugin'
		);
		echo '</p>';
	}

	public function render_token_field(): void {
		$token = $this->plugin->token();

		printf(
			'<input type="password" id="veezi-token" name="%1$s[token]" value="" class="regular-text" autocomplete="off" spellcheck="false" />',
			esc_attr( Settings::OPTION )
		);

		echo '<p class="description">';

		if ( Token::SOURCE_CONSTANT === $token->source() ) {
			printf(
				/* translators: 1: a PHP constant name, 2: the masked token. */
				esc_html__( 'A token is being supplied by the %1$s constant in wp-config.php (%2$s), and it takes precedence over anything saved here.', 'veezi-wordpress-plugin' ),
				'<code>' . esc_html( Token::CONSTANT ) . '</code>',
				'<code>' . esc_html( $token->masked() ) . '</code>'
			);
		} elseif ( $token->is_present() ) {
			printf(
				/* translators: %s: the masked token. */
				esc_html__( 'A token ending %s is saved. Leave this field empty to keep it, or paste a new one to replace it.', 'veezi-wordpress-plugin' ),
				'<code>' . esc_html( $token->masked() ) . '</code>'
			);
		} else {
			esc_html_e( 'No token is saved yet.', 'veezi-wordpress-plugin' );
		}

		echo '</p>';

		// Only offered when there is something to remove: an empty field means
		// "leave the token alone", so without this there would be no way to
		// clear one at all.
		if ( $this->plugin->has_stored_token() ) {
			printf(
				'<p><label><input type="checkbox" name="%1$s[%2$s]" value="1" /> %3$s</label></p>',
				esc_attr( Settings::OPTION ),
				esc_attr( Settings::DELETE_TOKEN_FIELD ),
				esc_html__( 'Forget the saved token', 'veezi-wordpress-plugin' )
			);
		}
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage Veezi settings.', 'veezi-wordpress-plugin' ),
				'',
				array( 'response' => 403 )
			);
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Veezi', 'veezi-wordpress-plugin' ) . '</h1>';

		$this->render_connection_notice();

		echo '<form action="options.php" method="post">';
		settings_fields( Settings::GROUP );
		do_settings_sections( self::MENU_SLUG );
		submit_button( __( 'Save settings', 'veezi-wordpress-plugin' ) );
		echo '</form>';

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Connection', 'veezi-wordpress-plugin' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Saving a new token checks it straight away. Use this to check the saved token again at any time — after revoking one at Veezi, say, or to tell an empty programme from a broken connection.', 'veezi-wordpress-plugin' ) . '</p>';

		printf( '<form action="%s" method="post">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::CHECK_ACTION ) );
		wp_nonce_field( self::CHECK_ACTION );
		// Named, because submit_button() derives the id from the name and
		// defaults both to "submit" — which the save button above already
		// uses. Two elements sharing an id is invalid HTML, and it makes the
		// button ambiguous to anything selecting by id, this page's own tests
		// included.
		submit_button( __( 'Test connection', 'veezi-wordpress-plugin' ), 'secondary', 'veezi-check-connection', false );
		echo '</form>';

		$this->render_starter_templates();

		echo '</div>';
	}

	/**
	 * Where to find the starter card.
	 *
	 * The template ships as a file, so without this it is discoverable only by
	 * somebody who thinks to look inside a plugin directory — which is nobody.
	 * An Elementor developer returning to this site a year from now should be
	 * able to find the way in from the screen the plugin already has.
	 */
	private function render_starter_templates(): void {
		echo '<hr />';
		echo '<h2>' . esc_html__( 'Starter templates', 'veezi-wordpress-plugin' ) . '</h2>';

		echo '<p class="description">';
		esc_html_e(
			'A film card, ready to import and restyle: poster, title, details, session times and a booking button, with every field already bound. Download it, then go to Templates → Saved Templates → Import Templates.',
			'veezi-wordpress-plugin'
		);
		echo '</p>';

		printf(
			'<p><a class="button button-secondary" href="%1$s" download>%2$s</a></p>',
			esc_url( plugins_url( self::FILM_CARD, \Veezi\WordPress\PLUGIN_FILE ) ),
			esc_html__( 'Download the film card', 'veezi-wordpress-plugin' )
		);
	}

	public function render_connection_notice(): void {
		$this->run_scheduled_check();

		$key    = $this->notice_key();
		$stored = get_transient( $key );

		if ( ! is_array( $stored ) ) {
			return;
		}

		delete_transient( $key );

		$result = ConnectionResult::from_array( $stored );

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			$result->is_success() ? 'notice-success' : 'notice-error',
			esc_html( $result->message() )
		);
	}

	/**
	 * The `admin_post_` handler: check, remember, and send the browser back to
	 * the screen so a refresh does not re-run it.
	 */
	public function handle_connection_check(): void {
		$this->run_connection_check();

		wp_safe_redirect( $this->url() );
		exit;
	}

	/**
	 * Separated from the redirect above so that the guards, which are the part
	 * worth getting right, can be exercised without a browser.
	 */
	public function run_connection_check(): ConnectionResult {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You are not allowed to test the Veezi connection.', 'veezi-wordpress-plugin' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::CHECK_ACTION );

		$result = $this->plugin->client()->check_connection();

		set_transient( $this->notice_key(), $result->to_array(), MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Runs the check a token save asked for, if there is one owing.
	 *
	 * No nonce or capability check of its own: it does nothing a GET of this
	 * page has not already been authorised for, it is reachable only by the
	 * administrator who saved the token, and it acts on a flag this plugin set
	 * rather than on anything from the request.
	 */
	private function run_scheduled_check(): void {
		$key = $this->pending_check_key();

		if ( ! get_transient( $key ) ) {
			return;
		}

		delete_transient( $key );

		set_transient(
			$this->notice_key(),
			$this->plugin->client()->check_connection()->to_array(),
			MINUTE_IN_SECONDS
		);
	}

	private function notice_key(): string {
		return self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
	}

	private function pending_check_key(): string {
		return self::PENDING_CHECK_TRANSIENT_PREFIX . get_current_user_id();
	}
}
