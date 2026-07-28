<?php
/**
 * What a template can bind to.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Presentation;

use Veezi\WordPress\ContentModel;

defined( 'ABSPATH' ) || exit;

/**
 * The answers behind the plugin's dynamic tags, with no Elementor in them.
 *
 * Each method takes the record being looked at and returns something a widget
 * can be handed — a string, or for the poster the pair an image control wants.
 * Never null, and never an error: a tag that throws takes the page down with it,
 * and a card is built long before anybody knows which of its fields a given
 * film will turn out to have.
 *
 * Two of them answer for either kind of record. On a screening they describe
 * that screening; on a film they describe one of its screenings, because a card
 * for a film still wants a time and a button — and it is the **same** screening
 * for both, so that a card cannot headline Saturday and sell Sunday.
 */
final class Fields {

	/**
	 * In minutes, as a bare number: a designer adds "min" with the tag's own
	 * After control rather than being given a unit they cannot change.
	 *
	 * @param int $post_id A film record.
	 */
	public static function runtime( int $post_id ): string {
		$minutes = (int) get_post_meta( $post_id, ContentModel::FILM_RUNTIME, true );

		return $minutes > 0 ? (string) $minutes : '';
	}

	/**
	 * @param int $post_id A film record.
	 */
	public static function classification( int $post_id ): string {
		return self::filed_under( $post_id, ContentModel::CLASSIFICATION );
	}

	/**
	 * @param int $post_id A film record.
	 */
	public static function genre( int $post_id ): string {
		return self::filed_under( $post_id, ContentModel::GENRE );
	}

	/**
	 * The poster, as an image control expects it.
	 *
	 * The id matters more than the URL: given one, Elementor's image widget can
	 * ask for a registered size, and ticket 04 registered `veezi-poster` for
	 * exactly that.
	 *
	 * The URL is that same card size rather than the original, because whatever
	 * reads the URL instead of the id — a background image, say — has no size to
	 * choose and would otherwise be handed the full-resolution poster, which for
	 * these runs to several megabytes. WordPress hands back the original anyway
	 * if the artwork was too small to make a card size from.
	 *
	 * @param  int $post_id A film record.
	 * @return array{id:int,url:string}
	 */
	public static function poster( int $post_id ): array {
		$attachment = (int) get_post_thumbnail_id( $post_id );

		if ( 0 === $attachment ) {
			return array(
				'id'  => 0,
				'url' => '',
			);
		}

		return array(
			'id'  => $attachment,
			'url' => (string) wp_get_attachment_image_url( $attachment, ContentModel::POSTER_SIZE ),
		);
	}

	/**
	 * @param int $post_id A film record.
	 */
	public static function trailer_url( int $post_id ): string {
		return (string) get_post_meta( $post_id, ContentModel::FILM_TRAILER, true );
	}

	/**
	 * Written out in the site's date and time format, in the cinema's timezone.
	 *
	 * @param int $post_id A film or a session record.
	 */
	public static function session_time( int $post_id ): string {
		$screening = self::screening_for( $post_id );

		if ( null === $screening ) {
			return '';
		}

		return $screening->in_words(
			trim( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) )
		);
	}

	/**
	 * Empty for a screening nobody can buy a ticket for, so that a button is
	 * never rendered with nowhere to go.
	 *
	 * @param int $post_id A film or a session record.
	 */
	public static function booking_url( int $post_id ): string {
		$screening = self::screening_for( $post_id );

		return null !== $screening && $screening->is_bookable() ? $screening->booking_url : '';
	}

	/**
	 * Which screening a record is talking about.
	 *
	 * On a screening, itself. On a film, the soonest one somebody can act on —
	 * and only failing that, simply the soonest.
	 *
	 * The skip matters both ways round. A card whose button points at the
	 * soonest screening whatever its state goes dead the moment that screening
	 * sells out, and stays dead for the rest of the week while five others are
	 * on sale. But a card that headlined Saturday and sold Sunday would be worse
	 * than either, so the time and the link are answered from one screening
	 * rather than two. When nothing at all can be bought the time is still the
	 * truth, and there is no link.
	 *
	 * @param int $post_id A film or a session record.
	 */
	private static function screening_for( int $post_id ): ?Screening {
		if ( self::is_screening( $post_id ) ) {
			return Screening::from_post( $post_id );
		}

		return Screening::next_bookable_for( $post_id ) ?? Screening::next_for( $post_id );
	}

	private static function is_screening( int $post_id ): bool {
		return ContentModel::SESSION === get_post_type( $post_id );
	}

	/**
	 * The terms a film is filed under, written out.
	 *
	 * Read from the taxonomy rather than from anything Veezi sent, so that a
	 * card and a genre archive cannot disagree about what a film is. The order
	 * is the taxonomy's own, which is alphabetical — upstream sends one
	 * comma-separated string whose order nothing preserves.
	 *
	 * @param int    $post_id  A film record.
	 * @param string $taxonomy Which classification.
	 */
	private static function filed_under( int $post_id, string $taxonomy ): string {
		$names = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );

		if ( is_wp_error( $names ) ) {
			return '';
		}

		return implode( ', ', $names );
	}
}
