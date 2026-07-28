<?php
/**
 * The Veezi HTTP client.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the Veezi API through WordPress's own HTTP layer.
 *
 * Going through `wp_remote_get` rather than cURL directly is what gives the
 * test suite a single seam — `pre_http_request` intercepts every call the
 * plugin makes, including the poster downloads that media sideloading will add
 * later — and it inherits the site's proxy and certificate configuration.
 *
 * The integration is strictly read-only. There is no method here that writes.
 */
final class Client {

	public const ERROR_NO_TOKEN          = 'veezi_no_token';
	public const ERROR_REJECTED          = 'veezi_rejected';
	public const ERROR_BLOCKED           = 'veezi_blocked';
	public const ERROR_UNREACHABLE       = 'veezi_unreachable';
	public const ERROR_UNEXPECTED_STATUS = 'veezi_unexpected_status';
	public const ERROR_MALFORMED         = 'veezi_malformed_response';

	/**
	 * Veezi issues an account against one regional endpoint. This is the
	 * Australia/New Zealand one; the filter below is how a cinema elsewhere
	 * points the plugin at theirs.
	 */
	public const DEFAULT_BASE_URL = 'https://api.oz.veezi.com';

	/*
	 * The four endpoints the plugin reads. Note the version mismatch: films are
	 * v4 and everything else is v1. That is Veezi's, not a mistake here.
	 */

	public const SITE = '/v1/site';

	/**
	 * Every scheduled session, including those not yet on sale — and carrying
	 * no booking link, for any of them.
	 */
	public const SESSIONS = '/v1/session';

	/**
	 * The sessions currently sellable online, each with the link to buy. A
	 * strict subset of {@see self::SESSIONS}, and the only source of booking
	 * links there is.
	 */
	public const WEB_SESSIONS = '/v1/websession';

	public const FILMS = '/v4/film';

	/**
	 * The one endpoint whose answer may be written down.
	 *
	 * The film catalogue and nothing else, for two reasons that happen to point
	 * the same way.
	 *
	 * It is the only one worth caching: 153KB and the slowest of the three a
	 * sync reads, and a catalogue of titles, synopses and artwork that barely
	 * changes from one week to the next.
	 *
	 * And it is the only one safe to cache. Both session feeds carry
	 * `SeatsSold`, `SeatsAvailable`, `SeatsHeld` and `PriceCardName`, and
	 * `/v1/session` carries programming the cinema has not announced. This
	 * plugin's guarantee is that those are discarded *as the programme is read*
	 * rather than filtered out later — so there is nothing to leak through a
	 * REST route, an export or a careless template, because they were never
	 * written down. A cache holding the raw feed would write them into
	 * `wp_options` in plaintext and break that guarantee at its root.
	 *
	 * Their freshness is also the freshness that matters: a cached session feed
	 * is exactly how "Sync now" would fail to see the change it was pressed for.
	 */
	private const CACHEABLE = array( self::FILMS );

	/** Case-sensitive, and the only accepted way to present the token. */
	private const TOKEN_HEADER = 'VeeziAccessToken';

	private const TIMEOUT_SECONDS = 15;

	public function __construct( private readonly Token $token ) {}

	public function base_url(): string {
		/**
		 * Filters the Veezi regional API endpoint.
		 *
		 * @param string $base_url Endpoint with no trailing slash.
		 */
		$base_url = (string) apply_filters( 'veezi_api_base_url', self::DEFAULT_BASE_URL );

		return untrailingslashit( $base_url );
	}

	/**
	 * Ask Veezi who we are connected to.
	 *
	 * `/v1/site` is the cheapest endpoint that proves the whole authentication
	 * path, and it answers with something an administrator can recognise. A
	 * bare "connected" would not distinguish the right account from a token
	 * left over from another cinema.
	 */
	public function check_connection(): ConnectionResult {
		// Never from the cache. This exists to ask Veezi *now* — after revoking
		// a token at their end, say — and an answer from four minutes ago would
		// report a credential as working when it has stopped.
		$site = $this->fresh( self::SITE );

		if ( is_wp_error( $site ) ) {
			return ConnectionResult::failure( $site->get_error_code(), $site->get_error_message() );
		}

		$name = isset( $site['Name'] ) ? trim( (string) $site['Name'] ) : '';

		if ( '' === $name ) {
			return ConnectionResult::failure(
				self::ERROR_MALFORMED,
				__( 'Veezi answered, but the reply did not contain the cinema’s details.', 'veezi-wordpress-plugin' )
			);
		}

		return ConnectionResult::success(
			$name,
			isset( $site['TimeZoneIdentifier'] ) ? trim( (string) $site['TimeZoneIdentifier'] ) : '',
			sprintf(
				/* translators: %s: the cinema's name as Veezi reports it. */
				__( 'Connected to %s.', 'veezi-wordpress-plugin' ),
				$name
			)
		);
	}

