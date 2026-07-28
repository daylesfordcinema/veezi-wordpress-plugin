<?php
/**
 * Where to buy a ticket.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Tags;

use Elementor\Modules\DynamicTags\Module;
use Veezi\WordPress\Presentation\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * A link out to Veezi's own booking page for one screening.
 *
 * Rendered for a visitor to click and never followed by the site itself. These
 * sit behind a bot challenge, so a server-side fetch to check one gets a
 * challenge page rather than an answer — and a link checker that treated that
 * as a broken link would be reporting on the challenge, not the cinema.
 *
 * Empty for a screening nobody can buy a ticket for, which is what stops a
 * button being rendered with nowhere to go.
 */
final class BookingUrl extends DataTag {

	public function get_name() {
		return 'veezi-booking-url';
	}

	public function get_title() {
		return esc_html__( 'Booking Link', 'veezi-wordpress-plugin' );
	}

	public function get_categories() {
		return array( Module::URL_CATEGORY );
	}

	protected function value(): mixed {
		return Fields::booking_url( $this->current_post() );
	}
}
