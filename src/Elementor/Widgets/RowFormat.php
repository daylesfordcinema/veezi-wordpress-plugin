<?php
/**
 * How a row of the session-times widget reads.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Widgets;

use Veezi\WordPress\Presentation\Badges;

defined( 'ABSPATH' ) || exit;

/**
 * The panel's answers, read once and passed around as one thing.
 *
 * These settings travel together everywhere they go, and reaching into the
 * settings array wherever one is needed means the same defaulting written out
 * over and over. Reading them once, here, is also what keeps knowledge of what
 * a control is called out of the rendering.
 *
 * The three badge words are a {@see Badges} rather than three more fields on
 * this, because the dynamic tag offering the same three has no widget and no
 * row format — and only one of the two should have to be edited when a fourth
 * state turns up.
 */
final class RowFormat {

	private function __construct(
		public readonly string $time,
		public readonly string $day,
		public readonly Badges $badges
	) {}

	/**
	 * @param array<string,mixed> $settings The widget's controls, as the panel set them.
	 */
	public static function from_settings( array $settings ): self {
		$of = static fn ( string $key ): string => trim( (string) ( $settings[ $key ] ?? '' ) );

		// An empty day format is how a row says it does not name its day, so the
		// switcher collapses into the format rather than travelling beside it.
		$day = 'yes' === ( $settings['show_date'] ?? '' ) ? $of( 'date_format' ) : '';

		return new self( $of( 'time_format' ), $day, Badges::from_settings( $settings ) );
	}

	public function names_its_day(): bool {
		return '' !== $this->day;
	}
}
