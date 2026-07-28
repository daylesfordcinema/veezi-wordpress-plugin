<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use DateTimeImmutable;
use Veezi\WordPress\CinemaTimezone;
use Veezi\WordPress\ContentModel;
use Veezi\WordPress\ResponseCache;
use Veezi\WordPress\Schedule;
use Veezi\WordPress\Settings;
use Veezi\WordPress\SyncLock;
use Veezi\WordPress\SyncLog;
use Veezi\WordPress\SyncResult;
use Veezi\WordPress\Uninstall;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * Deleting the plugin takes the plugin's own things with it.
 *
 * What counts as the plugin's own is the whole question, and the line drawn
 * here is: everything it configured itself with, and nothing it published. A
 * film has a public address somebody may have linked to, and its poster is in
 * the media library where the cinema was told to reuse it. WordPress does not
 * delete posts when a plugin is deleted, and neither does this.
 */
final class UninstallTest extends TestCase {

	/**
	 * A site that has been running for a while: connected, synced, scheduled,
	 * and with a failure and a held lock lying about for good measure.
	 */
	private function a_site_in_use(): void {
		$this->arrange_programme(
			array( $this->session_payload( array( 'starts' => '2026-08-02T19:00:00' ) ) ),
			array( $this->film_payload() )
		);
		$this->sync_at( '2026-08-01 00:00:00' );

		SyncLog::record( SyncResult::failed( new DateTimeImmutable( '@1785000000' ), 'Could not reach Veezi.' ) );
		SyncLock::acquire();
		Schedule::ensure();
		ContentModel::flush_rewrites();
		ResponseCache::forget();
	}

	/**
	 * @return array<int,string>
	 */
	private function every_option_the_plugin_writes(): array {
		return array(
			Settings::OPTION,
			SyncLog::LAST_SUCCESS,
			SyncLog::LAST_FAILURE,
			SyncLock::OPTION,
			ResponseCache::GENERATION,
			CinemaTimezone::OPTION,
			ContentModel::REWRITES_VERSION,
		);
	}

	public function test_every_option_the_plugin_wrote_is_removed(): void {
		$this->a_site_in_use();

		foreach ( $this->every_option_the_plugin_writes() as $option ) {
			$this->assertNotFalse( get_option( $option, false ), "Nothing wrote {$option}, so removing it proves nothing." );
		}

		Uninstall::run();

		foreach ( $this->every_option_the_plugin_writes() as $option ) {
			$this->assertFalse( get_option( $option, false ), "{$option} outlived the plugin." );
		}
	}

	/**
	 * The one piece of data here that is genuinely dangerous to leave behind:
	 * a live credential for a cinema's ticketing account, sitting in the
	 * database of a site that no longer has any code that uses it.
	 */
	public function test_the_access_token_does_not_outlive_the_plugin(): void {
		$this->a_site_in_use();

		Uninstall::run();

		$this->assertSame( '', ( new Settings() )->token() );
	}

	public function test_nothing_is_left_on_the_schedule(): void {
		$this->a_site_in_use();

		Uninstall::run();

		$this->assertNull( Schedule::next_run(), 'An event whose hook nothing answers fires forever.' );
	}

	/**
	 * A film's address may be linked from a newsletter, a social post or
	 * somebody's bookmarks, and its poster is in the media library because the
	 * cinema was told it could reuse it there.
	 */
	public function test_what_the_plugin_published_is_left_alone(): void {
		$this->a_site_in_use();
		$film = $this->film_record( 'film-cook' );

		Uninstall::run();

		$this->assertSame( 'publish', get_post_status( $film ) );
		$this->assertNotSame( 0, get_post_thumbnail_id( $film ) );
	}

	/**
	 * WordPress runs this file directly, without the plugin loaded and without
	 * anything else on the page. The guard is the only thing standing between
	 * that and a request to the file itself.
	 */
	public function test_the_uninstall_file_refuses_to_run_on_its_own(): void {
		$shim = dirname( __DIR__ ) . '/uninstall.php';

		$this->assertFileExists( $shim, 'WordPress looks for this file by name; without it nothing is cleaned up.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file in this repository, read by a test.
		$this->assertStringContainsString( 'WP_UNINSTALL_PLUGIN', (string) file_get_contents( $shim ) );
	}
}
