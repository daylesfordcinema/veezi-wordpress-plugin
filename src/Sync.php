<?php
/**
 * The sync entry point.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the cinema's programme from Veezi and publishes it as WordPress
 * content.
 *
 * Only the first step of that — authenticate, and establish whose programme
 * this is — is implemented so far. What the rest will be built on is here: a
 * clock the caller supplies.
 *
 * Every date decision a sync makes is a decision about *now*: which sessions
 * have already started, how far ahead "coming soon" reaches, which past
 * records are old enough to remove. Taking the current time as an argument is
 * what makes those decidable in a test, and it is a plain optional parameter
 * rather than an injected clock interface because there is exactly one thing
 * to vary.
 */
final class Sync {

	public function __construct( private readonly Client $client ) {}

	public function run( ?DateTimeImmutable $now = null ): SyncResult {
		$now ??= self::now();

		$connection = $this->client->check_connection();

		if ( ! $connection->is_success() ) {
			return SyncResult::failed( $now, $connection->message() );
		}

		return SyncResult::completed( $now, $connection->message() );
	}

	/**
	 * The real clock, in UTC.
	 *
	 * Deliberately not the site's timezone. Veezi reports times with no offset
	 * at all, so the cinema's zone has to be applied explicitly wherever those
	 * are read; starting from an unambiguous instant means a site whose
	 * timezone is unset or wrong cannot quietly shift the sync's idea of the
	 * present.
	 */
	public static function now(): DateTimeImmutable {
		return new DateTimeImmutable( '@' . time() );
	}
}
