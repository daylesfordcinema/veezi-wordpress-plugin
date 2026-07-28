<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Veezi\WordPress\Admin\Notices;
use Veezi\WordPress\CinemaTimezone;
use Veezi\WordPress\SyncLog;
use Veezi\WordPress\SyncResult;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * Finding out before a customer does.
 *
 * Both conditions here put themselves away: a failure ends when a run works,
 * and a timezone that disagrees with the cinema's stops disagreeing when
 * somebody corrects it. Nothing needs remembering that an administrator has
 * been told, which is why neither notice needs dismissing to stay dismissed.
 */
final class AdminNoticesTest extends TestCase {

	private Notices $notices;

	public function set_up(): void {
		parent::set_up();

		$this->notices = new Notices();
	}

	private function shown(): string {
		ob_start();
		$this->notices->render();

		return (string) ob_get_clean();
	}

	private function a_run_failed( string $message = 'Veezi refused the access token.' ): void {
		SyncLog::record( SyncResult::failed( new DateTimeImmutable( '@1785000000' ), $message ) );
	}

	private function a_run_worked(): void {
		SyncLog::record( SyncResult::completed( new DateTimeImmutable( '@1785003600' ), 'Synced 9 films and 32 sessions from Regal Picture Palace.' ) );
	}

	public function test_a_healthy_site_is_left_in_peace(): void {
		$this->become_administrator();

		$this->assertSame( '', trim( $this->shown() ) );
	}

	public function test_a_failed_sync_is_raised_where_somebody_will_see_it(): void {
		$this->become_administrator();
		$this->a_run_failed();

		$html = $this->shown();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Veezi refused the access token.', $html );
	}

	public function test_the_notice_goes_away_once_a_run_works(): void {
		$this->become_administrator();
		$this->a_run_failed();
		$this->a_run_worked();

		$this->assertStringNotContainsString( 'notice-error', $this->shown() );
	}

	/**
	 * The message is upstream's words, repeated. The client scrubs the token
	 * out of them before they get this far, and this is the screen that would
	 * put the result on somebody's monitor.
	 */
	public function test_a_failure_notice_never_carries_the_token(): void {
		$this->become_administrator();
		$this->a_run_failed( 'Could not reach Veezi: bad handshake' );

		$this->assertStringNotContainsString( self::TOKEN, $this->shown() );
	}

	/**
	 * The programme on the page survives an outage — but only if there was one
	 * to begin with. A site whose very first sync failed has nothing standing,
	 * and being reassured otherwise sends its administrator looking for content
	 * that was never there.
	 */
	public function test_a_site_that_has_never_synced_is_not_told_its_programme_is_intact(): void {
		$this->become_administrator();
		$this->a_run_failed();

		$this->assertStringContainsString( 'Nothing has synced yet', $this->shown() );
	}

	public function test_a_site_that_had_a_programme_is_told_it_still_has_one(): void {
		$this->become_administrator();
		$this->a_run_worked();
		$this->a_run_failed();

		$this->assertStringContainsString( 'still the last programme that synced', $this->shown() );
	}

	public function test_nothing_is_said_to_somebody_who_could_not_act_on_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->a_run_failed();

		$this->assertSame( '', trim( $this->shown() ) );
	}

	public function test_notices_are_wired_into_the_admin(): void {
		$this->notices->register();

		$this->assertNotFalse( has_action( 'admin_notices', array( $this->notices, 'render' ) ) );
	}

	/**
	 * The live site this was written for has exactly this misconfiguration: a
	 * WordPress timezone left unset while the cinema is in Melbourne. Showtimes
	 * are converted explicitly and so stay right, but every other date the site
	 * prints — and everything an administrator reads in the admin — is ten
	 * hours out, and looks entirely plausible.
	 */
	public function test_a_site_whose_clock_disagrees_with_the_cinemas_is_warned(): void {
		$this->become_administrator();
		$this->a_run_worked();
		$this->cinema_is_in( 'Australia/Sydney' );
		$this->site_is_in( '' );

		$html = $this->shown();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'Australia/Sydney', $html, 'A warning that does not name the two zones is not actionable.' );
	}

	public function test_a_site_keeping_the_cinemas_time_is_not_warned(): void {
		$this->become_administrator();
		$this->a_run_worked();
		$this->cinema_is_in( 'Australia/Sydney' );
		$this->site_is_in( 'Australia/Sydney' );

		$this->assertStringNotContainsString( 'notice-warning', $this->shown() );
	}

	/**
	 * Melbourne and Sydney are different names for the same clock. Warning
	 * about that would train an administrator to ignore the warning.
	 */
	public function test_two_names_for_the_same_clock_are_not_a_disagreement(): void {
		$this->become_administrator();
		$this->a_run_worked();
		$this->cinema_is_in( 'Australia/Sydney' );
		$this->site_is_in( 'Australia/Melbourne' );

		$this->assertStringNotContainsString( 'notice-warning', $this->shown() );
	}

	/**
	 * Until a sync has worked, the cinema's timezone is whatever the site's is,
	 * because that is the fallback — so the comparison would always agree, and
	 * on a site that had never connected it would be comparing nothing at all.
	 */
	public function test_nothing_is_claimed_about_a_cinema_the_site_has_never_reached(): void {
		$this->become_administrator();
		$this->cinema_is_in( 'Australia/Sydney' );
		$this->site_is_in( '' );

		$this->assertStringNotContainsString( 'notice-warning', $this->shown() );
	}

	private function cinema_is_in( string $zone ): void {
		CinemaTimezone::remember( new DateTimeZone( $zone ) );
	}

	/**
	 * @param string $zone An IANA name, or '' for the unset state — which is
	 *                     what the live site is in, and reads as UTC.
	 */
	private function site_is_in( string $zone ): void {
		update_option( 'timezone_string', $zone );
		update_option( 'gmt_offset', 0 );
	}
}
