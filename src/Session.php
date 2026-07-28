<?php
/**
 * A screening, as Veezi describes it.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * One screening: a film, a time, and whether you can buy a ticket for it.
 *
 * The times are the point of this class. Veezi sends `"2026-08-02T16:30:00"`
 * with no offset — a wall-clock reading, not a moment — so somebody has to say
 * which clock it was read from. That happens here, once, using the cinema's own
 * timezone, and everything downstream deals in real instants.
 *
 * What is *not* here matters as much: seats sold, seats available, seats held
 * and the price card all arrive in the same payload and are dropped on the
 * floor. Only the two badges a visitor needs survive.
 */
final class Session {

	private const ON_SALE = 'Open';
	private const PLANNED = 'Planned';

	public function __construct(
		public readonly int $id,
		public readonly string $film_id,
		public readonly string $title,
		public readonly DateTimeImmutable $starts_at,
		public readonly DateTimeImmutable $ends_at,
		public readonly bool $on_sale,
		public readonly string $booking_url,
		public readonly bool $sold_out,
		public readonly bool $few_tickets_left
	) {}

	/**
	 * @param array<string,mixed> $payload     One entry from `/v1/session`.
	 * @param DateTimeZone        $zone        The cinema's timezone.
	 * @param string              $booking_url From the web-session feed, which
	 *                                         is the only place a booking link
	 *                                         exists. Empty for a session that
	 *                                         is not on sale yet.
	 */
	public static function from_payload( array $payload, DateTimeZone $zone, string $booking_url ): ?self {
		$id     = isset( $payload['Id'] ) ? (int) $payload['Id'] : 0;
		$status = isset( $payload['Status'] ) ? (string) $payload['Status'] : '';

		if ( $id <= 0 ) {
			return null;
		}

		// A status the plugin has never met is not assumed to be safe to
		// publish. Better an administrator asks why something is missing than a
		// cancelled screening quietly stays on sale.
		if ( self::ON_SALE !== $status && self::PLANNED !== $status ) {
			return null;
		}

		// Private hires and staff screenings are real sessions that no visitor
		// should be offered. Veezi's own web feed filters them out; the
		// unfiltered feed does not, so this does.
		if ( 'Public' !== ( $payload['ShowType'] ?? 'Public' ) ) {
			return null;
		}

		$starts_at = self::moment( $payload['PreShowStartTime'] ?? '', $zone );
		$ends_at   = self::moment( $payload['FeatureEndTime'] ?? '', $zone );

		if ( null === $starts_at || null === $ends_at ) {
			return null;
		}

		return new self(
			$id,
			isset( $payload['FilmId'] ) ? trim( (string) $payload['FilmId'] ) : '',
			isset( $payload['Title'] ) ? trim( (string) $payload['Title'] ) : '',
			$starts_at,
			$ends_at,
			self::ON_SALE === $status,
			$booking_url,
			! empty( $payload['TicketsSoldOut'] ),
			! empty( $payload['FewTicketsLeft'] )
		);
	}

	/**
	 * Turn one of Veezi's offsetless datetimes into an actual instant.
	 *
	 * @param mixed        $value A naive `Y-m-d\TH:i:s` string, in theory.
	 * @param DateTimeZone $zone  The clock it was read from.
	 */
	private static function moment( mixed $value, DateTimeZone $zone ): ?DateTimeImmutable {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		// The leading `!` clears every field the format does not set. Without it
		// the microseconds come from the clock the sync happened to run on, so
		// two identical payloads produce times that do not compare equal.
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s', $value, $zone );

		return false === $parsed ? null : $parsed;
	}
}
