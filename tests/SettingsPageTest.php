<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Veezi\WordPress\Admin\SettingsPage;
use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Plugin;
use Veezi\WordPress\Schedule;
use Veezi\WordPress\Settings;
use Veezi\WordPress\SyncLock;
use Veezi\WordPress\SyncLog;
use Veezi\WordPress\SyncResult;
use Veezi\WordPress\Tests\Support\TestCase;
use WPDieException;

/**
 * The screen an administrator actually uses.
 *
 * The code is public, so the capability check and the nonce cannot be
 * informal: anyone can read exactly which request would reach this handler.
 */
final class SettingsPageTest extends TestCase {

	private SettingsPage $page;

	public function set_up(): void {
		parent::set_up();

		// The admin menu lives in globals that WordPress's own test case does
		// not reset, so without this a page registered by an earlier test is
		// still there when the next one asks whether it was registered.
		$GLOBALS['menu']              = array();
		$GLOBALS['submenu']           = array();
		$GLOBALS['_registered_pages'] = array();
		$GLOBALS['_parent_pages']     = array();

		// Wired as it is in production. The plugin only builds this page for an
		// admin request, which a test is not, so the hooks it hangs on saving
		// have to be registered here or half of what it does is invisible.
		$this->page = new SettingsPage( Plugin::boot() );
		$this->page->register();
	}

	private function render( callable $render ): string {
		ob_start();
		$render();

		return (string) ob_get_clean();
	}

	/**
	 * An administrator with a token saved and a valid nonce in hand — the
	 * ordinary state in which someone presses "Test connection". Spelled out
	 * rather than shared in the two tests where the nonce is the subject.
	 *
	 * @param string $token The token to save.
	 */
	private function arrange_administrator_ready_to_check( string $token = self::TOKEN ): void {
		$this->become_administrator();
		$this->store_token( $token );
		$_REQUEST['_wpnonce'] = wp_create_nonce( SettingsPage::CHECK_ACTION );
	}

	public function test_the_settings_page_is_offered_to_administrators(): void {
		$this->become_administrator();

		$this->page->add_page();

		$this->assertContains(
			SettingsPage::MENU_SLUG,
			wp_list_pluck( $GLOBALS['submenu']['options-general.php'] ?? array(), 2 )
		);
	}

	public function test_the_settings_page_is_not_offered_to_editors(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->page->add_page();

		$this->assertNotContains(
			SettingsPage::MENU_SLUG,
			wp_list_pluck( $GLOBALS['submenu']['options-general.php'] ?? array(), 2 )
		);
	}

	/**
	 * The token is write-only from the browser's point of view. Sending it
	 * back would put a live credential in every page cache, proxy log and
	 * screen-share that ever touched this screen.
	 */
	public function test_the_stored_token_is_never_rendered_back_into_the_field(): void {
		$secret = self::TOKEN;
		$this->store_token( $secret );
		$this->become_administrator();

		$html = $this->render( fn() => $this->page->render_token_field() );

		$this->assertStringNotContainsString( $secret, $html );
		$this->assertStringContainsString( 'type="password"', $html );
	}

	public function test_the_field_shows_enough_of_the_token_to_recognise_it(): void {
		$this->store_token( self::TOKEN );
		$this->become_administrator();

		$html = $this->render( fn() => $this->page->render_token_field() );

		$this->assertStringContainsString( 'WXYZ', $html );
	}

	public function test_the_field_offers_no_way_to_remove_a_token_that_is_not_there(): void {
		$this->become_administrator();

		$html = $this->render( fn() => $this->page->render_token_field() );

		$this->assertStringNotContainsString( 'delete_token', $html );
	}

	public function test_a_stored_token_can_be_removed_from_the_screen(): void {
		$this->store_token( self::TOKEN );
		$this->become_administrator();

		$html = $this->render( fn() => $this->page->render_token_field() );

		$this->assertStringContainsString( 'delete_token', $html );
	}

	/**
	 * Saving goes to WordPress's own options.php, which is what supplies the
	 * nonce and the capability check. These assert the wiring that makes that
	 * true — a form posted anywhere else, or an option group left unregistered,
	 * would save without either.
	 */
	public function test_the_save_form_is_nonce_protected(): void {
		$this->become_administrator();
		$this->page->add_fields();

		$html = $this->render( fn() => $this->page->render() );

		$this->assertStringContainsString( 'action="options.php"', $html );
		// Quote style is WordPress's own and differs between the two fields.
		$this->assertMatchesRegularExpression(
			'/name=.option_page. value=.' . preg_quote( Settings::GROUP, '/' ) . './',
			$html
		);
		$this->assertMatchesRegularExpression( '/name=._wpnonce. value=.[a-f0-9]+./', $html );
	}

