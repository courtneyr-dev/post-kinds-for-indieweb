<?php
/**
 * Play kind: archive groups, facts and labels.
 *
 * A play is a video game or a board game by the provider IDs it stores:
 * a RAWG or Steam ID makes it a video game, a BoardGameGeek ID a board
 * game, and a video game ID wins when a play has both. The archive sorts
 * by that rule in SQL, and play_group() and play_group_of_attrs() give the
 * same answer in PHP for one post or one card.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Cases_Source;
use PKIW\Grouping\Label_Mode;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Id of the group source the play archive sorts by.
 *
 * @since 1.9.0
 */
const PLAY_GROUP_SOURCE = 'play_type';

/**
 * Provider ID meta keys by group, in precedence order: video first.
 *
 * @since 1.9.0
 */
const PLAY_GROUP_META = [
	'video' => [ '_pkiw_play_rawg_id', '_pkiw_play_steam_id' ],
	'board' => [ '_pkiw_play_bgg_id' ],
];

/**
 * Play-card provider ID attributes by group, in the same order.
 *
 * @since 1.9.0
 */
const PLAY_GROUP_ATTRS = [
	'video' => [
		'rawgId'  => '_pkiw_play_rawg_id',
		'steamId' => '_pkiw_play_steam_id',
	],
	'board' => [
		'bggId' => '_pkiw_play_bgg_id',
	],
];

/**
 * The play-card block name.
 *
 * @since 1.9.0
 */
const PLAY_CARD_BLOCK = 'post-kinds-indieweb/play-card';

/**
 * The group source the play archive sorts by, one instance per request.
 *
 * Plugin labels only: 'Video games' and 'Board games', and no empty label,
 * so the empty group takes the marker's emptyLabel or the engine's
 * 'Other'. A theme names its sections through the marker's groupLabels.
 *
 * @since 1.9.0
 *
 * @return Cases_Source
 */
function play_group_source(): Cases_Source {
	static $source = null;

	if ( null === $source ) {
		$source = new Cases_Source(
			PLAY_GROUP_SOURCE,
			PLAY_GROUP_META,
			array_keys( PLAY_GROUP_META ),
			Label_Mode::map( static fn(): array => play_group_labels() ),
			''
		);
	}

	return $source;
}

/**
 * Plugin labels for the play groups.
 *
 * @since 1.9.0
 *
 * @return array<string, string> Group key => label.
 */
function play_group_labels(): array {
	return [
		'video' => __( 'Video games', 'post-kinds-for-indieweb-in-block-themes' ),
		'board' => __( 'Board games', 'post-kinds-for-indieweb-in-block-themes' ),
	];
}

/**
 * Register the play kind's archive source and facts reader.
 *
 * Runs when this file loads. Safe to run again: each registry keeps one
 * entry per id.
 *
 * @since 1.9.0
 *
 * @return void
 */
function register_play_kind(): void {
	Grouped_Archive::register_source( play_group_source(), 'play' );
	Kind_Facts::register( 'play', __NAMESPACE__ . '\\play_facts' );
}

/**
 * A play's group: 'video', 'board' or ''.
 *
 * '' unless the post has the play kind, so a watch post that kept a RAWG
 * ID from an earlier kind files nowhere. Otherwise the same rule the
 * archive's SQL sorts by, read from the stored rows: the first group with
 * a row that isn't blank.
 *
 * This reads the play source directly. A site that swaps the play
 * archive's source through `pkiw_archive_group_source` changes the
 * archive's sections, not this answer, so the two can then disagree.
 *
 * @since 1.9.0
 *
 * @param int $post_id Post ID.
 * @return string
 */
function play_group( int $post_id ): string {
	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || ! has_term( 'play', Taxonomy::TAXONOMY, $post ) ) {
		return '';
	}

	return play_group_source()->group_of( $post )->key();
}

