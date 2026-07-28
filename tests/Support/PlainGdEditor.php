<?php
/**
 * An image library built without WebP.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests\Support;

use WP_Error;
use WP_Image_Editor_GD;

/**
 * GD as a good many shared hosts still ship it.
 *
 * WordPress does not fail loudly when asked for a format its image library
 * cannot write: it generates no sizes at all and carries on. Installed through
 * the `wp_image_editors` filter, this stands in for that host so the fallback
 * can be exercised on one that does have WebP.
 *
 * Extending the real editor rather than faking one keeps every other part of
 * image handling genuine — the resize, the sub-sizes and the files on disk are
 * all still WordPress's own.
 */
final class PlainGdEditor extends WP_Image_Editor_GD {

	/** The format this host was built without. */
	private const UNAVAILABLE = 'image/webp';

	/**
	 * What `wp_image_editor_supports()` consults.
	 *
	 * @param  string $mime_type The format being asked about.
	 * @return bool
	 */
	public static function supports_mime_type( $mime_type ) {
		return self::UNAVAILABLE !== $mime_type && parent::supports_mime_type( $mime_type );
	}

	/**
	 * And what actually happens if something asks anyway.
	 *
	 * Overriding the answer alone would not reproduce the host: real GD checks
	 * for the encoder here, separately, and fails at the point of writing. A
	 * double that only refused the question would let code which never asked it
	 * sail through, which is the bug this exists to catch.
	 *
	 * @param  resource|\GdImage $image     The image to write.
	 * @param  string|null       $filename  Where to write it.
	 * @param  string|null       $mime_type What to write it as.
	 * @return array|WP_Error
	 */
	protected function _save( $image, $filename = null, $mime_type = null ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
		list( , , $resolved ) = $this->get_output_format( $filename, $mime_type );

		if ( self::UNAVAILABLE === $resolved ) {
			return new WP_Error( 'image_save_error', 'Image Editor Save Failed' );
		}

		return parent::_save( $image, $filename, $mime_type );
	}
}
