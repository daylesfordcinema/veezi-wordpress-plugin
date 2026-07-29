<?php
/**
 * How far ahead the cinema is willing to talk about.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * The two settings behind coming-soon publication, and the date they work out.
 *
 * Veezi's unfiltered session feed carries next month's programme as well as
 * this week's, and the two are not the same kind of thing. A planned session is
 * scheduled but not on sale: nobody can buy a ticket for it, the cinema may not
 * have announced it, and its time can still move. Publishing that is a decision
 * for whoever runs the cinema rather than a default, which is why this is off
 * until somebody turns it on and reaches only as far as they say.
 *
 * The horizon is counted in the **cinema's** days rather than in hours from
 * now. "A fortnight" said out loud means through the end of the fourteenth day,
 * not until whatever time of day the sync happened to run — and a listing that
 * grew by one screening every time the hour came round would be impossible for
 * anyone to reason about.
 */
final class ComingSoon {

	/**
	 * Long enough to advertise the next fortnight, which is what a cinema
	 * announcing a season is usually doing, and short enough that turning this
	 * on does not publish a quarter's programming in one go.
	 */
	public const DEFAULT_DAYS = 14;

	/**
	 * A year. Not a limit anybody should meet — it is here so that a mistyped
	 * horizon is a bounded mistake rather than one that publishes whatever
	 * Veezi happens to hold.
	 */
	public const MOST_DAYS = 365;

	private function __construct(
		private readonly bool $enabled,
		private readonly int $days
	) {}

	public static function from( Settings $settings ): self {
		return new self( $settings->coming_soon(), $settings->coming_soon_days() );
	}

	/**
	 * A number of days, made sane.
	 *
	 * Zero is meaningful — the rest of today and nothing else — so the floor is
	 * zero rather than one, and anything that is not a number at all falls back
	 * to the default rather than to zero, which would silently publish nothing.
	 *
	 * @param mixed $days Whatever was submitted or stored.
	 */
	public static function days_within_reason( mixed $days ): int {
		if ( ! is_numeric( $days ) ) {
			return self::DEFAULT_DAYS;
		}

		return max( 0, min( self::MOST_DAYS, (int) $days ) );
	}

	/**
	 * The last moment a planned screening may start at and still be published,
	 * or null when the cinema has not asked for any of this.
	 *
	 * Null rather than a date in the past, so that "switched off" is a state
	 * every caller has to answer for rather than one that happens to fall out
	 * of a comparison.
	 *
	 * @param DateTimeImmutable $now  The moment the sync is running at.
	 * @param DateTimeZone      $zone The cinema's timezone, which is whose days
	 *                                these are.
	 */
	public function horizon( DateTimeImmutable $now, DateTimeZone $zone ): ?DateTimeImmutable {
		if ( ! $this->enabled ) {
			return null;
		}

		return $now->setTimezone( $zone )
			->modify( sprintf( '+%d days', $this->days ) )
			->setTime( 23, 59, 59 );
	}
}
