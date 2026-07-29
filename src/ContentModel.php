<?php
/**
 * The post types, taxonomies and image sizes the programme is stored in.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Registers films, sessions, the taxonomies that classify them and the size a
 * poster is shown at.
 *
 * The programme is kept as ordinary WordPress content rather than in tables of
 * its own, because that is what makes it reachable from the page builder — a
 * designer binds a film's fields exactly as they would bind any other post's,
 * and a listing is a query anyone can point a loop widget at. Custom tables
 * would have been tidier and would have put the whole thing out of reach of the
 * people who have to maintain it.
 *
 * Films and sessions are separate post types rather than sessions living as
 * metadata on a film, because the chronological listing is a time-ordered list
 * of sessions across every film. That listing needs sessions to be queryable
 * and sortable in their own right.
 */
final class ContentModel {

	public const FILM    = 'veezi_film';
	public const SESSION = 'veezi_session';

	public const GENRE          = 'veezi_genre';
	public const CLASSIFICATION = 'veezi_classification';
	public const LISTING        = 'veezi_listing';

	public const NOW_SHOWING = 'now-showing';

	/*
	 * The fields the sync maintains.
	 *
	 * Underscore-prefixed, because nothing here is for a person to edit — a
	 * sync would overwrite the change within the hour. They are still the
	 * plugin's public surface, though: the dynamic tags a designer binds to
	 * read exactly these, so renaming one breaks templates on live sites.
	 */

	public const FILM_ID          = '_veezi_film_id';
	public const FILM_RUNTIME     = '_veezi_runtime';
	public const FILM_DISTRIBUTOR = '_veezi_distributor';
	public const FILM_RELEASED    = '_veezi_released_on';
	public const FILM_TRAILER     = '_veezi_trailer_url';

	/**
	 * Everybody credited on the film, encoded. The one field here that is a list
	 * rather than a value, because a role is a fact about a person *on this
	 * film* and there is nowhere else to keep it. See {@see Person::encode()}.
	 */
	public const FILM_PEOPLE = '_veezi_people';

	/*
	 * Two answers the sync works out and writes down, because a listing would
	 * otherwise have to ask the database a second question for every row it
	 * renders — and the page builder cannot ask that question at all.
	 */

	public const FILM_NEXT_SCREENING = '_veezi_next_screening';
	public const FILM_SESSION_COUNT  = '_veezi_session_count';

	/**
	 * Which Veezi media a poster was copied from. Kept on the attachment rather
	 * than the film, because it describes where those bytes came from and stays
	 * true however the media is reused afterwards.
	 */
	public const POSTER_SOURCE = '_veezi_poster_source';

	public const SESSION_ID          = '_veezi_session_id';
	public const SESSION_FILM        = '_veezi_film';
	public const SESSION_STARTS      = '_veezi_starts_at';
	public const SESSION_ENDS        = '_veezi_ends_at';
	public const SESSION_STARTS_TEXT = '_veezi_starts_at_text';
	public const SESSION_ENDS_TEXT   = '_veezi_ends_at_text';
	public const SESSION_BOOKING     = '_veezi_booking_url';
	public const SESSION_STATUS      = '_veezi_status';
	public const SESSION_SOLD_OUT    = '_veezi_sold_out';
	public const SESSION_FEW_LEFT    = '_veezi_few_tickets_left';

	/**
	 * The size a card should ask a poster for. Not a field — an image size, and
	 * part of the same public surface: a template binding to it by name breaks
	 * if this is renamed.
	 *
	 * Upstream artwork is around 1340x1920 and the only smaller variant Veezi
	 * offers is 125x182, a thumbnail for a box-office screen. WordPress's own
	 * `medium` is 300px wide, which is thin on a modern display. 600 is a card
	 * at twice its rendered width, and comfortable as the hero of a film page
	 * too — which is the other place it is asked for.
	 *
	 * Registered here rather than reaching for one of WordPress's own, because
	 * WordPress's own are a site setting and can be turned off: on the site this
	 * was written for `large` is not generated at all, so a template asking for
	 * it silently gets the full-resolution original instead. Measured on a real
	 * poster there: 588x900 at 118KB from this size, against 446KB for the
	 * original — and the originals run to five megabytes when a distributor
	 * supplies a lossless PNG.
	 */
	public const POSTER_SIZE = 'veezi-poster';

	/**
	 * Which version of the plugin last rebuilt the site's routing table.
	 */
	public const REWRITES_VERSION = 'veezi_rewrites_version';

