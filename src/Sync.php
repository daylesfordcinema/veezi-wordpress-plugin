<?php
/**
 * The sync entry point.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

use DateTimeImmutable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the cinema's programme from Veezi and publishes it as WordPress
 * content.
 *
 * The order of operations is the whole design. Authenticate first, which also
 * settles whose programme this is and which timezone its showtimes are read in.
 * Then fetch all three feeds, and only once every one of them has arrived
 * intact, write anything. A cinema's website going blank because the ticketing
 * provider had a bad minute is the failure this shape exists to prevent: if any
 * part of the fetch fails the run stops, and whatever synced last is still on
 * the site.
 *
 * Every date decision a sync makes is a decision about *now* — which sessions
 * have already started, how far ahead to look, which past records to remove —
 * so the current time is an argument. It is a plain optional parameter rather
 * than an injected clock interface because there is exactly one thing to vary.
 */
final class Sync {

	public function __construct( private readonly Client $client ) {}

	public function run( ?DateTimeImmutable $now = null ): SyncResult {
		$now ??= self::now();

		$connection = $this->client->check_connection();

		if ( ! $connection->is_success() ) {
			return SyncResult::failed( $now, $connection->message() );
		}

		$feeds = $this->fetch();

		if ( is_wp_error( $feeds ) ) {
			return SyncResult::failed( $now, $feeds->get_error_message() );
		}

		$zone = CinemaTimezone::resolve( $connection->timezone_identifier() );

		$programme = Programme::assemble( $feeds['sessions'], $feeds['web_sessions'], $feeds['films'], $zone, $now );

		( new Repository( $zone ) )->store( $programme );

		return SyncResult::completed(
			$now,
			self::summary( count( $programme->films() ), count( $programme->sessions() ), $connection->site_name() )
		);
	}

	/**
	 * All three feeds, or the first reason one of them could not be had.
	 *
	 * Fetched together and up front so that nothing is written on the strength
	 * of a partial answer.
	 *
	 * @return array{sessions:array<mixed>,web_sessions:array<mixed>,films:array<mixed>}|WP_Error
	 */
	private function fetch() {
		$endpoints = array(
			'sessions'     => Client::SESSIONS,
			'web_sessions' => Client::WEB_SESSIONS,
			'films'        => Client::FILMS,
		);

		$feeds = array();

		foreach ( $endpoints as $name => $path ) {
			$feed = $this->client->get( $path );

			if ( is_wp_error( $feed ) ) {
				return $feed;
			}

			$feeds[ $name ] = $feed;
		}

		return $feeds;
	}

	private static function summary( int $films, int $sessions, string $cinema ): string {
		return sprintf(
			/* translators: 1: film count with its noun, e.g. "3 films". 2: session count with its noun, e.g. "12 sessions". 3: the cinema's name. */
			__( 'Synced %1$s and %2$s from %3$s.', 'veezi-wordpress-plugin' ),
			sprintf(
				/* translators: %s: a number of films. */
				_n( '%s film', '%s films', $films, 'veezi-wordpress-plugin' ),
				number_format_i18n( $films )
			),
			sprintf(
				/* translators: %s: a number of sessions. */
				_n( '%s session', '%s sessions', $sessions, 'veezi-wordpress-plugin' ),
				number_format_i18n( $sessions )
			),
			$cinema
		);
	}

	/**
	 * The real clock, in UTC.
	 *
	 * Deliberately not the site's timezone. Veezi reports times with no offset
	 * at all, so the cinema's zone has to be applied explicitly wherever those
	 * are read; starting from an unambiguous instant means a site whose
	 * timezone is unset or wrong cannot quietly shift the sync's idea of the
	 * present.
	 */
	public static function now(): DateTimeImmutable {
		return new DateTimeImmutable( '@' . time() );
	}
}
