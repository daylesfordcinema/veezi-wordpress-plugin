<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Elementor\Plugin as Elementor;
use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * The fields a designer binds a card's widgets to.
 *
 * Every name asserted here — `veezi-runtime` and its siblings — is stored
 * verbatim inside the Elementor data of any template that uses it. Renaming one
 * is not a refactor: it is a change that empties a widget on a live site, with
 * no error anywhere. That is why the tests spell the names out rather than
 * reaching for a constant.
 */
final class DynamicTagsTest extends TestCase {

	/**
	 * A film with everything filled in, and one screening it can be booked for.
	 *
	 * @param array<string,mixed> $film    Fields to vary on the film.
	 * @param array<string,mixed> $session Fields to vary on its screening.
	 */
	private function synced( array $film = array(), array $session = array() ): void {
		$this->arrange_programme(
			array( $this->session_payload( $session ) ),
			array( $this->film_payload( $film ) )
		);

		$this->sync_at();
	}

	public function test_a_card_binds_a_films_runtime(): void {
		$this->synced();

		$this->assertSame( '100', $this->bound( 'veezi-runtime', $this->film_record( 'film-cook' ) ) );
	}

	public function test_a_card_binds_a_films_classification(): void {
		$this->synced();

		$this->assertSame( 'PG', $this->bound( 'veezi-classification', $this->film_record( 'film-cook' ) ) );
	}

	/**
	 * Both of them — a card showing only the first would quietly drop half of
	 * what the cinema said. The order is the taxonomy's, which is alphabetical
	 * rather than the order Veezi sent: the tag reads the terms the film is
	 * filed under, so a card and a genre archive cannot disagree.
	 */
	public function test_a_card_binds_every_genre_a_film_is_filed_under(): void {
		$this->synced();

		$this->assertSame( 'Comedy, Drama', $this->bound( 'veezi-genre', $this->film_record( 'film-cook' ) ) );
	}

	/**
	 * The poster resolves to the copy in the media library, never to Veezi's
	 * own address for it — that is the whole point of ticket 04 having put it
	 * there. It carries the attachment id as well as the URL, which is what lets
	 * an image widget ask for a size instead of the full-resolution original.
	 */
	public function test_a_card_binds_the_poster_in_the_media_library(): void {
		$this->synced();

		$film       = $this->film_record( 'film-cook' );
		$attachment = get_post_thumbnail_id( $film );
		$poster     = $this->bound( 'veezi-poster', $film );

		$this->assertSame( $attachment, $poster['id'] );
		$this->assertStringNotContainsString( 'images.example.test', (string) $poster['url'] );

		// Whatever reads the URL rather than the id — a background image, say —
		// has no size to choose, so it must not be handed the original.
		$this->assertSame(
			wp_get_attachment_image_url( $attachment, ContentModel::POSTER_SIZE ),
			$poster['url']
		);

		// Somebody reading the page with a screen reader is told which film.
		$this->assertStringContainsString(
			'Cook',
			(string) get_post_meta( $attachment, '_wp_attachment_image_alt', true )
		);
	}

	public function test_a_card_binds_a_films_trailer(): void {
		$this->synced();

		$this->assertSame(
			'https://www.youtube.com/watch?v=abcdefghijk',
			$this->bound( 'veezi-trailer-url', $this->film_record( 'film-cook' ) )
		);
	}

