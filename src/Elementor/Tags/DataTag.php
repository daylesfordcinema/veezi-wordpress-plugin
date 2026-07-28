<?php
/**
 * A tag that hands back a value rather than displaying one.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Tags;

use Elementor\Core\DynamicTags\Data_Tag;

defined( 'ABSPATH' ) || exit;

/**
 * The base for tags whose value is not text on the page.
 *
 * A link and an image are settings a widget acts on, not words it prints, so
 * they are handed over unescaped and the widget escapes them where it puts
 * them. Escaping here as well would put `&#038;` in the middle of a booking URL
 * and hand a video widget something it cannot parse.
 *
 * `get_value()` is Elementor's shape, complete with a render-options argument
 * none of these tags has any use for. Stating that once and giving the
 * subclasses the same `value()` as {@see Tag} is the whole of what this adds.
 */
abstract class DataTag extends Data_Tag {

	use ReadsCurrentRecord;

	/**
	 * The value to hand over, read from the record being looped over.
	 *
	 * A string for the links; the id-and-URL pair an image control wants for the
	 * poster.
	 */
	abstract protected function value(): mixed;

	/**
	 * @param array<string,mixed> $options Elementor's own render options.
	 */
	public function get_value( array $options = array() ) {
		return $this->value();
	}
}
