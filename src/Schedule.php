<?php
/**
 * When the programme refreshes itself.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * The recurring event that keeps the website following the ticketing system.
 *
 * WordPress's cron rather than anything of the plugin's own, because that is
 * what a host can drive from outside. This cinema's server does exactly that:
 * request-triggered cron is switched off and the platform runs the queue on a
 * real schedule, which is both more dependable than waiting for a visitor and
 * the reason the plugin must never try to spawn a run itself.
 *
 * The event heals. It is put in place on activation, and checked again on every
 * request, because the ways it can vanish are ordinary ones — a database
 * restored from before the plugin existed, or an update applied in place, which
 * reactivates silently and so skips the activation hook. A site whose event has
 * gone shows no error at all: the programme simply stops changing, which is the
 * hardest kind of failure to notice.
 */
final class Schedule {

	/** The action a due event fires. Also what `wp cron event list` shows. */
	public const HOOK = 'veezi_sync';

	/**
	 * Hourly, which is more often than a cinema's programme changes and cheap
	 * enough not to matter: three small JSON reads, and artwork only for a film
	 * whose poster is new.
	 */
	public const RECURRENCE = 'hourly';

	public static function ensure(): void {
		$wanted    = self::recurrence();
		$scheduled = wp_get_scheduled_event( self::HOOK );

		if ( false !== $scheduled && $scheduled->schedule === $wanted ) {
			return;
		}

		self::clear();

		// From now, so that a site which has just been activated — or has just
		// lost its event — gets a programme at the next run of the queue rather
		// than an hour after it.
		wp_schedule_event( time(), $wanted, self::HOOK );
	}

	public static function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * When the next sync is due, as an epoch second, or null if none is.
	 */
	public static function next_run(): ?int {
		$next = wp_next_scheduled( self::HOOK );

		return false === $next ? null : (int) $next;
	}

	/**
	 * How often to sync, checked against the intervals WordPress actually has.
	 *
	 * Checked because `wp_schedule_event()` refuses a name it does not know:
	 * taking the filter at its word would clear the existing event and fail to
	 * replace it, leaving a site that never syncs again over a typo.
	 */
	private static function recurrence(): string {
		/**
		 * Filters how often the programme is synced.
		 *
		 * @param string $recurrence The name of a WordPress cron schedule.
		 */
		$wanted = (string) apply_filters( 'veezi_sync_recurrence', self::RECURRENCE );

		$known = wp_get_schedules();

		return isset( $known[ $wanted ] ) ? $wanted : self::RECURRENCE;
	}
}
