<?php
/**
 * What every one of the plugin's dynamic tags has in common.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Tags;

use Veezi\WordPress\Elementor\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by both kinds of tag the plugin registers.
 *
 * A trait rather than a base class because Elementor has two unrelated
 * abstracts — one for tags that render text and one for tags that hand back a
 * value — and a tag has to extend the right one of them. What they have in
 * common is which heading they appear under and which record they read.
 */
trait ReadsCurrentRecord {

	final public function get_group() {
		return Integration::GROUP;
	}

	/**
	 * The record being looped over, or zero outside a loop.
	 *
	 * This is the whole of how these work inside a loop grid: each item is
	 * rendered with its post set up as the current one, so there is nothing for
	 * a designer to name and nothing to configure, and a duplicated template
	 * behaves exactly like the one it was copied from.
	 */
	final protected function current_post(): int {
		return (int) get_the_ID();
	}
}
