<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * The shape the programme takes once it is inside WordPress.
 *
 * These are the decisions everything else in the plugin inherits, so they are
 * asserted in terms of what they let a person do — find the programme in the
 * admin, share a link to a film, build a listing in the page builder — rather
 * than by reading back the arguments the registration was given.
 */
final class ContentModelTest extends TestCase {

	public function test_an_administrator_can_find_films_and_sessions_in_the_admin(): void {
		$this->assertTrue( get_post_type_object( ContentModel::FILM )->show_ui );
		$this->assertTrue( get_post_type_object( ContentModel::SESSION )->show_ui );
	}

	public function test_a_film_has_a_page_someone_can_link_to(): void {
		$film = self::factory()->post->create(
			array(
				'post_type'  => ContentModel::FILM,
				'post_title' => 'The Great Escape',
			)
		);

		$this->assertStringContainsString( 'the-great-escape', get_permalink( $film ) );
		$this->assertTrue( is_post_type_viewable( ContentModel::FILM ) );
	}

	/**
	 * A session is a time, not a destination. Nobody wants to land on a page
	 * whose entire content is "7:30pm", and search engines want it even less.
	 */
	public function test_a_session_has_no_page_of_its_own(): void {
		$this->assertFalse( is_post_type_viewable( ContentModel::SESSION ) );
		$this->assertTrue( get_post_type_object( ContentModel::SESSION )->exclude_from_search );
	}

	/**
	 * The chronological listing is built by pointing the page builder's loop
	 * widget at sessions, so both post types have to appear in that widget's
	 * Source control.
	 *
	 * Asserted the way Elementor Pro actually builds the list — `get_post_types(
	 * array( 'show_in_nav_menus' => true ) )`, then its own filter over the
	 * result — rather than against `public`, which is the belief this test used
	 * to encode and which is not the flag Elementor reads. Sessions are public
	 * and deliberately *not* in nav menus, so they were absent from the Source
	 * control while this passed: the calendar could not be built, whoever built
	 * it reached for films instead, and every screening after a film's first
	 * went missing from the listing. {@see Elementor\Integration} puts them back.
	 *
	 * The names are Elementor's, so nothing here can prove they are still
	 * current — Elementor Pro is commercial and cannot run in this suite. That
	 * check is in `docs/pre-release-checklist.md` and needs a licensed replica.
	 */
	public function test_the_page_builder_can_list_both_films_and_sessions(): void {
		// The hook is Elementor's and is spelled the way Elementor spells it. The
		// sniff is for hooks this project declares.
		// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores
		$offered = apply_filters(
			'elementor_pro/utils/get_public_post_types',
			get_post_types( array( 'show_in_nav_menus' => true ) )
		);
		// phpcs:enable WordPress.NamingConventions.ValidHookName.UseUnderscores

		$this->assertArrayHasKey( ContentModel::FILM, $offered );
		$this->assertArrayHasKey( ContentModel::SESSION, $offered );
	}

	public function test_films_can_be_browsed_by_genre_classification_and_listing(): void {
		foreach ( array( ContentModel::GENRE, ContentModel::CLASSIFICATION, ContentModel::LISTING ) as $taxonomy ) {
			$this->assertTrue(
				is_object_in_taxonomy( ContentModel::FILM, $taxonomy ),
				"Films should be classifiable by {$taxonomy}."
			);
		}
	}

	public function test_the_listing_taxonomy_has_a_now_showing_term(): void {
		$film = self::factory()->post->create( array( 'post_type' => ContentModel::FILM ) );

		wp_set_object_terms( $film, ContentModel::listing_term( ContentModel::NOW_SHOWING ), ContentModel::LISTING );

		$terms = wp_get_object_terms( $film, ContentModel::LISTING, array( 'fields' => 'slugs' ) );

		$this->assertSame( array( ContentModel::NOW_SHOWING ), $terms );
	}

	/**
	 * Updating a plugin does not reactivate it — the updater reactivates
	 * silently, and that skips activation hooks — so the routing table
	 * WordPress cached before the update knows nothing about film pages. Left
	 * alone, every film link on the site 404s until somebody opens Settings →
	 * Permalinks, which nobody would think to do.
	 */
	public function test_film_addresses_come_back_after_the_plugin_is_updated(): void {
		$this->set_permalink_structure( '/%postname%/' );

		// Setting the structure re-initialises WordPress's rewrite object,
		// which forgets every permastruct a post type registered. A real
		// request registers them again on `init`; so does this.
		ContentModel::register();

		// The site as it is a moment after an in-place update: routes cached by
		// a version that had never heard of films.
		update_option( 'rewrite_rules', array( 'somewhere/?$' => 'index.php' ) );
		update_option( ContentModel::REWRITES_VERSION, '0.0.1-old' );

		ContentModel::flush_rewrites_when_stale();

		$this->assertNotEmpty(
			preg_grep( '#^film/#', array_keys( (array) get_option( 'rewrite_rules' ) ) ),
			'No route reaches a film page, so every link to one is a 404.'
		);
	}

	public function test_the_routing_table_is_left_alone_when_it_is_already_current(): void {
		$this->set_permalink_structure( '/%postname%/' );

		// Setting the structure re-initialises WordPress's rewrite object,
		// which forgets every permastruct a post type registered. A real
		// request registers them again on `init`; so does this.
		ContentModel::register();

		ContentModel::flush_rewrites();

		update_option( 'rewrite_rules', array( 'untouched/?$' => 'index.php' ) );

		ContentModel::flush_rewrites_when_stale();

		$this->assertSame(
			array( 'untouched/?$' => 'index.php' ),
			get_option( 'rewrite_rules' ),
			'Rebuilding the routing table on every request is expensive and pointless.'
		);
	}

	/**
	 * Asked twice for the same term, the plugin must hand back the same one
	 * rather than quietly making "now-showing-2" — which would split the
	 * listing in half and leave the site looking half-empty.
	 */
	public function test_asking_for_a_listing_term_twice_yields_one_term(): void {
		$first  = ContentModel::listing_term( ContentModel::NOW_SHOWING );
		$second = ContentModel::listing_term( ContentModel::NOW_SHOWING );

		$this->assertSame( $first, $second );
		$this->assertGreaterThan( 0, $first );
	}
}
