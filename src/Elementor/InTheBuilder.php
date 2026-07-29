<?php
/**
 * Telling the builder apart from the site.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor;

use Elementor\Plugin as Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Whoever is looking at this: a visitor, or the person building the page.
 *
 * Shared by the two things that answer differently. Silent emptiness is the
 * hardest state for a designer to diagnose — a missing token, a preview set to
 * the wrong record and a cinema between seasons all look identical — so the
 * builder is told which of them it is, and the visitor is told only what they
 * could act on.
 *
 * A trait because its two users are a widget and a tag, which extend unrelated
 * Elementor abstracts and can share no base class of ours.
 */
trait InTheBuilder {

	/**
	 * Both halves are needed: the editor is the outer frame, and what it wraps
	 * is a preview of the page rendering itself.
	 */
	final protected function is_being_designed(): bool {
		return Elementor::$instance->editor->is_edit_mode()
			|| Elementor::$instance->preview->is_preview_mode();
	}
}
