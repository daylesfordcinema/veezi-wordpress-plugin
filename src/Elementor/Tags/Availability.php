<?php
/**
 * Whether there are still seats.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Tags;

use Elementor\Controls_Manager;
use Elementor\Modules\DynamicTags\Module;
use Veezi\WordPress\Presentation\Badges;
use Veezi\WordPress\Presentation\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * "Sold out", "Few tickets left", "On sale soon", or nothing at all.
 *
 * The same badge {@see \Veezi\WordPress\Elementor\Widgets\SessionTimes} renders
 * inside a card, offered as a field — because a chronological listing is built
 * from the page builder's own widgets with no widget of the plugin's in the row,
 * so there is nothing there to render it.
 *
 * Nothing at all is the ordinary case, and it is deliberately empty rather than
 * "On sale": a listing where every row carries a badge has no badge. Bind it to
 * a widget you are content to see render empty for most of the week.
 *
 * Only the numbers behind it never reach the site. Veezi reports seats sold and
 * seats held on the same record; the sync keeps the two booleans and discards
 * the rest, so a cinema's takings cannot be read off a page that shows this.
 */
final class Availability extends Tag {

	public function get_name() {
		return 'veezi-availability';
	}

	public function get_title() {
		return esc_html__( 'Availability', 'veezi-wordpress-plugin' );
	}

	public function get_categories() {
		return array( Module::TEXT_CATEGORY );
	}

	/**
	 * Labels are escaped and defaults are not: a label is text Elementor
	 * prints, a default is a value it stores and escapes again where it puts
	 * it. An escaped default would be double-encoded on the page, and saved
	 * that way into every template it was placed in.
	 */
	protected function register_controls() {
		$this->add_control(
			'sold_out_text',
			array(
				'label'   => esc_html__( 'Sold out reads', 'veezi-wordpress-plugin' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Sold out', 'veezi-wordpress-plugin' ),
				'ai'      => array( 'active' => false ),
			)
		);

		$this->add_control(
			'few_left_text',
			array(
				'label'   => esc_html__( 'Nearly sold out reads', 'veezi-wordpress-plugin' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Few tickets left', 'veezi-wordpress-plugin' ),
				'ai'      => array( 'active' => false ),
			)
		);

		$this->add_control(
			'on_sale_soon_text',
			array(
				'label'       => esc_html__( 'Not on sale yet reads', 'veezi-wordpress-plugin' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'On sale soon', 'veezi-wordpress-plugin' ),
				'description' => esc_html__( 'Only ever seen where Settings → Veezi has been asked to publish what is coming.', 'veezi-wordpress-plugin' ),
				'ai'          => array( 'active' => false ),
			)
		);
	}

	protected function value(): string {
		return Fields::availability( $this->current_post(), Badges::from_settings( $this->get_settings() ) );
	}
}
