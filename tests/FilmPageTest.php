<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * The page a link to a film lands on.
 *
 * Everything here is about an address rather than a layout. A film page is the
 * one thing this plugin makes that leaves the site — pasted into a message,
 * posted to a page, indexed — and the promise attached to it is that it goes on
 * resolving long after the film has stopped screening.
 */
final class FilmPageTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		// Films are addressed by name, which needs the site off its default
		// query-string permalinks. Every WordPress install a designer would
		// build this on is already there; the test harness starts at the
		// factory setting.
		//
		// Registering again afterwards is not ceremony: a post type only gets a
		// route of its own if permalinks were already on when it was
		// registered, and here the plugin registered on `init` before this line
		// ran. On a real site the order is the other way round, and changing
		// the setting re-registers everything on the next request anyway.
		$this->set_permalink_structure( '/%postname%/' );
		ContentModel::register();
		flush_rewrite_rules( false );
	}

	/**
	 * One film, screening twice, with a trailer unless this test says otherwise.
	 *
	 * @param array<string,mixed> $film Fields to vary on the film.
	 */
	private function showing( array $film = array() ): int {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 10,
						'starts' => '2036-08-02T16:30:00',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 20,
						'starts' => '2036-08-06T19:00:00',
					)
				),
			),
			array( $this->film_payload( $film ) )
		);

		$this->sync_at();

		return $this->film_record( 'film-cook' );
	}

	/**
	 * Readable, and named after the film rather than after a number or one of
	 * Veezi's identifiers — this is a link people are meant to share.
	 */
	public function test_a_film_has_an_address_made_of_its_own_name(): void {
		$film = $this->showing();

		$this->assertSame( home_url( '/film/the-cooks-tale/' ), get_permalink( $film ) );
	}

	/**
	 * And it resolves: the record is public, its routing table has been built,
	 * and the request lands on that one film rather than on a search or a 404.
	 */
	public function test_the_address_resolves_to_that_film(): void {
		$film = $this->showing();

		$this->go_to( (string) get_permalink( $film ) );

		$this->assertQueryTrue( 'is_single', 'is_singular' );
		$this->assertSame( $film, get_queried_object_id() );
	}

	/**
	 * The address is fixed the first time the film is published and never
	 * recomputed. Distributors rename films between announcement and release —
	 * a subtitle appears, a colon moves — and an address that followed the title
	 * would break every link anybody had already shared.
	 */
	public function test_a_film_renamed_upstream_keeps_the_address_it_was_shared_as(): void {
		$film    = $this->showing();
		$address = get_permalink( $film );

		$this->showing( array( 'Title' => 'The Cook’s Tale: Second Helpings' ) );

		$this->assertSame( 'The Cook’s Tale: Second Helpings', get_the_title( $film ) );
		$this->assertSame( $address, get_permalink( $film ) );
	}

	/**
	 * The promise the ticket is named for. Veezi stops listing the film, the
	 * sync takes down its screenings, and the page somebody bookmarked in
	 * August still opens in December.
	 */
	public function test_the_page_still_resolves_once_the_season_has_ended(): void {
		$film = $this->showing();

		$this->arrange_programme( array(), array() );
		$this->sync_at( '2036-09-01 00:00:00' );

		$this->assertSame( 'publish', get_post_status( $film ) );
		$this->assertSame( array(), $this->records( ContentModel::SESSION ) );

		$this->go_to( (string) get_permalink( $film ) );

		$this->assertQueryTrue( 'is_single', 'is_singular' );
		$this->assertSame( $film, get_queried_object_id() );
	}

	/**
	 * Every remaining screening, each linking to the seats for that screening —
	 * from the same widget the film card uses, dropped onto the page with
	 * nothing named and nothing configured. It takes its film from whatever the
	 * page is showing, which on a film page is the film.
	 */
	public function test_the_page_lists_every_remaining_screening_with_its_own_booking_link(): void {
		$film = $this->showing();

		$this->go_to( (string) get_permalink( $film ) );
		$this->assertTrue( have_posts() );
		the_post();

		$html = $this->rendered_widget_here( 'veezi-session-times', array( 'show_date' => 'yes' ) );

		$this->assertStringContainsString( 'https://ticketing.example.test/purchase?session=10', $html );
		$this->assertStringContainsString( 'https://ticketing.example.test/purchase?session=20', $html );
		$this->assertStringContainsString( '4:30 pm', $html );
		$this->assertStringContainsString( '7:00 pm', $html );
	}

	/**
	 * The trailer player the starter template ships, on one film.
	 *
	 * Elementor's own video widget with its link bound to the plugin's trailer
	 * tag — which is exactly what the template stores, down to the encoded
	 * shortcode Elementor writes into `__dynamic__`. The whole element is
	 * printed rather than only its contents, because for YouTube the player
	 * itself is mounted by script from the settings on the wrapper, and those
	 * settings are the interesting part.
	 *
	 * @param int $film The film being looked at.
	 */
	private function trailer_player( int $film ): string {
		$this->looping_over( $film );

		return $this->printed_widget_here(
			'video',
			array(
				'video_type'  => 'youtube',
				'youtube_url' => '',
				'__dynamic__' => array(
					'youtube_url' => sprintf(
						'[elementor-tag id="a1b2c3d" name="veezi-trailer-url" settings="%s"]',
						rawurlencode( '{}' )
					),
				),
			)
		);
	}

	/**
	 * Veezi sends a YouTube watch link, which is not an address a player can be
	 * pointed at. Elementor's video widget is what the starter template binds
	 * the trailer to, and it takes the watch form and works the embed out
	 * itself — so the conversion happens in the one place that already knows
	 * every provider, every privacy setting and every player parameter, rather
	 * than in a regular expression here that would have to keep up with all of
	 * them.
	 */
	public function test_a_film_with_a_trailer_renders_a_player_pointed_at_it(): void {
		$html = $this->trailer_player( $this->showing() );

		$this->assertStringContainsString( 'elementor-video', $html );
		$this->assertStringContainsString( 'youtube.com\/watch?v=abcdefghijk', $html );
	}

	/**
	 * More than half the catalogue has no trailer, so this is the ordinary case
	 * rather than an exception. Nothing renders: no player, no empty frame, and in
	 * particular not the sample video the widget's own control falls back to,
	 * which is what an unresolved binding could otherwise have left on the page.
	 */
	public function test_a_film_with_no_trailer_renders_no_player(): void {
		$html = $this->trailer_player( $this->showing( array( 'FilmTrailerUrl' => '' ) ) );

		$this->assertStringNotContainsString( 'elementor-video', $html );
		$this->assertStringNotContainsString( 'youtube', $html );
	}
}
