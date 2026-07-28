<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Tests\Support\PlainGdEditor;
use Veezi\WordPress\Tests\Support\TestCase;
use WP_Post;

/**
 * Artwork, from Veezi's media server into the WordPress media library.
 *
 * Three things about the upstream shape drive all of this. A poster is addressed
 * by media id alone — no filename, no extension, nothing saying what the bytes
 * will be. It is full-resolution: around 1340x1920, and the lossless ones run to
 * five and a half megabytes. And the one smaller variant on offer is 125x182,
 * which is a thumbnail for a box-office screen, not a card on a website.
 *
 * So posters are copied in rather than linked to, and everything below is about
 * what that copy has to be for it to be worth doing.
 */
final class PosterTest extends TestCase {

	/** The path half of {@see TestCase::POSTER_URL}, which is what the seam matches on. */
	private const POSTER = '/media/0000000008';

	/**
	 * One film with one session, and whatever the test wants to vary about it.
	 *
	 * @param array<string,mixed> $film Fields to change on the film payload.
	 */
	private function arrange_film( array $film = array() ): void {
		$this->arrange_programme(
			array( $this->session_payload() ),
			array( $this->film_payload( $film ) )
		);
	}

	private function poster_of( string $film_id = 'film-cook' ): int {
		return (int) get_post_thumbnail_id( $this->film_record( $film_id ) );
	}

	/**
	 * How many times artwork has actually been fetched, across every sync this
	 * test has run.
	 */
	private function downloads(): int {
		return count(
			array_filter(
				$this->veezi->requests,
				static fn ( array $request ): bool => str_contains( $request['url'], '/media/' )
			)
		);
	}

	/**
	 * Where an uploaded file lives, given the address it is served from.
	 *
	 * @param string $url As WordPress would render it.
	 */
	private function path_for( string $url ): string {
		$uploads = wp_upload_dir();

		return str_replace( $uploads['baseurl'], $uploads['basedir'], $url );
	}

	/**
	 * How see-through one pixel of a generated image is: 0 is solid, 127 is
	 * invisible. Read back through GD rather than inferred from the format,
	 * because the format is exactly what is under test.
	 *
	 * @param string $path Where the image is.
	 * @param int    $x    Across.
	 * @param int    $y    Down.
	 */
	private function transparency_at( string $path, int $x, int $y ): int {
		$image = imagecreatefromwebp( $path );

		$this->assertNotFalse( $image, "Nothing readable was written to {$path}." );

		return ( imagecolorat( $image, $x, $y ) >> 24 ) & 0x7F;
	}

	/**
	 * @return array<int,int> Every attachment in the library, oldest first.
	 */
	private function library(): array {
		return $this->records( 'attachment', 'inherit' );
	}

	public function test_a_synced_film_shows_its_poster(): void {
		$this->arrange_film();

		$this->sync_at();

		$poster = $this->poster_of();

		$this->assertGreaterThan( 0, $poster, 'The film ended up with no poster at all.' );
		$this->assertTrue( wp_attachment_is_image( $poster ) );
	}

	/**
	 * The cinema reuses this artwork in newsletters and social posts, so it has
	 * to be findable as media rather than buried as a plugin implementation
	 * detail: named after the film, and filed against it.
	 */
	public function test_the_poster_is_in_the_media_library_under_the_films_name(): void {
		$this->arrange_film();

		$this->sync_at();

		$poster = get_post( $this->poster_of() );

		$this->assertInstanceOf( WP_Post::class, $poster );
		$this->assertSame( 'The Cook’s Tale', $poster->post_title );
		$this->assertSame( $this->film_record( 'film-cook' ), (int) $poster->post_parent );
		$this->assertSame( 'image/jpeg', $poster->post_mime_type );
	}

	public function test_the_poster_carries_alternative_text_naming_the_film(): void {
		$this->arrange_film();

		$this->sync_at();

		$this->assertStringContainsString(
			'The Cook’s Tale',
			(string) get_post_meta( $this->poster_of(), '_wp_attachment_image_alt', true ),
			'A screen reader is told nothing about which film this is.'
		);
	}

	/**
	 * `/media/0000000008` is the whole upstream URL: it names no file and
	 * declares no format. So the name has to come from the film and the
	 * extension from the bytes — and getting the extension wrong is not
	 * cosmetic, because WordPress refuses an upload it cannot identify.
	 */
	public function test_the_file_is_named_from_the_film_and_the_bytes(): void {
		$this->arrange_film();
		$this->arrange_poster( self::POSTER_URL, 'image/png' );

		$this->sync_at();

		$file = wp_basename( (string) wp_get_original_image_path( $this->poster_of() ) );

		$this->assertStringStartsWith( 'the-cook', $file );
		$this->assertStringEndsWith( '.png', $file, 'The format was guessed rather than read.' );
		$this->assertStringNotContainsString( '0000000008', $file );
	}

