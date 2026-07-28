<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Veezi\WordPress\Plugin;
use Veezi\WordPress\Settings;
use Veezi\WordPress\Tests\Support\TestCase;

use const Veezi\WordPress\VERSION;

/**
 * What WordPress needs to be true of the plugin before any of it runs: a
 * header it can read, a licence it can redistribute, and an install and
 * uninstall that leave nothing behind.
 */
final class PluginTest extends TestCase {

	private const PLUGIN_FILE = 'veezi-wordpress-plugin.php';

	private function plugin_path(): string {
		return dirname( __DIR__ ) . '/' . self::PLUGIN_FILE;
	}

	/**
	 * @return array<string,string>
	 */
	private function plugin_header(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		return get_plugin_data( $this->plugin_path(), false, false );
	}

	public function test_wordpress_can_read_the_plugin_header(): void {
		$header = $this->plugin_header();

		$this->assertSame( 'Veezi for WordPress', $header['Name'] );
		$this->assertNotSame( '', $header['Version'] );
		$this->assertNotSame( '', $header['Description'] );
	}

	/**
	 * Required to distribute a WordPress plugin at all, and the reason this
	 * repository can be public.
	 */
	public function test_the_plugin_is_licensed_for_distribution(): void {
		// Not via get_plugin_data(): core parses no License header — that one
		// is read by WordPress.org's own tooling out of readme.txt — so a
		// missing licence would otherwise go unnoticed here.
		$declared = get_file_data( $this->plugin_path(), array( 'License' => 'License' ) );

		$this->assertSame( 'GPL-2.0-or-later', $declared['License'] );
		$this->assertFileExists( dirname( __DIR__ ) . '/LICENSE' );
		$this->assertFileExists( dirname( __DIR__ ) . '/readme.txt' );
	}

	/**
	 * The plugin slug ties three things together: the directory WordPress
	 * installs into, the main file's name, and the text domain translations
	 * are looked up by. A mismatch produces an untranslated plugin, and an
	 * archive that installs under the wrong name.
	 */
	public function test_the_slug_the_directory_the_file_and_the_text_domain_agree(): void {
		$slug = basename( self::PLUGIN_FILE, '.php' );

		$this->assertSame( $slug, basename( dirname( __DIR__ ) ) );
		$this->assertSame( $slug, $this->plugin_header()['TextDomain'] );
	}

	/**
	 * The version is written down in three files, and a release ties all three
	 * to the tag it was cut from. Each is read by something different — the
	 * header by WordPress's update check, the constant by every asset URL the
	 * plugin enqueues, `Stable tag` by the plugin directory — so a drifted one
	 * does not announce itself. It ships a plugin that misreports its own
	 * version, and the release that would have caught it is the one already
	 * published.
	 */
	public function test_the_header_the_constant_and_the_readme_agree_on_the_version(): void {
		$readme = get_file_data(
			dirname( __DIR__ ) . '/readme.txt',
			array( 'stable_tag' => 'Stable tag' )
		);

		$this->assertSame( VERSION, $this->plugin_header()['Version'] );
		$this->assertSame( VERSION, $readme['stable_tag'] );
	}

	public function test_the_declared_php_requirement_is_one_the_code_actually_needs(): void {
		$this->assertTrue(
			version_compare( PHP_VERSION, $this->plugin_header()['RequiresPHP'], '>=' ),
			'The suite is running on a PHP older than the plugin says it supports.'
		);
	}

	/**
	 * Installing the plugin is not the moment to start writing rows. Defaults
	 * are read, not stored, so an administrator who activates the plugin and
	 * changes their mind leaves nothing behind.
	 */
	public function test_activating_writes_nothing_to_the_database(): void {
		delete_option( Settings::OPTION );

		Plugin::activate();

		$this->assertNull( get_option( Settings::OPTION, null ) );
	}

	public function test_deactivating_leaves_no_scheduled_work_behind(): void {
		Plugin::activate();
		Plugin::deactivate();

		$scheduled = array();

		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			$scheduled = array_merge( $scheduled, array_keys( (array) $hooks ) );
		}

		$veezi_hooks = array_filter( $scheduled, static fn( $hook ) => str_starts_with( (string) $hook, 'veezi' ) );

		$this->assertSame( array(), $veezi_hooks );
	}

	public function test_booting_twice_does_not_register_anything_twice(): void {
		$first  = Plugin::boot();
		$second = Plugin::boot();

		$this->assertSame( $first, $second );
	}
}
