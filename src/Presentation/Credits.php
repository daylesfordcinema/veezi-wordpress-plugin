<?php
/**
 * A film's credits, as a page reads them.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Presentation;

use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Person;

defined( 'ABSPATH' ) || exit;

/**
 * Everybody credited on one film, ready to put on a page.
 *
 * A dynamic tag can only ever hand a widget one string, so the whole of the
 * design is which string. Asked for a role it gives the names alone — the
 * heading beside them is the designer's, the same way the runtime tag gives a
 * bare number and leaves "min" to whoever is building the page. Asked for
 * nothing in particular it gives the lot, each name carrying what that person
 * did, because a list of everyone without their roles would tell a visitor the
 * producer was in it.
 */
final class Credits {

	/**
	 * @param array<int,Person> $people In billing order, as Veezi sent them.
	 */
	private function __construct( private readonly array $people ) {}

	/**
	 * @param int $post_id A film record.
	 */
	public static function for_film( int $post_id ): self {
		return new self(
			Person::decode( (string) get_post_meta( $post_id, ContentModel::FILM_PEOPLE, true ) )
		);
	}

	/**
	 * @param string $role One of {@see Person::ROLES}, or an empty string for
	 *                     everybody.
	 */
	public function in_words( string $role = '' ): string {
		if ( '' !== $role ) {
			return implode( ', ', array_map( static fn ( Person $p ): string => $p->name, $this->credited_as( $role ) ) );
		}

		$credits = array();

		foreach ( $this->everybody() as $person ) {
			$credits[] = '' === $person->role
				? $person->name
				: sprintf(
					/* translators: 1: somebody's name, 2: what they did on the film, e.g. "Director". */
					__( '%1$s (%2$s)', 'veezi-wordpress-plugin' ),
					$person->name,
					self::role_in_words( $person->role )
				);
		}

		return implode( ', ', $credits );
	}

	/**
	 * The roles the builder offers as a dropdown, by the value a template
	 * stores.
	 *
	 * @return array<string,string>
	 */
	public static function roles(): array {
		$labelled = array();

		foreach ( Person::ROLES as $role ) {
			$labelled[ $role ] = self::role_in_words( $role );
		}

		return $labelled;
	}

	/**
	 * Everybody, crew before cast.
	 *
	 * A film page reads "directed by" before "starring", and within a role the
	 * order stays Veezi's, which is billing order. A role the plugin has never
	 * heard of sorts after the ones it has, rather than being dropped — the four
	 * known ones are what the catalogue happens to use today, not a rule.
	 *
	 * @return array<int,Person>
	 */
	private function everybody(): array {
		$ordered = array();

		foreach ( Person::ROLES as $role ) {
			$ordered = array_merge( $ordered, $this->credited_as( $role ) );
		}

		return array_merge(
			$ordered,
			array_values(
				array_filter(
					$this->people,
					static fn ( Person $p ): bool => ! in_array( $p->role, Person::ROLES, true )
				)
			)
		);
	}

	/**
	 * @param  string $role Upstream's own word for it.
	 * @return array<int,Person>
	 */
	private function credited_as( string $role ): array {
		return array_values(
			array_filter( $this->people, static fn ( Person $p ): bool => $p->role === $role )
		);
	}

	/**
	 * A role in the reader's language, where the plugin knows the word.
	 *
	 * Anything else is printed as Veezi sent it. Translating only the four roles
	 * the live catalogue uses and leaving a fifth in English is the honest
	 * outcome: the alternative is a credit list that silently loses whoever
	 * upstream added.
	 *
	 * The four are spelled out rather than looped over {@see Person::ROLES}
	 * because a translation string has to be a literal where `__()` is called —
	 * the tooling that collects them reads the source, and cannot see the value
	 * of a variable. So a fifth role means editing this as well, which is the
	 * price of the strings being translatable at all.
	 *
	 * @param string $role Upstream's own word for it.
	 */
	private static function role_in_words( string $role ): string {
		switch ( $role ) {
			case 'Director':
				return __( 'Director', 'veezi-wordpress-plugin' );
			case 'Screenwriter':
				return __( 'Screenwriter', 'veezi-wordpress-plugin' );
			case 'Producer':
				return __( 'Producer', 'veezi-wordpress-plugin' );
			case 'Actor':
				return __( 'Actor', 'veezi-wordpress-plugin' );
			default:
				return $role;
		}
	}
}
