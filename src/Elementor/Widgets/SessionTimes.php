<?php
/**
 * The list of times inside a film card.
 *
 * @package Veezi\WordPress
 */

declare( strict_types = 1 );

namespace Veezi\WordPress\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Plugin as Elementor;
use Elementor\Widget_Base;
use Veezi\WordPress\ContentModel;
use Veezi\WordPress\Presentation\Screening;
use Veezi\WordPress\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Every time one film screens, each linking to the seats for that screening.
 *
 * The only widget the plugin owns, and it is here because the card cannot be
 * built without it rather than because it is nicer than the alternative. A film
 * card listing the six times that film screens this week is a list inside a
 * list: the builder's loop widget cannot nest, and a dynamic tag can offer only
 * one value, so no arrangement of the tools a designer already has produces it.
 *
 * Which film it is showing comes from the record being rendered, so it works
 * inside a loop item with nothing named and nothing configured — and a
 * duplicated template behaves exactly like the one it was copied from.
 */
final class SessionTimes extends Widget_Base {

	public const STYLE = 'veezi-session-times';

	public function get_name() {
		return 'veezi-session-times';
	}

	public function get_title() {
		return esc_html__( 'Session Times', 'veezi-wordpress-plugin' );
	}

	public function get_icon() {
		return 'eicon-clock-o';
	}

	/**
	 * @return array<int,string>
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * What somebody types into the widget search having forgotten what this is
	 * called. "Showtimes" above all: it is the word half the industry uses and
	 * the one word that appears nowhere in the title.
	 *
	 * @return array<int,string>
	 */
	public function get_keywords() {
		return array( 'veezi', 'session', 'showtime', 'times', 'film', 'cinema', 'screening' );
	}

	/**
	 * @return array<int,string>
	 */
	public function get_style_depends() {
		return array( self::STYLE );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'rows',
			array( 'label' => esc_html__( 'Session Times', 'veezi-wordpress-plugin' ) )
		);

		$this->add_control(
			'time_format',
			array(
				'label'       => esc_html__( 'Time format', 'veezi-wordpress-plugin' ),
				'type'        => Controls_Manager::TEXT,

				// The site's own, so a card left alone matches the rest of the
				// site rather than imposing a house style nobody chose.
				'default'     => (string) get_option( 'time_format', 'g:i a' ),
				'description' => esc_html__( 'The same format codes as Settings → General.', 'veezi-wordpress-plugin' ),
				'ai'          => array( 'active' => false ),
			)
		);

		$this->add_control(
			'show_date',
			array(
				'label'       => esc_html__( 'Show the day', 'veezi-wordpress-plugin' ),
				'type'        => Controls_Manager::SWITCHER,
				'default'     => '',
				'description' => esc_html__( 'On for a film running across a fortnight; off for tonight’s times.', 'veezi-wordpress-plugin' ),
			)
		);

