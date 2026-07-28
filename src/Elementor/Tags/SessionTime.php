<?php
/**
 * When a screening starts.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Tags;

use Elementor\Modules\DynamicTags\Module;
use Veezi\WordPress\Presentation\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * On a screening, its own time. On a film, when it next screens.
 *
 * A tag can only ever offer one time, so on a film it offers the soonest. The
 * several times a film screens across a week are a list inside a card — a loop
 * within a loop, which the builder's own loop widget cannot nest — and that is
 * what {@see \Veezi\WordPress\Elementor\Widgets\SessionTimes} is for.
 */
final class SessionTime extends Tag {

	public function get_name() {
		return 'veezi-session-time';
	}

	public function get_title() {
		return esc_html__( 'Session Time', 'veezi-wordpress-plugin' );
	}

	public function get_categories() {
		return array( Module::TEXT_CATEGORY );
	}

	protected function value(): string {
		return Fields::session_time( $this->current_post() );
	}
}