	/**
	 * Ask a session query for every screening, including the ones already under
	 * way. See {@see self::hide_screenings_that_have_started()}.
	 */
	public const EVERY_SCREENING = 'veezi_every_screening';

	/**
	 * Keep a screening out of a listing from the moment it starts.
	 *
	 * Nearly everything about which records a listing holds is settled at sync
	 * time: past screenings are deleted, so "what is still to come" needs no
	 * date filter and the page builder — which could not express one anyway —
	 * has nothing to configure. This is the one thing that cannot be, because
	 * the sync runs hourly and the question changes every minute. A listing
	 * driven by the records alone would go on offering a screening for as long
	 * as an hour after it began.
	 *
	 * A screening is deleted once it *ends* rather than once it starts, and
	 * that is deliberate too: a film's own card should not claim it next
	 * screens tomorrow while an audience is sitting in it. So the record
	 * outlives the listing entry, and {@see Presentation\Screening::for_film()}
	 * asks for every screening while the chronological listing does not.
	 *
	 * Two kinds of query are exempt. A screen of wp-admin, because an
	 * administrator looking at Sessions is looking at the records rather than
	 * at what a visitor sees — but not an admin-ajax request, which is
	 * `is_admin()` too and is how the loop grid fetches its second page. And
	 * anything that asks for {@see self::EVERY_SCREENING}, which the sync's own
	 * lookup must: a record it fails to find is one it creates a second copy of.
	 *
	 * @param WP_Query $query Any query WordPress is about to run.
	 */
	public static function hide_screenings_that_have_started( WP_Query $query ): void {
		if ( ( is_admin() && ! wp_doing_ajax() ) || $query->get( self::EVERY_SCREENING ) ) {
			return;
		}

		if ( ! in_array( self::SESSION, (array) $query->get( 'post_type' ), true ) ) {
			return;
		}

		$existing = $query->get( 'meta_query' );
		$mine     = array(
			'key'     => self::SESSION_STARTS,

			// To the minute, not to the second. This value goes into the key
			// WordPress caches the query's results under, so one that moves
			// every second is a cache this query can never hit and a fresh
			// entry left behind every second — on a host running a persistent
			// object cache, which is the one this is written for. The cost is
			// that a screening can stay listed for its first minute.
			'value'   => (string) ( time() - time() % MINUTE_IN_SECONDS ),
			'compare' => '>',
			'type'    => 'NUMERIC',
		);

		// Wrapped rather than appended. A query carrying meta conditions of its
		// own keeps them — but appending to them only ands the two while the
		// existing set is an AND. Appended to an OR this would *widen* the
		// query, and a screening under way would reappear in the one listing
		// this exists to keep it out of.
		$query->set(
			'meta_query',
			is_array( $existing ) && array() !== $existing
				? array(
					'relation' => 'AND',
					$existing,
					$mine,
				)
				: array( $mine )
		);
	}

	public static function register(): void {
		self::register_post_types();
		self::register_taxonomies();

		// Uncropped: a poster is a designed rectangle whose proportions vary
		// from one distributor to the next, and cropping to a uniform card
		// takes the slice with the title on it. A grid can letterbox them.
		add_image_size( self::POSTER_SIZE, 600, 900, false );
	}

	/**
	 * Rebuild the site's routing table so film pages have addresses.
	 *
	 * Rules only — the second argument stops WordPress rewriting `.htaccess`,
	 * which it has no business doing on a host where that file may not even be
	 * writable, and which the film routes do not need.
	 */
	public static function flush_rewrites(): void {
		flush_rewrite_rules( false );
		update_option( self::REWRITES_VERSION, VERSION );
	}

	/**
	 * The same, but only when this version has not done it yet.
	 *
	 * WordPress caches its routing table and rebuilds it only when asked.
	 * Activating a plugin asks — but **updating one in place does not**: the
	 * updater reactivates silently, and silent reactivation skips activation
	 * hooks. So a site that upgrades to a version which adds or moves a film
	 * address serves 404s for every film page until somebody happens to open
	 * Settings → Permalinks, which nobody would think to do. Comparing a
	 * stamped version on `init` is what makes that heal itself.
	 */
	public static function flush_rewrites_when_stale(): void {
		if ( VERSION === get_option( self::REWRITES_VERSION ) ) {
			return;
		}

		self::flush_rewrites();
	}

