<?php
/**
 * Calendar-day limits for film listings.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps both listing settings on the same calendar-day boundaries.
 */
final class ListingHorizon {

	public const MOST_DAYS = 365;

	/**
	 * @param mixed $days A stored or submitted setting. Zero means today.
	 */
	public static function days_within_reason( mixed $days ): ?int {
		return is_numeric( $days ) ? max( 0, min( self::MOST_DAYS, (int) $days ) ) : null;
	}

	/**
	 * The last eligible start time, in the cinema's timezone.
	 *
	 * @param int|null          $days Calendar days ahead, or null for no cutoff.
	 * @param DateTimeImmutable $now  When the sync is running.
	 * @param DateTimeZone      $zone The cinema's timezone.
	 */
	public static function cutoff( ?int $days, DateTimeImmutable $now, DateTimeZone $zone ): ?DateTimeImmutable {
		if ( null === $days ) {
			return null;
		}

		return $now->setTimezone( $zone )
			->modify( sprintf( '+%d days', $days ) )
			->setTime( 23, 59, 59 );
	}
}
