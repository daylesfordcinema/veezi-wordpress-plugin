<?php
/**
 * The outcome of a sync run.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * What a sync run did, and when it started.
 *
 * The start time is carried rather than looked up, so that everything a run
 * reports refers to the same instant — including a run given a fixed clock by
 * a test.
 */
final class SyncResult {

	private function __construct(
		private readonly bool $success,
		private readonly DateTimeImmutable $started_at,
		private readonly string $message
	) {}

	public static function completed( DateTimeImmutable $started_at, string $message ): self {
		return new self( true, $started_at, $message );
	}

	public static function failed( DateTimeImmutable $started_at, string $message ): self {
		return new self( false, $started_at, $message );
	}

	public function is_success(): bool {
		return $this->success;
	}

	public function started_at(): DateTimeImmutable {
		return $this->started_at;
	}

	public function message(): string {
		return $this->message;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'success' => $this->success,
			'at'      => $this->started_at->getTimestamp(),
			'message' => $this->message,
		);
	}

	/**
	 * @param array<string,mixed> $data As produced by to_array() and read back
	 *                                  out of wherever it was written down.
	 */
	public static function from_array( array $data ): self {
		// From the epoch second, so a result written down under one site
		// timezone reads the same after somebody changes it.
		$started_at = new DateTimeImmutable( '@' . (int) ( $data['at'] ?? 0 ) );
		$message    = isset( $data['message'] ) ? (string) $data['message'] : '';

		return empty( $data['success'] )
			? self::failed( $started_at, $message )
			: self::completed( $started_at, $message );
	}
}
