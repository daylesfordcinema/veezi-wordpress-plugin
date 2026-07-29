<?php
/**
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Tests;

use Elementor\Plugin as Elementor;
use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Presentation\Credits;
use Veezi\WordPress\Tests\Support\TestCase;

use const Veezi\WordPress\PLUGIN_DIR;

/**
 * The templates that ship with the plugin.
 *
 * A starter template is documentation that runs: it shows how the tags and the
 * widget fit together, and a designer restyles it rather than starting from an
 * empty canvas. Which makes every name inside it a reference that can rot —
 * and a tag name that no longer resolves does not raise an error, it renders an
 * empty box. So the files are checked against what the plugin actually
 * registers.
 *
 * The importing itself is Elementor Pro's and cannot run here; what a human
 * checks in the replica is that the import lands and the page looks right.
 */
final class StarterTemplateTest extends TestCase {

	/**
	 * @return array<string,array<int,string>>
	 */
	public static function templates(): array {
		return array(
			'the film card'        => array( 'film-card.json' ),
			'the coming soon card' => array( 'coming-soon-card.json' ),
			'the film page'        => array( 'film-page.json' ),
			'the session row'      => array( 'session-row.json' ),
		);
	}

	/**
	 * The ones that show a film's artwork. Not the session row, which is one
	 * screening of whatever happens to be on, in a listing where nine posters
	 * would be nine films' worth of scrolling before the first Saturday.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function templates_with_a_poster(): array {
		$templates = self::templates();
		unset( $templates['the session row'] );

		return $templates;
	}

	/**
	 * The ones that list a film's screenings. Not the coming soon card, which
	 * deliberately shows none: those dates are planned rather than on sale, and
	 * printing times a cinema has not committed to invites somebody to turn up
	 * for one of them.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function templates_that_list_screenings(): array {
		$templates = self::templates_with_a_poster();
		unset( $templates['the coming soon card'] );

		return $templates;
	}

	/**
	 * @param  string $file Which template, by its file name.
	 * @return array<string,mixed>
	 */
	private function template( string $file ): array {
		$path = PLUGIN_DIR . '/templates/' . $file;

		$this->assertFileExists( $path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$decoded = json_decode( (string) file_get_contents( $path ), true );

		$this->assertIsArray( $decoded, "{$file} is not valid JSON." );

		return $decoded;
	}

	/**
	 * Every element in the template, at whatever depth.
	 *
	 * @param  string $file Which template, by its file name.
	 * @return array<int,array<string,mixed>>
	 */
	private function elements_of( string $file ): array {
		return $this->flattened( (array) $this->template( $file )['content'] );
	}

	/**
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
	 * Every dynamic tag the template binds: the name, and the settings the
	 * panel stored alongside it.
	 *
	 * @param  string $file Which template, by its file name.
	 * @return array<int,array{name:string,settings:array<string,mixed>}>
	 */
	private function bindings_in( string $file ): array {
		$bindings = array();

		foreach ( $this->elements_of( $file ) as $element ) {
			$bindings = array_merge( $bindings, $this->bindings_under( (array) ( $element['settings'] ?? array() ) ) );
		}

		return $bindings;
	}

	/**
	 * Gathered at whatever depth they sit, because a widget's settings are not
	 * flat: the session row binds its time and its booking link inside a
	 * repeater item, and each item carries a `__dynamic__` of its own. Reading
	 * only the top level would leave the two names that matter most in that
	 * template checked by nothing.
	 *
	 * @param  array<string,mixed> $settings One element's settings, or part of them.
	 * @return array<int,array{name:string,settings:array<string,mixed>}>
	 */
	private function bindings_under( array $settings ): array {
		$bindings = array();

		foreach ( $settings as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			if ( '__dynamic__' !== $key ) {
				$bindings = array_merge( $bindings, $this->bindings_under( $value ) );

				continue;
			}

			foreach ( $value as $reference ) {
				if ( ! preg_match( '/name="([^"]+)" settings="([^"]*)"/', (string) $reference, $matched ) ) {
					continue;
				}

				$decoded = json_decode( urldecode( $matched[2] ), true );

				$bindings[] = array(
					'name'     => $matched[1],
					'settings' => is_array( $decoded ) ? $decoded : array(),
				);
			}
		}

		return $bindings;
	}

	/**
	 * @param        string $file Which template, by its file name.
	 * @dataProvider templates
	 */
	public function test_a_starter_template_is_one_elementor_can_import( string $file ): void {
		$template = $this->template( $file );

		$this->assertNotEmpty( $template['title'] );
		$this->assertIsArray( $template['content'] );
		$this->assertNotEmpty( $template['content'] );
		$this->assertArrayHasKey(
			$template['type'],
			Elementor::$instance->documents->get_document_types(),
			'Elementor has no document type by that name, so the import would be refused.'
		);
	}

	/**
	 * The one that matters. A name here is stored text, resolved at render time
	 * against whatever happens to be registered — so a tag renamed in the code
	 * and not in this file empties a widget on every page built from it, and
	 * reports nothing anywhere.
	 *
	 * Only the plugin's own tags are checked. The templates also bind
	 * Elementor's, which belong to Elementor Pro and are absent from a free
	 * install like this one.
	 *
	 * @param        string $file Which template, by its file name.
	 * @dataProvider templates
	 */
	public function test_every_veezi_tag_a_template_binds_is_one_the_plugin_registers( string $file ): void {
		$registered = array_keys( Elementor::$instance->dynamic_tags->get_tags() );
		$ours       = array_filter(
			array_column( $this->bindings_in( $file ), 'name' ),
			static fn ( string $name ): bool => str_starts_with( $name, 'veezi-' )
		);

		$this->assertNotEmpty( $ours, "{$file} binds none of the plugin’s own tags." );

		foreach ( $ours as $name ) {
			$this->assertContains( $name, $registered, "{$file} binds {$name}, which nothing registers." );
		}
	}

	/**
	 * A tag's own settings rot the same way its name does, and more quietly: a
	 * role spelled one way here and another in the code renders a heading with
	 * nothing under it.
	 *
	 * @param        string $file Which template, by its file name.
	 * @dataProvider templates
	 */
	public function test_every_role_a_template_asks_for_is_one_veezi_uses( string $file ): void {
		$known = array_keys( Credits::roles() );

		foreach ( $this->bindings_in( $file ) as $binding ) {
			if ( 'veezi-cast-and-crew' !== $binding['name'] || ! isset( $binding['settings']['role'] ) ) {
				continue;
			}

			$this->assertContains( (string) $binding['settings']['role'], $known );
		}
	}

	/**
	 * The widget is the reason a listing can be built at all, so it had better
	 * be in both: the times under a card, and the times on the film's own page.
	 *
	 * @param        string $file Which template, by its file name.
	 * @dataProvider templates_that_list_screenings
	 */
	public function test_a_template_lists_its_sessions_with_the_plugins_widget( string $file ): void {
		$this->assertContains( 'veezi-session-times', array_column( $this->elements_of( $file ), 'widgetType' ) );
	}

	/**
	 * All three ask for the size ticket 04 registered, by the name that
	 * registered it — the card because a listing would otherwise serve the
	 * full-resolution original nine times over, and the page because the
	 * alternatives are worse: WordPress's own sizes are a site setting and can
	 * be turned off, and one asked for and not generated resolves to the
	 * original rather than to nothing.
	 *
	 * @param        string $file Which template, by its file name.
	 * @dataProvider templates_with_a_poster
	 */
	public function test_a_template_asks_for_a_poster_at_card_size( string $file ): void {
		$sizes = array();

		foreach ( $this->elements_of( $file ) as $element ) {
			if ( isset( $element['settings']['image_size'] ) ) {
				$sizes[] = (string) $element['settings']['image_size'];
			}
		}

		$this->assertSame( array( ContentModel::POSTER_SIZE ), $sizes );
		$this->assertContains( ContentModel::POSTER_SIZE, get_intermediate_image_sizes() );
	}

	/**
	 * What the coming soon card leaves out, which is the whole reason it is a
	 * second file rather than the film card again.
	 *
	 * No session times, because a planned date can still move and printing one
	 * invites somebody to turn up for it. And no button, because Elementor
	 * renders a button whose link resolves to nothing as a button that goes
	 * nowhere — rare on a Now Showing card, certain on this one.
	 *
	 * Asserted as absence, which is a thing tests are bad at holding on to: it
	 * is one careless copy-paste from the film card away, and it would look
	 * entirely reasonable in a diff.
	 */
	public function test_the_coming_soon_card_offers_no_dates_and_nothing_to_press(): void {
		$widgets = array_column( $this->elements_of( 'coming-soon-card.json' ), 'widgetType' );

		$this->assertNotContains( 'veezi-session-times', $widgets );
		$this->assertNotContains( 'button', $widgets );

		// It still has to say what it is, or the listing is a wall of posters
		// indistinguishable from the one next to it.
		$this->assertContains(
			'veezi-availability',
			array_column( $this->bindings_in( 'coming-soon-card.json' ), 'name' )
		);
	}

	/**
	 * The session row's five, which are the whole of what a row of the
	 * chronological listing shows: the day it belongs under, the time it
	 * starts, what is on, whether there are seats, and where to buy one.
	 *
	 * The two times are the same tag asked for two different shapes — a date
	 * for the heading and a clock time for the row.
	 */
	public function test_the_session_row_shows_a_day_a_time_a_film_and_a_way_in(): void {
		$bindings = $this->bindings_in( 'session-row.json' );
		$formats  = array();

		foreach ( $bindings as $binding ) {
			if ( 'veezi-session-time' === $binding['name'] ) {
				$formats[] = (string) ( $binding['settings']['format'] ?? '' );
			}
		}

		$this->assertSame( array( 'l j F', 'g:i a' ), $formats );
		$this->assertContains( 'veezi-film-title', array_column( $bindings, 'name' ) );
		$this->assertContains( 'veezi-availability', array_column( $bindings, 'name' ) );
		$this->assertContains( 'veezi-booking-url', array_column( $bindings, 'name' ) );
	}

	/**
	 * Criterion: the listing needs no widget of the plugin's.
	 *
	 * The card could not be built without one — a film's several times are a
	 * loop inside a loop. A row is one screening, so every part of it is an
	 * ordinary widget bound to a field, and the plugin's ongoing liability to
	 * Elementor's widget API does not grow by this view existing.
	 */
	public function test_the_session_row_is_built_from_the_builders_own_widgets(): void {
		$ours = array_filter(
			array_column( $this->elements_of( 'session-row.json' ), 'widgetType' ),
			static fn ( string $type ): bool => str_starts_with( $type, 'veezi-' )
		);

		$this->assertSame( array(), $ours );
	}

	/**
	 * Criterion: a sold-out row presents no active booking link.
	 *
	 * The time is the link, and it is an icon list rather than a button because
	 * of what each does with a binding that resolves to nothing. A button
	 * renders anyway, styled and clickable and going nowhere. An icon list
	 * renders the text with no anchor around it at all — which is exactly a
	 * sold-out row: still listed, still legible, nothing to click.
	 */
	public function test_the_session_rows_booking_link_is_the_time_itself(): void {
		$carrying = array();

		foreach ( $this->elements_of( 'session-row.json' ) as $element ) {
			foreach ( (array) ( $element['settings']['icon_list'] ?? array() ) as $item ) {
				$bound = (array) ( $item['__dynamic__'] ?? array() );

				if ( isset( $bound['link'] ) && isset( $bound['text'] ) ) {
					$carrying[] = (string) $element['widgetType'];
				}
			}
		}

		$this->assertSame( array( 'icon-list' ), $carrying );
	}

	/**
	 * The film page's own three: the synopsis, the credits, and a trailer bound
	 * to Elementor's video widget — which is where the watch link Veezi sends
	 * becomes something a browser can play.
	 */
	public function test_the_film_page_shows_a_synopsis_credits_and_a_trailer(): void {
		$elements = $this->elements_of( 'film-page.json' );

		$this->assertContains( 'theme-post-content', array_column( $elements, 'widgetType' ), 'The film page shows no synopsis.' );
		$this->assertContains( 'veezi-cast-and-crew', array_column( $this->bindings_in( 'film-page.json' ), 'name' ), 'The film page credits nobody.' );

		$players = array_filter(
			$elements,
			static fn ( array $element ): bool => isset( $element['settings']['__dynamic__']['youtube_url'] )
		);

		$this->assertSame( array( 'video' ), array_values( array_column( $players, 'widgetType' ) ) );
	}
}
