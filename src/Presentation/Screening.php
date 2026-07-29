<?php
/**
 * A screening, as the site has it stored.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Presentation;

use Veezi\WordPress\CinemaTimezone;
use Veezi\WordPress\ContentModel;

defined( 'ABSPATH' ) || exit;

/**
 * One screening read back out of WordPress, ready to put on a page.
 *
 * The mirror image of {@see \Veezi\WordPress\Session}, and deliberately a
 * separate class from it. That one is what Veezi said, on its way in; this is
 * what the site holds, on its way out. They carry different fields for good
 * reasons — this one knows a post id and a formatted time, and has never heard
 * of a Veezi identifier — and collapsing them would tie what a page can show to
 * the shape of somebody else's API.
 *
 * Only published screenings are ever found here. A planned one is programming
 * the cinema may not have announced, and ticket 09 is where deciding to show it
 * belongs; until then the answer is no, at the only place that has to be right.
 */
final class Screening {

	private function __construct(
		public readonly int $post_id,
		public readonly int $starts_at,
		public readonly string $booking_url,
		public readonly bool $sold_out,
		public readonly bool $few_tickets_left
	) {}

	/**
	 * @param int $post_id A session record.
	 */
	public static function from_post( int $post_id ): ?self {
		$starts_at = (string) get_post_meta( $post_id, ContentModel::SESSION_STARTS, true );

		if ( '' === $starts_at ) {
			return null;
		}

		return new self(
			$post_id,
			(int) $starts_at,
			(string) get_post_meta( $post_id, ContentModel::SESSION_BOOKING, true ),
			'' !== (string) get_post_meta( $post_id, ContentModel::SESSION_SOLD_OUT, true ),
			'' !== (string) get_post_meta( $post_id, ContentModel::SESSION_FEW_LEFT, true )
		);
	}

	/**
	 * The time, written out the way the cinema would say it.
	 *
	 * Worked out here from the instant rather than reprinted from the string the
	 * sync stored alongside it. That string is fixed at sync time in the site's
	 * date and time format, so reading it back would mean a widget offering a
	 * format control could not honour it, and a card printing two times for one
	 * screening in two different shapes — one of them stale until the next sync.
	 *
	 * The zone is the cinema's, never the site's. A WordPress install with its
	 * timezone unset is common, and this one's is.
	 *
	 * @param string $format A PHP date format.
	 */
	public function in_words( string $format ): string {
		return (string) wp_date( $format, $this->starts_at, CinemaTimezone::stored() );
	}

	/**
	 * Everything still to come for one film, soonest first.
	 *
	 * Ordered by the rank the sync writes rather than by the stored timestamp,
	 * because that rank is already chronological and `menu_order` is a column on
	 * the posts table — sorting by the timestamp would mean a join and a sort on
	 * a text column holding a number.
	 *
	 * Every screening still on the site, including one under way — which a
	 * chronological listing hides and a card does not. The argument is at
	 * {@see ContentModel::hide_screenings_that_have_started()}; here it is why
	 * this asks for the exemption. {@see self::is_bookable()} is what makes such
	 * a row unsellable rather than merely present.
	 *
	 * @param  int $film_post A film record.
	 * @param  int $limit     How many at most; zero for all of them.
	 * @return array<int,self>
	 */
	public static function for_film( int $film_post, int $limit = 0 ): array {
		if ( $film_post <= 0 ) {
			return array();
		}

		// Matched on the film relation, which is a lookup by meta value — the
		// thing the standards warn about on a page a visitor loads. It is the
		// right shape here: the key is indexed, a cinema's whole forward
		// programme is a few dozen rows, and avoiding it would mean a second
		// copy of the relation for the sync to keep in step.
		// phpcs:disable WordPress.DB.SlowDBQuery
		$found = get_posts(
			array(
				'post_type'                   => ContentModel::SESSION,
				'post_status'                 => 'publish',
				'numberposts'                 => $limit > 0 ? $limit : -1,
				'fields'                      => 'ids',
				'orderby'                     => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'meta_key'                    => ContentModel::SESSION_FILM,
				'meta_value'                  => (string) $film_post,

				ContentModel::EVERY_SCREENING => true,
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery

		$screenings = array();

		foreach ( $found as $post_id ) {
			$screening = self::from_post( (int) $post_id );

			if ( null !== $screening ) {
				$screenings[] = $screening;
			}
		}

		return $screenings;
	}

	/**
	 * Whether the cinema has anything at all still to come.
	 *
	 * Asked without {@see ContentModel::EVERY_SCREENING}, so it is answered by
	 * exactly the rule the chronological listing follows. Anything else and the
	 * two would disagree at the worst possible moment: an empty listing under a
	 * heading saying there is plenty on, or the reverse.
	 */
	public static function any_upcoming(): bool {
		$found = get_posts(
			array(
				'post_type'   => ContentModel::SESSION,
				'post_status' => 'publish',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);

		return array() !== $found;
	}

	/**
	 * When this film next screens, sold out or not.
	 *
	 * @param int $film_post A film record.
	 */
	public static function next_for( int $film_post ): ?self {
		return self::for_film( $film_post, 1 )[0] ?? null;
	}

	/**
	 * The soonest screening of this film somebody can still buy a ticket for,
	 * which is not always the next one.
	 *
	 * @param int $film_post A film record.
	 */
	public static function next_bookable_for( int $film_post ): ?self {
		foreach ( self::for_film( $film_post ) as $screening ) {
			if ( $screening->is_bookable() ) {
				return $screening;
			}
		}

		return null;
	}

	/**
	 * What to say about the seats, or nothing when there is nothing to say.
	 *
	 * The words are the caller's because they are the designer's: one panel
	 * calls it "Sold out" and the next "Full house", and neither should need a
	 * translation file. Sold out wins when Veezi sends both, which it does —
	 * it is the one that stops somebody making the trip.
	 *
	 * @param string $sold_out What a screening with no seats reads.
	 * @param string $few_left What a screening nearly gone reads.
	 */
	public function availability( string $sold_out, string $few_left ): string {
		if ( $this->sold_out ) {
			return $sold_out;
		}

		return $this->few_tickets_left ? $few_left : '';
	}

	/**
	 * Whether this one has begun.
	 *
	 * Read from the clock rather than from anything the sync wrote, because the
	 * sync runs hourly and this changes every minute.
	 */
	public function has_started(): bool {
		return $this->starts_at <= time();
	}

	/**
	 * Whether a visitor can still buy a ticket for this one.
	 *
	 * Three ways to answer no. Sold out is the obvious one. The second is a
	 * screening with no link at all, which is either box-office-only or one
	 * whose sales have closed — and in both cases there is nothing useful to
	 * point at.
	 *
	 * The third is a screening that has begun. Veezi withdraws the link itself,
	 * its websession feed being future-only, but not until the next sync — so
	 * for as long as an hour a card would go on selling something the visitor
	 * has already missed. The row stays, which is ticket 05's point; the offer
	 * does not, which is this one's. It is also what keeps a card's headline
	 * time and its button on the same screening, since both follow the soonest
	 * one that can still be acted on.
	 */
	public function is_bookable(): bool {
		return ! $this->sold_out && '' !== $this->booking_url && ! $this->has_started();
	}
}
