<?php
/**
 * Writing the programme into WordPress.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an assembled programme into posts, terms and metadata.
 *
 * Three rules shape everything here.
 *
 * The first is that a sync which changes nothing must *write* nothing. Records
 * are matched on their Veezi identifiers and compared field by field before
 * anything is saved, so a run against unchanged data leaves modification dates,
 * revisions and caches exactly as it found them. Anything else would make a
 * scheduled sync look like a person editing the site every hour.
 *
 * The second is that times are written twice: as the instant the screening
 * happens, which sorts and filters correctly, and as the words to print, worked
 * out in the cinema's timezone rather than the site's. A WordPress install
 * whose own timezone is unset or simply wrong is common, and it should still
 * show the right time on the page.
 *
 * The third is that both kinds of record carry a rank in `menu_order`, written
 * from the order the programme comes in — films by when they next screen,
 * sessions chronologically. Neither of the fields above can order a listing:
 * the page builder's loop grid sorts by published date, title, menu order, last
 * modified, comment count or random, and nothing else. For synced content its
 * default is actively misleading, because published date is when the sync
 * happened to create the record. A listing left alone would come out in a
 * meaningless order and report no error at all, so the one sortable field there
 * is gets the answer written into it. A position rather than a timestamp: the
 * column is a signed 32-bit integer, and epoch seconds run out in 2038.
 */
final class Repository {

	public function __construct(
		private readonly DateTimeZone $zone,
		private readonly PosterLibrary $posters = new PosterLibrary()
	) {}

	/**
	 * @param Programme $programme What Veezi says is still to come.
	 */
	public function store( Programme $programme ): void {
		// Kept where a page can read it, because the times a widget prints are
		// worked out again at render time rather than reprinted from what is
		// stored — which is the only way the format can be a control a designer
		// changes. Writing the same name twice is not a write.
		CinemaTimezone::remember( $this->zone );

		$this->store_sessions( $programme, $this->store_films( $programme ) );
		$this->forget_what_veezi_no_longer_lists( $programme );
	}

	/**
	 * @param  Programme $programme What Veezi says is still to come.
	 * @return array<string,int> Veezi film identifier to WordPress post id.
	 */
	private function store_films( Programme $programme ): array {
		$existing = $this->index( ContentModel::FILM, ContentModel::FILM_ID );
		$stored   = array();
		$rank     = 0;

		foreach ( $programme->films() as $film ) {
			++$rank;

			$post_id = $this->store_film( $film, $programme, $rank, $existing[ $film->id ] ?? 0 );

			if ( $post_id > 0 ) {
				$stored[ $film->id ] = $post_id;
			}
		}

		return $stored;
	}

	private function store_film( Film $film, Programme $programme, int $rank, int $post_id ): int {
		$on_sale     = $programme->is_on_sale( $film->id );
		$coming_soon = $programme->is_coming_soon( $film->id );

		// Never un-publish a film that has been on sale. Its address may be in
		// somebody's inbox or a search index, and ticket 08's promise is that
		// the link keeps working after the season ends. A film published only
		// because coming-soon publication is switched on has no such promise —
		// that switch has to be reversible — which is the whole reason the two
		// are told apart.
		$keeps_its_page = $this->keeps_its_page( $post_id );

		$post = array(
			'post_type'    => ContentModel::FILM,
			'post_title'   => $this->as_plain_text( $film->title ),
			'menu_order'   => $rank,

			// Sanitised here rather than left to WordPress, because WordPress
			// only strips markup for users who lack the unfiltered_html
			// capability — which would mean a sync run by cron and the same
			// sync run by an administrator storing different content, and each
			// one undoing the other.
			'post_content' => wp_kses_post( $film->synopsis ),

			// A film nobody can buy a ticket for yet is programming that may
			// not have been announced, so it is written down but not published
			// until the cinema says otherwise.
			'post_status'  => $on_sale || $coming_soon || $keeps_its_page ? 'publish' : 'draft',
		);

		$post_id = $this->upsert( $post_id, $post );

		if ( 0 === $post_id ) {
			return 0;
		}

		$next_screening = $programme->next_screening( $film->id );

		$this->write_meta(
			$post_id,
			array(
				ContentModel::FILM_ID               => $film->id,
				ContentModel::FILM_RUNTIME          => (string) $film->runtime,
				ContentModel::FILM_DISTRIBUTOR      => $film->distributor,
				ContentModel::FILM_RELEASED         => $film->released_on,
				ContentModel::FILM_TRAILER          => $film->trailer_url,
				ContentModel::FILM_PEOPLE           => Person::encode( $film->people ),
				ContentModel::FILM_NEXT_SCREENING   => null === $next_screening
					? ''
					: (string) $next_screening->getTimestamp(),
				ContentModel::FILM_SESSION_COUNT    => (string) $programme->session_count( $film->id ),

				// Set for as long as this record would have nothing published
				// about it were the switch moved back, and cleared for good the
				// moment a ticket can be bought.
				ContentModel::FILM_COMING_SOON_ONLY => $on_sale || $keeps_its_page ? '' : '1',
			)
		);

		wp_set_object_terms( $post_id, $film->genres, ContentModel::GENRE );
		wp_set_object_terms(
			$post_id,
			'' === $film->classification ? array() : array( $film->classification ),
			ContentModel::CLASSIFICATION
		);
		wp_set_object_terms( $post_id, $this->listings( $on_sale, $coming_soon ), ContentModel::LISTING );

		$this->posters->attach( $post_id, $film );

		return $post_id;
	}

