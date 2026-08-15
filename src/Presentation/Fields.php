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
 * **Every one of them answers for either kind of record**, in the direction that
 * makes sense for it. The ones describing a screening — a time, a button, a word
 * about the seats — answer for a film by picking one of its screenings, and it is
 * the **same** screening for all three, so that a card cannot headline Saturday
 * and sell Sunday. The ones describing a film — its poster, runtime, rating,
 * genre, credits and trailer — answer for a screening by looking up the film it
 * screens, {@see self::film_for()}.
 *
 * That second direction is what lets a chronological listing be more than a list
 * of times. The record being rendered there is the screening, but almost
 * everything a visitor wants to read about it belongs to the film, and a row that
 * could not reach it left a designer choosing between a calendar and a card.
 */
final class Fields {

	/**
	 * In minutes, as a bare number: a designer adds "min" with the tag's own
	 * After control rather than being given a unit they cannot change.
	 *
	 * @param int $post_id A film or a session record.
	 */
	public static function runtime( int $post_id ): string {
		$minutes = (int) get_post_meta( self::film_for( $post_id ), ContentModel::FILM_RUNTIME, true );

		return $minutes > 0 ? (string) $minutes : '';
	}

	/**
	 * @param int $post_id A film or a session record.
	 */
	public static function classification( int $post_id ): string {
		return self::filed_under( self::film_for( $post_id ), ContentModel::CLASSIFICATION );
	}

	/**
	 * @param int $post_id A film or a session record.
	 */
	public static function genre( int $post_id ): string {
		return self::filed_under( self::film_for( $post_id ), ContentModel::GENRE );
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
	 * @param  int $post_id A film or a session record.
	 * @return array{id:int,url:string}
	 */
	public static function poster( int $post_id ): array {
		$attachment = (int) get_post_thumbnail_id( self::film_for( $post_id ) );

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
	 * Who worked on the film, and what they did.
	 *
	 * @param int    $post_id A film or a session record.
	 * @param string $role    One of {@see \Veezi\WordPress\Person::ROLES}, or an
	 *                        empty string for everybody.
	 */
	public static function cast_and_crew( int $post_id, string $role = '' ): string {
		return Credits::for_film( self::film_for( $post_id ) )->in_words( $role );
	}

	/**
	 * @param int $post_id A film or a session record.
	 */
	public static function trailer_url( int $post_id ): string {
		return (string) get_post_meta( self::film_for( $post_id ), ContentModel::FILM_TRAILER, true );
	}

	/**
	 * Written out in the cinema's timezone, in whatever shape was asked for.
	 *
	 * The format is what lets one tag give a chronological listing both of the
	 * things it needs: a date alone is the day heading a row sits under, and a
	 * time alone is the row. Left empty it is the site's own date and time,
	 * which is what a card asks for and what every template built before there
	 * was a choice stored.
	 *
	 * @param int    $post_id A film or a session record.
	 * @param string $format  A PHP date format, or an empty string for the site's.
	 */
	public static function session_time( int $post_id, string $format = '' ): string {
		$screening = self::screening_for( $post_id );

		if ( null === $screening ) {
			return '';
		}

		return $screening->in_words( '' === $format ? self::site_format() : $format );
	}

	/**
	 * How this site writes a date and a time out, together.
	 */
	public static function site_format(): string {
		return trim( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) );
	}

	/**
	 * What the cinema is saying about the seats for this screening.
	 *
	 * @param int    $post_id A film or a session record.
	 * @param Badges $words   How this site says each of the three states.
	 */
	public static function availability( int $post_id, Badges $words ): string {
		return self::screening_for( $post_id )?->availability( $words ) ?? '';
	}

	/**
	 * The film's title, whichever kind of record is being looked at.
	 *
	 * A session's own title carries the time as well as the film — that is what
	 * makes a list of them legible in the admin — so a row bound to the ordinary
	 * post title would print the time twice, once in a shape no control can
	 * change.
	 *
	 * @param int $post_id A film or a session record.
	 */
	public static function film_title( int $post_id ): string {
		$film = self::film_for( $post_id );

		return $film > 0 ? get_the_title( $film ) : '';
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
	 * Which film a record is about.
	 *
	 * On a film, itself. On a screening, the film it screens — read from the
	 * relation the sync maintains rather than from the record's own fields,
	 * which carry none of this.
	 *
	 * Zero when a screening has no film the site knows about. That is a real
	 * state rather than a defensive one: the sync stores a screening whether or
	 * not the catalogue admitted to the film, so that a listing is never
	 * silently short. Every caller below treats zero as "nothing to show",
	 * which is what each of them does for a film missing that field anyway.
	 *
	 * @param int $post_id A film or a session record.
	 */
	private static function film_for( int $post_id ): int {
		if ( ! self::is_screening( $post_id ) ) {
			return $post_id;
		}

		return (int) get_post_meta( $post_id, ContentModel::SESSION_FILM, true );
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
		// Asked before WordPress is, because a screening whose film the site
		// does not have arrives here as zero, and that is a question about no
		// object rather than a malformed one.
		if ( $post_id <= 0 ) {
			return '';
		}

		$names = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );

		if ( is_wp_error( $names ) ) {
			return '';
		}

		return implode( ', ', $names );
	}
}
