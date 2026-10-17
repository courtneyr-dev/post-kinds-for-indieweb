<?php
/**
 * Exercise Card Block - Server-side Render
 *
 * Three shapes, checked in this order: inside a Stream card, on an HTML
 * listing, and everywhere else (the full record).
 *
 * Distance, duration, splits and the personal best print only when the
 * card's own showDetailsPublicly is on AND the post's details row says so
 * (Meta_Fields::health_details_visible() reads the row alone, so the card
 * is checked here too). No text, class or attribute names a field's
 * visibility, and no heart rate, calories, start, end or clock time exists
 * to print. The card's origin is never printed.
 *
 * @package PKIW
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content (empty for dynamic blocks).
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- render.php variables are scoped by WordPress block rendering.

use PKIW\Exercise_Archive;
use PKIW\Kind_Facts;
use PKIW\Meta_Fields;
use function PKIW\card_calendar_date;
use function PKIW\card_clock_duration;
use function PKIW\card_duration_iso;
use function PKIW\get_kind_label;
use function PKIW\is_rendering_stream_card;
use function PKIW\untitled_name;

$pkiw_post_id = ( isset( $block ) && $block instanceof WP_Block && ! empty( $block->context['postId'] ) ) ? (int) $block->context['postId'] : (int) get_the_ID();
$pkiw_post    = $pkiw_post_id > 0 ? get_post( $pkiw_post_id ) : null;

// A protected post prints nothing from its card, whatever asks.
if ( ! $pkiw_post instanceof WP_Post || post_password_required( $pkiw_post ) ) {
	return;
}

$pkiw_card = Exercise_Archive::card_values( (array) $attributes );
$pkiw_html = '';

if ( is_rendering_stream_card() ) {
	// Inside a Stream card: the skeleton every card shares there. The
	// Stream links the title and adds the date under it.
	$pkiw_facts   = Kind_Facts::get( $pkiw_post->ID );
	$pkiw_named   = '' !== trim( wp_strip_all_tags( $pkiw_post->post_title ) );
	$pkiw_title   = $pkiw_named && isset( $pkiw_facts['name'] ) ? (string) $pkiw_facts['name'] : untitled_name( $pkiw_post, false );
	$pkiw_wrapper = get_block_wrapper_attributes( [ 'class' => 'pk-card k-exercise h-entry' ] );

	$pkiw_html  = '<article ' . $pkiw_wrapper . '>';
	$pkiw_html .= '<span class="pk-kindlabel">' . esc_html( get_kind_label( __( 'Exercise', 'post-kinds-for-indieweb-in-block-themes' ), 'exercise', 'exercise-card' ) ) . '</span>';
	$pkiw_html .= '<h2 class="' . ( $pkiw_named ? 'pk-title p-name' : 'pk-title' ) . '">' . esc_html( $pkiw_title ) . '</h2>';
	if ( ! empty( $pkiw_facts['activity'] ) ) {
		$pkiw_html .= '<p class="pkiw-exercise__activity p-exercise">' . esc_html( (string) $pkiw_facts['activity'] ) . '</p>';
	}
	if ( ! empty( $pkiw_facts['distance_label'] ) ) {
		$pkiw_html .= '<p class="pkiw-exercise__distance">' . esc_html( (string) $pkiw_facts['distance_label'] ) . '</p>';
	}
	$pkiw_html .= '</article>';
} elseif ( ( is_archive() || is_search() || is_home() ) && ! is_feed() ) {
	// An HTML listing: the activity only. Core sets is_archive() on every
	// taxonomy, author and date feed too, which stay on the full record.
	$pkiw_facts = Kind_Facts::get( $pkiw_post->ID );
	if ( ! empty( $pkiw_facts['activity'] ) ) {
		$pkiw_class = ! empty( $pkiw_facts['activity_in_content'] ) ? 'pkiw-exercise__activity' : 'pkiw-exercise__activity p-exercise';
		$pkiw_html  = '<div ' . get_block_wrapper_attributes( [ 'class' => 'pkiw-exercise pkiw-exercise--listing' ] ) . '>'
			. '<p class="' . esc_attr( $pkiw_class ) . '">' . esc_html( (string) $pkiw_facts['activity'] ) . '</p></div>';
	}
} else {
	// The full record: a single, a feed, REST content, the Markdown copy, a push.
	$pkiw_open  = $pkiw_card['open'] && Meta_Fields::health_details_visible( $pkiw_post->ID );
	$pkiw_unit  = 'mi' === $pkiw_card['unit'] ? 'mi' : 'km';
	$pkiw_in    = Exercise_Archive::activity_in_content( $pkiw_post );
	$pkiw_date  = card_calendar_date( substr( (string) $pkiw_post->post_date, 0, 10 ) );
	$pkiw_inner = '';

	$pkiw_band = '';
	if ( '' !== $pkiw_card['activity'] ) {
		$pkiw_band .= '<p class="' . ( $pkiw_in ? 'pkiw-exercise__activity' : 'pkiw-exercise__activity p-exercise' ) . '">' . esc_html( $pkiw_card['activity'] ) . '</p>';
	}
	if ( '' !== $pkiw_date[0] ) {
		$pkiw_band .= '<time class="pkiw-exercise__date" datetime="' . esc_attr( $pkiw_date[0] ) . '">' . esc_html( $pkiw_date[1] ) . '</time>';
	}
	if ( '' !== $pkiw_band ) {
		$pkiw_inner .= '<div class="pkiw-exercise__band">' . $pkiw_band . '</div>';
	}

	if ( $pkiw_open ) {
		if ( null !== $pkiw_card['distance'] ) {
			$pkiw_inner .= '<p class="pkiw-exercise__distance">' . esc_html( Exercise_Archive::distance_label( $pkiw_card['distance'], $pkiw_unit ) ) . '</p>';
		}
		if ( $pkiw_card['seconds'] > 0 ) {
			$pkiw_inner .= '<p class="pkiw-exercise__duration"><time datetime="' . esc_attr( card_duration_iso( $pkiw_card['seconds'] ) ) . '">' . esc_html( card_clock_duration( $pkiw_card['seconds'] ) ) . '</time></p>';
		}
		if ( $pkiw_card['best'] ) {
			$pkiw_best = [];
			if ( '' !== $pkiw_card['category'] ) {
				$pkiw_best[] = Exercise_Archive::pb_label( $pkiw_card['category'] );
			}
			if ( $pkiw_card['seconds'] > 0 ) {
				$pkiw_best[] = card_clock_duration( $pkiw_card['seconds'] );
			}
			$pkiw_label  = __( 'Personal best', 'post-kinds-for-indieweb-in-block-themes' );
			$pkiw_inner .= '<p class="pkiw-exercise__best">' . esc_html( [] === $pkiw_best ? $pkiw_label : $pkiw_label . ': ' . implode( ', ', $pkiw_best ) ) . '</p>';
		}
		if ( [] !== $pkiw_card['splits'] ) {
			$pkiw_heading_id = 'pkiw-exercise-splits-' . $pkiw_post->ID;
			$pkiw_rows       = '';
			foreach ( Exercise_Archive::split_rows( $pkiw_card['splits'], $pkiw_unit ) as $pkiw_split ) {
				$pkiw_rows .= '<tr><td>' . esc_html( (string) $pkiw_split['index'] ) . '</td><td><time datetime="' . esc_attr( $pkiw_split['iso'] ) . '">' . esc_html( $pkiw_split['label'] ) . '</time></td></tr>';
			}
			$pkiw_inner .= '<h2 id="' . esc_attr( $pkiw_heading_id ) . '" class="pkiw-exercise__splits-heading">' . esc_html__( 'Splits', 'post-kinds-for-indieweb-in-block-themes' ) . '</h2>'
				. '<table class="pkiw-exercise__splits" aria-labelledby="' . esc_attr( $pkiw_heading_id ) . '"><thead><tr>'
				. '<th scope="col">' . esc_html( $pkiw_unit ) . '</th><th scope="col">' . esc_html__( 'Time', 'post-kinds-for-indieweb-in-block-themes' ) . '</th>'
				. '</tr></thead><tbody>' . $pkiw_rows . '</tbody></table>';
		}
	}

	// A single adds a plain-text place line at the public location tier; the facts cut it.
	if ( is_singular() && ! is_feed() ) {
		$pkiw_facts = Kind_Facts::get( $pkiw_post->ID );
		$pkiw_place = [];
		foreach ( [ 'place_name', 'place_street', 'place_locality', 'place_region', 'place_country' ] as $pkiw_key ) {
			$pkiw_part = (string) ( $pkiw_facts[ $pkiw_key ] ?? '' );
			if ( '' !== $pkiw_part ) {
				$pkiw_place[] = $pkiw_part;
			}
		}
		if ( [] !== $pkiw_place ) {
			$pkiw_inner .= '<p class="pkiw-exercise__place">' . esc_html( implode( ', ', $pkiw_place ) ) . '</p>';
		}
	}

	if ( '' !== $pkiw_inner ) {
		$pkiw_html = '<div ' . get_block_wrapper_attributes( [ 'class' => 'pkiw-exercise' ] ) . '>' . $pkiw_inner . '</div>';
	}
}

echo $pkiw_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped where it is built.
