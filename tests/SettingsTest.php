<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\Settings;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * Storing and sanitising the plugin's settings.
 */
final class SettingsTest extends TestCase {

	private Settings $settings;

	public function set_up(): void {
		parent::set_up();

		$this->settings = new Settings();
	}

	public function test_nothing_is_configured_on_a_fresh_install(): void {
		$this->assertSame( '', $this->settings->token() );
	}

	public function test_a_saved_token_is_persisted(): void {
		$this->settings->update( array( 'token' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' ) );

		$this->assertSame( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', ( new Settings() )->token() );
	}

	/**
	 * Pasting from Veezi's own interface picks up surrounding whitespace, and
	 * a token with a space on the end simply fails to authenticate.
	 */
	public function test_a_pasted_token_survives_surrounding_whitespace(): void {
		$clean = $this->settings->sanitize( array( 'token' => "  ABCDEFGHIJKLMNOPQRSTUVWXYZ \n" ) );

		$this->assertSame( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', $clean['token'] );
	}

	/**
	 * The token is sent as an HTTP request header. A line break in it would be
	 * a header injection, so it cannot survive saving in any form.
	 */
	public function test_a_line_break_inside_a_token_cannot_be_saved(): void {
		$clean = $this->settings->sanitize(
			array( 'token' => "ABCDEFGHIJKL\r\nX-Injected: yes\r\n" )
		);

		$this->assertStringNotContainsString( "\r", $clean['token'] );
		$this->assertStringNotContainsString( "\n", $clean['token'] );
		$this->assertStringNotContainsString( ' ', $clean['token'] );
		$this->assertStringNotContainsString( ':', $clean['token'] );
	}

	public function test_removing_characters_from_a_token_is_reported_to_the_administrator(): void {
		$this->settings->sanitize( array( 'token' => 'ABCDEF<script>GHIJKL' ) );

		$codes = wp_list_pluck( get_settings_errors( Settings::OPTION ), 'code' );

		$this->assertContains( 'veezi_token_characters', $codes );
	}

	public function test_a_clean_token_produces_no_warning(): void {
		$this->settings->sanitize( array( 'token' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' ) );

		$this->assertSame( array(), get_settings_errors( Settings::OPTION ) );
	}

	/**
	 * The field is rendered empty on every page load, because the stored token
	 * is never sent back to the browser. Saving any other setting therefore
	 * submits an empty token field, and that must not wipe the credential.
	 */
	public function test_saving_with_an_empty_token_field_leaves_the_stored_token_alone(): void {
		$this->settings->update( array( 'token' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' ) );

		$clean = $this->settings->sanitize( array( 'token' => '' ) );

		$this->assertSame( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', $clean['token'] );
	}

	public function test_asking_to_remove_the_token_clears_it(): void {
		$this->settings->update( array( 'token' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' ) );

		$clean = $this->settings->sanitize(
			array(
				'token'                      => '',
				Settings::DELETE_TOKEN_FIELD => '1',
			)
		);

		$this->assertSame( '', $clean['token'] );
	}

	public function test_the_removal_request_is_not_itself_stored(): void {
		$clean = $this->settings->sanitize( array( Settings::DELETE_TOKEN_FIELD => '1' ) );

		$this->assertArrayNotHasKey( Settings::DELETE_TOKEN_FIELD, $clean );
	}

	public function test_fields_the_plugin_does_not_own_are_discarded(): void {
		$clean = $this->settings->sanitize(
			array(
				'token'     => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
				'admin_url' => 'https://example.test/',
			)
		);

		$this->assertArrayNotHasKey( 'admin_url', $clean );
	}

	public function test_a_corrupted_option_falls_back_to_defaults_rather_than_erroring(): void {
		update_option( Settings::OPTION, 'not an array' );

		$this->assertSame( '', ( new Settings() )->token() );
	}

	/**
	 * Everything above is the sanitiser in isolation; this is the wiring that
	 * makes WordPress actually call it when the settings form is submitted.
	 */
	public function test_sanitising_is_applied_when_the_option_is_written(): void {
		$this->settings->register();

		update_option( Settings::OPTION, array( 'token' => "  ABCDEFGHIJKL\r\nMNOP  " ) );

		$this->assertSame( 'ABCDEFGHIJKLMNOP', ( new Settings() )->token() );
	}

	public function test_the_token_is_not_exposed_through_the_rest_api(): void {
		$this->settings->register();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( Settings::OPTION, $registered );
		$this->assertFalse( $registered[ Settings::OPTION ]['show_in_rest'] );
	}
}
