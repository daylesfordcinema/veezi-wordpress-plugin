<?php
/**
 * A tag that displays a piece of text.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Tags;

use Elementor\Core\DynamicTags\Tag as ElementorTag;

defined( 'ABSPATH' ) || exit;

/**
 * A tag that reads one field off whatever record it finds itself on.
 *
 * There is one class per tag because Elementor's manager keys tags by name and
 * builds them with `new $class`, so a name cannot be a constructor argument.
 * The subclasses are therefore as small as that constraint allows: a name, a
 * label, where it belongs in the picker, and which field to read.
 *
 * Extending this rather than {@see DataTag} is what gives a tag Elementor's own
 * Before, After and Fallback controls. That is why the runtime tag can return a
 * bare number and leave the word "min" to whoever is designing the card.
 */
abstract class Tag extends ElementorTag {

	use ReadsCurrentRecord;

	/**
	 * The value to display, read from the record being looped over.
	 */
	abstract protected function value(): string;

	protected function render() {
		echo esc_html( $this->value() );
	}
}
