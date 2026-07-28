<?php
/**
 * Who made the film.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Tags;

use Elementor\Controls_Manager;
use Elementor\Modules\DynamicTags\Module;
use Veezi\WordPress\Presentation\Credits;
use Veezi\WordPress\Presentation\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * The credits, filtered to one role or not filtered at all.
 *
 * The only one of the plugin's tags that carries a control, and it is here
 * because a film page wants the same field twice under two different headings —
 * "Directed by" above one name, "Starring" above a dozen. Two tags would have
 * done that too, but not a third role, and upstream owns the list of roles.
 *
 * Left on Everyone it names each person's role beside them, so that a page
 * built by dropping the tag once and reading no documentation is still correct.
 */
final class CastAndCrew extends Tag {

	public function get_name() {
		return 'veezi-cast-and-crew';
	}

	/**
	 * Spelled out rather than "Cast & Crew". A tag's title is escaped like every
	 * other label here and then handed to the panel as data rather than as
	 * markup, so an ampersand would arrive in the dropdown as `&amp;`.
	 */
	public function get_title() {
		return esc_html__( 'Cast and Crew', 'veezi-wordpress-plugin' );
	}

	public function get_categories() {
		return array( Module::TEXT_CATEGORY );
	}

	protected function register_controls() {
		$this->add_control(
			'role',
			array(
				'label'       => esc_html__( 'Role', 'veezi-wordpress-plugin' ),
				'type'        => Controls_Manager::SELECT,

				// Everyone is the default because it is the only answer that is
				// complete: a designer who binds this and moves on has the whole
				// credit list rather than one role's worth of it.
				'default'     => '',
				'options'     => array_merge(
					array( '' => esc_html__( 'Everyone, with their roles', 'veezi-wordpress-plugin' ) ),
					Credits::roles()
				),
				'description' => esc_html__( 'One role gives the names alone, for a heading of your own.', 'veezi-wordpress-plugin' ),
			)
		);
	}

	protected function value(): string {
		return Fields::cast_and_crew( $this->current_post(), (string) $this->get_settings( 'role' ) );
	}
}
