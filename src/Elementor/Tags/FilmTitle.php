<?php
/**
 * Which film is screening.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Tags;

use Elementor\Modules\DynamicTags\Module;
use Veezi\WordPress\Presentation\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * The film's title, on a film and on one of its screenings alike.
 *
 * On a film this is Elementor's own post title tag by another name, and it
 * exists for the row underneath. A session record's title is the film and the
 * time together — "The Cook's Tale — Saturday 2 August, 4:30 pm" — because a
 * screen full of them is otherwise unreadable in the admin, so a row of a
 * chronological listing bound to the ordinary post title prints the time twice,
 * the second time in a shape no control can change.
 */
final class FilmTitle extends Tag {

	public function get_name() {
		return 'veezi-film-title';
	}

	public function get_title() {
		return esc_html__( 'Film Title', 'veezi-wordpress-plugin' );
	}

	public function get_categories() {
		return array( Module::TEXT_CATEGORY );
	}

	protected function value(): string {
		return Fields::film_title( $this->current_post() );
	}
}