	private static function register_post_types(): void {
		register_post_type(
			self::FILM,
			array(
				'labels'          => array(
					'name'          => __( 'Films', 'veezi-wordpress-plugin' ),
					'singular_name' => __( 'Film', 'veezi-wordpress-plugin' ),
					'menu_name'     => __( 'Films', 'veezi-wordpress-plugin' ),
					'all_items'     => __( 'All Films', 'veezi-wordpress-plugin' ),
					'search_items'  => __( 'Search Films', 'veezi-wordpress-plugin' ),
					'not_found'     => __( 'No films have been synced yet.', 'veezi-wordpress-plugin' ),
				),
				'public'          => true,
				'has_archive'     => false,
				'menu_icon'       => 'dashicons-format-video',
				'rewrite'         => array(
					'slug'       => 'film',
					'with_front' => false,
				),
				'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'capability_type' => 'post',
				'show_in_rest'    => true,
			)
		);

		register_post_type(
			self::SESSION,
			array(
				'labels'              => array(
					'name'          => __( 'Sessions', 'veezi-wordpress-plugin' ),
					'singular_name' => __( 'Session', 'veezi-wordpress-plugin' ),
					'menu_name'     => __( 'Sessions', 'veezi-wordpress-plugin' ),
					'all_items'     => __( 'All Sessions', 'veezi-wordpress-plugin' ),
					'search_items'  => __( 'Search Sessions', 'veezi-wordpress-plugin' ),
					'not_found'     => __( 'No sessions have been synced yet.', 'veezi-wordpress-plugin' ),
				),

				// Public, yet with every front-end route switched off. The page
				// builder's loop widget only offers post types registered as
				// public, and the chronological listing is built by pointing it
				// at sessions — so sessions have to be public to be listable at
				// all. Nothing should ever land on a single session, though: its
				// whole content is a time and a link, and it exists to be shown
				// inside a listing.
				'public'              => true,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
				'show_in_nav_menus'   => false,

				'menu_icon'           => 'dashicons-clock',
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'show_in_rest'        => false,
			)
		);
	}

	private static function register_taxonomies(): void {
		register_taxonomy(
			self::GENRE,
			self::FILM,
			array(
				'labels'       => array(
					'name'          => __( 'Genres', 'veezi-wordpress-plugin' ),
					'singular_name' => __( 'Genre', 'veezi-wordpress-plugin' ),
				),
				'public'       => true,
				'hierarchical' => false,
				'rewrite'      => array( 'slug' => 'film-genre' ),
				'show_in_rest' => true,
			)
		);

		register_taxonomy(
			self::CLASSIFICATION,
			self::FILM,
			array(
				'labels'       => array(
					'name'          => __( 'Classifications', 'veezi-wordpress-plugin' ),
					'singular_name' => __( 'Classification', 'veezi-wordpress-plugin' ),
				),
				'public'       => true,
				'hierarchical' => false,
				'rewrite'      => array( 'slug' => 'film-classification' ),
				'show_in_rest' => true,
			)
		);

		register_taxonomy(
			self::LISTING,
			self::FILM,
			array(
				'labels'            => array(
					'name'          => __( 'Listings', 'veezi-wordpress-plugin' ),
					'singular_name' => __( 'Listing', 'veezi-wordpress-plugin' ),
				),

				// Maintained by the sync, so there is nothing useful for anyone
				// to do with it by hand — but it must stay queryable, because
				// selecting this term from a dropdown is how a listing gets
				// built without writing a query.
				'public'            => true,
				'show_ui'           => false,
				'show_in_nav_menus' => false,
				'hierarchical'      => false,
				'rewrite'           => false,
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * The id of a listing term, creating it if this is the first time it has
	 * been needed.
	 *
	 * Terms are made on demand rather than on activation so that a site which
	 * activated an earlier version, or had its terms deleted, heals itself on
	 * the next sync instead of silently losing a listing.
	 *
	 * @param string $slug Which listing.
	 */
	public static function listing_term( string $slug ): int {
		$existing = get_term_by( 'slug', $slug, self::LISTING );

		if ( $existing instanceof \WP_Term ) {
			return $existing->term_id;
		}

		$created = wp_insert_term( self::listing_name( $slug ), self::LISTING, array( 'slug' => $slug ) );

		if ( is_wp_error( $created ) ) {
			// Another process won the race between the lookup and the insert.
			$existing = get_term_by( 'slug', $slug, self::LISTING );

			return $existing instanceof \WP_Term ? $existing->term_id : 0;
		}

		return (int) $created['term_id'];
	}

	private static function listing_name( string $slug ): string {
		if ( self::NOW_SHOWING === $slug ) {
			return __( 'Now Showing', 'veezi-wordpress-plugin' );
		}

		return $slug;
	}
}
