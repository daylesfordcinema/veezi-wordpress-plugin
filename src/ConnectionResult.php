<?php
/**
 * The outcome of a connection check.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

use JsonSerializable;

defined( 'ABSPATH' ) || exit;

/**
 * What "Test connection" found, in a form that survives a redirect.
 *
 * The check runs on a POST and its answer is shown on the GET that follows, so
 * the result spends a moment in a transient. Hence the array conversions.
 */
final class ConnectionResult implements JsonSerializable {

	private function __construct(
		private readonly bool $success,
		private readonly string $site_name,
		private readonly string $timezone_identifier,
		private readonly string $code,
		private readonly string $message
	) {}

	public static function success( string $site_name, string $timezone_identifier, string $message ): self {
		return new self( true, $site_name, $timezone_identifier, '', $message );
	}

	public static function failure( string $code, string $message ): self {
		return new self( false, '', '', $code, $message );
	}

	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * The cinema's name as Veezi reports it — the thing that tells an
	 * administrator they connected to the right account and not, say, the one
	 * belonging to the last site this token was used on.
	 */
	public function site_name(): string {
		return $this->site_name;
	}

	/**
	 * The cinema's timezone, in whatever naming Veezi chose to report it —
	 * normally Microsoft's. Learned here because `/v1/site` is the only place
	 * it appears, and every showtime in the programme depends on it.
	 */
	public function timezone_identifier(): string {
		return $this->timezone_identifier;
	}

	public function code(): string {
		return $this->code;
	}

	public function message(): string {
		return $this->message;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'success'   => $this->success,
			'site_name' => $this->site_name,
			'timezone'  => $this->timezone_identifier,
			'code'      => $this->code,
			'message'   => $this->message,
		);
	}

	/**
	 * @param array<string,mixed> $data As produced by to_array(), read back
	 *                                  out of the transient it waited in.
	 */
	public static function from_array( array $data ): self {
		return new self(
			! empty( $data['success'] ),
			isset( $data['site_name'] ) ? (string) $data['site_name'] : '',
			isset( $data['timezone'] ) ? (string) $data['timezone'] : '',
			isset( $data['code'] ) ? (string) $data['code'] : '',
			isset( $data['message'] ) ? (string) $data['message'] : ''
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return $this->to_array();
	}
}
