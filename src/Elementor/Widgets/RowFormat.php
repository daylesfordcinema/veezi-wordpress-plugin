<?php
/**
 * How a row of the session-times widget reads.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * The panel's answers, read once and passed around as one thing.
 *
 * These five settings travel together everywhere they go, and reaching into the
 * settings array wherever one is needed means the same defaulting written out
 * five times over. Reading them once, here, is also the only place that has to
 * know what a control is called.
 */
final class RowFormat {

	private function __construct(
		public readonly string $time,
		public readonly string $day,
		public readonly string $sold_out,
		public readonly string $few_left
	) {}

	/**
	 * @param array<string,mixed> $settings The widget's controls, as the panel set them.
	 */
	public static function from_settings( array $settings ): self {
		$of = static fn ( string $key ): string => trim( (string) ( $settings[ $key ] ?? '' ) );

		// An empty day format is how a row says it does not name its day, so the
		// switcher collapses into the format rather than travelling beside it.
		$day = 'yes' === ( $settings['show_date'] ?? '' ) ? $of( 'date_format' ) : '';

		return new self(
			$of( 'time_format' ),
			$day,
			$of( 'sold_out_text' ),
			$of( 'few_left_text' )
		);
	}

	public function names_its_day(): bool {
		return '' !== $this->day;
	}
}
