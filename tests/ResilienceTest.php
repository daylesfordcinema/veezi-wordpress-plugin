<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\Client;
use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * An outage at the ticketing provider must not blank the cinema's website.
 *
 * The shape of the sync is the whole defence: authenticate, fetch every feed,
 * and only once all of them have arrived intact, write anything. So the tests
 * here are mostly one assertion made several ways — that after a bad minute
 * upstream, the site is exactly as it was.
 */
final class ResilienceTest extends TestCase {

	/**
	 * A programme on the site, put there by a run that worked.
	 */
	private function a_programme_is_published(): void {
		$this->veezi_is_showing( 2 );
		$this->sync_at( '2026-08-01 00:00:00' );
	}

	/**
	 * Everything about the published programme that a sync could disturb.
	 *
	 * Compared whole rather than field by field, so that a run which quietly
	 * rewrote something nobody thought to assert on still fails this.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function the_programme(): array {
		$snapshot = array();

		foreach ( array( ContentModel::FILM, ContentModel::SESSION ) as $type ) {
			foreach ( $this->records( $type ) as $id ) {
				$post = get_post( $id );

				$snapshot[] = array(
					'id'        => $id,
					'type'      => $post->post_type,
					'title'     => $post->post_title,
					'status'    => $post->post_status,
					'order'     => $post->menu_order,
					'modified'  => $post->post_modified_gmt,
					'thumbnail' => get_post_thumbnail_id( $id ),
					'next'      => get_post_meta( $id, ContentModel::FILM_NEXT_SCREENING, true ),
					'booking'   => get_post_meta( $id, ContentModel::SESSION_BOOKING, true ),
					'starts'    => get_post_meta( $id, ContentModel::SESSION_STARTS, true ),
					'listed_as' => wp_get_object_terms( $id, ContentModel::LISTING, array( 'fields' => 'slugs' ) ),
				);
			}
		}

		return $snapshot;
	}

	public function test_a_veezi_that_cannot_be_reached_leaves_the_programme_exactly_as_it_was(): void {
		$this->a_programme_is_published();
		$before = $this->the_programme();

		$this->veezi->will_fail( Client::SITE );
		$result = $this->sync_at( '2026-08-01 01:00:00' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( $before, $this->the_programme() );
	}

	public function test_a_refused_token_leaves_the_programme_exactly_as_it_was(): void {
		$this->a_programme_is_published();
		$before = $this->the_programme();

		$this->veezi->will_return( Client::SITE, '', 403 );
		$result = $this->sync_at( '2026-08-01 01:00:00' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( $before, $this->the_programme() );
	}

	/**
	 * The dangerous case: enough of the fetch works to look like an answer.
	 * Veezi has reported one screening where the site has two, and the film
	 * catalogue then fails — so a sync that wrote as it read would have deleted
	 * a screening on the strength of half a reply.
	 */
	public function test_a_fetch_that_fails_halfway_writes_none_of_what_it_did_get(): void {
		$this->a_programme_is_published();
		$before = $this->the_programme();

		$this->veezi_is_showing( 1 );
		$this->veezi->will_fail( Client::FILMS );
		$result = $this->sync_at( '2026-08-01 01:00:00' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( $before, $this->the_programme() );
		$this->assertCount( 2, $this->records( ContentModel::SESSION ), 'A screening was deleted on the strength of a partial answer.' );
	}

	public function test_the_programme_stays_published_through_an_outage(): void {
		$this->a_programme_is_published();

		$this->veezi->will_fail( Client::SITE );
		$this->sync_at( '2026-08-01 01:00:00' );

		foreach ( $this->records( ContentModel::FILM ) as $film ) {
			$this->assertSame( 'publish', get_post_status( $film ) );
		}
	}

	/**
	 * A visitor is looking at a page while this happens, and must not be able
	 * to tell. The sync runs on cron rather than on a page load, so the way it
	 * would reach them is by printing something — a warning, a notice, a
	 * stack trace — into whatever is being rendered.
	 */
	public function test_a_failing_sync_prints_nothing(): void {
		$this->a_programme_is_published();
		$this->veezi->will_fail( Client::SITE );

		ob_start();
		$this->sync_at( '2026-08-01 01:00:00' );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_an_outage_is_diagnosable_from_the_server_log(): void {
		$this->a_programme_is_published();

		$this->veezi->will_fail( Client::SITE, 'Connection timed out after 15000 milliseconds' );
		$this->sync_at( '2026-08-01 01:00:00' );

		$this->assertStringContainsString( 'Connection timed out', $this->logged() );
	}

	/**
	 * The end that matters: what somebody looking at the website sees while
	 * Veezi is down. Which is the programme, unchanged.
	 */
	public function test_a_visitor_still_sees_the_session_times(): void {
		$this->a_programme_is_published();

		$this->veezi->will_fail( Client::SITE );
		$this->sync_at( '2026-08-01 01:00:00' );

		$html = $this->rendered_widget(
			'veezi-session-times',
			$this->film_record( 'film-cook' ),
			array( 'time_format' => 'g:i a' )
		);

		$this->assertStringContainsString( '7:00 pm', $html );
		$this->assertStringContainsString( 'ticketing.example.test', $html );
	}

	/**
	 * And the hour after that, the site recovers by itself. Nothing has to be
	 * reset, cleared or pressed.
	 */
	public function test_the_next_run_after_an_outage_picks_up_where_it_left_off(): void {
		$this->a_programme_is_published();

		$this->veezi->will_fail( Client::SITE );
		$this->sync_at( '2026-08-01 01:00:00' );

		$this->veezi_is_showing( 3 );
		$result = $this->sync_at( '2026-08-01 02:00:00' );

		$this->assertTrue( $result->is_success() );
		$this->assertCount( 3, $this->records( ContentModel::SESSION ) );
	}
}