	/**
	 * A listing asking for the poster must get something made for a card, not
	 * the full-resolution original.
	 */
	public function test_a_card_is_served_a_sized_poster_and_not_the_original(): void {
		$this->arrange_film();
		$this->arrange_poster( self::POSTER_URL, 'image/jpeg', 1343, 1920 );

		$this->sync_at();

		$poster = $this->poster_of();
		$card   = wp_get_attachment_image_src( $poster, ContentModel::POSTER_SIZE );

		$this->assertIsArray( $card );
		$this->assertSame( 600, $card[1], 'A card image should be 600px wide.' );
		$this->assertTrue( $card[3], 'This is the original, not a generated size.' );
		$this->assertNotSame( wp_get_attachment_url( $poster ), $card[0] );
	}

	/**
	 * Roughly one poster in seventy arrives as a lossless PNG, and those are the
	 * five-megabyte ones. Measured against the live catalogue, eight of nine
	 * cards in a listing come out between 58KB and 118KB and the one PNG among
	 * them comes out at 877KB — on its own, nearly half the page.
	 *
	 * So nothing the site serves stays lossless, including the full size. The
	 * file as it arrived is still kept: WordPress files it as the attachment's
	 * original, which is what the cinema reuses elsewhere and what a mistake
	 * here could be undone from.
	 */
	public function test_nothing_the_site_serves_of_a_lossless_poster_is_lossless(): void {
		$this->arrange_film();
		$this->arrange_poster( self::POSTER_URL, 'image/png', 1343, 1920 );

		$this->sync_at();

		$poster   = $this->poster_of();
		$card     = wp_get_attachment_image_src( $poster, ContentModel::POSTER_SIZE );
		$original = (string) wp_get_original_image_path( $poster );

		$this->assertIsArray( $card );
		$this->assertStringEndsWith( '.webp', (string) $card[0] );
		$this->assertStringEndsWith( '.webp', (string) get_attached_file( $poster ) );

		$this->assertStringEndsWith( '.png', $original, 'The artwork as it arrived was thrown away.' );
		$this->assertFileExists( $original );
	}

	/**
	 * Posters really do carry transparency — the lossless one in the live
	 * catalogue declares an alpha channel and uses it — and there is no telling
	 * a feathered edge from a title treatment designed to sit on the page
	 * itself. Recompressing must not quietly decide the answer, because a poster
	 * flattened onto a guessed background is a mistake anyone can see.
	 */
	public function test_a_poster_with_transparency_keeps_it(): void {
		$this->arrange_film();
		$this->veezi->will_return_image(
			self::POSTER,
			$this->image_bytes( 'image/png', 1343, 1920, true ),
			'image/png'
		);

		$this->sync_at();

		$card = wp_get_attachment_image_src( $this->poster_of(), ContentModel::POSTER_SIZE );

		$this->assertIsArray( $card );

		$this->assertGreaterThan(
			0,
			$this->transparency_at( $this->path_for( (string) $card[0] ), 5, 5 ),
			'The see-through half of the poster came out solid, which on a page is a black box.'
		);
	}

	/**
	 * The sync runs hourly. Re-downloading fifteen full-resolution posters every
	 * time would be several hundred megabytes a day off someone else's server,
	 * for artwork that changes maybe twice a year.
	 */
	public function test_a_second_sync_fetches_no_artwork(): void {
		$this->arrange_film();
		$this->sync_at();

		$first = $this->poster_of();

		$this->sync_at();

		$this->assertSame( $first, $this->poster_of() );
		$this->assertSame( 1, $this->downloads(), 'The poster was downloaded all over again.' );
		$this->assertCount( 1, $this->library() );
	}

