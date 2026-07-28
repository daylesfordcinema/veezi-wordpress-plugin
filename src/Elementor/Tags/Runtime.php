<?php
/**
 * How long a film runs.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Tags;

use Elementor\Modules\DynamicTags\Module;
use Veezi\WordPress\Presentation\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * A bare number of minutes.
 *
 * No unit, deliberately: Elementor's own After control puts "min" — or "mins",
 * or " minutes" — after it, which leaves the wording with whoever is designing
 * the card rather than with this file.
 */
final class Runtime extends Tag {

	public function get_name() {
		return 'veezi-runtime';
	}

	public function get_title() {
		return esc_html__( 'Runtime (minutes)', 'veezi-wordpress-plugin' );
	}

	public function get_categories() {
		return array( Module::TEXT_CATEGORY );
	}

	protected function value(): string {
		return Fields::runtime( $this->current_post() );
	}
}
