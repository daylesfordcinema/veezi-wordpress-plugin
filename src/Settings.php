<?php
/**
 * Stored plugin settings.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's single site option, and the sanitising that guards it.
 *
 * One option holding an array rather than an option per field, so that adding
 * a setting later needs no migration and reading them all is one query.
 */
final class Settings {

	public const OPTION = 'veezi_settings';

	/** The settings group, as registered with WordPress's Settings API. */
	public const GROUP = 'veezi_settings';

	/** Checkbox asking for the stored token to be forgotten. Never persisted. */
	public const DELETE_TOKEN_FIELD = 'delete_token';

	/**
	 * Matches anything a token may *not* contain, for stripping.
	 *
	 * The permitted set is deliberately broader than the alphanumeric tokens
	 * seen in practice: another Veezi account may be issued something in hex,
	 * base64 or GUID form, and silently truncating a valid token would present
	 * as an authentication failure with no explanation. Everything outside it
	 * is removed — carriage returns above all, since the value ends up in an
	 * HTTP request header.
	 */
	private const DISALLOWED_TOKEN_CHARACTERS = '/[^A-Za-z0-9._~+\/=-]/';

	private const DEFAULTS = array(
		'token' => '',
	);

	/**
	 * Hand the option to WordPress's Settings API, which is what gives the
	 * settings form its nonce and its capability check.
	 */
	public function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'object',
				'default'           => self::DEFAULTS,
				'sanitize_callback' => array( $this, 'sanitize' ),
				// The token is a credential: it must not become readable
				// through the REST API by anyone who can read options.
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
	}

	public function token(): string {
		return (string) $this->all()['token'];
	}

	/**
	 * @param array<string,mixed> $values Merged over what is already stored.
	 */
	public function update( array $values ): void {
		update_option( self::OPTION, array_merge( $this->all(), $values ) );
	}

	/**
	 * @param mixed $input Whatever was submitted.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$clean = $this->all();

		if ( ! is_array( $input ) ) {
			return $clean;
		}

		if ( ! empty( $input[ self::DELETE_TOKEN_FIELD ] ) ) {
			$clean['token'] = '';

			return $clean;
		}

		if ( array_key_exists( 'token', $input ) ) {
			$submitted = trim( (string) $input['token'] );

			// An empty field means "leave it as it is", not "delete it". The
			// field is rendered empty on every page load because the stored
			// token is never sent back to the browser, so treating empty as a
			// deletion would wipe the token every time an administrator saved
			// any other setting.
			if ( '' !== $submitted ) {
				$clean['token'] = $this->clean_token( $submitted );
			}
		}

		return $clean;
	}

	private function clean_token( string $submitted ): string {
		$cleaned = (string) preg_replace( self::DISALLOWED_TOKEN_CHARACTERS, '', $submitted );

		if ( $cleaned === $submitted ) {
			return $cleaned;
		}

		// Report rather than silently accept a mangled value: a truncated
		// token fails authentication, and an administrator staring at a
		// rejected connection deserves to know the field was altered.
		add_settings_error(
			self::OPTION,
			'veezi_token_characters',
			__( 'The access token contained characters that cannot appear in one, and they were removed. Check that you pasted the whole token and nothing else.', 'veezi-wordpress-plugin' ),
			'warning'
		);

		return $cleaned;
	}
}
