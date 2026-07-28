<?php
/**
 * Base test case.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests\Support;

use DateTimeImmutable;
use Veezi\WordPress\Settings;
use WP_UnitTestCase;

/**
 * Real WordPress, real post types, real options — with one seam.
 *
 * Everything below WordPress's HTTP layer is faked and everything above it is
 * the code that ships. See the bootstrap: a request that no test arranged an
 * answer for is an error, not a network call.
 */
abstract class TestCase extends WP_UnitTestCase {

	protected FakeVeezi $veezi;

	public function set_up(): void {
		parent::set_up();

		// add_settings_error() appends to a global that WordPress's own test
		// case does not reset, so without this a test would see the notices
		// raised by whichever tests ran before it.
		$GLOBALS['wp_settings_errors'] = array();

		$this->veezi = new FakeVeezi();
		$this->veezi->register();
	}

	public function tear_down(): void {
		$this->veezi->unregister();
		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	/**
	 * The shape `/v1/site` returns, with only the fields the plugin reads
	 * populated. Synthesised, never captured: a real capture would carry a
	 * cinema's trading details into a public repository.
	 *
	 * @param  array<string,mixed> $overrides Fields to vary for this test.
	 * @return array<string,mixed>
	 */
	protected function site_payload( array $overrides = array() ): array {
		return array_merge(
			array(
				'Name'               => 'Regal Picture Palace',
				'ShortName'          => 'Regal',
				'LegalName'          => 'Regal Picture Palace Society',
				'TimeZoneIdentifier' => 'AUS Eastern Standard Time',
				'Country'            => 'Australia',
			),
			$overrides
		);
	}

	/**
	 * One session, in the shape `/v1/session` returns.
	 *
	 * Pass `starts` to move it and `runtime` to lengthen it; the four other
	 * times Veezi reports are derived from those, as they are upstream. All of
	 * them are naive local wall-clock strings with no offset — which is the
	 * single most consequential thing about this API.
	 *
	 * @param  array<string,mixed> $overrides Fields to vary, plus the two
	 *                                        conveniences described above.
	 * @return array<string,mixed>
	 */
	protected function session_payload( array $overrides = array() ): array {
		$starts  = (string) ( $overrides['starts'] ?? '2026-08-02T16:30:00' );
		$runtime = (int) ( $overrides['runtime'] ?? 100 );
		unset( $overrides['starts'], $overrides['runtime'] );

		$at = static fn ( int $minutes ): string => ( new DateTimeImmutable( $starts ) )
			->modify( sprintf( '%+d minutes', $minutes ) )
			->format( 'Y-m-d\TH:i:s' );

		return array_merge(
			array(
				'Id'                        => 1001,
				'FilmId'                    => 'film-cook',
				'FilmPackageId'             => null,
				'Title'                     => 'The Cook’s Tale',
				'ScreenId'                  => 1,
				'Seating'                   => 'Open',
				'AreComplimentariesAllowed' => true,
				'TicketsSoldOut'            => false,
				'FewTicketsLeft'            => false,
				'ShowType'                  => 'Public',
				'SalesVia'                  => array( 'WWW', 'POS' ),
				'Status'                    => 'Open',

				// The advertised time is the pre-show: the feature itself
				// starts once the ads have run.
				'PreShowStartTime'          => $starts,
				'FeatureStartTime'          => $at( 10 ),
				'FeatureEndTime'            => $at( 10 + $runtime ),
				'CleanupEndTime'            => $at( 20 + $runtime ),
				'SalesCutOffTime'           => $at( 20 ),

				// Deliberately distinctive, so a test can prove none of it was
				// written anywhere.
				'SeatsAvailable'            => 4321,
				'SeatsHeld'                 => 765,
				'SeatsHouse'                => 987,
				'SeatsSold'                 => 8765,
				'PriceCardName'             => 'Weekday Matinee Concession',

				'FilmFormat'                => 'D-Cinema',
				'Attributes'                => array(),
				'AudioLanguage'             => null,
			),
			$overrides
		);
	}

	/**
	 * One film, in the shape `/v4/film` returns.
	 *
	 * @param  array<string,mixed> $overrides Fields to vary for this test.
	 * @return array<string,mixed>
	 */
	protected function film_payload( array $overrides = array() ): array {
		return array_merge(
			array(
				'Id'                     => 'film-cook',
				'Title'                  => 'The Cook’s Tale',
				'ShortName'              => 'Cook',
				'Synopsis'               => 'A kitchen hand inherits a failing restaurant and one very old recipe.',

				// Comma-separated, and upstream is careless about spacing.
				'Genre'                  => 'Drama, Comedy ',
				'SignageText'            => '',
				'Distributor'            => 'Mirador Pictures',
				'OpeningDate'            => '2026-07-30T00:00:00',
				'Rating'                 => 'PG',

				// Every film in the catalogue says this, including the test
				// records, which is why it can never drive a listing.
				'Status'                 => 'Active',
				'Content'                => 'Mild themes',
				'Duration'               => 100,
				'DisplaySequence'        => 0,
				'NationalCode'           => null,
				'Format'                 => 'D-Cinema',
				'IsRestricted'           => false,
				'People'                 => array(
					array(
						'Id'        => 'person-1',
						'FirstName' => 'Ada',
						'LastName'  => 'Vaughan',
						'Role'      => 'Director',
					),
				),
				'AudioLanguage'          => null,
				'GovernmentFilmTitle'    => null,
				'FilmPosterUrl'          => 'https://images.example.test/cook.png',
				'FilmPosterThumbnailUrl' => 'https://images.example.test/cook-thumb.jpg',
				'BackdropImageUrl'       => 'https://images.example.test/cook-backdrop.png',
				'FilmTrailerUrl'         => 'https://www.youtube.com/watch?v=abcdefghijk',
				'Attributes'             => array(),
			),
			$overrides
		);
	}

	/**
	 * Arrange the endpoints a sync reads, answering them the way Veezi does.
	 *
	 * `/v1/websession` is derived here rather than passed in, because upstream
	 * it is not an independent list: it is the subset of `/v1/session` that can
	 * be sold online, with a booking link attached. A test that could set the
	 * two out of step would be testing a situation that cannot happen.
	 *
	 * "Sold online" means on sale *and* carrying `WWW` in `SalesVia`. Those two
	 * are not the same thing — a session can be selling at the box office only —
	 * and conflating them here would make a whole class of session impossible to
	 * write a test for.
	 *
	 * @param array<int,array<string,mixed>> $sessions Whatever `/v1/session` should return.
	 * @param array<int,array<string,mixed>> $films    Whatever `/v4/film` should return.
	 */
	protected function arrange_programme( array $sessions, array $films = array() ): void {
		$on_sale = array_filter(
			$sessions,
			static fn ( array $s ): bool => 'Open' === $s['Status'] && in_array( 'WWW', (array) $s['SalesVia'], true )
		);

		$this->veezi->will_return( '/v1/site', $this->site_payload() );
		$this->veezi->will_return( '/v1/session', array_values( $sessions ) );
		$this->veezi->will_return( '/v4/film', array_values( $films ) );
		$this->veezi->will_return(
			'/v1/websession',
			array_values(
				array_map(
					static fn ( array $s ): array => array_merge(
						array( 'Url' => 'https://ticketing.example.test/purchase?session=' . $s['Id'] ),
						$s
					),
					$on_sale
				)
			)
		);
	}

	protected function store_token( string $token ): void {
		update_option( Settings::OPTION, array( 'token' => $token ) );
	}

	protected function become_administrator(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}
}