	/**
	 * Which listings a film belongs in.
	 *
	 * Both is an ordinary answer, not an edge case: a film showing this week
	 * with more dates announced for next month is in the current programme and
	 * in what is coming. Neither is also ordinary — that is a film whose season
	 * has ended, or one nobody has decided to talk about yet.
	 *
	 * @param  bool $on_sale     Whether a ticket can be bought for it.
	 * @param  bool $coming_soon Whether something of it has been announced ahead
	 *                           of going on sale.
	 * @return array<int,int> Term ids, made on demand.
	 */
	private function listings( bool $on_sale, bool $coming_soon ): array {
		$listings = array();

		if ( $on_sale ) {
			$listings[] = ContentModel::listing_term( ContentModel::NOW_SHOWING );
		}

		if ( $coming_soon ) {
			$listings[] = ContentModel::listing_term( ContentModel::COMING_SOON );
		}

		return $listings;
	}

	/**
	 * Whether this film's page outlives whatever the cinema does with the
	 * coming-soon switch — which it does once it has been on sale, and not
	 * before.
	 *
	 * Read from what is stored rather than from what is about to be, so it has
	 * to be asked before {@see self::store_film()} writes the mark again.
	 *
	 * @param int $post_id Zero for a film not stored yet.
	 */
	private function keeps_its_page( int $post_id ): bool {
		return 0 !== $post_id
			&& 'publish' === get_post_status( $post_id )
			&& ! $this->is_only_coming_soon( $post_id );
	}

