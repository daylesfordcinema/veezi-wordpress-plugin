<?php
/**
 * The film's artwork.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Tags;

use Elementor\Modules\DynamicTags\Module;
use Veezi\WordPress\Presentation\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * The poster, from the media library.
 *
 * Elementor's own Featured Image tag would resolve to the same attachment, and
 * this one exists anyway: it is named for the thing a cinema calls it, it sits
 * with the rest of the programme's fields in the picker, and it ships in free
 * Elementor rather than Pro.
 */
final class Poster extends DataTag {

	public function get_name() {
		return 'veezi-poster';
	}

	public function get_title() {
		return esc_html__( 'Poster', 'veezi-wordpress-plugin' );
	}

	/**
	 * Offered to image controls and to media ones, so the same artwork can be a
	 * card's picture or the background it sits on.
	 */
	public function get_categories() {
		return array( Module::IMAGE_CATEGORY, Module::MEDIA_CATEGORY );
	}

	/**
	 * @return array{id:int,url:string}
	 */
	protected function value(): mixed {
		return Fields::poster( $this->current_post() );
	}
}
