<?php
/**
 * What the last sync run did, and when.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * The site's memory of its own syncing.
 *
 * Two records rather than one, because an administrator asks two questions from
 * opposite directions and a single "last run" answers neither well. *Can I
 * trust what is on the site* is answered by the last run that worked, and stays
 * answered right through an outage — the programme on the page is exactly what
 * that run put there. *Is something wrong* is answered by a failure that has
 * not been recovered from, and has to put itself away when it is: nothing
 * dismisses the notice it raises except the next run that works.
 */
final class SyncLog {

	/**
	 * The last run that got all the way through.
	 *
	 * Written only after every feed has arrived and been stored, so its absence
	 * means precisely one thing: this site has never had a programme. That is
	 * not the same question as "are there any screenings" — a cinema between
	 * seasons has none and is working perfectly — and telling the two apart is
	 * what stops a widget accusing a correctly configured site of being broken.
	 */
	public const LAST_SUCCESS = 'veezi_last_sync';

	/** A failure nothing has recovered from yet. Deleted by the next success. */
	public const LAST_FAILURE = 'veezi_last_failure';

	public static function record( SyncResult $result ): void {
		if ( $result->is_success() ) {
			update_option( self::LAST_SUCCESS, $result->to_array() );
			delete_option( self::LAST_FAILURE );

			return;
		}

		// Deliberately not autoloaded: this is read on admin screens, and on the
		// overwhelming majority of requests there is nothing here to read.
		update_option( self::LAST_FAILURE, $result->to_array(), false );

		self::write_to_the_log( $result->message() );
	}

	public static function last_success(): ?SyncResult {
		return self::read( self::LAST_SUCCESS );
	}

	public static function unresolved_failure(): ?SyncResult {
		return self::read( self::LAST_FAILURE );
	}

	public static function has_ever_succeeded(): bool {
		return null !== self::last_success();
	}

	public static function forget(): void {
		delete_option( self::LAST_SUCCESS );
		delete_option( self::LAST_FAILURE );
	}

	private static function read( string $option ): ?SyncResult {
		$stored = get_option( $option, null );

		return is_array( $stored ) ? SyncResult::from_array( $stored ) : null;
	}

	/**
	 * Leave a line somewhere the person who has to fix this will find it.
	 *
	 * Failures only. A cinema's programme syncs every hour of every day, and a
	 * line per run would bury the one that matters under thousands that do not.
	 *
	 * Ungated by `WP_DEBUG`, which is off on a production site — the one place a
	 * record of this actually matters. Where the line ends up is then the
	 * server's decision, as it should be: a host that logs nowhere gets nothing,
	 * and nothing here is written into the page a visitor is looking at.
	 *
	 * @param string $message Already stripped of the access token by the client.
	 */
	private static function write_to_the_log( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The point: a failure has to be diagnosable from the server's log.
		error_log( 'Veezi: sync failed. ' . $message );
	}
}
