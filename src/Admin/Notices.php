<?php
/**
 * What the plugin tells an administrator without being asked.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Veezi\WordPress\CinemaTimezone;
use Veezi\WordPress\SyncLog;

defined( 'ABSPATH' ) || exit;

/**
 * Two things worth interrupting somebody for.
 *
 * Both are conditions rather than events, and both put themselves away: a
 * failure ends when a run works, and a clock that disagrees with the cinema's
 * stops disagreeing when somebody corrects it. So neither is remembered as
 * "told" — there is nothing to remember. Dismissing one clears it from the
 * screen in front of you, and it comes back until the thing it is about is
 * actually fixed, which is the behaviour a broken site should have.
 *
 * Shown only to somebody who could act on them. A notice an editor cannot do
 * anything about is noise, and the second one names a setting they cannot
 * reach.
 */
final class Notices {

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			return;
		}

		$this->sync_failure();
		$this->clocks_that_disagree();
	}

	/**
	 * A sync that failed and has not since worked.
	 *
	 * The programme on the site is still whatever the last good run put there —
	 * nothing has gone blank — but it is ageing, and by tomorrow it will be
	 * advertising screenings that have already happened.
	 */
	private function sync_failure(): void {
		$failure = SyncLog::unresolved_failure();

		if ( null === $failure ) {
			return;
		}

		// Only claimed when it is true. A site that has never synced has no
		// last programme standing, and telling its administrator otherwise
		// would send them looking for content that was never there.
		if ( SyncLog::has_ever_succeeded() ) {
			/* translators: %s: a link to the plugin's settings screen, reading "Settings → Veezi". */
			$reassurance = __( 'What is on the site is still the last programme that synced. %s has the connection and a button to try again.', 'veezi-wordpress-plugin' );
		} else {
			/* translators: %s: a link to the plugin's settings screen, reading "Settings → Veezi". */
			$reassurance = __( 'Nothing has synced yet, so there is no programme on the site. %s has the connection and a button to try again.', 'veezi-wordpress-plugin' );
		}

		self::warn(
			'error',
			__( 'Veezi: the programme could not be synced.', 'veezi-wordpress-plugin' ),
			$failure->message(),
			sprintf(
				$reassurance,
				self::link_to(
					admin_url( 'options-general.php?page=' . SettingsPage::MENU_SLUG ),
					__( 'Settings → Veezi', 'veezi-wordpress-plugin' )
				)
			)
		);
	}

	/**
	 * The site's timezone and the cinema's, when they are not the same clock.
	 *
	 * Showtimes themselves are safe: the plugin converts them in the cinema's
	 * zone rather than trusting the site's, precisely because this
	 * misconfiguration is so common — the site this was written for has it. But
	 * everything *else* dated on a WordPress site is the site's timezone's
	 * doing, from a post's publication time to whatever a page builder prints
	 * from a date field, and all of it is quietly hours out.
	 *
	 * Nothing is said before a sync has worked, because until then the cinema's
	 * timezone falls back to the site's and the two agree by construction.
	 */
	private function clocks_that_disagree(): void {
		if ( ! SyncLog::has_ever_succeeded() ) {
			return;
		}

		$cinema = CinemaTimezone::stored();
		$site   = wp_timezone();
		$now    = new DateTimeImmutable( '@' . time() );

		// Compared as offsets rather than names, because Melbourne and Sydney
		// are two names for one clock and warning about that would teach an
		// administrator to ignore the warning.
		if ( $cinema->getOffset( $now ) === $site->getOffset( $now ) ) {
			return;
		}

		self::warn(
			'warning',
			__( 'Veezi: this site keeps a different time from the cinema.', 'veezi-wordpress-plugin' ),
			sprintf(
				/* translators: 1: the site's timezone, 2: the cinema's timezone as Veezi reports it. */
				__( 'WordPress is set to %1$s and the cinema is in %2$s.', 'veezi-wordpress-plugin' ),
				self::describe( $site ),
				$cinema->getName()
			),
			sprintf(
				/* translators: %s: a link to WordPress's own General settings screen, reading "Settings → General". */
				__( 'Showtimes are not affected — those are converted in the cinema’s own timezone. Every other date on the site is out by the difference. %s is where the site’s timezone is set.', 'veezi-wordpress-plugin' ),
				self::link_to( admin_url( 'options-general.php' ), __( 'Settings → General', 'veezi-wordpress-plugin' ) )
			)
		);
	}

	/**
	 * Both notices, in the shape WordPress draws them: a headline and what is
	 * wrong, then what to do about it.
	 *
	 * @param string $level     `error` or `warning`.
	 * @param string $headline  The condition, in a few words.
	 * @param string $detail    Plain text. Upstream's own words, in one case.
	 * @param string $follow_up May carry a link, and nothing else.
	 */
	private static function warn( string $level, string $headline, string $detail, string $follow_up ): void {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s</strong> %3$s</p><p>%4$s</p></div>',
			esc_attr( $level ),
			esc_html( $headline ),
			esc_html( $detail ),
			wp_kses( $follow_up, array( 'a' => array( 'href' => array() ) ) )
		);
	}

	private static function link_to( string $url, string $label ): string {
		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
	}

	/**
	 * A timezone in the terms the settings screen offers it.
	 *
	 * A site with its timezone unset has no name to report — only an offset,
	 * which is what WordPress builds a zone from in that case.
	 *
	 * @param DateTimeZone $zone The site's timezone, as WordPress resolved it.
	 */
	private static function describe( DateTimeZone $zone ): string {
		$configured = (string) get_option( 'timezone_string', '' );

		return '' !== $configured ? $configured : $zone->getName();
	}
}
