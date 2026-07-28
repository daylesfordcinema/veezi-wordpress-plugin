<?php
/**
 * Constant precedence, in its own process.
 *
 * A constant cannot be undefined once set, so asserting both "the constant
 * wins" and "the stored token is used when there is no constant" in one
 * process would make the two tests order-dependent. This file is isolated;
 * TokenTest covers the no-constant case in the ordinary run.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\Admin\SettingsPage;
use Veezi\WordPress\Plugin;
use Veezi\WordPress\Settings;
use Veezi\WordPress\Token;
use Veezi\WordPress\Tests\Support\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TokenConstantTest extends TestCase {

	public function test_a_token_defined_by_constant_beats_the_stored_one(): void {
		$this->store_token( 'STOREDTOKEN0123456789ABCD' );
		define( Token::CONSTANT, 'CONSTANTTOKEN0123456789AB' );

		$token = Token::resolve( new Settings() );

		$this->assertSame( 'CONSTANTTOKEN0123456789AB', $token->value() );
		$this->assertSame( Token::SOURCE_CONSTANT, $token->source() );
	}

	public function test_a_constant_supplies_a_token_when_nothing_is_stored(): void {
		define( Token::CONSTANT, 'CONSTANTTOKEN0123456789AB' );

		$token = Token::resolve( new Settings() );

		$this->assertTrue( $token->is_present() );
		$this->assertSame( Token::SOURCE_CONSTANT, $token->source() );
		$this->assertSame( '', ( new Settings() )->token(), 'The database is left alone.' );
	}

	public function test_an_empty_constant_falls_back_to_the_stored_token(): void {
		$this->store_token( 'STOREDTOKEN0123456789ABCD' );
		define( Token::CONSTANT, '' );

		$token = Token::resolve( new Settings() );

		$this->assertSame( 'STOREDTOKEN0123456789ABCD', $token->value() );
		$this->assertSame( Token::SOURCE_OPTION, $token->source() );
	}

	/**
	 * Lives here rather than with the rest of the settings screen because it
	 * needs the constant defined. An administrator whose saved token is being
	 * quietly overridden would otherwise change it, see no effect, and have
	 * nothing on the screen to explain why.
	 */
	public function test_the_settings_screen_says_when_a_constant_is_overriding_the_saved_token(): void {
		$this->store_token( 'STOREDTOKEN0123456789ABCD' );
		define( Token::CONSTANT, 'CONSTANTTOKEN0123456789AB' );
		$this->become_administrator();

		ob_start();
		( new SettingsPage( Plugin::boot() ) )->render_token_field();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( Token::CONSTANT, $html );
		$this->assertStringNotContainsString( 'CONSTANTTOKEN0123456789AB', $html );
		$this->assertStringNotContainsString( 'STOREDTOKEN0123456789ABCD', $html );
	}
}
