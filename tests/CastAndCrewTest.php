<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Elementor\Plugin as Elementor;
use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Presentation\Credits;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * Who made the film, and what they did on it.
 *
 * The one field on a film page that is a list of things rather than a single
 * value, which is what makes it awkward: Veezi sends up to twenty-six people per
 * film, each with a role, and a page has to be able to say "directed by" and
 * "starring" separately without a designer writing a query.
 */
final class CastAndCrewTest extends TestCase {

	/**
	 * A film credited to whoever this test cares about.
	 *
	 * @param array<int,array<string,string>> $people As `/v4/film` sends them:
	 *                                                two name parts and a role.
	 */
	private function credited_to( array $people ): int {
		$this->arrange_programme(
			array( $this->session_payload() ),
			array( $this->film_payload( array( 'People' => $people ) ) )
		);

		$this->sync_at();

		return $this->film_record( 'film-cook' );
	}

	/**
	 * One person, in the shape the film catalogue sends them.
	 *
	 * @param  string $first The given name, as sent.
	 * @param  string $last  The family name, which upstream sometimes leaves
	 *                       empty for somebody who goes by one name.
	 * @param  string $role  Upstream's own word for what they did.
	 * @param  string $id    Veezi's identifier for them, which the plugin does
	 *                       not keep — named here only where a test needs two
	 *                       entries upstream considers different.
	 * @return array<string,string>
	 */
	private function person( string $first, string $last, string $role, string $id = '' ): array {
		return array(
			'Id'        => '' === $id ? strtolower( $first . $last ) : $id,
			'FirstName' => $first,
			'LastName'  => $last,
			'Role'      => $role,
		);
	}

	public function test_a_film_page_names_the_director(): void {
		$film = $this->credited_to( array( $this->person( 'Ada', 'Vaughan', 'Director' ) ) );

		$this->assertSame(
			'Ada Vaughan',
			$this->bound( 'veezi-cast-and-crew', $film, array( 'role' => 'Director' ) )
		);
	}

	/**
	 * In the order Veezi sent them, which is billing order — the first name on
	 * the poster is the first name upstream lists. Sorting them alphabetically
	 * would throw that away, and it is the one thing about a cast list that
	 * carries meaning.
	 */
	public function test_the_cast_reads_in_billing_order(): void {
		$film = $this->credited_to(
			array(
				$this->person( 'Wilhelmina', 'Cray', 'Actor' ),
				$this->person( 'Ada', 'Vaughan', 'Director' ),
				$this->person( 'Bo', 'Aldridge', 'Actor' ),
			)
		);

		$this->assertSame(
			'Wilhelmina Cray, Bo Aldridge',
			$this->bound( 'veezi-cast-and-crew', $film, array( 'role' => 'Actor' ) )
		);
	}

	/**
	 * Bound with nothing chosen the tag is the whole credit list, and each name
	 * carries what that person did — otherwise a page that showed everyone would
	 * be telling a visitor that the producer was in it.
	 *
	 * Crew before cast, because a film page reads "directed by" before
	 * "starring", and within a role the order stays upstream's.
	 */
	public function test_everyone_is_listed_with_what_they_did(): void {
		$film = $this->credited_to(
			array(
				$this->person( 'Wilhelmina', 'Cray', 'Actor' ),
				$this->person( 'Ada', 'Vaughan', 'Director' ),
				$this->person( 'Bo', 'Aldridge', 'Actor' ),
				$this->person( 'Cy', 'Nkemelu', 'Producer' ),
			)
		);

		$this->assertSame(
			'Ada Vaughan (Director), Cy Nkemelu (Producer), Wilhelmina Cray (Actor), Bo Aldridge (Actor)',
			$this->bound( 'veezi-cast-and-crew', $film )
		);
	}

	/**
	 * Somebody who goes by one name is in the live catalogue — Zendaya, with an
	 * empty family name — and joining the two parts without checking would
	 * print a trailing space into every page that shows her.
	 */
	public function test_somebody_with_one_name_is_named_once(): void {
		$film = $this->credited_to( array( $this->person( 'Zendaya', '', 'Actor' ) ) );

		$this->assertSame( 'Zendaya', $this->bound( 'veezi-cast-and-crew', $film, array( 'role' => 'Actor' ) ) );
	}

