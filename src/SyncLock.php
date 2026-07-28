<?php
/**
 * The guarantee that only one sync runs at a time.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * A lock held for the length of one sync run.
 *
 * Two runs overlapping is not a theoretical worry: a first sync has a poster to
 * download per film and can easily outlast the gap between two cron firings,
 * and an administrator pressing "Sync now" while one is already going is one
 * click. Both would have two processes writing the same records and fetching
 * the same artwork.
 *
 * Every step is a single statement the database settles, because the options
 * API reads before it writes and two processes can both pass that read. Taking
 * the lock is `INSERT IGNORE`, which the unique index on `option_name` decides;
 * this is the pattern WordPress's own updater uses, for the same reason.
 * Clearing one — whether an abandoned lock or your own at the end of a run — is
 * a `DELETE` conditional on the value you saw, so that of two runs looking at
 * the same abandoned lock exactly one clears it and goes on to claim it, and so
 * that a run which comes back from the dead cannot free its successor's.
 */
final class SyncLock {

	public const OPTION = 'veezi_sync_lock';

	/**
	 * After this, a lock is treated as abandoned rather than held.
	 *
	 * A run killed halfway — a PHP timeout, a container restarted mid-sync —
	 * never reaches its release. Without an expiry that site never syncs again,
	 * and the only symptom is a programme that quietly stops changing, which is
	 * the hardest kind of failure to notice. Generous enough that a genuinely
	 * slow first run is not mistaken for a dead one.
	 */
	public const EXPIRES_AFTER = 15 * MINUTE_IN_SECONDS;

	private bool $held = true;

	private function __construct( private readonly int $taken_at ) {}

	/**
	 * The lock, or null if somebody else has it.
	 *
	 * Whether a lock counts as abandoned is a question about now, so now is an
	 * argument — the same seam, and for the same reason, as the sync's own
	 * clock. It is also the only way to hold a lock that was genuinely taken
	 * fifteen minutes ago, which is the state the interesting case starts from.
	 *
	 * @param int|null $now The moment to judge the lock by. Defaults to the
	 *                      real clock.
	 */
	public static function acquire( ?int $now = null ): ?self {
		$now ??= time();

		if ( self::claim( $now ) ) {
			return new self( $now );
		}

		$held_since = (int) get_option( self::OPTION, 0 );

		if ( $held_since > $now - self::EXPIRES_AFTER ) {
			return null;
		}

		// Conditional on the abandoned value this run read, so that of two runs
		// finding the same dead lock, only the one whose delete matched goes on
		// to claim it. The loser is turned away rather than joining in.
		if ( ! self::discard( $held_since ) ) {
			return null;
		}

		return self::claim( $now ) ? new self( $now ) : null;
	}

	/**
	 * Hand it back — but only if it is still ours.
	 *
	 * A run declared abandoned and superseded can still reach this line, and an
	 * unconditional delete would free the lock its successor is holding.
	 */
	public function release(): void {
		if ( ! $this->held ) {
			return;
		}

		$this->held = false;

		self::discard( $this->taken_at );
	}

	/**
	 * Drop the lock whoever holds it. For uninstalling, when there is nothing
	 * left to protect.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
	}

	private static function claim( int $taken_at ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$won = (bool) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$wpdb->options}` ( `option_name`, `option_value`, `autoload` ) VALUES ( %s, %s, 'no' )",
				self::OPTION,
				(string) $taken_at
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $won ) {
			return false;
		}

		// That row went in behind the options API's back, so anything holding a
		// cached copy of this option — a persistent object cache, above all —
		// still believes there is none. Writing it again through the front door
		// brings the two into line, and settles the autoload value with it.
		update_option( self::OPTION, $taken_at, false );

		return true;
	}

	/**
	 * Remove the lock, if it still holds the value the caller saw.
	 *
	 * @param  int $expected The value read before deciding to remove it.
	 * @return bool Whether this call was the one that removed it.
	 */
	private static function discard( int $expected ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed = (bool) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` = %s",
				self::OPTION,
				(string) $expected
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $removed ) {
			// Deleted behind the options API's back, so the cached copy has to
			// go the same way the insert's did. `delete_option()` is no help:
			// it looks the row up first and returns early now that it is gone.
			wp_cache_delete( self::OPTION, 'options' );
		}

		return $removed;
	}
}
