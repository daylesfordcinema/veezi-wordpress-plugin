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
}
