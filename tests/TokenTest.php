<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\Settings;
use Veezi\WordPress\Token;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * Resolving the access token, and keeping it out of everywhere it shouldn't be.
 */
final class TokenTest extends TestCase {

	public function test_no_token_is_configured_on_a_fresh_install(): void {
		$token = Token::resolve( new Settings() );

		$this->assertFalse( $token->is_present() );
		$this->assertSame( '', $token->value() );
		$this->assertSame( Token::SOURCE_NONE, $token->source() );
	}

	public function test_a_stored_token_is_used(): void {
		$this->store_token( 'STOREDTOKEN0123456789ABCD' );

		$token = Token::resolve( new Settings() );

		$this->assertTrue( $token->is_present() );
		$this->assertSame( 'STOREDTOKEN0123456789ABCD', $token->value() );
		$this->assertSame( Token::SOURCE_OPTION, $token->source() );
	}

	public function test_the_masked_form_does_not_reveal_the_token(): void {
		$secret = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$this->store_token( $secret );

		$masked = Token::resolve( new Settings() )->masked();

		$this->assertStringNotContainsString( $secret, $masked );
		$this->assertStringNotContainsString( substr( $secret, 0, 8 ), $masked );
		$this->assertStringEndsWith( 'WXYZ', $masked, 'The last few characters identify which token is stored.' );
	}

	public function test_a_short_token_is_masked_completely(): void {
		$this->store_token( 'SHORT' );

		$masked = Token::resolve( new Settings() )->masked();

		$this->assertStringNotContainsString( 'SHORT', $masked );
		$this->assertStringNotContainsString( 'ORT', $masked );
	}

	public function test_an_absent_token_has_no_masked_form(): void {
		$this->assertSame( '', Token::resolve( new Settings() )->masked() );
	}

	/**
	 * A token that reaches a log file or an error message is a leaked
	 * credential, and string interpolation is how that happens by accident.
	 */
	public function test_a_token_interpolated_into_a_string_yields_only_the_mask(): void {
		$secret = 'LEAKYTOKEN0123456789ABCDEF';
		$this->store_token( $secret );

		$token = Token::resolve( new Settings() );

		$this->assertStringNotContainsString( $secret, "Connecting with {$token}" );
		$this->assertStringNotContainsString( $secret, print_r( $token, true ) );
		$this->assertStringNotContainsString( $secret, (string) wp_json_encode( $token ) );
	}

	public function test_the_token_is_scrubbed_out_of_text_written_elsewhere(): void {
		$secret = 'LEAKYTOKEN0123456789ABCDEF';
		$this->store_token( $secret );

		$scrubbed = Token::resolve( new Settings() )
			->redact( "cURL error 7: failed connecting with {$secret}" );

		$this->assertStringNotContainsString( $secret, $scrubbed );
		$this->assertStringContainsString( 'cURL error 7', $scrubbed, 'The diagnosis is what makes the message worth showing.' );
	}

	public function test_redacting_leaves_text_alone_when_there_is_no_token(): void {
		$this->assertSame(
			'cURL error 7',
			Token::resolve( new Settings() )->redact( 'cURL error 7' )
		);
	}
}