	public function test_saving_requires_permission_to_manage_options(): void {
		$this->become_administrator();
		( new Settings() )->register();

		$this->assertSame(
			'manage_options',
			apply_filters( 'option_page_capability_' . Settings::GROUP, 'manage_options' ),
			'Nothing may lower the bar options.php enforces for this group.'
		);
	}

	/**
	 * The starter templates ship as files inside the plugin, which makes them
	 * discoverable only by somebody who thinks to look in a plugin directory —
	 * which is nobody. This screen is the one place an administrator already
	 * knows about, so the way in starts here.
	 */
	public function test_the_screen_offers_the_starter_templates(): void {
		$this->become_administrator();

		$html = $this->render( array( $this->page, 'render' ) );

		$this->assertStringContainsString( SettingsPage::FILM_CARD, $html );
		$this->assertStringContainsString( SettingsPage::COMING_SOON_CARD, $html );
		$this->assertStringContainsString( SettingsPage::SESSION_ROW, $html );
		$this->assertStringContainsString( SettingsPage::FILM_PAGE, $html );
		$this->assertStringContainsString( 'Saved Templates', $html );
	}

	/**
	 * Criterion: the settings screen states plainly that enabling coming-soon
	 * publication publishes programming that may not yet be announced, and that
	 * planned times can still change.
	 *
	 * Both, in words, next to the switch — not in the README, which the person
	 * flicking it will not have read. The words are asserted rather than the
	 * fact that some paragraph exists, because a warning that got shortened
	 * into meaninglessness would still be a paragraph.
	 */
	public function test_the_screen_says_what_publishing_what_is_coming_costs(): void {
		$this->become_administrator();

		$html = $this->render( array( $this->page, 'render_coming_soon_intro' ) );

		$this->assertStringContainsString( 'may not have been announced', $html );
		$this->assertStringContainsString( 'can still move or be dropped', $html );
	}

	/**
	 * Switching it off is a retraction, and a retraction that quietly takes an
	 * hour is not what somebody pressing it has in mind. The screen says how
	 * long, and names the button that makes it immediate.
	 */
	public function test_the_screen_says_when_the_switch_takes_effect(): void {
		$this->become_administrator();

		$this->assertStringContainsString(
			'next sync',
			$this->render( array( $this->page, 'render_coming_soon_intro' ) )
		);
	}

	public function test_the_screen_offers_the_switch_and_the_horizon(): void {
		$this->become_administrator();

		$this->assertStringContainsString(
			'type="checkbox"',
			$this->render( array( $this->page, 'render_coming_soon_field' ) )
		);
		$this->assertStringContainsString(
			'value="14"',
			$this->render( array( $this->page, 'render_coming_soon_days_field' ) )
		);
	}

	/**
	 * A checkbox rendered unticked whatever is stored is the settings bug that
	 * costs an afternoon: it reads as "off", and saving anything else on the
	 * screen then genuinely turns it off.
	 */
	public function test_the_switch_shows_the_position_it_is_actually_in(): void {
		$this->become_administrator();
		( new Settings() )->update( array( Settings::COMING_SOON_FIELD => true ) );

		$this->assertStringContainsString(
			'checked',
			$this->render( array( $this->page, 'render_coming_soon_field' ) )
		);
	}

	public function test_the_screen_itself_is_closed_to_anyone_without_permission(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->expectException( WPDieException::class );

		$this->page->render();
	}

	/**
	 * The ticket's flow is paste, press one button, be told which cinema — so
	 * an administrator should not have to know that testing is a second step.
	 */
	public function test_saving_a_new_token_checks_it_without_being_asked(): void {
		$this->become_administrator();
		$this->veezi->will_return( '/v1/site', $this->site_payload( array( 'Name' => 'Regal Picture Palace' ) ) );

		( new Settings() )->update( array( 'token' => self::TOKEN ) );
		$html = $this->render( fn() => $this->page->render_notice() );

		$this->assertStringContainsString( 'Regal Picture Palace', $html );
	}

	public function test_saving_without_changing_the_token_does_not_call_veezi(): void {
		$this->become_administrator();
		$settings = new Settings();
		$settings->update( array( 'token' => self::TOKEN ) );

		// Consume the check the first save earned.
		$this->veezi->will_return( '/v1/site', $this->site_payload() );
		$this->render( fn() => $this->page->render_notice() );
		$before = count( $this->veezi->requests );

		$settings->update( array( 'token' => self::TOKEN ) );
		$this->render( fn() => $this->page->render_notice() );

		$this->assertSame( $before, count( $this->veezi->requests ) );
	}

