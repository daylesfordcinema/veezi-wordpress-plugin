<?php
/**
 * Something to tell the administrator who just pressed a button.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * The answer to a button press, in a form that survives the redirect after it.
 *
 * Every action on the settings screen runs on a POST and is answered on the GET
 * that follows, so its answer spends a moment in a transient. Hence the array
 * conversions.
 *
 * Three tones rather than two. "A sync is already running" is neither a success
 * nor a failure — nothing happened, and nothing is wrong — and a green notice
 * claiming a sync that did not happen is the more misleading of the two lies
 * available.
 */
final class Message {

	private const SUCCESS = 'success';
	private const ERROR   = 'error';
	private const INFO    = 'info';

	private function __construct(
		private readonly string $level,
		private readonly string $text
	) {}

	public static function success( string $text ): self {
		return new self( self::SUCCESS, $text );
	}

	public static function error( string $text ): self {
		return new self( self::ERROR, $text );
	}

	/**
	 * Neither good news nor bad: something the administrator should know.
	 *
	 * @param string $text What to tell them.
	 */
	public static function info( string $text ): self {
		return new self( self::INFO, $text );
	}

	public function render(): void {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $this->level ),
			esc_html( $this->text )
		);
	}

	/**
	 * @return array<string,string>
	 */
	public function to_array(): array {
		return array(
			'level' => $this->level,
			'text'  => $this->text,
		);
	}

	/**
	 * @param array<string,mixed> $data As produced by to_array(), read back out
	 *                                  of the transient it waited in.
	 */
	public static function from_array( array $data ): ?self {
		$text = isset( $data['text'] ) ? (string) $data['text'] : '';

		if ( '' === $text ) {
			return null;
		}

		$level = isset( $data['level'] ) ? (string) $data['level'] : '';

		// An unrecognised tone reads as a neutral one rather than as a class
		// name going into the page. This value ends up in an attribute.
		if ( ! in_array( $level, array( self::SUCCESS, self::ERROR, self::INFO ), true ) ) {
			$level = self::INFO;
		}

		return new self( $level, $text );
	}
}
