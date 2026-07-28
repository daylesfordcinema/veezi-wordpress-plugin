<?php
/**
 * The Veezi access token.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

use JsonSerializable;

defined( 'ABSPATH' ) || exit;

/**
 * The cinema's API credential, and where it came from.
 *
 * A value object rather than a bare string, so that the ways a secret usually
 * escapes — interpolation into a log line, a var_dump of some enclosing
 * structure, a JSON response — yield the mask instead. Reading the real value
 * is deliberately explicit: only `value()` returns it, and only the HTTP client
 * calls that.
 */
final class Token implements JsonSerializable {

	/**
	 * Defining this in wp-config.php overrides whatever is stored, so a
	 * staging site can point at a different Veezi account without a database
	 * change, and a production credential need never be typed into a browser.
	 */
	public const CONSTANT = 'VEEZI_API_TOKEN';

	public const SOURCE_CONSTANT = 'constant';
	public const SOURCE_OPTION   = 'option';
	public const SOURCE_NONE     = 'none';

	/** How many trailing characters the mask reveals, to tell tokens apart. */
	private const VISIBLE_CHARACTERS = 4;

	/** Below this length, revealing the tail would give away too much of it. */
	private const MINIMUM_LENGTH_TO_REVEAL_ANY = 12;

	private const MASK = '••••••••';

	private function __construct(
		#[\SensitiveParameter]
		private readonly string $value,
		private readonly string $source
	) {}

	public static function resolve( Settings $settings ): self {
		$from_constant = defined( self::CONSTANT ) ? trim( (string) constant( self::CONSTANT ) ) : '';

		if ( '' !== $from_constant ) {
			return new self( $from_constant, self::SOURCE_CONSTANT );
		}

		$stored = trim( $settings->token() );

		if ( '' !== $stored ) {
			return new self( $stored, self::SOURCE_OPTION );
		}

		return new self( '', self::SOURCE_NONE );
	}

	public function value(): string {
		return $this->value;
	}

	public function source(): string {
		return $this->source;
	}

	public function is_present(): bool {
		return '' !== $this->value;
	}

	/**
	 * Safe to display, log or send to a browser.
	 */
	public function masked(): string {
		if ( '' === $this->value ) {
			return '';
		}

		if ( strlen( $this->value ) < self::MINIMUM_LENGTH_TO_REVEAL_ANY ) {
			return self::MASK;
		}

		return self::MASK . substr( $this->value, -self::VISIBLE_CHARACTERS );
	}

	/**
	 * Remove the token from text that came from somewhere else.
	 *
	 * Upstream error messages are worth showing an administrator verbatim —
	 * "could not resolve host" is the whole diagnosis — but they are text this
	 * plugin did not write, so they are scrubbed before being repeated.
	 *
	 * @param string $text Text from outside the plugin, about to be displayed
	 *                     or logged.
	 */
	public function redact( string $text ): string {
		if ( '' === $this->value ) {
			return $text;
		}

		return str_replace( $this->value, $this->masked(), $text );
	}

	public function __toString(): string {
		return $this->masked();
	}

	/**
	 * Covers print_r and var_dump, which read private properties otherwise.
	 *
	 * @return array<string,string>
	 */
	public function __debugInfo(): array {
		return array(
			'value'  => $this->masked(),
			'source' => $this->source,
		);
	}

	public function jsonSerialize(): string {
		return $this->masked();
	}
}