	public function test_someone_without_permission_cannot_run_a_connection_check(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( SettingsPage::CHECK_ACTION );

		$this->expectException( WPDieException::class );

		$this->page->run_connection_check();
	}

	public function test_a_logged_out_visitor_cannot_run_a_connection_check(): void {
		wp_set_current_user( 0 );
		$_REQUEST['_wpnonce'] = wp_create_nonce( SettingsPage::CHECK_ACTION );

		$this->expectException( WPDieException::class );

		$this->page->run_connection_check();
	}

	/**
	 * Without this, a page anywhere on the internet could make a logged-in
	 * administrator's browser fire connection checks at Veezi.
	 */
	public function test_a_connection_check_without_a_valid_nonce_is_rejected(): void {
		$this->become_administrator();
		$_REQUEST['_wpnonce'] = 'not-the-nonce';

		$this->expectException( WPDieException::class );

		$this->page->run_connection_check();
	}

	public function test_an_administrator_with_a_valid_nonce_gets_an_answer(): void {
		$this->arrange_administrator_ready_to_check();
		$this->veezi->will_return( '/v1/site', $this->site_payload( array( 'Name' => 'Regal Picture Palace' ) ) );

		$result = $this->page->run_connection_check();

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'Regal Picture Palace', $result->site_name() );
	}

	/**
	 * The check runs on a POST and its answer is shown on the redirect that
	 * follows, so it has to survive the round trip.
	 */
	public function test_the_answer_is_shown_on_the_page_after_the_check(): void {
		$this->arrange_administrator_ready_to_check();
		$this->veezi->will_return( '/v1/site', $this->site_payload( array( 'Name' => 'Regal Picture Palace' ) ) );

		$this->page->run_connection_check();
		$html = $this->render( fn() => $this->page->render_notice() );

		$this->assertStringContainsString( 'Regal Picture Palace', $html );
		$this->assertStringContainsString( 'notice-success', $html );
	}

	public function test_a_failed_check_is_shown_as_an_error(): void {
		$this->arrange_administrator_ready_to_check();
		$this->veezi->will_return( '/v1/site', '', 403 );

		$this->page->run_connection_check();
		$html = $this->render( fn() => $this->page->render_notice() );

		$this->assertStringContainsString( 'notice-error', $html );
	}

	public function test_an_answer_is_shown_once_and_not_again_on_reload(): void {
		$this->arrange_administrator_ready_to_check();
		$this->veezi->will_return( '/v1/site', $this->site_payload() );

		$this->page->run_connection_check();
		$this->render( fn() => $this->page->render_notice() );
		$second = $this->render( fn() => $this->page->render_notice() );

		$this->assertSame( '', trim( $second ) );
	}

	/**
	 * Two administrators can be looking at this screen at once, and a result
	 * belongs to whoever asked for it.
	 */
	public function test_one_administrators_answer_is_not_shown_to_another(): void {
		$this->arrange_administrator_ready_to_check();
		$this->veezi->will_return( '/v1/site', $this->site_payload() );
		$this->page->run_connection_check();

		$this->become_administrator();
		$html = $this->render( fn() => $this->page->render_notice() );

		$this->assertSame( '', trim( $html ) );
	}

	public function test_a_failure_notice_never_contains_the_token(): void {
		$secret = 'LEAKYTOKEN0123456789ABCDEF';
		$this->arrange_administrator_ready_to_check( $secret );
		$this->veezi->will_fail( '/v1/site', "Failed connecting with {$secret}" );

		$this->page->run_connection_check();
		$html = $this->render( fn() => $this->page->render_notice() );

		$this->assertStringNotContainsString( $secret, $html );
	}

	/**
	 * The same, for the button beside it: an administrator with a token saved
	 * and a valid nonce, about to press "Sync now".
	 */
	private function arrange_administrator_ready_to_sync(): void {
		$this->become_administrator();
		$this->store_token( self::TOKEN );

		// Saving a token earns a connection check on the next page load, and
		// its answer is a notice. These tests are about the button underneath,
		// so that page load happens here and is done with.
		$this->veezi->will_return( '/v1/site', $this->site_payload() );
		$this->render( fn() => $this->page->render_notice() );

		$_REQUEST['_wpnonce'] = wp_create_nonce( SettingsPage::SYNC_ACTION );
	}

	public function test_the_screen_says_when_a_site_has_never_synced(): void {
		$this->become_administrator();

		$html = $this->render( array( $this->page, 'render' ) );

		$this->assertStringContainsString( 'never synced', $html );
	}

	public function test_the_screen_says_when_the_programme_last_synced(): void {
		$this->become_administrator();
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		SyncLog::record(
			SyncResult::completed(
				new DateTimeImmutable( '2026-07-28 03:04:00', new DateTimeZone( 'UTC' ) ),
				'Synced 9 films and 32 sessions from the Regal.'
			)
		);

		$html = $this->render( array( $this->page, 'render' ) );

		$this->assertStringContainsString( '2026-07-28 03:04', $html );
		$this->assertStringContainsString( 'Synced 9 films and 32 sessions from the Regal.', $html );
	}

	public function test_the_screen_says_when_the_next_sync_is_due(): void {
		$this->become_administrator();
		Schedule::ensure();

		$this->assertStringContainsString( 'next sync is due', $this->render( array( $this->page, 'render' ) ) );
	}

	public function test_the_screen_offers_a_way_to_sync_on_demand(): void {
		$this->become_administrator();

		$html = $this->render( array( $this->page, 'render' ) );

		$this->assertStringContainsString( 'Sync now', $html );
		$this->assertStringContainsString( SettingsPage::SYNC_ACTION, $html );
		$this->assertMatchesRegularExpression( '/name=._wpnonce. value=.[a-f0-9]+./', $html );
	}

	public function test_someone_without_permission_cannot_sync(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( SettingsPage::SYNC_ACTION );

		$this->expectException( WPDieException::class );

		$this->page->run_sync_now();
	}

	/**
	 * Without this, a page anywhere on the internet could make a logged-in
	 * administrator's browser fire syncs at Veezi.
	 */
	public function test_a_sync_without_a_valid_nonce_is_rejected(): void {
		$this->become_administrator();
		$_REQUEST['_wpnonce'] = 'not-the-nonce';

		$this->expectException( WPDieException::class );

		$this->page->run_sync_now();
	}

	public function test_an_administrator_can_sync_on_demand(): void {
		$this->arrange_administrator_ready_to_sync();
		$this->veezi_is_showing();

		$result = $this->page->run_sync_now();

		$this->assertNotNull( $result );
		$this->assertTrue( $result->is_success() );
		$this->assertCount( 1, $this->records( ContentModel::FILM ) );
	}

	/**
	 * The whole point of the button: a change made at the box office a minute
	 * ago is on the site a minute later. An answer cached from the last run is
	 * exactly the thing being re-asked.
	 */
	public function test_syncing_now_sees_a_change_made_moments_ago(): void {
		$this->arrange_administrator_ready_to_sync();
		$this->veezi_is_showing( 1 );
		$this->sync_at( '2026-08-01 00:00:00' );

		$this->veezi_is_showing( 2 );
		$this->page->run_sync_now();

		$this->assertCount( 2, $this->records( ContentModel::SESSION ) );
	}

	public function test_a_sync_that_worked_says_so_on_the_page_after_it(): void {
		$this->arrange_administrator_ready_to_sync();
		$this->veezi_is_showing();

		$this->page->run_sync_now();
		$html = $this->render( fn() => $this->page->render_notice() );

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'Regal Picture Palace', $html );
	}

	/**
	 * Pressing the button while the hourly run is mid-flight is not an error,
	 * and reporting one would send somebody looking for a problem that is not
	 * there.
	 */
	public function test_an_administrator_is_told_when_a_sync_is_already_going(): void {
		$this->arrange_administrator_ready_to_sync();
		$this->veezi_is_showing();
		SyncLock::acquire();

		$this->assertNull( $this->page->run_sync_now() );

		$html = $this->render( fn() => $this->page->render_notice() );

		$this->assertStringContainsString( 'already running', $html );
		$this->assertStringNotContainsString( 'notice-error', $html );
	}

	/**
	 * A failed sync has already raised its own notice through the sync log, and
	 * two red boxes saying one thing reads as two problems.
	 */
	public function test_a_failed_sync_is_not_reported_twice_on_one_screen(): void {
		$this->arrange_administrator_ready_to_sync();
		$this->veezi->will_fail( '/v1/site' );

		$this->page->run_sync_now();

		$this->assertSame( '', trim( $this->render( fn() => $this->page->render_notice() ) ) );
		$this->assertNotNull( SyncLog::unresolved_failure(), 'The failure still has to be recorded somewhere.' );
	}
}