	/**
	 * Whether the mark is on this record — see
	 * {@see ContentModel::FILM_COMING_SOON_ONLY}.
	 *
	 * @param int $post_id A film record.
	 */
	private function is_only_coming_soon( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, ContentModel::FILM_COMING_SOON_ONLY, true );
	}

	/**
	 * @param Programme         $programme  What Veezi says is still to come.
	 * @param array<string,int> $film_posts Veezi film identifier to post id.
	 */
	private function store_sessions( Programme $programme, array $film_posts ): void {
		$existing = $this->index( ContentModel::SESSION, ContentModel::SESSION_ID );
		$rank     = 0;

		foreach ( $programme->sessions() as $session ) {
			++$rank;

			$this->store_session(
				$session,
				$programme->is_published( $session ),
				$film_posts[ $session->film_id ] ?? 0,
				$rank,
				$existing[ (string) $session->id ] ?? 0
			);
		}
	}

	/**
	 * @param Session $session   One screening, as Veezi describes it.
	 * @param bool    $published Whether a visitor should be able to find it —
	 *                           on sale, or announced and inside the horizon.
	 * @param int     $film_post The film it belongs to, zero if the catalogue
	 *                           has never heard of it.
	 * @param int     $rank      Its position in the chronological order.
	 * @param int     $post_id   Zero for a screening not stored yet.
	 */
	private function store_session( Session $session, bool $published, int $film_post, int $rank, int $post_id ): void {
		$starts_text = $this->in_words( $session->starts_at );
		$title       = $this->as_plain_text( $session->title );

		$post = array(
			'post_type'   => ContentModel::SESSION,
			'post_title'  => '' === $title ? $starts_text : sprintf( '%s — %s', $title, $starts_text ),
			'post_status' => $published ? 'publish' : 'draft',
			'menu_order'  => $rank,
		);

		$post_id = $this->upsert( $post_id, $post );

		if ( 0 === $post_id ) {
			return;
		}

		$this->write_meta(
			$post_id,
			array(
				ContentModel::SESSION_ID          => (string) $session->id,
				ContentModel::SESSION_FILM        => (string) $film_post,
				ContentModel::SESSION_STARTS      => (string) $session->starts_at->getTimestamp(),
				ContentModel::SESSION_ENDS        => (string) $session->ends_at->getTimestamp(),
				ContentModel::SESSION_STARTS_TEXT => $starts_text,
				ContentModel::SESSION_ENDS_TEXT   => $this->in_words( $session->ends_at ),
				ContentModel::SESSION_BOOKING     => $session->booking_url,
				ContentModel::SESSION_STATUS      => $session->on_sale
					? ContentModel::STATUS_ON_SALE
					: ContentModel::STATUS_PLANNED,
				ContentModel::SESSION_SOLD_OUT    => $session->sold_out ? '1' : '',
				ContentModel::SESSION_FEW_LEFT    => $session->few_tickets_left ? '1' : '',
			)
		);
	}

	/**
	 * Take down whatever is no longer to come.
	 *
	 * Two things reach this. A cancelled screening is the one that matters most:
	 * left alone it keeps a published record and a live booking link, so the site
	 * goes on offering tickets for something that is not happening — worse than
	 * showing nothing. The other is a screening that has simply been and gone,
	 * which the programme no longer holds, and which is deleted for the same
	 * reason: so that a listing can be "the next six" without a date filter.
	 *
	 * Films are treated differently: they are never deleted, because a link to
	 * one has to keep working after its season. A film with nothing left to
	 * screen leaves the current listing and has its two forward-looking fields
	 * emptied — otherwise a page would go on advertising a next screening that
	 * happened last month — and its record and address stay exactly where they
	 * were.
	 *
	 * Only reached once every feed has arrived intact, so a failed fetch can
	 * never trigger it. An empty programme, though, genuinely means an empty
	 * programme — a cinema between seasons has nothing on, and saying so is
	 * right.
	 *
	 * @param Programme $programme What Veezi says is still to come.
	 */
	private function forget_what_veezi_no_longer_lists( Programme $programme ): void {
		$listed = $programme->sessions();

		foreach ( $this->index( ContentModel::SESSION, ContentModel::SESSION_ID ) as $upstream_id => $post_id ) {
			if ( ! isset( $listed[ (int) $upstream_id ] ) ) {
				wp_delete_post( $post_id, true );
			}
		}

		$scheduled = $programme->films();
		$rank      = count( $scheduled );

		foreach ( $this->index( ContentModel::FILM, ContentModel::FILM_ID ) as $upstream_id => $post_id ) {
			if ( isset( $scheduled[ (string) $upstream_id ] ) ) {
				continue;
			}

			// Ranked on past the films that are still showing, rather than left
			// holding whichever position it had when its season ended — which
			// would otherwise be position 1, shared with whatever is showing
			// first now. Their order among themselves is the order they were
			// first synced in: arbitrary, but the same on every run, which is
			// what stops this rewriting ranks for ever.
			++$rank;

			$film = array(
				'post_type'  => ContentModel::FILM,
				'menu_order' => $rank,
			);

			// A film published only because it was announced, and then dropped
			// from the schedule altogether, is an announcement of something
			// that is not happening. It goes back to a draft — which is the
			// same promise the switch makes, kept when Veezi is the one who
			// changed its mind. A film that has been on sale keeps its page.
			if ( $this->is_only_coming_soon( $post_id ) ) {
				$film['post_status'] = 'draft';
			}

			$this->upsert( $post_id, $film );

			wp_set_object_terms( $post_id, array(), ContentModel::LISTING );
			$this->write_meta(
				$post_id,
				array(
					ContentModel::FILM_NEXT_SCREENING => '',
					ContentModel::FILM_SESSION_COUNT  => '0',
				)
			);
		}
	}

	/**
	 * Create the record or bring it up to date, and hand back its id.
	 *
	 * @param  int                 $post_id Zero for something not stored yet.
	 * @param  array<string,mixed> $post    What the record should say.
	 * @return int Zero if WordPress refused to store it.
	 */
	private function upsert( int $post_id, array $post ): int {
		if ( 0 !== $post_id ) {
			$this->update_if_changed( $post_id, $post );

			return $post_id;
		}

		$created = wp_insert_post( wp_slash( $post ), true );

		return is_wp_error( $created ) ? 0 : (int) $created;
	}

	/**
	 * Titles are stripped of markup for the same reason synopses are filtered:
	 * WordPress does it only for users without the unfiltered_html capability,
	 * so leaving it to WordPress means a cron sync and an administrator's sync
	 * storing different bytes and each rewriting the other's. A film title has
	 * no business carrying markup anyway.
	 *
	 * @param string $text As Veezi sent it.
	 */
	private function as_plain_text( string $text ): string {
		return trim( wp_strip_all_tags( $text ) );
	}

	/**
	 * The time as the cinema would print it.
	 *
	 * The zone is passed explicitly rather than left to `wp_date()`'s default,
	 * which is the site's. The site's is not the cinema's, and on an install
	 * nobody has configured it is not anywhere at all.
	 *
	 * @param DateTimeImmutable $moment The instant to print.
	 */
	private function in_words( DateTimeImmutable $moment ): string {
		$format = trim(
			(string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' )
		);

		return (string) wp_date( $format, $moment->getTimestamp(), $this->zone );
	}

	/**
	 * Save, but only if saving would actually alter something.
	 *
	 * Compared as strings because these are not all of a type: `menu_order` is
	 * an integer going in and arrives back from the database as a string, and a
	 * strict comparison of the two would find a difference in every record on
	 * every run — which is exactly the churn this method exists to prevent.
	 *
	 * The list is every field written, and has to stay that way. A field left
	 * off is one whose changes are computed, discarded, and computed again next
	 * run: the rank spent a while in that state, which looked like ordering
	 * simply not working.
	 *
	 * @param int                 $post_id Which record.
	 * @param array<string,mixed> $post    What it should say.
	 */
	private function update_if_changed( int $post_id, array $post ): void {
		$existing = get_post( $post_id );

		if ( ! $existing instanceof WP_Post ) {
			return;
		}

		foreach ( array( 'post_title', 'post_content', 'post_status', 'menu_order' ) as $field ) {
			if ( array_key_exists( $field, $post ) && (string) $existing->$field !== (string) $post[ $field ] ) {
				$post['ID'] = $post_id;
				wp_update_post( wp_slash( $post ) );

				return;
			}
		}
	}

	/**
	 * Metadata is written as strings throughout. WordPress stores it as strings
	 * regardless, and comparing an integer against what comes back out never
	 * matches — so passing integers would rewrite every value on every sync.
	 *
	 * Slashed on the way in because the metadata API unslashes on the way
	 * through, so a value handed over as it stands loses every backslash in it.
	 * That is invisible for most of these and fatal for the credits, which are
	 * encoded: `Penélope` arrives back as `Penu00e9lope`, and Penélope Cruz
	 * has lost a letter on every page that names her.
	 *
	 * @param int                  $post_id Which record.
	 * @param array<string,string> $meta    Keys and their values.
	 */
	private function write_meta( int $post_id, array $meta ): void {
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}

	/**
	 * Everything already stored of one kind, by the upstream identifier it was
	 * synced from — gathered up front so that matching records is two queries
	 * rather than two per record.
	 *
	 * @param  string $post_type Which kind of record.
	 * @param  string $meta_key  The field holding the upstream identifier.
	 * @return array<string,int>
	 */
	private function index( string $post_type, string $meta_key ): array {
		$post_ids = get_posts(
			array(
				'post_type'                   => $post_type,

				// Every status by name rather than 'any', which quietly omits
				// the trash. A trashed film found by nobody is a film the next
				// sync creates all over again, leaving two.
				'post_status'                 => array_keys( get_post_stati() ),
				'numberposts'                 => -1,
				'fields'                      => 'ids',
				'orderby'                     => 'ID',
				'order'                       => 'ASC',
				'suppress_filters'            => false,

				// Including the screening that started twenty minutes ago,
				// which a listing hides and this must not: a record the sync
				// fails to find is one it creates all over again.
				ContentModel::EVERY_SCREENING => true,
			)
		);

		if ( array() === $post_ids ) {
			return array();
		}

		update_meta_cache( 'post', $post_ids );

		$index = array();

		foreach ( $post_ids as $post_id ) {
			$upstream_id = (string) get_post_meta( (int) $post_id, $meta_key, true );

			if ( '' !== $upstream_id ) {
				$index[ $upstream_id ] = (int) $post_id;
			}
		}

		return $index;
	}
}