/**
 * A play card's group from its attributes: 'video', 'board' or ''.
 *
 * The same video-first rule as play_group(). An attribute names a provider
 * when Card_Meta_Sync would store a non-empty row for it: a string that
 * stays non-empty after the clean, unslash and key sanitizer that write
 * runs. After any save path the card syncs through, this matches
 * play_group() for the post, with one exception: a card that names no
 * provider leaves the IDs the sidebar or Quick Post stored, so the post
 * can have a group while its card has none.
 *
 * @since 1.9.0
 *
 * @param array<string, mixed> $attrs Play-card attributes.
 * @return string
 */
function play_group_of_attrs( array $attrs ): string {
	foreach ( PLAY_GROUP_ATTRS as $group => $providers ) {
		foreach ( $providers as $attr => $meta_key ) {
			$value = $attrs[ $attr ] ?? '';
			if ( ! is_string( $value ) ) {
				continue;
			}
			$stored = sanitize_meta( $meta_key, wp_unslash( sanitize_text_field( sanitize_text_field( $value ) ) ), 'post', 'post' );
			if ( is_scalar( $stored ) && '' !== trim( (string) $stored ) ) {
				return $group;
			}
		}
	}

	return '';
}

/**
 * Labels for each play status the card and the sidebar store.
 *
 * @since 1.9.0
 *
 * @return array<string, string> Status => label.
 */
function play_status_labels(): array {
	return [
		'playing'   => __( 'Playing', 'post-kinds-for-indieweb-in-block-themes' ),
		'completed' => __( 'Completed', 'post-kinds-for-indieweb-in-block-themes' ),
		'abandoned' => __( 'Abandoned', 'post-kinds-for-indieweb-in-block-themes' ),
		'backlog'   => __( 'Backlog', 'post-kinds-for-indieweb-in-block-themes' ),
		'wishlist'  => __( 'Wishlist', 'post-kinds-for-indieweb-in-block-themes' ),
	];
}

/**
 * Hours played as text: '1 hour played', '3.5 hours played', or ''.
 *
 * Decimals stay, up to two places. Gettext has no plural form for a
 * fraction, so a fractional count picks the form for 2, which is the
 * plural in English and the form most languages use for decimals.
 *
 * @since 1.9.0
 *
 * @param float $hours Hours played.
 * @return string Label, or '' for no hours.
 */
function play_hours_label( float $hours ): string {
	if ( $hours <= 0 ) {
		return '';
	}

	$hours = round( $hours, 2 );
	$whole = floor( $hours ) === $hours;
	$text  = $whole
		? number_format_i18n( $hours, 0 )
		: rtrim( rtrim( number_format_i18n( $hours, 2 ), '0' ), '.,' );

	return sprintf(
		/* translators: %s: hours played, a number that may have decimals. */
		_n( '%s hour played', '%s hours played', $whole ? (int) $hours : 2, 'post-kinds-for-indieweb-in-block-themes' ),
		$text
	);
}

/**
 * Link text for a play's game URL, by its host.
 *
 * 'View on BGG', 'View on RAWG' or 'View on Steam' for those sites, and
 * the host itself for any other.
 *
 * @since 1.9.0
 *
 * @param string $url Game URL.
 * @return string Label, or '' when the URL isn't an http(s) URL with a host.
 */
function play_game_url_label( string $url ): string {
	$url = web_url( $url );
	if ( '' === $url ) {
		return '';
	}

	$host = url_host( $url );

	return match ( $host ) {
		'boardgamegeek.com'      => __( 'View on BGG', 'post-kinds-for-indieweb-in-block-themes' ),
		'rawg.io'                => __( 'View on RAWG', 'post-kinds-for-indieweb-in-block-themes' ),
		'store.steampowered.com' => __( 'View on Steam', 'post-kinds-for-indieweb-in-block-themes' ),
		default                  => $host,
	};
}

