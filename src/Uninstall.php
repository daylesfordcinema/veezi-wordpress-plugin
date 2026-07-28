<?php
/**
 * What deleting the plugin takes with it.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Removes what the plugin configured itself with, and nothing it published.
 *
 * The line matters. Everything below is the plugin's own bookkeeping — a
 * credential, a schedule, a lock, some notes about the last run — and is
 * meaningless without the code that wrote it. The films, sessions and posters
 * are not: a film has a public address somebody may have linked to, and its
 * poster is in the media library because this plugin's own documentation
 * encourages the cinema to reuse it in a newsletter. WordPress does not delete
 * a site's posts when a plugin is deleted, and neither does this. The README
 * says so, and says how to remove them for anyone who wants to.
 *
 * The one thing here that would be genuinely dangerous to leave is the access
 * token: a live credential for a cinema's ticketing account, in the database of
 * a site with no code left that uses it.
 */
final class Uninstall {

	/**
	 * Listed rather than swept, so that an option added later and forgotten
	 * here is a visible omission in one place instead of an invisible one
	 * everywhere.
	 *
	 * @return array<int,string>
	 */
	private static function options(): array {
		return array(
			Settings::OPTION,
			SyncLog::LAST_SUCCESS,
			SyncLog::LAST_FAILURE,
			SyncLock::OPTION,
			ResponseCache::GENERATION,
			CinemaTimezone::OPTION,
			ContentModel::REWRITES_VERSION,
		);
	}

	public static function run(): void {
		// Deactivation already did this, but a plugin can be deleted from a
		// state deactivation never reached — a file removed by hand, a site
		// restored from a backup — and an event whose hook nothing answers
		// fires forever.
		Schedule::clear();

		foreach ( self::options() as $option ) {
			delete_option( $option );
		}

		// Cached responses are transients and expire in minutes on their own.
		// They cannot be swept: under a persistent object cache, which is where
		// this cinema's site keeps them, there is no table to look in.
	}
}