	/**
	 * Read an endpoint, reusing a recent answer where that is allowed.
	 *
	 * @param  string $path Path below the regional endpoint, e.g. `/v1/site`.
	 * @return array<mixed>|WP_Error Decoded JSON, or why not.
	 */
	public function get( string $path ) {
		if ( ! in_array( $path, self::CACHEABLE, true ) ) {
			return $this->fresh( $path );
		}

		$url    = $this->url_for( $path );
		$cached = ResponseCache::recall( $url, $this->token->value() );

		if ( null !== $cached ) {
			return $cached;
		}

		$response = $this->fresh( $path );

		// Never a failure. Remembering an outage would extend it past the
		// moment Veezi came back.
		if ( ! is_wp_error( $response ) ) {
			ResponseCache::remember( $url, $this->token->value(), $response );
		}

		return $response;
	}

	/**
	 * The same, but always from Veezi.
	 *
	 * @param  string $path Path below the regional endpoint, e.g. `/v1/site`.
	 * @return array<mixed>|WP_Error Decoded JSON, or why not.
	 */
	private function fresh( string $path ) {
		if ( ! $this->token->is_present() ) {
			return new WP_Error(
				self::ERROR_NO_TOKEN,
				__( 'No Veezi access token is configured.', 'veezi-wordpress-plugin' )
			);
		}

		$response = wp_remote_get(
			$this->url_for( $path ),
			array(
				'timeout' => self::TIMEOUT_SECONDS,
				'headers' => array(
					self::TOKEN_HEADER => $this->token->value(),
					'Accept'           => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				self::ERROR_UNREACHABLE,
				sprintf(
					/* translators: %s: the reason the request failed, as reported by the HTTP client. */
					__( 'Could not reach Veezi: %s', 'veezi-wordpress-plugin' ),
					$this->token->redact( $response->get_error_message() )
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $status || 403 === $status ) {
			// Not every 403 is Veezi's. The API sits behind a CDN whose bot and
			// rate-limit checks answer 403 with an HTML challenge page, and
			// reporting that as a bad credential sends an administrator off to
			// replace a token that was never the problem — the exact confusion
			// this check exists to prevent. Veezi's own rejection is a 403 with
			// an empty body; a challenge is unmistakably a web page.
			if ( $this->looks_like_a_web_page( $response ) ) {
				return new WP_Error(
					self::ERROR_BLOCKED,
					__( 'Something between this site and Veezi blocked the request — a security or rate-limit check answered instead of the API. The access token is not the problem. This usually clears on its own; if it does not, the site’s host or network is where to look.', 'veezi-wordpress-plugin' )
				);
			}

			// 401 is folded in with 403 because the distinction an
			// administrator needs is "your credential was refused", not which
			// of the two spellings Veezi used.
			return new WP_Error(
				self::ERROR_REJECTED,
				__( 'Veezi refused the access token. Check that it was copied in full and has not been revoked.', 'veezi-wordpress-plugin' )
			);
		}

		if ( $status < 200 || $status > 299 ) {
			return new WP_Error(
				self::ERROR_UNEXPECTED_STATUS,
				sprintf(
					/* translators: %d: an HTTP status code. */
					__( 'Veezi answered with an unexpected status (%d). This is a problem at their end, not with the token.', 'veezi-wordpress-plugin' ),
					$status
				)
			);
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				self::ERROR_MALFORMED,
				__( 'Veezi answered, but the reply could not be read as JSON.', 'veezi-wordpress-plugin' )
			);
		}

		return $decoded;
	}

	private function url_for( string $path ): string {
		return $this->base_url() . '/' . ltrim( $path, '/' );
	}

	/**
	 * Whether a response is a page meant for a human rather than an API reply.
	 *
	 * @param array<string,mixed> $response As returned by wp_remote_get().
	 */
	private function looks_like_a_web_page( array $response ): bool {
		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );

		if ( str_contains( strtolower( $content_type ), 'text/html' ) ) {
			return true;
		}

		// Some challenge pages send no content type at all, so fall back to
		// what the body obviously is.
		$body = ltrim( (string) wp_remote_retrieve_body( $response ) );

		return str_starts_with( strtolower( $body ), '<!doctype html' )
			|| str_starts_with( strtolower( $body ), '<html' );
	}
}
