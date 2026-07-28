<?php
/**
 * A film, as Veezi describes it.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * One entry from the film catalogue, reduced to what a website needs.
 *
 * Deliberately a plain record with no behaviour and no WordPress in it: this is
 * the boundary where Veezi's vocabulary stops and the plugin's begins, and
 * keeping it dumb means the awkwardness of the upstream shape — a genre list
 * that arrives as one comma-separated string, a release date that arrives as a
 * midnight timestamp — is dealt with once, here.
 *
 * `$genres` is the one field that is not a scalar: a list of names, one term
 * each, already split and tidied.
 */
final class Film {

	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly string $synopsis,
		public readonly array $genres,
		public readonly string $classification,
		public readonly int $runtime,
		public readonly string $distributor,
		public readonly string $released_on,
		public readonly string $trailer_url
	) {}

	/**
	 * @param array<string,mixed> $payload One entry from `/v4/film`.
	 */
	public static function from_payload( array $payload ): ?self {
		$id    = isset( $payload['Id'] ) ? trim( (string) $payload['Id'] ) : '';
		$title = isset( $payload['Title'] ) ? trim( (string) $payload['Title'] ) : '';

		if ( '' === $id || '' === $title ) {
			return null;
		}

		return new self(
			$id,
			$title,
			isset( $payload['Synopsis'] ) ? trim( (string) $payload['Synopsis'] ) : '',
			self::genres( isset( $payload['Genre'] ) ? (string) $payload['Genre'] : '' ),
			isset( $payload['Rating'] ) ? trim( (string) $payload['Rating'] ) : '',
			isset( $payload['Duration'] ) ? (int) $payload['Duration'] : 0,
			isset( $payload['Distributor'] ) ? trim( (string) $payload['Distributor'] ) : '',
			self::released_on( isset( $payload['OpeningDate'] ) ? (string) $payload['OpeningDate'] : '' ),
			isset( $payload['FilmTrailerUrl'] ) ? trim( (string) $payload['FilmTrailerUrl'] ) : ''
		);
	}

	/**
	 * Genres arrive as one string — "Drama, Comedy " — with whatever spacing
	 * whoever typed it felt like. Splitting here is what stops "Comedy" and
	 * " Comedy" becoming two terms and halving a listing.
	 *
	 * @param  string $genre The comma-separated list, as sent.
	 * @return array<int,string>
	 */
	private static function genres( string $genre ): array {
		$names = array_map( 'trim', explode( ',', $genre ) );

		return array_values( array_unique( array_filter( $names, static fn ( string $name ): bool => '' !== $name ) ) );
	}

	/**
	 * The opening date arrives as a naive midnight timestamp. Only the day
	 * matters, and keeping it as a day avoids inventing a timezone for a value
	 * that never had one.
	 *
	 * @param string $opening_date The timestamp, as sent.
	 */
	private static function released_on( string $opening_date ): string {
		if ( '' === $opening_date ) {
			return '';
		}

		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s', $opening_date );

		return false === $parsed ? '' : $parsed->format( 'Y-m-d' );
	}
}
