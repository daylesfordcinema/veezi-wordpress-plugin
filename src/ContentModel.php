<?php
/**
 * The post types and taxonomies the programme is stored in.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Registers films, sessions and the taxonomies that classify them.
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

	/*
	 * Two answers the sync works out and writes down, because a listing would
	 * otherwise have to ask the database a second question for every row it
	 * renders — and the page builder cannot ask that question at all.
	 */

	public const FILM_NEXT_SCREENING = '_veezi_next_screening';
	public const FILM_SESSION_COUNT  = '_veezi_session_count';

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
	 * Which version of the plugin last rebuilt the site's routing table.
	 */
	public const REWRITES_VERSION = 'veezi_rewrites_version';

	public static function register(): void {
		self::register_post_types();
		self::register_taxonomies();
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
