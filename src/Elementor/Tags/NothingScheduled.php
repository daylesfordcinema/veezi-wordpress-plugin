<?php
/**
 * What a listing says when there is no listing.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Tags;

use Elementor\Controls_Manager;
use Elementor\Modules\DynamicTags\Module;
use Veezi\WordPress\Elementor\InTheBuilder;
use Veezi\WordPress\Presentation\Screening;
use Veezi\WordPress\SyncLog;

defined( 'ABSPATH' ) || exit;

/**
 * A sentence when the cinema has nothing on, and silence the rest of the year.
 *
 * A loop grid with nothing to loop over renders nothing, and nothing is exactly
 * what a site whose token has stopped working renders too. So a page that lists
 * a programme wants somewhere to say which — put a heading below the listing and
 * bind it to this.
 *
 * The one tag here that reads no record. It is a fact about the cinema rather
 * than about a film, so it answers the same wherever it is dropped, and it sits
 * outside the loop where an empty listing still leaves something on the page.
 * Bind it to a widget you are content to see render empty, because for as long
 * as there is a programme that is what it does.
 *
 * Who is told what is the whole of the design. A visitor is told the cinema has
 * nothing scheduled, which is all they could act on either way. Whoever is
 * building the page is told when nothing has ever synced, because that is a
 * fault and they are the person who can go and fix it.
 */
final class NothingScheduled extends Tag {

	use InTheBuilder;

	public function get_name() {
		return 'veezi-nothing-scheduled';
	}

	public function get_title() {
		return esc_html__( 'Nothing Scheduled', 'veezi-wordpress-plugin' );
	}

	public function get_categories() {
		return array( Module::TEXT_CATEGORY );
	}

	protected function register_controls() {
		$this->add_control(
			'message',
			array(
				'label'       => esc_html__( 'Reads', 'veezi-wordpress-plugin' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'default'     => __( 'There is nothing scheduled at the moment.', 'veezi-wordpress-plugin' ),
				'description' => esc_html__( 'Shown only while the cinema has nothing still to come.', 'veezi-wordpress-plugin' ),
				'ai'          => array( 'active' => false ),
			)
		);
	}

	protected function value(): string {
		if ( Screening::any_upcoming() ) {
			return '';
		}

		// Counting screenings could not tell these apart, which is why the sync
		// records that it finished: a cinema between seasons has none and is
		// working perfectly, and telling it to go and check its connection
		// sends somebody looking for a fault that is not there.
		if ( $this->is_being_designed() && ! SyncLog::has_ever_succeeded() ) {
			return __( 'No programme has synced yet. Check the connection under Settings → Veezi.', 'veezi-wordpress-plugin' );
		}

		return (string) $this->get_settings( 'message' );
	}
}
