<?php
/**
 * What a film is rated.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Tags;

use Elementor\Modules\DynamicTags\Module;
use Veezi\WordPress\Presentation\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * The rating, as the cinema's own classification board words it — "PG", "M",
 * "CTC". Read from the taxonomy the sync files films under, so a card and a
 * classification archive cannot end up saying different things.
 */
final class Classification extends Tag {

	public function get_name() {
		return 'veezi-classification';
	}

	public function get_title() {
		return esc_html__( 'Classification', 'veezi-wordpress-plugin' );
	}

	public function get_categories() {
		return array( Module::TEXT_CATEGORY );
	}

	protected function value(): string {
		return Fields::classification( $this->current_post() );
	}
}
