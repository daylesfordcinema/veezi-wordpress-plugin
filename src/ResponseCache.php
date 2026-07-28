<?php
/**
 * Short-lived memory of what Veezi last answered.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the plugin from asking Veezi the same question twice in a minute.
 *
 * Veezi publishes no rate limits and marks every response `no-cache`, so how
 * often this plugin calls is entirely its own restraint. Most of that restraint
 * is structural rather than here: the site renders from WordPress content and
 * never calls the API to draw a page, so a listing seen by ten thousand
 * visitors costs Veezi nothing. What is left is bursts — two cron firings
 * catching up after downtime, a run retried straight after a partial failure —
 * and this is the window that absorbs them.
 *
 * Deliberately short. A cache long enough to matter is a cache long enough to
 * hide a programme change from a cinema that has just made one.
 *
 * Emptying it is a version bump rather than a sweep of the database: transients
 * cannot be enumerated when a persistent object cache is holding them, which on
 * this cinema's server is exactly where they live.
 */
final class ResponseCache {

	public const KEEP_FOR = 5 * MINUTE_IN_SECONDS;

	private const PREFIX = 'veezi_response_';

	/** Bumped to invalidate everything cached before it. */
	public const GENERATION = 'veezi_cache_generation';

	/**
	 * What Veezi last said, if it was recently enough.
	 *
	 * @param  string $url   The full request URL.
	 * @param  string $token The credential it would be asked with.
	 * @return array<mixed>|null
	 */
	public static function recall( string $url, string $token ): ?array {
		$cached = get_transient( self::key( $url, $token ) );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * @param string       $url      The full request URL.
	 * @param string       $token    The credential it was asked with.
	 * @param array<mixed> $response The decoded answer. Only ever a success —
	 *                               remembering an outage would extend it past
	 *                               the moment Veezi came back.
	 */
	public static function remember( string $url, string $token, array $response ): void {
		set_transient( self::key( $url, $token ), $response, self::KEEP_FOR );
	}

	public static function forget(): void {
		update_option( self::GENERATION, self::generation() + 1, false );
	}

	/**
	 * Everything that makes two requests the same request.
	 *
	 * The token is in here because whose programme this is, is part of the
	 * question: a site repointed at another cinema's account must not be
	 * answered with the last one's films. It is hashed along with the rest and
	 * never stored — a transient name is not a place to keep a credential.
	 *
	 * @param string $url   The full request URL.
	 * @param string $token The credential it is asked with.
	 */
	private static function key( string $url, string $token ): string {
		return self::PREFIX . md5( self::generation() . '|' . $url . '|' . $token );
	}

	private static function generation(): int {
		return (int) get_option( self::GENERATION, 0 );
	}
}