	/**
	 * A role nobody thought of is still somebody's credit. The four in the
	 * dropdown are the four the live catalogue uses; upstream is free to add a
	 * fifth, and when it does that person appears in the full list under
	 * whatever Veezi called them rather than vanishing.
	 */
	public function test_a_role_the_plugin_has_never_heard_of_still_gets_a_credit(): void {
		$film = $this->credited_to(
			array(
				$this->person( 'Ada', 'Vaughan', 'Director' ),
				$this->person( 'Rin', 'Okonkwo', 'Composer' ),
			)
		);

		$this->assertSame(
			'Ada Vaughan (Director), Rin Okonkwo (Composer)',
			$this->bound( 'veezi-cast-and-crew', $film )
		);
	}

	/**
	 * Two films in the live catalogue have no `People` at all, so this is the
	 * live case rather than a defensive one. A template built for a full credit
	 * list has to render an empty heading, not a fatal error.
	 */
	public function test_a_film_with_no_credits_resolves_to_nothing(): void {
		$film = $this->credited_to( array() );

		$this->assertSame( '', $this->bound( 'veezi-cast-and-crew', $film ) );
		$this->assertSame( '', $this->bound( 'veezi-cast-and-crew', $film, array( 'role' => 'Director' ) ) );
	}

	/**
	 * Asking for a role this film has nobody in is ordinary, not exceptional:
	 * plenty of records credit a director and nobody else.
	 */
	public function test_a_role_this_film_credits_nobody_in_resolves_to_nothing(): void {
		$film = $this->credited_to( array( $this->person( 'Ada', 'Vaughan', 'Director' ) ) );

		$this->assertSame( '', $this->bound( 'veezi-cast-and-crew', $film, array( 'role' => 'Actor' ) ) );
	}

	/**
	 * The live catalogue does exactly this: All the President's Men credits
	 * Jason Robards twice, as two people upstream with one name between them.
	 * Printed as sent, a cast list reads "Jason Robards, Jason Robards", which
	 * looks like a bug in this plugin rather than a duplicate in a ticketing
	 * system nobody here can edit.
	 *
	 * Only an identical name in an identical role is folded together. "Alan
	 * Pakula" and "Alan J. Pakula" — the same film, also real — are two
	 * different strings, and guessing that they are one person is not this
	 * plugin's business.
	 */
	public function test_the_same_person_credited_twice_over_is_named_once(): void {
		$film = $this->credited_to(
			array(
				$this->person( 'Jason', 'Robards', 'Actor', 'person-1' ),
				$this->person( 'Jason', 'Robards', 'Actor', 'person-2' ),
				$this->person( 'Alan', 'Pakula', 'Director', 'person-3' ),
				$this->person( 'Alan J.', 'Pakula', 'Director', 'person-4' ),
			)
		);

		$this->assertSame( 'Jason Robards', $this->bound( 'veezi-cast-and-crew', $film, array( 'role' => 'Actor' ) ) );
		$this->assertSame(
			'Alan Pakula, Alan J. Pakula',
			$this->bound( 'veezi-cast-and-crew', $film, array( 'role' => 'Director' ) )
		);
	}

	/**
	 * Half the names in the live catalogue carry an accent, and this is the one
	 * field the plugin stores as an encoded value rather than as plain text —
	 * so it is the one field where WordPress's own slashing of metadata eats a
	 * character. Unfixed, Penélope Cruz reaches the page as "Penu00e9lope".
	 */
	public function test_a_name_with_an_accent_in_it_survives_being_stored(): void {
		$film = $this->credited_to( array( $this->person( 'Penélope', 'Cruz', 'Actor' ) ) );

		$this->assertSame(
			'Penélope Cruz',
			$this->bound( 'veezi-cast-and-crew', $film, array( 'role' => 'Actor' ) )
		);
	}

