<?php
/**
 * The words a screening's state is said in.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Presentation;

defined( 'ABSPATH' ) || exit;

/**
 * What "sold out", "nearly gone" and "not on sale yet" read as on this site.
 *
 * The words are the designer's rather than the plugin's, because one panel says
 * "Sold out" and the next "Full house" and neither should need a translation
 * file to change. They travel together everywhere they go — a widget's controls,
 * a dynamic tag's controls, and the one method that decides which of them a
 * screening has earned — so they travel as one thing.
 *
 * Empty is a legitimate value for any of them: a badge nobody has written words
 * for renders nothing, which is what a listing that would rather not say wants.
 */
final class Badges {

	public function __construct(
		public readonly string $sold_out = '',
		public readonly string $few_left = '',
		public readonly string $on_sale_soon = ''
	) {}

	/**
	 * The three controls, read off a panel.
	 *
	 * The only place that knows what they are called, which is what keeps a
	 * widget and a tag offering the same three names — and what makes renaming
	 * one a single edit rather than a hunt.
	 *
	 * @param array<string,mixed> $settings The controls, as the panel set them.
	 */
	public static function from_settings( array $settings ): self {
		$of = static fn ( string $key ): string => trim( (string) ( $settings[ $key ] ?? '' ) );

		return new self(
			$of( 'sold_out_text' ),
			$of( 'few_left_text' ),
			$of( 'on_sale_soon_text' )
		);
	}
}