	/**
	 * The key is the media reference, not the film's current featured image.
	 * Somebody swapping a poster by hand is a reasonable thing to do — and once
	 * they have, the artwork the sync is looking for is still sitting in the
	 * library. Downloading five megabytes to arrive at a file already on disk,
	 * and filing a second copy of it, would be the worst of both.
	 */
	public function test_artwork_already_in_the_library_is_not_fetched_a_second_time(): void {
		$this->arrange_film();
		$this->sync_at();

		$poster = $this->poster_of();
		$theirs = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
			)
		);

		update_post_meta( $this->film_record( 'film-cook' ), '_thumbnail_id', $theirs );

		$this->sync_at();

		$this->assertSame( $poster, $this->poster_of(), 'The poster in the library was not recognised.' );
		$this->assertSame( 1, $this->downloads() );
		$this->assertCount( 2, $this->library(), 'A second copy of the same artwork was filed.' );
	}

	/**
	 * Veezi dropping an image is more often a re-upload in progress than a
	 * decision, and an administrator may have set a featured image by hand
	 * precisely because there was none. Either way, blanking it every hour on
	 * the hour would be worse than showing yesterday's poster.
	 */
	public function test_artwork_disappearing_upstream_leaves_the_poster_alone(): void {
		$this->arrange_film();
		$this->sync_at();

		$poster = $this->poster_of();

		$this->arrange_film( array( 'FilmPosterUrl' => '' ) );
		$this->sync_at();

		$this->assertSame( $poster, $this->poster_of() );
	}

	/**
	 * Most shared hosting builds its image library without WebP, and the plugin
	 * is meant to be usable on hosting nobody here chose. WordPress checks the
	 * format is writable before honouring the request and quietly keeps the one
	 * the file came in — so the fallback needs no code, only this, which is what
	 * would notice if that ever stopped being true. A card that silently failed
	 * to generate is an empty listing on every such site.
	 */
	public function test_a_host_that_cannot_write_webp_still_gets_a_card(): void {
		add_filter( 'wp_image_editors', static fn (): array => array( PlainGdEditor::class ) );

		$this->arrange_film();
		$this->arrange_poster( self::POSTER_URL, 'image/png', 1343, 1920 );

		$this->sync_at();

		$card = wp_get_attachment_image_src( $this->poster_of(), ContentModel::POSTER_SIZE );

		$this->assertIsArray( $card, 'No card image was generated at all.' );
		$this->assertSame( 600, $card[1] );
		$this->assertStringEndsWith( '.png', (string) $card[0] );
	}

	public function test_new_artwork_upstream_becomes_the_new_poster(): void {
		$this->arrange_film();
		$this->sync_at();

		$first = $this->poster_of();

		$this->arrange_film( array( 'FilmPosterUrl' => 'https://images.example.test/media/0000000099' ) );
		$this->sync_at();

		$this->assertGreaterThan( 0, $this->poster_of() );
		$this->assertNotSame( $first, $this->poster_of() );
		$this->assertInstanceOf(
			WP_Post::class,
			get_post( $first ),
			'The superseded poster was deleted, and the cinema may have used it elsewhere.'
		);
	}

	/**
	 * Whatever has happened to the media — deleted by hand, lost in a migration,
	 * pointing at nothing — the next sync should put it right rather than leave
	 * a film with a blank card forever.
	 */
	public function test_a_film_whose_poster_has_gone_missing_gets_it_back(): void {
		$this->arrange_film();
		$this->sync_at();

		wp_delete_attachment( $this->poster_of(), true );
		update_post_meta( $this->film_record( 'film-cook' ), '_thumbnail_id', 4242 );

		$this->sync_at();

		$poster = $this->poster_of();

		$this->assertNotSame( 4242, $poster );
		$this->assertTrue( wp_attachment_is_image( $poster ) );
	}

	/**
	 * One film in the live catalogue has no artwork at all.
	 */
	public function test_a_film_with_no_artwork_syncs_without_a_poster(): void {
		$this->arrange_film( array( 'FilmPosterUrl' => '' ) );

		$result = $this->sync_at();

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 0, $this->poster_of() );
		$this->assertSame( 0, $this->downloads(), 'Something was fetched for a film with no artwork.' );
	}

	public function test_artwork_that_cannot_be_downloaded_leaves_the_rest_of_the_sync_alone(): void {
		$this->arrange_film();
		$this->veezi->will_fail( self::POSTER, 'Could not resolve host.' );

		$result = $this->sync_at();

		$this->assertTrue( $result->is_success(), 'A missing poster stopped the whole programme syncing.' );
		$this->assertSame( 'The Cook’s Tale', get_post_field( 'post_title', $this->film_record( 'film-cook' ) ) );
		$this->assertSame( 0, $this->poster_of() );
	}

	/**
	 * Media servers sit behind the same bot challenges the booking links do, and
	 * a challenge answers with a web page and a perfectly ordinary 200.
	 */
	public function test_something_that_is_not_an_image_is_refused(): void {
		$this->arrange_film();
		$this->veezi->will_return_image( self::POSTER, '<html><body>Just a moment…</body></html>', 'text/html' );

		$result = $this->sync_at();

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 0, $this->poster_of() );
		$this->assertSame( array(), $this->library(), 'A web page was filed in the media library.' );
	}

	/**
	 * The media server is a public CDN that asks for no credential. Sending one
	 * anyway would put the cinema's access token — which reads seat counts and
	 * takings — into somebody else's request logs.
	 */
	public function test_the_access_token_is_never_sent_to_the_media_server(): void {
		$this->arrange_film();

		$this->sync_at();

		$request = $this->veezi->last_request_to( '/media/' );

		$this->assertNotNull( $request );
		$this->assertStringNotContainsString( self::TOKEN, (string) wp_json_encode( $request['args'] ) );
	}
}