/**
 * The first play-card block in a post, searched depth first.
 *
 * @since 1.9.0
 *
 * @param int $post_id Post ID.
 * @return array<string, mixed>|null The card's attributes, or null when the post has none.
 */
function play_card_attrs( int $post_id ): ?array {
	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || ! has_block( PLAY_CARD_BLOCK, $post ) ) {
		return null;
	}

	$find = static function ( array $blocks ) use ( &$find ): ?array {
		foreach ( $blocks as $block ) {
			if ( PLAY_CARD_BLOCK === ( $block['blockName'] ?? '' ) ) {
				return is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			}
			$found = $find( (array) ( $block['innerBlocks'] ?? [] ) );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	};

	return $find( parse_blocks( $post->post_content ) );
}

/**
 * The facts a theme prints for a play.
 *
 * Read from the meta Card_Meta_Sync mirrors from the card, never from
 * block attributes, so a stale card copy can't leak (X16). Kind_Facts
 * gives [] for a post the requester can't read or one behind a password.
 *
 * Two facts are the X16 exception: `played_at` and `cover_alt` come from
 * the post's first play card, because they're the only play-card fields
 * Card_Meta_Sync doesn't mirror to meta. A play with no card has neither.
 *
 * - `status_label`: the play_status_labels() label, or the stored status
 *   when it has none.
 * - `hours_label`: play_hours_label(), the text a theme prints for hours.
 * - `rating`: the stored rating, at most 5, as the card's stars show it.
 * - `game_url_label`: play_game_url_label().
 * - `group`: play_group().
 * - `played_at`: card_calendar_date() of the card's playedAt, a machine
 *   date and a display date, or two empty strings.
 *
 * @since 1.9.0
 *
 * @param int $post_id Post ID.
 * @return array{title: string, platform: string, status: string, status_label: string, hours: float, hours_label: string, rating: float, rating_label: string, review: string, game_url: string, game_url_label: string, official_url: string, purchase_url: string, bgg_id: string, rawg_id: string, steam_id: string, group: string, played_at: array{0: string, 1: string}, cover_alt: string}
 */
function play_facts( int $post_id ): array {
	$meta = static fn( string $suffix ): string => trim( (string) get_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, true ) );

	$status   = $meta( 'play_status' );
	$hours    = (float) get_post_meta( $post_id, Meta_Fields::PREFIX . 'play_hours', true );
	$rating   = card_star_counts( (float) get_post_meta( $post_id, Meta_Fields::PREFIX . 'play_rating', true ) )['value'];
	$game_url = web_url( $meta( 'play_game_url' ) );
	$card     = play_card_attrs( $post_id ) ?? [];

	return [
		'title'          => $meta( 'play_title' ),
		'platform'       => $meta( 'play_platform' ),
		'status'         => $status,
		'status_label'   => play_status_labels()[ $status ] ?? $status,
		'hours'          => $hours,
		'hours_label'    => play_hours_label( $hours ),
		'rating'         => $rating,
		'rating_label'   => card_rating_label( $rating ),
		'review'         => (string) get_post_meta( $post_id, Meta_Fields::PREFIX . 'play_review', true ),
		'game_url'       => $game_url,
		'game_url_label' => play_game_url_label( $game_url ),
		'official_url'   => web_url( $meta( 'play_official_url' ) ),
		'purchase_url'   => web_url( $meta( 'play_purchase_url' ) ),
		'bgg_id'         => $meta( 'play_bgg_id' ),
		'rawg_id'        => $meta( 'play_rawg_id' ),
		'steam_id'       => $meta( 'play_steam_id' ),
		'group'          => play_group( $post_id ),
		'played_at'      => card_calendar_date( is_string( $card['playedAt'] ?? null ) ? $card['playedAt'] : '' ),
		'cover_alt'      => is_string( $card['coverAlt'] ?? null ) ? trim( $card['coverAlt'] ) : '',
	];
}

register_play_kind();
