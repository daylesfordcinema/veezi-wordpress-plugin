<?php
/**
 * When a screening starts.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Tags;

use Elementor\Controls_Manager;
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
 *
 * The format is a control, which is what lets a chronological listing group
 * itself: the same tag bound twice in a row gives the day it belongs under and
 * the time it starts. A tag of its own for the day would have been a second
 * name in the picker for one question, differing by a default string.
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

	protected function register_controls() {
		$this->add_control(
			'format',
			array(
				'label'       => esc_html__( 'Format', 'veezi-wordpress-plugin' ),
				'type'        => Controls_Manager::TEXT,

				// Empty means the site's own, worked out at render time — so a
				// tag dropped in and left alone matches the rest of the site
				// rather than imposing a house style nobody chose, and follows
				// Settings → General being changed afterwards.
				//
				// The site's format is the placeholder rather than the default
				// for that reason. Elementor builds a tag's controls once per
				// request and caches them by class, so a default read from an
				// option is the value that option held the first time anything
				// on the page asked — which is exactly the staleness this tag
				// exists to avoid.
				'default'     => '',
				'placeholder' => Fields::site_format(),
				'description' => esc_html__( 'The same format codes as Settings → General. A date on its own gives a listing its day heading. Leave it empty for the site’s own format.', 'veezi-wordpress-plugin' ),
				'ai'          => array( 'active' => false ),
			)
		);
	}

	protected function value(): string {
		return Fields::session_time( $this->current_post(), (string) $this->get_settings( 'format' ) );
	}
}
