<?php
/**
 * Which timezone the cinema is in.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

use DateTimeZone;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Translates the timezone Veezi reports into one PHP can work with.
 *
 * Veezi names timezones the way Windows does — "AUS Eastern Standard Time" —
 * and PHP only knows the IANA names, so the two have to be mapped. Everything
 * downstream depends on getting this right: Veezi sends showtimes with no
 * offset at all, so the zone chosen here *is* what turns "16:30" into a moment
 * in time. Pick the wrong one and every session on the site is quietly hours
 * out while the page looks perfectly fine.
 *
 * The table below is the practical subset of Microsoft's list — the places
 * Veezi sells tickets — rather than all of it. A cinema outside it is not stuck:
 * an unknown name falls back to the site's own timezone, and the filter is there
 * to name a zone exactly.
 */
final class CinemaTimezone {

	/**
	 * Where the answer is kept between syncs.
	 *
	 * The sync learns the cinema's timezone from Veezi, and every page that
	 * prints a showtime needs it again — a widget offering a format control has
	 * to work the time out afresh rather than reprint what the sync stored.
	 * Asking Veezi at render time is out of the question, so the answer is
	 * written down once and read from here.
	 */
	public const OPTION = 'veezi_timezone';

	/**
	 * Windows timezone identifiers to their IANA equivalents.
	 *
	 * @var array<string,string>
	 */
	private const WINDOWS_ZONES = array(
		'AUS Central Standard Time'       => 'Australia/Darwin',
		'AUS Eastern Standard Time'       => 'Australia/Sydney',
		'Alaskan Standard Time'           => 'America/Anchorage',
		'Arabian Standard Time'           => 'Asia/Dubai',
		'Argentina Standard Time'         => 'America/Argentina/Buenos_Aires',
		'Atlantic Standard Time'          => 'America/Halifax',
		'Aus Central W. Standard Time'    => 'Australia/Eucla',
		'Canada Central Standard Time'    => 'America/Regina',
		'Cen. Australia Standard Time'    => 'Australia/Adelaide',
		'Central America Standard Time'   => 'America/Guatemala',
		'Central Europe Standard Time'    => 'Europe/Budapest',
		'Central European Standard Time'  => 'Europe/Warsaw',
		'Central Standard Time'           => 'America/Chicago',
		'Central Standard Time (Mexico)'  => 'America/Mexico_City',
		'Chatham Islands Standard Time'   => 'Pacific/Chatham',
		'China Standard Time'             => 'Asia/Shanghai',
		'E. Africa Standard Time'         => 'Africa/Nairobi',
		'E. Australia Standard Time'      => 'Australia/Brisbane',
		'E. South America Standard Time'  => 'America/Sao_Paulo',
		'Eastern Standard Time'           => 'America/New_York',
		'Eastern Standard Time (Mexico)'  => 'America/Cancun',
		'FLE Standard Time'               => 'Europe/Kyiv',
		'Fiji Standard Time'              => 'Pacific/Fiji',
		'GMT Standard Time'               => 'Europe/London',
		'GTB Standard Time'               => 'Europe/Bucharest',
		'Greenwich Standard Time'         => 'Atlantic/Reykjavik',
		'Hawaiian Standard Time'          => 'Pacific/Honolulu',
		'India Standard Time'             => 'Asia/Kolkata',
		'Korea Standard Time'             => 'Asia/Seoul',
		'Lord Howe Standard Time'         => 'Australia/Lord_Howe',
		'Malay Peninsula Standard Time'   => 'Asia/Kuala_Lumpur',
		'Mountain Standard Time'          => 'America/Denver',
		'Mountain Standard Time (Mexico)' => 'America/Chihuahua',
		'New Zealand Standard Time'       => 'Pacific/Auckland',
		'Newfoundland Standard Time'      => 'America/St_Johns',
		'Pacific SA Standard Time'        => 'America/Santiago',
		'Pacific Standard Time'           => 'America/Los_Angeles',
		'Pacific Standard Time (Mexico)'  => 'America/Tijuana',
		'Romance Standard Time'           => 'Europe/Paris',
		'SA Eastern Standard Time'        => 'America/Cayenne',
		'SA Pacific Standard Time'        => 'America/Bogota',
		'SA Western Standard Time'        => 'America/La_Paz',
		'SE Asia Standard Time'           => 'Asia/Bangkok',
		'Singapore Standard Time'         => 'Asia/Singapore',
		'South Africa Standard Time'      => 'Africa/Johannesburg',
		'Taipei Standard Time'            => 'Asia/Taipei',
		'Tasmania Standard Time'          => 'Australia/Hobart',
		'Tokyo Standard Time'             => 'Asia/Tokyo',
		'US Eastern Standard Time'        => 'America/Indiana/Indianapolis',
		'US Mountain Standard Time'       => 'America/Phoenix',
		'UTC'                             => 'UTC',
		'W. Australia Standard Time'      => 'Australia/Perth',
		'W. Central Africa Standard Time' => 'Africa/Lagos',
		'W. Europe Standard Time'         => 'Europe/Berlin',
		'West Asia Standard Time'         => 'Asia/Tashkent',
	);

	/**
	 * @param string $identifier Whatever `/v1/site` reported, which is normally
	 *                           a Windows name but is not guaranteed to be.
	 */
	public static function resolve( string $identifier ): DateTimeZone {
		$identifier = trim( $identifier );
		$name       = self::WINDOWS_ZONES[ $identifier ] ?? $identifier;

		/**
		 * Filters the timezone the cinema's showtimes are interpreted in.
		 *
		 * @param string $name       An IANA timezone name.
		 * @param string $identifier The timezone identifier Veezi reported.
		 */
		$name = (string) apply_filters( 'veezi_cinema_timezone', $name, $identifier );

		return self::zone( $name ) ?? wp_timezone();
	}

	/**
	 * Write down what the last sync worked out.
	 *
	 * @param DateTimeZone $zone The cinema's timezone.
	 */
	public static function remember( DateTimeZone $zone ): void {
		update_option( self::OPTION, $zone->getName() );
	}

	/**
	 * The cinema's timezone as of the last sync.
	 *
	 * Falls back to the site's, which is the same fallback {@see self::resolve()}
	 * makes and wrong in the same way — but a page has to print something, and a
	 * time in the wrong zone is at least a time.
	 */
	public static function stored(): DateTimeZone {
		return self::zone( (string) get_option( self::OPTION, '' ) ) ?? wp_timezone();
	}

	/**
	 * A timezone by name, or null if PHP has never heard of it.
	 *
	 * @param string $name An IANA timezone name, in hope.
	 */
	private static function zone( string $name ): ?DateTimeZone {
		if ( '' === $name ) {
			return null;
		}

		try {
			return new DateTimeZone( $name );
		} catch ( Exception ) {
			return null;
		}
	}
}