	/**
	 * Markup in a name would otherwise reach the page: this tag prints text, and
	 * the text comes from somebody else's database.
	 */
	public function test_a_name_cannot_carry_markup_onto_the_page(): void {
		$film = $this->credited_to(
			array( $this->person( '<script>alert(1)</script>Ada', 'Vaughan', 'Director' ) )
		);

		$this->assertStringNotContainsString(
			'<script>',
			(string) $this->bound( 'veezi-cast-and-crew', $film, array( 'role' => 'Director' ) )
		);
	}

	/**
	 * A sync that changes nothing must write nothing, and a list of people is
	 * the one field here stored as a single encoded value — so a difference in
	 * key order or spacing would rewrite it on every run and make the film look
	 * edited every hour.
	 */
	public function test_syncing_the_same_credits_twice_rewrites_nothing(): void {
		$film     = $this->credited_to( array( $this->person( 'Ada', 'Vaughan', 'Director' ) ) );
		$modified = get_post_field( 'post_modified_gmt', $film );
		$stored   = get_post_meta( $film, ContentModel::FILM_PEOPLE, true );

		$this->sync_at( '2026-08-01 01:00:00' );

		$this->assertSame( $modified, get_post_field( 'post_modified_gmt', $film ) );
		$this->assertSame( $stored, get_post_meta( $film, ContentModel::FILM_PEOPLE, true ) );
	}

	/**
	 * The tag belongs with the rest of the plugin's, and offers itself to text
	 * controls — a credit is words on a page, not a link or an image.
	 */
	public function test_the_tag_is_offered_alongside_the_others(): void {
		$config = Elementor::$instance->dynamic_tags->get_config()['tags']['veezi-cast-and-crew'] ?? null;

		$this->assertIsArray( $config, 'The dynamic-data picker has no cast and crew tag.' );
		$this->assertSame( 'veezi', $config['group'] );
		$this->assertSame( array( 'text' ), $config['categories'] );

		// Not "Cast & Crew". A title is escaped like every other label here and
		// then handed to the panel as data rather than as markup, so the
		// ampersand would arrive in the dropdown spelled `&amp;`.
		$this->assertSame( 'Cast and Crew', $config['title'] );
	}

	/**
	 * The tag is chosen from a dropdown rather than typed into, and left alone
	 * it is everybody.
	 */
	public function test_the_role_is_chosen_from_a_dropdown(): void {
		$control = Elementor::$instance->dynamic_tags->get_config()['tags']['veezi-cast-and-crew']['controls']['role'] ?? null;

		$this->assertIsArray( $control, 'The cast and crew tag has no role control.' );
		$this->assertSame( 'select', $control['type'] );
		$this->assertSame( '', $control['default'] );
	}

	/**
	 * The one that matters about the dropdown. Each of its values is stored
	 * inside whatever template a designer binds this in, and resolved later
	 * against the roles Veezi sends — so a word changed on one side and not the
	 * other empties a heading on a live page and reports nothing anywhere.
	 *
	 * Spelled out rather than read from the code for the same reason the tag
	 * names are: this is the contract, not a restatement of it.
	 *
	 * What the dropdown *looks* like is not assertable here. Elementor strips
	 * labels and options from its controls outside the editor, and PHPUnit
	 * stands on the front end — so the panel itself is read in the replica.
	 */
	public function test_every_role_the_dropdown_offers_finds_the_person_it_names(): void {
		$roles = array( 'Director', 'Screenwriter', 'Producer', 'Actor' );

		$this->assertSame( $roles, array_keys( Credits::roles() ) );

		$film = $this->credited_to(
			array_map(
				fn ( string $role ): array => $this->person( ucfirst( strtolower( $role ) ), 'Vaughan', $role ),
				$roles
			)
		);

		foreach ( $roles as $role ) {
			$this->assertSame(
				ucfirst( strtolower( $role ) ) . ' Vaughan',
				$this->bound( 'veezi-cast-and-crew', $film, array( 'role' => $role ) ),
				"Nobody is credited as {$role}, though the dropdown offers it."
			);
		}
	}
}
