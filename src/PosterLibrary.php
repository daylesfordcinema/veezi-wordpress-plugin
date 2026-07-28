<?php
/**
 * Poster artwork, copied into the WordPress media library.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Gives each film its poster, as ordinary WordPress media.
 *
 * Copied in rather than linked to, for two reasons. Veezi serves one full
 * resolution image — around 1340x1920, and the lossless ones run to five and a
 * half megabytes — and the only smaller variant it offers is 125x182, a
 * thumbnail meant for a box-office screen. Measured against a live account, a
 * nine-film listing linked to the originals is eight megabytes a page view and
 * the same listing served from here is around 800KB. Once the artwork is in the
 * library WordPress makes its own sizes, and the cinema can reuse it in a
 * newsletter without going back to the ticketing system for it.
 *
 * The whole thing is best-effort by design: artwork is decoration, and a poster
 * that cannot be had is never a reason to fail a sync or hold up a programme.
 */
final class PosterLibrary {

	/**
	 * Long enough for a five-megabyte image on an unhurried connection, short
	 * enough that a media server having a bad afternoon does not hold a
	 * scheduled sync open. A poster missed here is picked up on the next run.
	 */
	private const TIMEOUT_SECONDS = 30;

	/** What a lossless poster's sizes are written as instead. */
	private const RECOMPRESSED_AS = 'image/webp';

	/**
	 * Make sure this film's poster is its featured image.
	 *
	 * @param int  $film_post The film's record.
	 * @param Film $film      What Veezi says about it.
	 */
	public function attach( int $film_post, Film $film ): void {
		// A film with no artwork upstream keeps whatever it has. Veezi dropping
		// an image is more often a re-upload in progress than a decision, and
		// an administrator may have set a featured image by hand precisely
		// because there was none — clearing it would undo their work every
		// hour, on the hour.
		if ( '' === $film->poster_url || $this->is_current( $film_post, $film->poster_url ) ) {
			return;
		}

		// The library is asked before the network is. This artwork may already
		// be here under another film, or under this one before somebody changed
		// its featured image by hand — and a five-megabyte download to arrive at
		// a file we already have is the one thing this class exists to avoid.
		$poster = $this->already_here( $film->poster_url );

		if ( 0 === $poster ) {
			$poster = $this->import( $film_post, $film );
		}

		if ( 0 !== $poster ) {
			set_post_thumbnail( $film_post, $poster );
		}
	}

