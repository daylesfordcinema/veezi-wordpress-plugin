<?php
/**
 * Where the plugin meets the page builder.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor;

use Elementor\Core\DynamicTags\Manager as DynamicTags;
use Elementor\Widgets_Manager;
use Veezi\WordPress\ContentModel;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the plugin knows about Elementor, gathered into one directory.
 *
 * Custom widgets and dynamic tags are the largest maintenance liability in this
 * project — a larger one than the Veezi integration, which is a stable
 * read-only API. Elementor's is neither: it is a third party's internal
 * plumbing, and it moves. Confining the coupling here is what makes a breaking
 * change a bounded problem: everything under `Presentation` answers the same
 * questions with no Elementor in it, so a rewrite of this directory does not
 * touch what the answers are.
 *
 * Nothing here checks whether Elementor is installed, and nothing needs to.
 * These are two hooks Elementor fires; on a site without it they never fire,
 * the classes below are never autoloaded, and the plugin goes on syncing.
 */
final class Integration {

	/**
	 * Which group in the dynamic-data picker the plugin's tags belong to. The
	 * heading itself is set in {@see self::register_tags()}, and leads on
	 * "Veezi" rather than on anything more descriptive: that is the word on the
	 * screen the cinema's staff log into every day, and a group called
	 * "Programme" would be accurate and unfindable.
	 */
	public const GROUP = 'veezi';

	/**
	 * @var array<int,class-string<Tags\Tag>>
	 */
	private const TAGS = array(
		Tags\Availability::class,
		Tags\BookingUrl::class,
		Tags\CastAndCrew::class,
		Tags\Classification::class,
		Tags\FilmTitle::class,
		Tags\Genre::class,
		Tags\NothingScheduled::class,
		Tags\Poster::class,
		Tags\Runtime::class,
		Tags\SessionTime::class,
		Tags\TrailerUrl::class,
	);

	public function register(): void {
		add_action( 'elementor/dynamic_tags/register', array( $this, 'register_tags' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'elementor/frontend/after_register_styles', array( $this, 'register_styles' ) );
		add_filter( 'elementor_pro/utils/get_public_post_types', array( $this, 'offer_sessions_as_a_loop_source' ) );

		// Elementor Pro applies this before fetching a Taxonomy Filter's terms.
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		add_filter( 'elementor/loop_taxonomy/args', array( $this, 'order_session_filter_terms' ) );
	}

	/**
	 * Present generated session terms in calendar order.
	 *
	 * WordPress defaults to sorting terms by their friendly names. That makes
	 * exact dates alphabetical by weekday and weekdays alphabetical by label —
	 * both valid queries and unusable filters. ISO date slugs sort naturally;
	 * weekdays need the explicit order people scan a programme in.
	 *
	 * `include` is preserved when another Elementor control supplied one. Its
	 * ids are reordered rather than widened, so a designer's narrower set stays
	 * narrower.
	 *
	 * @param  array<string,mixed> $args Arguments Elementor will pass to get_terms().
	 * @return array<string,mixed>
	 */
	public function order_session_filter_terms( array $args ): array {
		if ( ContentModel::SESSION_DATE === ( $args['taxonomy'] ?? '' ) ) {
			$args['orderby'] = 'slug';
			$args['order']   = 'ASC';

			return $args;
		}

		if ( ContentModel::SESSION_WEEKDAY !== ( $args['taxonomy'] ?? '' ) ) {
			return $args;
		}

		$included = isset( $args['include'] ) ? array_map( 'intval', (array) $args['include'] ) : array();
		$ordered  = array();

		foreach ( ContentModel::WEEKDAYS as $slug ) {
			$term = get_term_by( 'slug', $slug, ContentModel::SESSION_WEEKDAY );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			if ( array() === $included || in_array( $term->term_id, $included, true ) ) {
				$ordered[] = $term->term_id;
			}
		}

		if ( array() !== $ordered ) {
			$args['include'] = $ordered;
			$args['orderby'] = 'include';
		}

		return $args;
	}

	/**
	 * Put Sessions in the loop widget's Source list.
	 *
	 * The chronological listing is a loop grid pointed at sessions, so the post
	 * type has to be selectable in the builder. Elementor Pro builds that list
	 * from post types registered with `show_in_nav_menus` — not, as the
	 * registration in {@see ContentModel} long assumed, from `public` — and
	 * sessions deliberately have it off: a session has no address of its own, so
	 * a nav menu offering one would be offering a link to a 404.
	 *
	 * Naming the post type here rather than turning that flag on keeps both facts
	 * true — the builder can list sessions, and nothing else can link to one.
	 * Without it the calendar cannot be built at all: the Source control simply
	 * does not offer sessions, and whoever is in the builder reaches for films
	 * instead and gets one row per film rather than one per screening.
	 *
	 * @param  array<string,string> $post_types Label by post type name.
	 * @return array<string,string>
	 */
	public function offer_sessions_as_a_loop_source( array $post_types ): array {
		$session = get_post_type_object( ContentModel::SESSION );

		if ( null !== $session ) {
			$post_types[ ContentModel::SESSION ] = $session->label;
		}

		return $post_types;
	}

	/**
	 * @param DynamicTags $tags Elementor's dynamic tags manager.
	 */
	public function register_tags( DynamicTags $tags ): void {
		$tags->register_group(
			self::GROUP,
			array( 'title' => __( 'Veezi Programme', 'veezi-wordpress-plugin' ) )
		);

		foreach ( self::TAGS as $tag ) {
			$tags->register( new $tag() );
		}
	}

	/**
	 * @param Widgets_Manager $widgets Elementor's widget manager.
	 */
	public function register_widgets( Widgets_Manager $widgets ): void {
		$widgets->register( new Widgets\SessionTimes() );
	}

	/**
	 * Registered rather than enqueued: Elementor loads a widget's stylesheet
	 * only on pages where that widget actually appears, which it works out from
	 * the handle the widget names. A site with no session times on it downloads
	 * nothing.
	 */
	public function register_styles(): void {
		wp_register_style(
			Widgets\SessionTimes::STYLE,
			plugins_url( 'assets/session-times.css', \Veezi\WordPress\PLUGIN_FILE ),
			array(),
			\Veezi\WordPress\VERSION
		);
	}
}
