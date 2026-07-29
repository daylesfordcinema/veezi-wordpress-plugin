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
use Veezi\WordPress\ResponseCache;
use Veezi\WordPress\Schedule;
use Veezi\WordPress\Settings;
use Veezi\WordPress\SyncLog;
use Veezi\WordPress\SyncResult;
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
	public const SYNC_ACTION  = 'veezi_sync_now';

	/** The starter templates, relative to the plugin's main file. */
	public const FILM_CARD   = 'templates/film-card.json';
	public const FILM_PAGE   = 'templates/film-page.json';
	public const SESSION_ROW = 'templates/session-row.json';

	/**
	 * What the button just pressed did waits here for the redirect that follows
	 * it. Keyed by user, so two administrators working at once each see their
	 * own result rather than whichever landed last.
	 */
	private const NOTICE_TRANSIENT_PREFIX = 'veezi_connection_notice_';

	/** Set when a save changed the token, so the next page load verifies it. */
	private const PENDING_CHECK_TRANSIENT_PREFIX = 'veezi_check_pending_';

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'add_fields' ) );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( $this, 'handle_connection_check' ) );
		add_action( 'admin_post_' . self::SYNC_ACTION, array( $this, 'handle_sync_now' ) );

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

		$this->render_notice();

		echo '<form action="options.php" method="post">';
		settings_fields( Settings::GROUP );
		do_settings_sections( self::MENU_SLUG );
		submit_button( __( 'Save settings', 'veezi-wordpress-plugin' ) );
		echo '</form>';

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Connection', 'veezi-wordpress-plugin' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Saving a new token checks it straight away. Use this to check the saved token again at any time — after revoking one at Veezi, say, or to tell an empty programme from a broken connection.', 'veezi-wordpress-plugin' ) . '</p>';

		$this->render_action(
			self::CHECK_ACTION,
			__( 'Test connection', 'veezi-wordpress-plugin' ),
			'veezi-check-connection'
		);

		$this->render_programme();
		$this->render_starter_templates();

		echo '</div>';
	}

	/**
	 * Whether the programme is keeping itself up to date, and a way to hurry it.
	 *
	 * The last run that *worked* rather than the last run, because that is what
	 * the site is currently showing: through an outage the programme on the
	 * page is exactly what that run put there, and saying otherwise would send
	 * an administrator looking for content that is not missing. A run that
	 * failed raises its own notice.
	 */
	private function render_programme(): void {
		echo '<hr />';
		echo '<h2>' . esc_html__( 'Programme', 'veezi-wordpress-plugin' ) . '</h2>';

		$last = SyncLog::last_success();

		echo '<p>';
		if ( null === $last ) {
			esc_html_e( 'The programme has never synced.', 'veezi-wordpress-plugin' );
		} else {
			printf(
				/* translators: 1: a date and time, 2: what that run did, e.g. "Synced 9 films and 32 sessions from the Regal." */
				esc_html__( 'Last synced %1$s. %2$s', 'veezi-wordpress-plugin' ),
				esc_html( self::in_words( $last->started_at()->getTimestamp() ) ),
				esc_html( $last->message() )
			);
		}
		echo '</p>';

		echo '<p class="description">' . esc_html( self::when_the_next_one_is_due() ) . '</p>';

		$this->render_action( self::SYNC_ACTION, __( 'Sync now', 'veezi-wordpress-plugin' ), 'veezi-sync-now' );
	}

	/**
	 * A one-button form posting to `admin-post.php`, with its nonce.
	 *
	 * Separate from the settings form above, which goes to WordPress's own
	 * `options.php`: these two buttons change nothing that is saved, and an
	 * administrator who presses one has not saved anything.
	 *
	 * @param string $action    The `admin_post_` name, and the nonce action.
	 * @param string $label     What the button says.
	 * @param string $button_id Named, because submit_button() derives the id
	 *                          from the name and defaults both to "submit" —
	 *                          which the save form already uses. Two elements
	 *                          sharing an id is invalid HTML, and makes the
	 *                          button ambiguous to anything selecting by id,
	 *                          this page's own tests included.
	 */
	private function render_action( string $action, string $label, string $button_id ): void {
		printf( '<form action="%s" method="post">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $action ) );
		wp_nonce_field( $action );
		submit_button( $label, 'secondary', $button_id, false );
		echo '</form>';
	}

	/**
	 * A time in the site's own terms — its timezone and its chosen format.
	 *
	 * The site's rather than the cinema's, deliberately: this is a fact about
	 * the website, read by whoever administers it, and it belongs on the same
	 * clock as every other date they see in here. Showtimes are the other way
	 * round, and are converted in the cinema's zone wherever they are printed.
	 *
	 * @param int $timestamp An epoch second.
	 */
	private static function in_words( int $timestamp ): string {
		return (string) wp_date(
			trim( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) ),
			$timestamp
		);
	}

	private static function when_the_next_one_is_due(): string {
		$next = Schedule::next_run();

		if ( null === $next ) {
			return __( 'No sync is scheduled. Deactivating and reactivating the plugin puts it back.', 'veezi-wordpress-plugin' );
		}

		// A due time in the past is the ordinary state of an externally driven
		// cron between the moment an event falls due and the moment the host
		// gets to it, so it is not a fault to report.
		if ( $next <= time() ) {
			return __( 'The next sync is due now.', 'veezi-wordpress-plugin' );
		}

		return sprintf(
			/* translators: %s: a rough length of time, e.g. "42 mins". */
			__( 'The next sync is due in %s.', 'veezi-wordpress-plugin' ),
			human_time_diff( time(), $next )
		);
	}

	/**
	 * Where to find the starter templates.
	 *
	 * They ship as files, so without this they are discoverable only by somebody
	 * who thinks to look inside a plugin directory — which is nobody. An
	 * Elementor developer returning to this site a year from now should be able
	 * to find the way in from the screen the plugin already has.
	 */
	private function render_starter_templates(): void {
		echo '<hr />';
		echo '<h2>' . esc_html__( 'Starter templates', 'veezi-wordpress-plugin' ) . '</h2>';

		echo '<p class="description">';
		esc_html_e(
			'Ready to import and restyle, with every field already bound. Download one, then go to Templates → Saved Templates → Import Templates.',
			'veezi-wordpress-plugin'
		);
		echo '</p>';

		self::render_starter_template(
			self::FILM_CARD,
			__( 'Download the film card', 'veezi-wordpress-plugin' ),
			__( 'One film in a listing: poster, title, details, session times and a booking button. Use it as the loop item of a Now Showing grid.', 'veezi-wordpress-plugin' )
		);

		self::render_starter_template(
			self::SESSION_ROW,
			__( 'Download the session row', 'veezi-wordpress-plugin' ),
			__( 'One screening in a chronological listing: the day it is on, the time, the film and a link to the seats. Use it as the loop item of a grid over Sessions.', 'veezi-wordpress-plugin' )
		);

		self::render_starter_template(
			self::FILM_PAGE,
			__( 'Download the film page', 'veezi-wordpress-plugin' ),
			__( 'A film’s own page: synopsis, cast and crew, every remaining screening and the trailer. Use it inside a theme-builder Single template for Films.', 'veezi-wordpress-plugin' )
		);
	}

	/**
	 * One template, with a line saying what it is for.
	 *
	 * @param string $template    Its path, relative to the plugin's main file.
	 * @param string $label       What the button reads.
	 * @param string $description What somebody would use it for.
	 */
	private static function render_starter_template( string $template, string $label, string $description ): void {
		printf(
			'<p class="description">%1$s</p><p><a class="button button-secondary" href="%2$s" download>%3$s</a></p>',
			esc_html( $description ),
			esc_url( plugins_url( $template, \Veezi\WordPress\PLUGIN_FILE ) ),
			esc_html( $label )
		);
	}

	/**
	 * What the last button press did, shown once on the page after it.
	 */
	public function render_notice(): void {
		$this->run_scheduled_check();

		$key    = $this->notice_key();
		$stored = get_transient( $key );

		if ( ! is_array( $stored ) ) {
			return;
		}

		delete_transient( $key );

		Message::from_array( $stored )?->render();
	}

	/**
	 * Put something aside to say on the page after the redirect.
	 *
	 * @param Message $message Already scrubbed of the token by whoever built it.
	 */
	private function remember( Message $message ): void {
		set_transient( $this->notice_key(), $message->to_array(), MINUTE_IN_SECONDS );
	}

	/**
	 * The `admin_post_` handlers: do the thing, then send the browser back to
	 * the screen so that a refresh does not do it again.
	 */
	public function handle_connection_check(): void {
		$this->run_connection_check();

		$this->back_to_the_screen();
	}

	public function handle_sync_now(): void {
		$this->run_sync_now();

		$this->back_to_the_screen();
	}

	private function back_to_the_screen(): void {
		wp_safe_redirect( $this->url() );
		exit;
	}

	/**
	 * Sync because somebody asked, rather than because the hour came round.
	 *
	 * Null when a sync is already running, which is not a failure: the work
	 * this run would have done is being done. Separated from the redirect above
	 * so that the guards, which are the part worth getting right, can be
	 * exercised without a browser.
	 */
	public function run_sync_now(): ?SyncResult {
		$this->authorise(
			self::SYNC_ACTION,
			__( 'You are not allowed to sync the Veezi programme.', 'veezi-wordpress-plugin' )
		);

		// This button exists for the last-minute programme change, so "now"
		// has to mean now: an answer cached minutes ago is precisely the thing
		// being re-asked.
		ResponseCache::forget();

		$result = $this->plugin->sync()->attempt();

		if ( null === $result ) {
			$this->remember( Message::info( __( 'A sync is already running. Give it a moment and reload.', 'veezi-wordpress-plugin' ) ) );

			return null;
		}

		// Only a success is said here. A failure has already raised its own
		// notice through the sync log, and one problem stated twice on one
		// screen reads as two.
		if ( $result->is_success() ) {
			$this->remember( Message::success( $result->message() ) );
		}

		return $result;
	}

	/**
	 * Separated from the redirect above so that the guards, which are the part
	 * worth getting right, can be exercised without a browser.
	 */
	public function run_connection_check(): ConnectionResult {
		$this->authorise(
			self::CHECK_ACTION,
			__( 'You are not allowed to test the Veezi connection.', 'veezi-wordpress-plugin' )
		);

		return $this->check_the_connection();
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

		$this->check_the_connection();
	}

	private function check_the_connection(): ConnectionResult {
		$result = $this->plugin->client()->check_connection();

		$this->remember(
			$result->is_success()
				? Message::success( $result->message() )
				: Message::error( $result->message() )
		);

		return $result;
	}

	/**
	 * The two guards every action on this screen carries.
	 *
	 * The code is public, so anyone can read exactly which request would reach
	 * a handler — which is why neither of these can be informal, and why they
	 * are in one place rather than repeated per action.
	 *
	 * @param string $action  The nonce action, which is also the `admin_post_`
	 *                        name the request arrived under.
	 * @param string $refusal What to tell somebody who may not do this.
	 */
	private function authorise( string $action, string $refusal ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html( $refusal ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	private function notice_key(): string {
		return self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
	}

	private function pending_check_key(): string {
		return self::PENDING_CHECK_TRANSIENT_PREFIX . get_current_user_id();
	}
}
