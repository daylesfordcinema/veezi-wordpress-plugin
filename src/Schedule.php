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
	 * Every quarter of an hour.
	 *
	 * More often than a cinema's programme changes, which is the point: what
	 * matters is not how often the answer moves but how long the website is
	 * wrong once it has. A session added at the box office, a cancellation, a
	 * season going on sale — each of those is a visitor being told something
	 * untrue until the next run, and an hour of that is a long time on a Friday
	 * evening. It stays cheap at this rate: three small JSON reads, and artwork
	 * only for a film whose poster is new.
	 *
	 * **Not one of WordPress's own** — it offers hourly and nothing shorter — so
	 * the plugin adds it, {@see self::add_interval()}. That makes the name
	 * something this plugin has to keep on the list rather than something it can
	 * assume, which is why {@see self::recurrence()} has a second fallback now.
	 */
	public const RECURRENCE = 'veezi_quarter_hour';

	/**
	 * How long that is, in seconds.
	 */
	public const EVERY = 15 * MINUTE_IN_SECONDS;

	/**
	 * Where to go if even our own interval is not on the list.
	 *
	 * One of WordPress's own, so it cannot fail the same way twice. A site whose
	 * `cron_schedules` has been filtered down to the built-ins syncs an hour at a
	 * time, which is what it used to do, rather than not at all.
	 */
	private const FALLBACK = 'hourly';

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
	 * Put the plugin's own interval on WordPress's list.
	 *
	 * WordPress ships hourly, twicedaily, daily and weekly, and a name it does
	 * not know is refused — so a quarter-hour sync means adding one.
	 *
	 * @param  mixed $schedules WordPress's own, plus whatever else has been added.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function add_interval( mixed $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();

		$schedules[ self::RECURRENCE ] = array(
			'interval' => self::EVERY,
			'display'  => __( 'Every 15 minutes (Veezi)', 'veezi-wordpress-plugin' ),
		);

		return $schedules;
	}

	/**
	 * How often to sync, checked against the intervals WordPress actually has.
	 *
	 * Checked because `wp_schedule_event()` refuses a name it does not know:
	 * taking the filter at its word would clear the existing event and fail to
	 * replace it, leaving a site that never syncs again over a typo.
	 *
	 * Two fallbacks rather than one, because the default is now a name the plugin
	 * puts on the list itself. Returning it unchecked would reintroduce exactly
	 * the failure above on any site whose `cron_schedules` no longer carries it.
	 */
	private static function recurrence(): string {
		/**
		 * Filters how often the programme is synced.
		 *
		 * @param string $recurrence The name of a WordPress cron schedule.
		 */
		$wanted = (string) apply_filters( 'veezi_sync_recurrence', self::RECURRENCE );

		$known = wp_get_schedules();

		if ( isset( $known[ $wanted ] ) ) {
			return $wanted;
		}

		return isset( $known[ self::RECURRENCE ] ) ? self::RECURRENCE : self::FALLBACK;
	}
}
