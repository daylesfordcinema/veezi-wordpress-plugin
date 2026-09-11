<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * The two visitor-facing filters over the chronological session listing.
 *
 * A session already carries its start as an exact instant, which is the right
 * representation for ordering and range queries. Elementor Pro's native
 * filter only understands taxonomies, though, so the sync also files each
 * session under the local date and weekday that instant falls on. These tests
 * pin the resulting WordPress relationships rather than Elementor Pro's UI,
 * which is checked against the licensed replica before release.
 */
final class SessionTaxonomiesTest extends TestCase {

	public function test_elementor_can_offer_date_and_weekday_filters_for_sessions(): void {
		$this->assertTrue( is_object_in_taxonomy( ContentModel::SESSION, ContentModel::SESSION_DATE ) );
		$this->assertTrue( is_object_in_taxonomy( ContentModel::SESSION, ContentModel::SESSION_WEEKDAY ) );

		$this->assertFalse( is_object_in_taxonomy( ContentModel::FILM, ContentModel::SESSION_DATE ) );
		$this->assertFalse( is_object_in_taxonomy( ContentModel::FILM, ContentModel::SESSION_WEEKDAY ) );
	}

	/**
	 * Nine in the morning in Melbourne is still the previous day in UTC. The
	 * filter has to agree with the words on the cinema's programme rather than
	 * with either the server or WordPress site's clock.
	 */
	public function test_a_session_is_filed_under_its_date_in_the_cinema_timezone(): void {
		$this->arrange_programme(
			array( $this->session_payload( array( 'starts' => '2036-08-02T09:00:00' ) ) ),
			array( $this->film_payload() )
		);

		$this->sync_at();

		$session = $this->session_record( 1001 );

		$this->assertSame(
			array( '2036-08-02' ),
			wp_get_object_terms( $session, ContentModel::SESSION_DATE, array( 'fields' => 'slugs' ) )
		);
		$this->assertSame(
			array( 'saturday' ),
			wp_get_object_terms( $session, ContentModel::SESSION_WEEKDAY, array( 'fields' => 'slugs' ) )
		);

		$date = get_term_by( 'slug', '2036-08-02', ContentModel::SESSION_DATE );
		$this->assertInstanceOf( \WP_Term::class, $date );
		$this->assertSame( 'Saturday 2 August 2036', $date->name );
	}

	public function test_session_queries_can_filter_by_date_and_weekday_together(): void {
		$this->veezi_is_showing( 2 );
		$this->sync_at();

		$friday   = $this->session_record( 2000 );
		$saturday = $this->session_record( 2001 );

		$this->assertSame(
			array( $saturday ),
			$this->sessions_filed_under( '2036-08-02', 'saturday' )
		);
		$this->assertSame(
			array(),
			$this->sessions_filed_under( '2036-08-02', 'friday' ),
			'A date and a mismatched weekday were combined as alternatives rather than filters.'
		);
		$this->assertSame(
			array( $friday ),
			$this->sessions_filed_under( '2036-08-01', 'friday' )
		);
	}

	/**
	 * Veezi can move a session. Appending its new classifications would make it
	 * appear under both dates and both weekdays, and leaving the abandoned date
	 * behind would grow a filter full of empty historical choices over time.
	 */
	public function test_moving_a_session_replaces_its_terms_and_removes_the_abandoned_date(): void {
		$this->arrange_programme(
			array( $this->session_payload( array( 'starts' => '2036-08-01T19:00:00' ) ) ),
			array( $this->film_payload() )
		);
		$this->sync_at();

		$this->arrange_programme(
			array( $this->session_payload( array( 'starts' => '2036-08-02T19:00:00' ) ) ),
			array( $this->film_payload() )
		);
		$this->sync_at();

		$session = $this->session_record( 1001 );

		$this->assertSame(
			array( '2036-08-02' ),
			wp_get_object_terms( $session, ContentModel::SESSION_DATE, array( 'fields' => 'slugs' ) )
		);
		$this->assertSame(
			array( 'saturday' ),
			wp_get_object_terms( $session, ContentModel::SESSION_WEEKDAY, array( 'fields' => 'slugs' ) )
		);
		$this->assertFalse( get_term_by( 'slug', '2036-08-01', ContentModel::SESSION_DATE ) );
	}

	/**
	 * WordPress orders terms by their labels by default. Friendly date labels
	 * and weekday names would therefore produce a calendar filter in alphabetic
	 * order, which is useless even though filtering itself still works.
	 */
	public function test_elementor_filter_choices_are_calendar_ordered(): void {
		$sessions = array(
			$this->session_payload(
				array(
					'Id'     => 2001,
					'starts' => '2036-08-01T19:00:00',
				)
			),
			$this->session_payload(
				array(
					'Id'     => 2002,
					'starts' => '2036-08-05T19:00:00',
				)
			),
		);

		$this->arrange_programme( $sessions, array( $this->film_payload() ) );
		$this->sync_at();

		// The hook is Elementor's and is spelled the way Elementor spells it. The
		// sniff is for hooks this project declares.
		// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores
		$date_args = apply_filters(
			'elementor/loop_taxonomy/args',
			array(
				'taxonomy'   => ContentModel::SESSION_DATE,
				'hide_empty' => true,
			)
		);
		$day_args  = apply_filters(
			'elementor/loop_taxonomy/args',
			array(
				'taxonomy'   => ContentModel::SESSION_WEEKDAY,
				'hide_empty' => true,
			)
		);
		// phpcs:enable WordPress.NamingConventions.ValidHookName.UseUnderscores

		$this->assertSame(
			array( '2036-08-01', '2036-08-05' ),
			wp_list_pluck( get_terms( $date_args ), 'slug' )
		);
		$this->assertSame(
			array( 'tuesday', 'friday' ),
			wp_list_pluck( get_terms( $day_args ), 'slug' )
		);
	}

	/**
	 * The shape two Taxonomy Filter widgets add to the loop query. WordPress
	 * joins separate taxonomy clauses with AND unless told otherwise.
	 *
	 * @param  string $date    An exact local date slug.
	 * @param  string $weekday A local weekday slug.
	 * @return array<int,int>
	 */
	private function sessions_filed_under( string $date, string $weekday ): array {
		return get_posts(
			array(
				'post_type'   => ContentModel::SESSION,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
				'orderby'     => array( 'menu_order' => 'ASC' ),
				'tax_query'   => array(
					array(
						'taxonomy' => ContentModel::SESSION_DATE,
						'field'    => 'slug',
						'terms'    => array( $date ),
					),
					array(
						'taxonomy' => ContentModel::SESSION_WEEKDAY,
						'field'    => 'slug',
						'terms'    => array( $weekday ),
					),
				),
			)
		);
	}
}
