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
			// not have been announced, so it is written down but not published.
			'post_status'  => $programme->is_on_sale( $film->id ) ? 'publish' : 'draft',
		);

		// Never un-publish. Once a film has been on sale its address may be in
		// somebody's inbox or a search index, and ticket 08's promise is that
		// the link keeps working after the season ends.
		if ( 0 !== $post_id && 'publish' === get_post_status( $post_id ) ) {
			$post['post_status'] = 'publish';
		}

		$post_id = $this->upsert( $post_id, $post );

		if ( 0 === $post_id ) {
			return 0;
		}

		$next_screening = $programme->next_screening( $film->id );

		$this->write_meta(
			$post_id,
			array(
				ContentModel::FILM_ID             => $film->id,
				ContentModel::FILM_RUNTIME        => (string) $film->runtime,
				ContentModel::FILM_DISTRIBUTOR    => $film->distributor,
				ContentModel::FILM_RELEASED       => $film->released_on,
				ContentModel::FILM_TRAILER        => $film->trailer_url,
				ContentModel::FILM_NEXT_SCREENING => null === $next_screening
					? ''
					: (string) $next_screening->getTimestamp(),
				ContentModel::FILM_SESSION_COUNT  => (string) $programme->session_count( $film->id ),
			)
		);

		wp_set_object_terms( $post_id, $film->genres, ContentModel::GENRE );
		wp_set_object_terms(
			$post_id,
			'' === $film->classification ? array() : array( $film->classification ),
			ContentModel::CLASSIFICATION
		);
		wp_set_object_terms(
			$post_id,
			$programme->is_on_sale( $film->id )
				? array( ContentModel::listing_term( ContentModel::NOW_SHOWING ) )
				: array(),
			ContentModel::LISTING
		);

		$this->posters->attach( $post_id, $film );

		return $post_id;
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
				$film_posts[ $session->film_id ] ?? 0,
				$rank,
				$existing[ (string) $session->id ] ?? 0
			);
		}
	}

	private function store_session( Session $session, int $film_post, int $rank, int $post_id ): void {
		$starts_text = $this->in_words( $session->starts_at );
		$title       = $this->as_plain_text( $session->title );

		$post = array(
			'post_type'   => ContentModel::SESSION,
			'post_title'  => '' === $title ? $starts_text : sprintf( '%s — %s', $title, $starts_text ),
			'post_status' => $session->on_sale ? 'publish' : 'draft',
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
				ContentModel::SESSION_STATUS      => $session->on_sale ? 'open' : 'planned',
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

			$this->upsert(
				$post_id,
				array(
					'post_type'  => ContentModel::FILM,
					'menu_order' => $rank,
				)
			);

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
	 * @param int                  $post_id Which record.
	 * @param array<string,string> $meta    Keys and their values.
	 */
	private function write_meta( int $post_id, array $meta ): void {
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
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
				'post_type'        => $post_type,

				// Every status by name rather than 'any', which quietly omits
				// the trash. A trashed film found by nobody is a film the next
				// sync creates all over again, leaving two.
				'post_status'      => array_keys( get_post_stati() ),
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
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
