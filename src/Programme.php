<?php
/**
 * The cinema's programme, assembled from three separate feeds.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * What is showing, joined together from what Veezi will tell us.
 *
 * It takes three calls because no single one of them answers the question.
 * `/v1/session` is the only feed carrying sessions that are scheduled but not
 * yet on sale, and it has no booking link in it at all; `/v1/websession` has
 * the booking links but drops everything not yet selling; `/v4/film` has the
 * artwork and the synopsis but no idea what is scheduled.
 *
 * The join therefore runs one way only: sessions are the spine, and a film
 * enters the programme because something is scheduled for it. The catalogue is
 * never the source of the listing — it holds every film the cinema has ever
 * loaded, test rows included, and each one of them reports itself as active.
 */
final class Programme {

	/**
	 * The same sessions again, gathered under the film they belong to.
	 *
	 * @var array<string,array<int,Session>>
	 */
	private readonly array $by_film;

	/**
	 * @param array<string,Film> $films    Keyed by their Veezi identifier.
	 * @param array<int,Session> $sessions Keyed by their Veezi identifier.
	 */
	private function __construct(
		private readonly array $films,
		private readonly array $sessions
	) {
		$by_film = array();

		foreach ( $sessions as $session ) {
			$by_film[ $session->film_id ][] = $session;
		}

		$this->by_film = $by_film;
	}

	/**
	 * @param array<int,mixed> $sessions     Whatever `/v1/session` returned.
	 * @param array<int,mixed> $web_sessions Whatever `/v1/websession` returned.
	 * @param array<int,mixed> $films        Whatever `/v4/film` returned.
	 * @param DateTimeZone     $zone         The cinema's timezone.
	 */
	public static function assemble( array $sessions, array $web_sessions, array $films, DateTimeZone $zone ): self {
		$booking_urls = self::booking_urls( $web_sessions );

		$assembled = array();

		foreach ( $sessions as $payload ) {
			if ( ! is_array( $payload ) ) {
				continue;
			}

			$id      = isset( $payload['Id'] ) ? (int) $payload['Id'] : 0;
			$session = Session::from_payload( $payload, $zone, $booking_urls[ $id ] ?? '' );

			if ( null !== $session ) {
				$assembled[ $session->id ] = $session;
			}
		}

		return new self( self::scheduled_films( $films, $assembled ), $assembled );
	}

	/**
	 * @return array<string,Film>
	 */
	public function films(): array {
		return $this->films;
	}

	/**
	 * @return array<int,Session>
	 */
	public function sessions(): array {
		return $this->sessions;
	}

	/**
	 * Whether tickets for this film are on sale at all — the question that
	 * decides whether its page is published, since a film known only from
	 * planned sessions is programming the cinema may not have announced.
	 *
	 * @param string $film_id Its Veezi identifier.
	 */
	public function is_on_sale( string $film_id ): bool {
		return null !== $this->next_on_sale( $film_id, null );
	}

	/**
	 * Whether there is still a ticket to sell — the question that decides
	 * whether the film belongs in the current listing. A season that has
	 * finished drops out of it; the film's own page stays.
	 *
	 * @param string            $film_id Its Veezi identifier.
	 * @param DateTimeImmutable $now     The moment the sync is running at.
	 */
	public function is_showing( string $film_id, DateTimeImmutable $now ): bool {
		return null !== $this->next_on_sale( $film_id, $now );
	}

	/**
	 * When this film next screens, whether or not it is selling yet.
	 *
	 * Kept on the film record so a listing can be ordered by next screening
	 * without asking a second question of the database for every row.
	 *
	 * @param string            $film_id Its Veezi identifier.
	 * @param DateTimeImmutable $now     The moment the sync is running at.
	 */
	public function next_screening( string $film_id, DateTimeImmutable $now ): ?DateTimeImmutable {
		$next = null;

		foreach ( $this->by_film[ $film_id ] ?? array() as $session ) {
			if ( $session->starts_at < $now ) {
				continue;
			}

			if ( null === $next || $session->starts_at < $next ) {
				$next = $session->starts_at;
			}
		}

		return $next;
	}

	/**
	 * @param string $film_id Its Veezi identifier.
	 */
	public function session_count( string $film_id ): int {
		return count( $this->by_film[ $film_id ] ?? array() );
	}

	/**
	 * The soonest session of this film that is actually selling.
	 *
	 * @param string                 $film_id Its Veezi identifier.
	 * @param DateTimeImmutable|null $from    Ignore anything earlier than this;
	 *                                        null to consider the lot.
	 */
	private function next_on_sale( string $film_id, ?DateTimeImmutable $from ): ?Session {
		$next = null;

		foreach ( $this->by_film[ $film_id ] ?? array() as $session ) {
			if ( ! $session->on_sale ) {
				continue;
			}

			if ( null !== $from && $session->starts_at < $from ) {
				continue;
			}

			if ( null === $next || $session->starts_at < $next->starts_at ) {
				$next = $session;
			}
		}

		return $next;
	}

	/**
	 * @param  array<int,mixed> $web_sessions Whatever `/v1/websession` returned.
	 * @return array<int,string> Booking link by session identifier.
	 */
	private static function booking_urls( array $web_sessions ): array {
		$urls = array();

		foreach ( $web_sessions as $payload ) {
			if ( ! is_array( $payload ) || empty( $payload['Id'] ) || empty( $payload['Url'] ) ) {
				continue;
			}

			$urls[ (int) $payload['Id'] ] = (string) $payload['Url'];
		}

		return $urls;
	}

	/**
	 * The catalogue, narrowed to the films something is actually scheduled for.
	 *
	 * @param  array<int,mixed>   $films    Whatever `/v4/film` returned.
	 * @param  array<int,Session> $sessions The sessions that survived parsing.
	 * @return array<string,Film>
	 */
	private static function scheduled_films( array $films, array $sessions ): array {
		$scheduled = array();

		foreach ( $sessions as $session ) {
			$scheduled[ $session->film_id ] = true;
		}

		$assembled = array();

		foreach ( $films as $payload ) {
			if ( ! is_array( $payload ) ) {
				continue;
			}

			$film = Film::from_payload( $payload );

			if ( null !== $film && isset( $scheduled[ $film->id ] ) ) {
				$assembled[ $film->id ] = $film;
			}
		}

		return $assembled;
	}
}