	/**
	 * One tag can only ever offer one time, so on a film it offers the soonest.
	 * The several times a film screens across a week are what the session-times
	 * widget exists for.
	 */
	public function test_a_card_binds_the_next_time_a_film_screens(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'     => 20,
						'starts' => '2026-08-06T19:00:00',
					)
				),
				$this->session_payload(
					array(
						'Id'     => 10,
						'starts' => '2026-08-02T16:30:00',
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();

		// 16:30 in Melbourne, printed in the site's own date and time format.
		$this->assertSame(
			'August 2, 2026 4:30 pm',
			$this->bound( 'veezi-session-time', $this->film_record( 'film-cook' ) )
		);
	}

	/**
	 * The time is worked out when the page is rendered, not reprinted from what
	 * the sync stored. Otherwise changing the site's time format would leave
	 * every card showing the old one until the next sync happened to rewrite it.
	 */
	public function test_a_time_follows_the_format_the_site_is_set_to_now(): void {
		$this->synced();

		update_option( 'date_format', 'D j M' );
		update_option( 'time_format', 'H:i' );

		$this->assertSame(
			'Sun 2 Aug 16:30',
			$this->bound( 'veezi-session-time', $this->film_record( 'film-cook' ) )
		);
	}

	public function test_a_card_binds_a_booking_link(): void {
		$this->synced();

		$this->assertSame(
			'https://ticketing.example.test/purchase?session=1001',
			$this->bound( 'veezi-booking-url', $this->film_record( 'film-cook' ) )
		);
	}

	/**
	 * The same two tags on a row of the chronological listing, where the record
	 * being looped over is the screening itself. Nothing is configured to make
	 * that happen: the tag answers for whatever it finds itself on.
	 */
	public function test_a_session_row_binds_its_own_time_and_link(): void {
		$this->synced();

		$session = $this->session_record( 1001 );

		$this->assertSame( 'August 2, 2026 4:30 pm', $this->bound( 'veezi-session-time', $session ) );
		$this->assertSame(
			'https://ticketing.example.test/purchase?session=1001',
			$this->bound( 'veezi-booking-url', $session )
		);
	}

	public function test_a_sold_out_session_offers_no_booking_link(): void {
		$this->synced( array(), array( 'TicketsSoldOut' => true ) );

		$this->assertSame( '', $this->bound( 'veezi-booking-url', $this->session_record( 1001 ) ) );
	}

	/**
	 * A card's button skips past a sold-out screening to the next one somebody
	 * can actually buy. Offering the soonest whatever its state would leave the
	 * button dead for the rest of the week, which is worse than the truth.
	 *
	 * And the headline time skips with it. A card saying Sunday while its button
	 * sold Thursday would be worse than either — a lie a visitor only finds out
	 * about once they have clicked.
	 */
	public function test_a_card_headlines_the_same_screening_it_books(): void {
		$this->arrange_programme(
			array(
				$this->session_payload(
					array(
						'Id'             => 10,
						'starts'         => '2026-08-02T16:30:00',
						'TicketsSoldOut' => true,
					)
				),
				$this->session_payload(
					array(
						'Id'     => 20,
						'starts' => '2026-08-06T19:00:00',
					)
				),
			),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$film = $this->film_record( 'film-cook' );

		$this->assertSame(
			'https://ticketing.example.test/purchase?session=20',
			$this->bound( 'veezi-booking-url', $film )
		);
		$this->assertSame( 'August 6, 2026 7:00 pm', $this->bound( 'veezi-session-time', $film ) );
	}

	/**
	 * When a whole week has sold out there is no screening to skip to, and the
	 * card falls back to telling the truth: this is when it is on, and no, you
	 * cannot have a ticket.
	 */
	public function test_a_card_still_says_when_a_sold_out_film_screens(): void {
		$this->synced( array(), array( 'TicketsSoldOut' => true ) );

		$film = $this->film_record( 'film-cook' );

		$this->assertSame( 'August 2, 2026 4:30 pm', $this->bound( 'veezi-session-time', $film ) );
		$this->assertSame( '', $this->bound( 'veezi-booking-url', $film ) );
	}

	/**
	 * A film Veezi holds nothing for — no artwork, no trailer, no rating, no
	 * genre and no running time — is not hypothetical: the live catalogue has
	 * one with no artwork at all. Every tag has to come back with something a
	 * widget can be handed without complaining.
	 */
	public function test_a_tag_whose_field_is_missing_resolves_to_nothing(): void {
		$this->synced(
			array(
				'Duration'       => 0,
				'Rating'         => '',
				'Genre'          => '',
				'FilmTrailerUrl' => '',
				'FilmPosterUrl'  => '',
			)
		);

		$film = $this->film_record( 'film-cook' );

		foreach ( array( 'veezi-runtime', 'veezi-classification', 'veezi-genre', 'veezi-trailer-url' ) as $tag ) {
			$this->assertSame( '', $this->bound( $tag, $film ), "{$tag} should resolve to nothing." );
		}

		$this->assertSame(
			array(
				'id'  => 0,
				'url' => '',
			),
			$this->bound( 'veezi-poster', $film )
		);
	}

	/**
	 * A film between seasons keeps its page, and a template built for it must
	 * not start throwing once the last screening has gone.
	 */
	public function test_a_film_with_nothing_left_to_screen_offers_no_time_and_no_link(): void {
		$this->synced();
		$this->arrange_programme( array(), array() );
		$this->sync_at( '2026-09-01 00:00:00' );

		$film = $this->film_record( 'film-cook' );

		$this->assertSame( '', $this->bound( 'veezi-session-time', $film ) );
		$this->assertSame( '', $this->bound( 'veezi-booking-url', $film ) );
	}

	/**
	 * What the dynamic-data picker shows. Everything the plugin adds sits under
	 * one heading rather than scattered through Elementor's own list, and each
	 * entry is named the way somebody would describe it out loud.
	 */
	public function test_the_tags_are_grouped_together_and_named_for_the_panel(): void {
		$config = Elementor::$instance->dynamic_tags->get_config();

		$this->assertSame( 'Veezi Programme', $config['groups']['veezi']['title'] ?? null );

		$titles = array();

		foreach ( $config['tags'] as $name => $tag ) {
			if ( 'veezi' === $tag['group'] ) {
				$titles[ $name ] = $tag['title'];
			}
		}

		$this->assertSame(
			array(
				'veezi-booking-url'    => 'Booking Link',
				'veezi-cast-and-crew'  => 'Cast and Crew',
				'veezi-classification' => 'Classification',
				'veezi-genre'          => 'Genre',
				'veezi-poster'         => 'Poster',
				'veezi-runtime'        => 'Runtime (minutes)',
				'veezi-session-time'   => 'Session Time',
				'veezi-trailer-url'    => 'Trailer Link',
			),
			$titles
		);
	}

	/**
	 * Which of Elementor's own controls each tag offers itself to. Get this
	 * wrong and the tag is simply absent from the picker on the widget a
	 * designer is standing in front of — invisible rather than broken, which is
	 * the harder thing to diagnose.
	 */
	public function test_each_tag_is_offered_where_it_belongs(): void {
		$config = Elementor::$instance->dynamic_tags->get_config()['tags'];

		$this->assertSame( array( 'url' ), $config['veezi-booking-url']['categories'] );
		$this->assertSame( array( 'url' ), $config['veezi-trailer-url']['categories'] );
		$this->assertSame( array( 'image', 'media' ), $config['veezi-poster']['categories'] );
		$this->assertSame( array( 'text' ), $config['veezi-session-time']['categories'] );
	}
}
