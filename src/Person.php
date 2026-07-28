<?php
/**
 * Somebody who worked on a film.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * One credit: a name, and what that person did.
 *
 * A plain record like {@see Film}, and for the same reason — this is where
 * Veezi's vocabulary stops. Two awkward things about the upstream shape are
 * dealt with here and nowhere else: a name arrives in two parts, one of which
 * is sometimes empty for somebody who goes by a single name, and a role is a
 * free-text word rather than anything enumerated.
 *
 * The four roles below are every role the live catalogue uses, and they are
 * offered in the builder as a dropdown — but nothing rejects a fifth. Upstream
 * is free to start sending "Composer", and when it does that person keeps their
 * credit under Veezi's own word for it.
 *
 * A film's credits are stored as one encoded field rather than as a taxonomy:
 * the role is a fact about this person *on this film*, which a term shared
 * across films cannot carry, and nothing in the spec browses by it.
 */
final class Person {

	/**
	 * Every role the plugin knows by name, in the order a credit block reads —
	 * which is also the order the dropdown offers them.
	 *
	 * @var array<int,string>
	 */
	public const ROLES = array( 'Director', 'Screenwriter', 'Producer', 'Actor' );

	public function __construct(
		public readonly string $name,
		public readonly string $role
	) {}

	/**
	 * @param array<string,mixed> $payload One entry from a film's `People`.
	 */
	public static function from_payload( array $payload ): ?self {
		$name = trim(
			trim( isset( $payload['FirstName'] ) ? (string) $payload['FirstName'] : '' ) . ' ' .
			trim( isset( $payload['LastName'] ) ? (string) $payload['LastName'] : '' )
		);

		if ( '' === $name ) {
			return null;
		}

		return new self( $name, trim( isset( $payload['Role'] ) ? (string) $payload['Role'] : '' ) );
	}

	/**
	 * Everybody a film payload credits, in the order it credits them — which is
	 * billing order, and the one thing about a cast list that carries meaning.
	 *
	 * The same name in the same role appears once however many times upstream
	 * sends it. The live catalogue credits Jason Robards twice on one film, as
	 * two people with two identifiers and one name; printed as sent, that is a
	 * cast list reading "Jason Robards, Jason Robards", which looks like a fault
	 * here rather than a duplicate in a ticketing system nobody on this side can
	 * edit. Only an exact match is folded together — the same film also credits
	 * "Alan Pakula" and "Alan J. Pakula", and deciding those are one person is
	 * not this plugin's business.
	 *
	 * @param  array<int,mixed> $payloads A film's `People`, as sent.
	 * @return array<int,self>
	 */
	public static function all_from_payload( array $payloads ): array {
		$people = array();

		foreach ( $payloads as $payload ) {
			$person = is_array( $payload ) ? self::from_payload( $payload ) : null;

			if ( null !== $person ) {
				$people[ $person->role . '|' . $person->name ] = $person;
			}
		}

		return array_values( $people );
	}

	/**
	 * The credits as one value to store.
	 *
	 * JSON rather than serialised PHP: this is somebody else's data going into
	 * the database, and unserialising attacker-influenced input is a class of
	 * bug worth not having at all. Key order is fixed by the shape below, which
	 * is what lets a sync against unchanged credits write nothing.
	 *
	 * @param array<int,self> $people In billing order.
	 */
	public static function encode( array $people ): string {
		return (string) wp_json_encode(
			array_map(
				static fn ( self $person ): array => array(
					'name' => $person->name,
					'role' => $person->role,
				),
				$people
			)
		);
	}

	/**
	 * @param  string $stored Whatever is in the field, which on a record synced
	 *                        by an older version is an empty string.
	 * @return array<int,self>
	 */
	public static function decode( string $stored ): array {
		$decoded = '' === $stored ? null : json_decode( $stored, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$people = array();

		foreach ( $decoded as $entry ) {
			$name = is_array( $entry ) && isset( $entry['name'] ) ? (string) $entry['name'] : '';

			if ( '' !== $name ) {
				$people[] = new self( $name, isset( $entry['role'] ) ? (string) $entry['role'] : '' );
			}
		}

		return $people;
	}
}
