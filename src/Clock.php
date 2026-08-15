<?php
/**
 * The moment a page load happens at.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * What the front end treats as now.
 *
 * The sync has always taken its present as an argument — {@see Sync::attempt()}
 * — because what it decides depends entirely on when it runs, and a decision
 * that reads a hidden global is one nothing can check. The two places that
 * decide whether a screening has begun read the clock directly instead, and
 * that asymmetry is a real cost rather than an untidiness: it is why the test
 * suite was only correct while the wall clock sat inside the window its
 * fixtures were written for, and why it began failing on a date nobody chose.
 *
 * So the render side gets the same seam, in the form WordPress already has for
 * it. Both callers floor or compare rather than format, so this is seconds
 * rather than a `DateTimeImmutable`: the timezone question belongs to whoever
 * is printing, and {@see CinemaTimezone} answers it there.
 */
final class Clock {

	/**
	 * Now, as an epoch second.
	 */
	public static function now(): int {
		/**
		 * Filters the moment the front end treats as the present.
		 *
		 * Whether a screening has started is the only thing that turns on it, so
		 * moving this shows a listing as it will be rather than as it is —
		 * which is what the test suite uses it for, and what previewing a
		 * Friday evening on a Tuesday would need.
		 *
		 * @param int $now An epoch second.
		 */
		return (int) apply_filters( 'veezi_now', time() );
	}
}
