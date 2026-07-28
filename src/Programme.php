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
 *
 * A programme is **what is still to come**. The sync's idea of the present is
 * applied once, here, and a screening that has finished is simply not in it —
 * which is what lets everything downstream stop asking what time it is. It is
 * also how past screenings come to be deleted rather than filtered out: a
 * listing can be "the next six" with no date filter, and the page builder could
 * not express one anyway.
 *
 * Both lists come out in the order they should be shown in: sessions
 * chronologically, films by when they next screen.
 */
final class Programme {

	/**
	 * How many screenings each film has left.
	 *
	 * @var array<string,int>
	 */
	private readonly array $remaining;

	/**
	 * When each film next screens.
	 *
	 * @var array<string,DateTimeImmutable>
	 */
	private readonly array $soonest;

	/**
	 * Which films still have something on sale.
	 *
	 * @var array<string,bool>
	 */
	private readonly array $selling;

	/**
	 * @var array<string,Film>
	 */
	private readonly array $films;

	/**
	 * @param array<string,Film> $films    Keyed by their Veezi identifier.
	 * @param array<int,Session> $sessions Keyed by their Veezi identifier, soonest first.
	 */
	private function __construct(
		array $films,
		private readonly array $sessions
	) {
		$remaining = array();
		$soonest   = array();
		$selling   = array();

		// Answered by looking at every session rather than by trusting the one
		// that happens to be first: the sessions do arrive sorted, but a fact
		// about a film should not quietly depend on that staying true.
		foreach ( $sessions as $session ) {
			$film_id = $session->film_id;

			$remaining[ $film_id ] = ( $remaining[ $film_id ] ?? 0 ) + 1;
			$selling[ $film_id ]   = ( $selling[ $film_id ] ?? false ) || $session->on_sale;

			if ( ! isset( $soonest[ $film_id ] ) || $session->starts_at < $soonest[ $film_id ] ) {
				$soonest[ $film_id ] = $session->starts_at;
			}
		}

		$this->remaining = $remaining;
		$this->soonest   = $soonest;
		$this->selling   = $selling;

		// Every film here has at least one session — that is what put it here.
		uasort(
			$films,
			static function ( Film $a, Film $b ) use ( $soonest ): int {
				$order = $soonest[ $a->id ] <=> $soonest[ $b->id ];

				// Two films can start at the same minute on different screens.
				// Falling back to the identifier keeps the order stable rather
				// than letting it depend on the sort implementation.
				return 0 === $order ? strcmp( $a->id, $b->id ) : $order;
			}
		);

		$this->films = $films;
	}

	/**
	 * @param array<int,mixed>  $sessions     Whatever `/v1/session` returned.
	 * @param array<int,mixed>  $web_sessions Whatever `/v1/websession` returned.
	 * @param array<int,mixed>  $films        Whatever `/v4/film` returned.
	 * @param DateTimeZone      $zone         The cinema's timezone.
	 * @param DateTimeImmutable $now          The moment the sync is running at.
	 */
	public static function assemble( array $sessions, array $web_sessions, array $films, DateTimeZone $zone, DateTimeImmutable $now ): self {
		$booking_urls = self::booking_urls( $web_sessions );

		$assembled = array();

		foreach ( $sessions as $payload ) {
			if ( ! is_array( $payload ) ) {
				continue;
			}

			$id      = isset( $payload['Id'] ) ? (int) $payload['Id'] : 0;
			$session = Session::from_payload( $payload, $zone, $booking_urls[ $id ] ?? '' );

			// Until it finishes, not until it starts: somebody arriving late
			// still wants to see that it is on, and a screening should not
			// disappear from the website while there is an audience sitting in
			// it. This is the latest a screening can survive rather than a
			// promise of the earliest — anything Veezi stops listing goes on the
			// next sync whatever its time says, because that is also how a
			// cancelled screening stops being sold, and the two look identical
			// from here.
			if ( null !== $session && $session->ends_at >= $now ) {
				$assembled[ $session->id ] = $session;
			}
		}

		uasort(
			$assembled,
			static function ( Session $a, Session $b ): int {
				$order = $a->starts_at <=> $b->starts_at;

				return 0 === $order ? $a->id <=> $b->id : $order;
			}
		);

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
	 * Whether a visitor can buy a ticket for this film.
	 *
	 * One question, answering two: whether the film's page is published, since a
	 * film known only from planned sessions is programming the cinema may not
	 * have announced; and whether it belongs in the current listing. Those two
	 * had separate answers while the programme still held screenings that had
	 * been and gone. It does not, so they do not.
	 *
	 * @param string $film_id Its Veezi identifier.
	 */
	public function is_on_sale( string $film_id ): bool {
		return $this->selling[ $film_id ] ?? false;
	}

	/**
	 * When this film next screens, whether or not it is selling yet.
	 *
	 * Kept on the film record so a listing can show it without asking a second
	 * question of the database for every row. A screening already under way
	 * counts as the next one, which is both true and what the film's rank says.
	 *
	 * @param string $film_id Its Veezi identifier.
	 */
	public function next_screening( string $film_id ): ?DateTimeImmutable {
		return $this->soonest[ $film_id ] ?? null;
	}

	/**
	 * How many screenings of this film are still to come.
	 *
	 * @param string $film_id Its Veezi identifier.
	 */
	public function session_count( string $film_id ): int {
		return $this->remaining[ $film_id ] ?? 0;
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
