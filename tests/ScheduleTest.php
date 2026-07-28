<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Plugin;
use Veezi\WordPress\Schedule;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * The programme keeps itself up to date.
 *
 * Nothing here asks WordPress to run the event — the host does that, and on
 * this cinema's server it does it from outside, with WordPress's own
 * request-triggered cron switched off. What is tested is that the event exists,
 * recurs, survives, and is wired to a sync, which is the whole of what a plugin
 * can control about it.
 */
final class ScheduleTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		// The plugin boots once for the whole suite, so by the time any test
		// runs the event is already scheduled. Each test says what it starts
		// from rather than inheriting that.
		Schedule::clear();
	}

	public function test_activating_the_plugin_puts_a_sync_on_the_schedule(): void {
		Plugin::activate();

		$this->assertNotNull( Schedule::next_run() );
	}

	/**
	 * A single event would sync once and never again, which looks identical
	 * from the admin on the day it is set up.
	 */
	public function test_the_sync_recurs(): void {
		Schedule::ensure();

		$event = wp_get_scheduled_event( Schedule::HOOK );

		$this->assertNotFalse( $event );
		$this->assertArrayHasKey(
			$event->schedule,
			wp_get_schedules(),
			'The recurrence has to be one WordPress knows, or the event runs once and stops.'
		);
	}

	public function test_asking_twice_leaves_one_event(): void {
		Schedule::ensure();
		$first = Schedule::next_run();

		Schedule::ensure();

		$this->assertSame( $first, Schedule::next_run() );
	}

	/**
	 * Events go missing: a database restored from before the plugin was
	 * installed, a migration that dropped the cron option, an update applied in
	 * place — which reactivates silently and so skips the activation hook. The
	 * only symptom is a programme that quietly stops changing.
	 */
	public function test_an_event_that_has_gone_missing_comes_back(): void {
		Schedule::ensure();
		wp_clear_scheduled_hook( Schedule::HOOK );

		Schedule::ensure();

		$this->assertNotNull( Schedule::next_run(), 'A lost event has to heal without anybody noticing it was lost.' );
	}

	/**
	 * And it heals on an ordinary page load, without an administrator visiting
	 * anything or the plugin being reactivated.
	 */
	public function test_every_request_checks_the_event_is_still_there(): void {
		$this->assertNotFalse( has_action( 'init', array( Schedule::class, 'ensure' ) ) );
	}

	public function test_a_cinema_can_ask_for_a_different_interval(): void {
		add_filter( 'veezi_sync_recurrence', static fn (): string => 'twicedaily' );

		Schedule::ensure();

		$this->assertSame( 'twicedaily', wp_get_scheduled_event( Schedule::HOOK )->schedule );
	}

	public function test_changing_the_interval_moves_the_event_already_scheduled(): void {
		Schedule::ensure();

		add_filter( 'veezi_sync_recurrence', static fn (): string => 'twicedaily' );
		Schedule::ensure();

		$this->assertSame( 'twicedaily', wp_get_scheduled_event( Schedule::HOOK )->schedule );
	}

	/**
	 * WordPress refuses an interval it has never heard of, so taking the filter
	 * at its word would clear the existing event and fail to replace it —
	 * leaving a site that never syncs again over a typo.
	 */
	public function test_an_interval_wordpress_does_not_know_leaves_the_site_scheduled_anyway(): void {
		add_filter( 'veezi_sync_recurrence', static fn (): string => 'occasionally' );

		Schedule::ensure();

		$event = wp_get_scheduled_event( Schedule::HOOK );

		$this->assertNotFalse( $event );
		$this->assertArrayHasKey( $event->schedule, wp_get_schedules() );
	}

	public function test_deactivating_takes_the_sync_off_the_schedule(): void {
		Schedule::ensure();

		Plugin::deactivate();

		$this->assertNull( Schedule::next_run() );
	}

	/**
	 * The end of the wire: whatever the host's cron does, what it reaches is a
	 * sync that publishes a programme.
	 */
	public function test_the_scheduled_event_syncs_the_programme(): void {
		$this->store_token( self::TOKEN );
		$this->arrange_programme(
			array( $this->session_payload( array( 'starts' => '2036-08-02T16:30:00' ) ) ),
			array( $this->film_payload() )
		);

		do_action( Schedule::HOOK );

		$this->assertCount( 1, $this->records( ContentModel::FILM ) );
	}
}
