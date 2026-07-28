<?php
/**
 * Base test case.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Veezi\WordPress\Client;
use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Settings;
use Veezi\WordPress\Sync;
use Veezi\WordPress\SyncResult;
use Veezi\WordPress\Token;
use WP_UnitTestCase;

/**
 * Real WordPress, real post types, real options — with one seam.
 *
 * Everything below WordPress's HTTP layer is faked and everything above it is
 * the code that ships. See the bootstrap: a request that no test arranged an
 * answer for is an error, not a network call.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Distinctive on purpose: a test can search a whole request for it and
	 * prove it went nowhere it should not have.
	 */
	protected const TOKEN = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

	/**
	 * Where {@see self::film_payload()} says its artwork is. Named here so a
	 * test arranging different bytes for it cannot drift from the payload.
	 */
	protected const POSTER_URL = 'https://images.example.test/media/0000000008';

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

		// Anything that looped over a record left it set up as the current post.
		wp_reset_postdata();

		// Elementor caches the answer to "am I being edited?", so a test that
		// said yes has to hand it back rather than leave it saying yes to
		// everything that follows. Null is "work it out again".
		\Elementor\Plugin::$instance->editor->set_edit_mode( null );

		// Posters are real files on disk, and WordPress's own test case does not
		// clear them. Left behind they accumulate across a run, and the next
		// test to sideload the same film gets `the-cooks-tale-4.jpg` — so an
		// assertion about a filename would pass or fail on test order.
		$this->remove_added_uploads();

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
				// Artwork is addressed by media id and nothing else: no file
				// extension, no name, and nothing in the URL saying what the
				// bytes will turn out to be.
				'FilmPosterUrl'          => self::POSTER_URL,
				'FilmPosterThumbnailUrl' => 'https://images.example.test/media/0000000006',
				'BackdropImageUrl'       => 'https://images.example.test/media/0000000009',
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

		// Whatever artwork these films claim to have, the CDN is serving. A
		// test wanting different bytes — or none — arranges the same path again
		// afterwards, which replaces this.
		foreach ( $films as $film ) {
			if ( ! empty( $film['FilmPosterUrl'] ) ) {
				$this->arrange_poster( (string) $film['FilmPosterUrl'] );
			}
		}
	}

	/**
	 * Serve a poster from the URL a film payload points at.
	 *
	 * Small by default, because most tests here are about records rather than
	 * pixels and every extra one is a real resize. Pass the live dimensions when
	 * the sizes themselves are the point.
	 *
	 * @param string $url    Where the film payload says its poster is.
	 * @param string $mime   `image/jpeg` or `image/png`.
	 * @param int    $width  In pixels.
	 * @param int    $height In pixels.
	 */
	protected function arrange_poster( string $url, string $mime = 'image/jpeg', int $width = 350, int $height = 500 ): void {
		$this->veezi->will_return_image(
			(string) wp_parse_url( $url, PHP_URL_PATH ),
			$this->image_bytes( $mime, $width, $height ),
			$mime
		);
	}

	/**
	 * A real image, made rather than committed.
	 *
	 * It has to be genuine: WordPress reads the bytes to decide the file type,
	 * refuses anything it cannot identify, and then actually resizes it. A
	 * checked-in fixture would do as well, but this way the shape and the format
	 * of each one is stated in the test that needs it.
	 *
	 * The gradient is not decoration — a single flat colour compresses to almost
	 * nothing in either format, which would make any comparison between them
	 * meaningless.
	 *
	 * @param  string $mime        `image/jpeg` or `image/png`.
	 * @param  int    $width       In pixels.
	 * @param  int    $height      In pixels.
	 * @param  bool   $transparent Leave the top half of a PNG see-through.
	 * @return string The encoded image.
	 */
	protected function image_bytes( string $mime = 'image/jpeg', int $width = 350, int $height = 500, bool $transparent = false ): string {
		$canvas = imagecreatetruecolor( $width, $height );

		if ( $transparent ) {
			imagealphablending( $canvas, false );
			imagesavealpha( $canvas, true );
			imagefill( $canvas, 0, 0, imagecolorallocatealpha( $canvas, 0, 0, 0, 127 ) );
		}

		for ( $y = $transparent ? (int) ( $height / 2 ) : 0; $y < $height; $y++ ) {
			$shade = (int) round( 255 * $y / max( 1, $height - 1 ) );

			imagefilledrectangle( $canvas, 0, $y, $width - 1, $y, imagecolorallocate( $canvas, $shade, 64, 255 - $shade ) );
		}

		ob_start();

		if ( 'image/png' === $mime ) {
			imagepng( $canvas );
		} else {
			imagejpeg( $canvas );
		}

		imagedestroy( $canvas );

		return (string) ob_get_clean();
	}

	/**
	 * Run a whole sync, at a moment of the test's choosing.
	 *
	 * @param string $moment When the run happens, in UTC — every date the sync
	 *                       decides anything by is relative to this.
	 */
	protected function sync_at( string $moment = '2026-08-01 00:00:00' ): SyncResult {
		$this->store_token( self::TOKEN );

		return ( new Sync( new Client( Token::resolve( new Settings() ) ) ) )
			->run( new DateTimeImmutable( $moment, new DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * The record the sync made for one of Veezi's identifiers.
	 *
	 * @param string $post_type Which kind of record.
	 * @param string $meta_key  The field holding the upstream identifier.
	 * @param string $upstream  The identifier itself.
	 */
	protected function record_for( string $post_type, string $meta_key, string $upstream ): int {
		$found = get_posts(
			array(
				'post_type'   => $post_type,
				'post_status' => array_keys( get_post_stati() ),
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => $meta_key,
				'meta_value'  => $upstream,
			)
		);

		$this->assertNotEmpty( $found, "The sync made no {$post_type} record for {$upstream}." );

		return (int) $found[0];
	}

	protected function film_record( string $upstream_id ): int {
		return $this->record_for( ContentModel::FILM, ContentModel::FILM_ID, $upstream_id );
	}

	protected function session_record( int $upstream_id ): int {
		return $this->record_for( ContentModel::SESSION, ContentModel::SESSION_ID, (string) $upstream_id );
	}

	/**
	 * Put a record in the position a loop grid would put it.
	 *
	 * A loop item is rendered with its post set up as the current one, and that
	 * is the whole of how the plugin's tags and its widget know which film they
	 * are describing — there is no film to name and nothing to configure. So
	 * that global is the seam a test drives them through.
	 *
	 * @param int $post_id The record being looped over.
	 */
	protected function looping_over( int $post_id ): void {
		$GLOBALS['post'] = get_post( $post_id );

		setup_postdata( $GLOBALS['post'] );
	}

	/**
	 * What a widget bound to one of the plugin's dynamic tags would show.
	 *
	 * Goes through Elementor's own manager rather than the tag class, so this
	 * asserts what the builder does with the tag and not merely what the class
	 * would return if asked directly. An unregistered name comes back null.
	 *
	 * @param  string              $tag      The name a template stores, e.g. `veezi-runtime`.
	 * @param  int                 $post_id  The record being looped over.
	 * @param  array<string,mixed> $settings The tag's own controls, as the panel would set them.
	 * @return mixed A string for most tags; an array for the poster.
	 */
	protected function bound( string $tag, int $post_id, array $settings = array() ) {
		$this->looping_over( $post_id );

		return \Elementor\Plugin::$instance->dynamic_tags->get_tag_data_content( 'a1b2c3d', $tag, $settings );
	}

	/**
	 * What one of the plugin's widgets puts on the page.
	 *
	 * Built through Elementor's own element manager, which is the same call the
	 * front end makes for every widget on a page — so this fails if the widget
	 * was never registered, rather than quietly testing a class nobody can
	 * reach from the builder.
	 *
	 * @param string              $widget   The name a template stores, e.g. `veezi-session-times`.
	 * @param int                 $post_id  The record being looped over.
	 * @param array<string,mixed> $settings The widget's controls, as the panel would set them.
	 */
	protected function rendered_widget( string $widget, int $post_id, array $settings = array() ): string {
		$this->looping_over( $post_id );

		$element = \Elementor\Plugin::$instance->elements_manager->create_element_instance(
			array(
				'id'         => 'a1b2c3d',
				'elType'     => 'widget',
				'widgetType' => $widget,
				'settings'   => $settings,
			)
		);

		$this->assertNotNull( $element, "Elementor has no {$widget} widget registered." );

		ob_start();
		$element->render_content();

		return (string) ob_get_clean();
	}

	/**
	 * Stand where a designer stands: inside the builder rather than on the site.
	 *
	 * @param bool $editing Whether Elementor should believe it is being edited.
	 */
	protected function in_the_editor( bool $editing = true ): void {
		\Elementor\Plugin::$instance->editor->set_edit_mode( $editing );
	}

	/**
	 * Everything of one kind, oldest first.
	 *
	 * @param  string $post_type   Which kind of record to gather up.
	 * @param  string $post_status Attachments live under `inherit`; everything
	 *                             else this asks about is `any`.
	 * @return array<int,int>
	 */
	protected function records( string $post_type, string $post_status = 'any' ): array {
		return get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => $post_status,
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => false,
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