	/**
	 * Whatever was imported from this media reference, if anything ever was.
	 *
	 * @param string $source Where Veezi says the artwork is.
	 */
	private function already_here( string $source ): int {
		$found = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => 1,
				'fields'      => 'ids',

				// Looking media up by where it came from is the whole point, and
				// there is no other column to do it on. The sniff is about
				// queries on a page a visitor loads; this one runs during a sync,
				// only for a film whose artwork has actually changed, and against
				// the handful of rows carrying this key.
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_key'    => ContentModel::POSTER_SOURCE,
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $source,
			)
		);

		return array() === $found ? 0 : (int) $found[0];
	}

	/**
	 * Whether this film already has this exact artwork.
	 *
	 * The media reference is the key, so a sync that runs hourly downloads
	 * nothing for artwork that changes twice a year. Checking that the
	 * attachment is still really there is what makes a poster deleted by hand,
	 * or lost in a migration, come back on the next run rather than leave a
	 * blank card for ever.
	 *
	 * @param int    $film_post The film's record.
	 * @param string $source    Where Veezi says its poster is.
	 */
	private function is_current( int $film_post, string $source ): bool {
		$poster = (int) get_post_thumbnail_id( $film_post );

		return $poster > 0
			&& wp_attachment_is_image( $poster )
			&& get_post_meta( $poster, ContentModel::POSTER_SOURCE, true ) === $source;
	}

	/**
	 * Fetch the artwork and file it, or hand back zero having changed nothing.
	 *
	 * @param  int  $film_post The film's record, which the media is filed under.
	 * @param  Film $film      What Veezi says about it.
	 * @return int The attachment, or zero.
	 */
	private function import( int $film_post, Film $film ): int {
		// Media handling lives in wp-admin, and a scheduled sync is not an
		// admin request.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$downloaded = download_url( $film->poster_url, self::TIMEOUT_SECONDS );

		if ( is_wp_error( $downloaded ) ) {
			return 0;
		}

		// One look at the bytes settles every question there is about them —
		// whether they are an image, what to call the file, how to store it.
		// Veezi's URLs answer none of the three.
		$image  = wp_getimagesize( $downloaded );
		$mime   = is_array( $image ) && ! empty( $image['mime'] ) ? (string) $image['mime'] : '';
		$poster = '' === $mime ? 0 : $this->sideload( $downloaded, $mime, $film_post, $film );

		if ( 0 === $poster ) {
			wp_delete_file( $downloaded );

			return 0;
		}

		update_post_meta( $poster, ContentModel::POSTER_SOURCE, $film->poster_url );
		update_post_meta( $poster, '_wp_attachment_image_alt', $this->alternative_text( $film ) );

		return $poster;
	}

	/**
	 * Hand the downloaded file to WordPress and get an attachment back.
	 *
	 * @param  string $downloaded Where the bytes are.
	 * @param  string $mime       What they turned out to be.
	 * @param  int    $film_post  The film's record.
	 * @param  Film   $film       What Veezi says about it.
	 * @return int The attachment, or zero if WordPress refused it.
	 */
	private function sideload( string $downloaded, string $mime, int $film_post, Film $film ): int {
		$name = $this->filename( $mime, $film );

		if ( '' === $name ) {
			return 0;
		}

		$recompress = $this->recompression_for( $mime );

		if ( null !== $recompress ) {
			add_filter( 'image_editor_output_format', $recompress );
		}

		// The title is slashed on the way in because media_handle_sideload
		// passes it straight to wp_insert_post, which unslashes what it is
		// given. Unslashed, a title containing a backslash loses it.
		$poster = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $downloaded,
			),
			$film_post,
			wp_slash( $film->title )
		);

		if ( null !== $recompress ) {
			remove_filter( 'image_editor_output_format', $recompress );
		}

		return is_wp_error( $poster ) ? 0 : (int) $poster;
	}

	/**
	 * How this poster's sizes should be written, if not in the format it came in.
	 *
	 * Roughly one poster in seventy arrives as a lossless PNG, and those are the
	 * five-megabyte ones. PNG is a poor way to store a photograph: measured
	 * against the live catalogue, eight of nine cards in a listing come out
	 * between 58KB and 118KB, and the one PNG among them comes out at 877KB —
	 * on its own, nearly half of what the page transfers.
	 *
	 * WebP rather than JPEG because posters carry transparency and JPEG cannot.
	 * Real artwork does: the one in that catalogue declares an alpha channel and
	 * uses it, on a three-pixel feathered border. That particular one would
	 * survive being flattened — but nothing in a file distinguishes a feathered
	 * border from a title treatment designed to sit on the page itself, and
	 * flattening one of those onto a guessed background is a mistake anyone can
	 * see. WebP means the question never has to be answered: the same picture is
	 * around 80KB with its alpha intact.
	 *
	 * WordPress applies this to the full size as well as the generated ones and
	 * files the image exactly as it arrived alongside as the attachment's
	 * original — so nothing the site serves is a five-megabyte PNG, and the copy
	 * the cinema reuses elsewhere is still the one Veezi sent.
	 *
	 * @param  string $mime What the downloaded bytes turned out to be.
	 * @return callable|null A filter to install for this one import, or null.
	 */
	private function recompression_for( string $mime ): ?callable {
		if ( 'image/png' !== $mime ) {
			return null;
		}

		// Asking whether the host can write WebP would be redundant: WordPress
		// checks that itself before honouring this mapping, and falls back to
		// the format the file came in. An image library built without WebP —
		// which is most of shared hosting — therefore gets PNG cards rather than
		// no cards, with nothing here to arrange it. There is a test.

		/**
		 * @param  array<string,string> $formats Source mime type to output mime type.
		 * @return array<string,string>
		 */
		return static function ( array $formats ): array {
			$formats['image/png'] = self::RECOMPRESSED_AS;

			return $formats;
		};
	}

	/**
	 * What to call the file.
	 *
	 * Veezi addresses artwork by media id and nothing else — no filename, no
	 * extension, nothing declaring the format — so the name comes from the film
	 * and the extension from the bytes. Both matter: WordPress refuses an upload
	 * whose type it cannot establish, and a media library full of files named
	 * after ten-digit media ids is one nobody can use.
	 *
	 * @param  string $mime What the downloaded bytes turned out to be.
	 * @param  Film   $film What Veezi says about it.
	 * @return string An empty string if these are not bytes worth keeping.
	 */
	private function filename( string $mime, Film $film ): string {
		$extension = wp_get_default_extension_for_mime_type( $mime );

		if ( ! is_string( $extension ) || '' === $extension ) {
			return '';
		}

		$stem = sanitize_title( $film->title );

		// A title that sanitises away to nothing is unlikely, but an attachment
		// called ".jpg" is a hidden file rather than a poster.
		if ( '' === $stem ) {
			$stem = 'poster';
		}

		return $stem . '.' . $extension;
	}

	/**
	 * @param Film $film What Veezi says about it.
	 */
	private function alternative_text( Film $film ): string {
		return sanitize_text_field(
			sprintf(
				/* translators: %s: a film's title. */
				__( 'Poster for %s', 'veezi-wordpress-plugin' ),
				$film->title
			)
		);
	}
}
