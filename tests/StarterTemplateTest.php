<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Elementor\Plugin as Elementor;
use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Tests\Support\TestCase;

use const Veezi\WordPress\PLUGIN_DIR;

/**
 * The film card that ships with the plugin.
 *
 * A starter template is documentation that runs: it shows how the tags and the
 * widget fit together, and a designer restyles it rather than starting from an
 * empty canvas. Which makes every name inside it a reference that can rot —
 * and a tag name that no longer resolves does not raise an error, it renders an
 * empty box. So the file is checked against what the plugin actually registers.
 *
 * The importing itself is Elementor Pro's and cannot run here; what a human
 * checks in the replica is that the import lands and the card looks right.
 */
final class StarterTemplateTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function card(): array {
		$path = PLUGIN_DIR . '/templates/film-card.json';

		$this->assertFileExists( $path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$decoded = json_decode( (string) file_get_contents( $path ), true );

		$this->assertIsArray( $decoded, 'The starter card is not valid JSON.' );

		return $decoded;
	}

	/**
	 * Every element in the card, at whatever depth.
	 *
	 * @param  array<int,mixed> $elements Whatever the template holds.
	 * @return array<int,array<string,mixed>>
	 */
	private function flattened( array $elements ): array {
		$flat = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$flat[] = $element;
			$flat   = array_merge( $flat, $this->flattened( (array) ( $element['elements'] ?? array() ) ) );
		}

		return $flat;
	}

	/**
	 * @return array<int,string> Every dynamic tag name the card binds.
	 */
	private function tags_bound(): array {
		$names = array();

		foreach ( $this->flattened( (array) $this->card()['content'] ) as $element ) {
			foreach ( (array) ( $element['settings']['__dynamic__'] ?? array() ) as $reference ) {
				if ( preg_match( '/name="([^"]+)"/', (string) $reference, $matched ) ) {
					$names[] = $matched[1];
				}
			}
		}

		return $names;
	}

	public function test_the_starter_card_is_a_template_elementor_can_import(): void {
		$card = $this->card();

		$this->assertNotEmpty( $card['title'] );
		$this->assertIsArray( $card['content'] );
		$this->assertNotEmpty( $card['content'] );
		$this->assertArrayHasKey(
			$card['type'],
			Elementor::$instance->documents->get_document_types(),
			'Elementor has no document type by that name, so the import would be refused.'
		);
	}

	/**
	 * The one that matters. A name here is stored text, resolved at render time
	 * against whatever happens to be registered — so a tag renamed in the code
	 * and not in this file empties a widget on every card built from it, and
	 * reports nothing anywhere.
	 */
	public function test_every_veezi_tag_the_card_binds_is_one_the_plugin_registers(): void {
		$registered = array_keys( Elementor::$instance->dynamic_tags->get_tags() );
		$ours       = array_filter(
			$this->tags_bound(),
			static fn ( string $name ): bool => str_starts_with( $name, 'veezi-' )
		);

		$this->assertNotEmpty( $ours, 'The starter card binds none of the plugin’s own tags.' );

		foreach ( $ours as $name ) {
			$this->assertContains( $name, $registered, "The starter card binds {$name}, which nothing registers." );
		}
	}

	/**
	 * The card is the reason the widget exists, so it had better be in it.
	 */
	public function test_the_card_lists_its_sessions_with_the_plugins_widget(): void {
		$widgets = array_column( $this->flattened( (array) $this->card()['content'] ), 'widgetType' );

		$this->assertContains( 'veezi-session-times', $widgets );
	}

	/**
	 * A card asking for the full-size poster would serve a five-megabyte image
	 * nine times over on the listing this template is for. It asks for the size
	 * ticket 04 registered, by the name that registered it.
	 */
	public function test_the_card_asks_for_a_poster_at_card_size(): void {
		$sizes = array();

		foreach ( $this->flattened( (array) $this->card()['content'] ) as $element ) {
			if ( isset( $element['settings']['image_size'] ) ) {
				$sizes[] = (string) $element['settings']['image_size'];
			}
		}

		$this->assertSame( array( ContentModel::POSTER_SIZE ), $sizes );
		$this->assertContains( ContentModel::POSTER_SIZE, get_intermediate_image_sizes() );
	}
}