		$this->add_control(
			'date_format',
			array(
				'label'     => esc_html__( 'Day format', 'veezi-wordpress-plugin' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => (string) get_option( 'date_format', 'F j, Y' ),
				'condition' => array( 'show_date' => 'yes' ),
				'ai'        => array( 'active' => false ),
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'       => esc_html__( 'Most to show', 'veezi-wordpress-plugin' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 0,
				'description' => esc_html__( 'Zero shows every screening still to come.', 'veezi-wordpress-plugin' ),
			)
		);

		// Labels are escaped and defaults are not: a label is text Elementor
		// prints, a default is a value it stores and this widget escapes again
		// where it puts it. An escaped default would be double-encoded on the
		// page, and saved that way into every template it was placed in.
		$this->add_control(
			'sold_out_text',
			array(
				'label'   => esc_html__( 'Sold out reads', 'veezi-wordpress-plugin' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Sold out', 'veezi-wordpress-plugin' ),
				'ai'      => array( 'active' => false ),
			)
		);

		$this->add_control(
			'few_left_text',
			array(
				'label'   => esc_html__( 'Nearly sold out reads', 'veezi-wordpress-plugin' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Few tickets left', 'veezi-wordpress-plugin' ),
				'ai'      => array( 'active' => false ),
			)
		);

		$this->end_controls_section();

		$this->register_style_controls();
	}

	private function register_style_controls(): void {
		$this->start_controls_section(
			'times_style',
			array(
				'label' => esc_html__( 'Times', 'veezi-wordpress-plugin' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'gap',
			array(
				'label'      => esc_html__( 'Space between', 'veezi-wordpress-plugin' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .veezi-session-times' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'selector' => '{{WRAPPER}} .veezi-session-times__session',
			)
		);

		$this->add_responsive_control(
			'padding',
			array(
				'label'      => esc_html__( 'Padding', 'veezi-wordpress-plugin' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .veezi-session-times__session' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'radius',
			array(
				'label'      => esc_html__( 'Border radius', 'veezi-wordpress-plugin' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .veezi-session-times__session' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->start_controls_tabs( 'times_states' );

		$this->start_controls_tab( 'times_normal', array( 'label' => esc_html__( 'Normal', 'veezi-wordpress-plugin' ) ) );
		$this->add_colour_controls( '' );
		$this->end_controls_tab();

		$this->start_controls_tab( 'times_hover', array( 'label' => esc_html__( 'Hover', 'veezi-wordpress-plugin' ) ) );
		$this->add_colour_controls( ':hover' );
		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();

		$this->start_controls_section(
			'badge_style',
			array(
				'label' => esc_html__( 'Sold-out badge', 'veezi-wordpress-plugin' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'badge_colour',
			array(
				'label'     => esc_html__( 'Colour', 'veezi-wordpress-plugin' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .veezi-session-times__badge' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'badge_typography',
				'selector' => '{{WRAPPER}} .veezi-session-times__badge',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The three colours a row has, in whichever state.
	 *
	 * @param string $state A CSS pseudo-class, or an empty string for the resting state.
	 */
	private function add_colour_controls( string $state ): void {
		$selector = '{{WRAPPER}} .veezi-session-times__session' . $state;
		$suffix   = '' === $state ? '' : '_hover';

		$this->add_control(
			'text_colour' . $suffix,
			array(
				'label'     => esc_html__( 'Text colour', 'veezi-wordpress-plugin' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $selector => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'background_colour' . $suffix,
			array(
				'label'     => esc_html__( 'Background', 'veezi-wordpress-plugin' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $selector => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'border_colour' . $suffix,
			array(
				'label'     => esc_html__( 'Border', 'veezi-wordpress-plugin' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( $selector => 'border-color: {{VALUE}};' ),
			)
		);
	}

	protected function render() {
		$film     = (int) get_the_ID();
		$settings = $this->get_settings_for_display();

		$screenings = ContentModel::FILM === get_post_type( $film )
			? Screening::for_film( $film, max( 0, (int) ( $settings['limit'] ?? 0 ) ) )
			: array();

		if ( array() === $screenings ) {
			$this->explain_the_emptiness( $film );

			return;
		}

		$format = RowFormat::from_settings( $settings );
		$rows   = '';

		foreach ( $screenings as $screening ) {
			$rows .= $this->row( $screening, $format );
		}

		printf( '<ul class="veezi-session-times">%s</ul>', $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built below from escaped parts.
	}

	/**
	 * One screening: when it is, whether there are seats, and where to buy one.
	 *
	 * A screening nobody can book renders the same row without the link rather
	 * than disappearing. Somebody scanning the week needs to see that Saturday
	 * is on and gone, not be left wondering whether they misread the listing —
	 * but a button that lands on "no seats available" is a wasted trip, so there
	 * is no button.
	 *
	 * @param Screening $screening One of this film's remaining screenings.
	 * @param RowFormat $format    How the panel says a row should read.
	 */
	private function row( Screening $screening, RowFormat $format ): string {
		$parts = array();

		if ( $format->names_its_day() ) {
			$parts[] = sprintf(
				'<span class="veezi-session-times__date">%s</span>',
				esc_html( $screening->in_words( $format->day ) )
			);
		}

		$parts[] = sprintf(
			'<span class="veezi-session-times__time">%s</span>',
			esc_html( $screening->in_words( $format->time ) )
		);

		$badge = $this->badge( $screening, $format );

		if ( '' !== $badge ) {
			$parts[] = sprintf( '<span class="veezi-session-times__badge">%s</span>', esc_html( $badge ) );
		}

		$inner = implode( '', $parts );

		if ( ! $screening->is_bookable() ) {
			return sprintf(
				'<li class="veezi-session-times__item"><span class="veezi-session-times__session veezi-session-times__session--closed">%s</span></li>',
				$inner
			);
		}

		// A plain link, never fetched, checked or proxied by the site. Veezi's
		// booking pages sit behind a bot challenge, so anything following one
		// server-side reads the challenge and concludes the wrong thing.
		return sprintf(
			'<li class="veezi-session-times__item"><a class="veezi-session-times__session" href="%s">%s</a></li>',
			esc_url( $screening->booking_url ),
			$inner
		);
	}

	/**
	 * @param Screening $screening One of this film's remaining screenings.
	 * @param RowFormat $format    How the panel says a row should read.
	 */
	private function badge( Screening $screening, RowFormat $format ): string {
		if ( $screening->sold_out ) {
			return $format->sold_out;
		}

		if ( $screening->few_tickets_left ) {
			return $format->few_left;
		}

		return '';
	}

	/**
	 * Say why there is nothing here — but only to whoever is building the page.
	 *
	 * Silent emptiness is the hardest state for a designer to diagnose, because
	 * a misconfigured widget, a missing token and a cinema between seasons all
	 * look identical: a card with a hole in it. A visitor gets nothing, which is
	 * correct; the builder gets the reason.
	 *
	 * @param int $film The record being rendered, which may not be a film at all.
	 */
	private function explain_the_emptiness( int $film ): void {
		if ( ! $this->is_being_designed() ) {
			return;
		}

		printf(
			'<p class="veezi-session-times__notice">%s</p>',
			esc_html( $this->reason_for_emptiness( $film ) )
		);
	}

	/**
	 * Three different problems, and telling them apart is the whole point.
	 *
	 * Counting screenings would not do it: a cinema between seasons has none and
	 * is working perfectly, and being told to go and check its connection would
	 * send somebody looking for a fault that is not there.
	 *
	 * @param int $film The record being rendered, which may not be a film at all.
	 */
	private function reason_for_emptiness( int $film ): string {
		if ( ! Sync::has_ever_completed() ) {
			return __( 'No programme has synced yet. Check the connection under Settings → Veezi.', 'veezi-wordpress-plugin' );
		}

		// Ordinary while designing: a loop item previews against whichever post
		// Elementor picked, which is rarely a film. Naming the remedy saves the
		// half hour otherwise spent looking for the fault in the card.
		if ( ContentModel::FILM !== get_post_type( $film ) ) {
			return __( 'Session Times lists one film’s screenings. Set this template’s preview to a film to see them.', 'veezi-wordpress-plugin' );
		}

		return __( 'This film has no upcoming sessions.', 'veezi-wordpress-plugin' );
	}

	/**
	 * Whether this is the builder rather than the site.
	 *
	 * Both halves are needed: the editor is the outer frame, and the widget
	 * itself renders inside the preview it wraps.
	 */
	private function is_being_designed(): bool {
		return Elementor::$instance->editor->is_edit_mode()
			|| Elementor::$instance->preview->is_preview_mode();
	}
}
