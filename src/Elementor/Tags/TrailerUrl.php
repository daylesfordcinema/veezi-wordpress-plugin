<?php
/**
 * Where the trailer is.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Tags;

use Elementor\Modules\DynamicTags\Module;
use Veezi\WordPress\Presentation\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * The trailer as Veezi gives it: a YouTube watch link.
 *
 * Left in that form deliberately. Elementor's video widget takes a watch link
 * and works out the embed itself, so converting it here would hand that widget
 * something it does not expect in order to serve a case it does not have.
 */
final class TrailerUrl extends DataTag {

	public function get_name() {
		return 'veezi-trailer-url';
	}

	public function get_title() {
		return esc_html__( 'Trailer Link', 'veezi-wordpress-plugin' );
	}

	public function get_categories() {
		return array( Module::URL_CATEGORY );
	}

	protected function value(): mixed {
		return Fields::trailer_url( $this->current_post() );
	}
}
