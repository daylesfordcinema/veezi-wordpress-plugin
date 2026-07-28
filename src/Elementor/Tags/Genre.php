<?php
/**
 * What kind of film it is.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Tags;

use Elementor\Modules\DynamicTags\Module;
use Veezi\WordPress\Presentation\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Every genre a film is filed under, not just the first.
 *
 * Upstream sends them as one comma-separated string; the sync splits it into
 * terms, and this puts them back together. Going through the taxonomy rather
 * than keeping the original string is what makes a card and a genre archive
 * agree — at the cost of alphabetical order rather than the cinema's own.
 */
final class Genre extends Tag {

	public function get_name() {
		return 'veezi-genre';
	}

	public function get_title() {
		return esc_html__( 'Genre', 'veezi-wordpress-plugin' );
	}

	public function get_categories() {
		return array( Module::TEXT_CATEGORY );
	}

	protected function value(): string {
		return Fields::genre( $this->current_post() );
	}
}
