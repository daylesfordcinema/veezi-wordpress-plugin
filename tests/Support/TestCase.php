<?php
/**
 * Base test case.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests\Support;

use Veezi\WordPress\Settings;
use WP_UnitTestCase;

/**
 * Real WordPress, real post types, real options — with one seam.
 *
 * Everything below WordPress's HTTP layer is faked and everything above it is
 * the code that ships. See the bootstrap: a request that no test arranged an
 * answer for is an error, not a network call.
 */
abstract class TestCase extends WP_UnitTestCase {

	protected FakeVeezi $veezi;

	public function set_up(): void {
		parent::set_up();

		// add_settings_error() appends to a global that WordPress's own test
		// case does not reset, so without this a test would see the notices
		// raised by whichever tests ran before it.
		$GLOBALS['wp_settings_errors'] = array();

		$this->veezi = new FakeVeezi();
		$this->veezi->register();
	}

	public function tear_down(): void {
		$this->veezi->unregister();
		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	/**
	 * The shape `/v1/site` returns, with only the fields the plugin reads
	 * populated. Synthesised, never captured: a real capture would carry a
	 * cinema's trading details into a public repository.
	 *
	 * @param  array<string,mixed> $overrides Fields to vary for this test.
	 * @return array<string,mixed>
	 */
	protected function site_payload( array $overrides = array() ): array {
		return array_merge(
			array(
				'Name'               => 'Regal Picture Palace',
				'ShortName'          => 'Regal',
				'LegalName'          => 'Regal Picture Palace Society',
				'TimeZoneIdentifier' => 'AUS Eastern Standard Time',
				'Country'            => 'Australia',
			),
			$overrides
		);
	}

	protected function store_token( string $token ): void {
		update_option( Settings::OPTION, array( 'token' => $token ) );
	}

	protected function become_administrator(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}
}
