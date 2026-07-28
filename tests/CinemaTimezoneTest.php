<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Veezi\WordPress\CinemaTimezone;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * Working out which timezone the cinema is in.
 *
 * Veezi reports its timezone in Microsoft's naming, which PHP does not know, so
 * something has to translate. Getting this wrong is the failure mode that hurts
 * most: every showtime on the site is silently off by hours, and nothing about
 * the page looks broken.
 */
final class CinemaTimezoneTest extends TestCase {

	private function offset_at( DateTimeZone $zone, string $moment ): int {
		return $zone->getOffset( new DateTimeImmutable( $moment, new DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Asserted through its behaviour rather than its name: what matters is that
	 * the zone keeps southern-hemisphere summer time, not which of the several
	 * interchangeable identifiers for that rule set we happened to pick.
	 */
	public function test_it_understands_the_timezone_veezi_reports(): void {
		$zone = CinemaTimezone::resolve( 'AUS Eastern Standard Time' );

		$this->assertSame( 11 * HOUR_IN_SECONDS, $this->offset_at( $zone, '2026-01-15 12:00:00' ) );
		$this->assertSame( 10 * HOUR_IN_SECONDS, $this->offset_at( $zone, '2026-07-15 12:00:00' ) );
	}

	public function test_it_understands_a_cinema_that_keeps_no_summer_time(): void {
		$zone = CinemaTimezone::resolve( 'E. Australia Standard Time' );

		$this->assertSame( 10 * HOUR_IN_SECONDS, $this->offset_at( $zone, '2026-01-15 12:00:00' ) );
		$this->assertSame( 10 * HOUR_IN_SECONDS, $this->offset_at( $zone, '2026-07-15 12:00:00' ) );
	}

	public function test_it_accepts_an_identifier_php_already_knows(): void {
		$zone = CinemaTimezone::resolve( 'Pacific/Auckland' );

		$this->assertSame( 'Pacific/Auckland', $zone->getName() );
	}

	/**
	 * An unrecognised identifier must not become UTC by default. The site's own
	 * timezone is the closest thing to the truth available, and a cinema is far
	 * likelier to share its website's timezone than Greenwich's.
	 */
	public function test_an_unrecognised_identifier_falls_back_to_the_site_timezone(): void {
		update_option( 'timezone_string', 'Europe/Dublin' );

		$zone = CinemaTimezone::resolve( 'Sea Of Tranquility Standard Time' );

		$this->assertSame( 'Europe/Dublin', $zone->getName() );
	}

	public function test_no_identifier_at_all_falls_back_to_the_site_timezone(): void {
		update_option( 'timezone_string', 'Europe/Dublin' );

		$this->assertSame( 'Europe/Dublin', CinemaTimezone::resolve( '' )->getName() );
	}

	/**
	 * The mapping table cannot be complete — Microsoft's list is long and this
	 * plugin ships a working subset — so a cinema in an unlisted zone needs a
	 * way to say so without waiting for a plugin release.
	 */
	public function test_a_site_can_override_the_resolved_timezone(): void {
		add_filter(
			'veezi_cinema_timezone',
			static fn (): string => 'Australia/Melbourne'
		);

		$this->assertSame(
			'Australia/Melbourne',
			CinemaTimezone::resolve( 'AUS Eastern Standard Time' )->getName()
		);
	}

	public function test_an_override_that_names_nothing_real_is_ignored(): void {
		update_option( 'timezone_string', 'Europe/Dublin' );

		add_filter(
			'veezi_cinema_timezone',
			static fn (): string => 'Somewhere/Nowhere'
		);

		$this->assertSame(
			'Europe/Dublin',
			CinemaTimezone::resolve( 'AUS Eastern Standard Time' )->getName()
		);
	}
}
