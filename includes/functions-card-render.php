<?php
/**
 * Card render helpers.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parse a stored card datetime.
 *
 * Naive values are wall-clock times in the site timezone. Values carrying
 * an offset are instants and are converted to the site timezone, unless
 * $keep_offset asks for the stored offset (a trip endpoint's local time).
 *
 * @since 1.9.0
 *
 * @param string $raw         Stored value.
 * @param bool   $keep_offset Whether a stored offset stays instead of the site timezone.
 * @return \DateTimeImmutable|null Parsed datetime, or null for invalid input.
 */
function card_datetime( string $raw, bool $keep_offset = false ): ?\DateTimeImmutable {
	$raw = trim( $raw );
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})[Tt ](\d{2}):(\d{2})(?::(\d{2})(\.\d+)?)?([Zz]|[+-](?:[01]\d|2[0-3]):?[0-5]\d)?$/', $raw, $matches ) ) {
		return null;
	}

	$year   = (int) $matches[1];
	$month  = (int) $matches[2];
	$day    = (int) $matches[3];
	$hour   = (int) $matches[4];
	$minute = (int) $matches[5];
	$second = isset( $matches[6] ) && '' !== $matches[6] ? (int) $matches[6] : 0;

	if ( ! checkdate( $month, $day, $year ) || $hour > 23 || $minute > 59 || $second > 59 ) {
		return null;
	}

	$fraction = $matches[7] ?? '';
	$offset   = $matches[8] ?? '';
	if ( 'z' === strtolower( $offset ) ) {
		$offset = '+00:00';
	} elseif ( '' !== $offset && false === strpos( $offset, ':' ) ) {
		$offset = substr( $offset, 0, 3 ) . ':' . substr( $offset, 3 );
	}

	$normalized = sprintf( '%04d-%02d-%02dT%02d:%02d:%02d%s%s', $year, $month, $day, $hour, $minute, $second, $fraction, $offset );

	try {
		$date = '' === $offset
			? new \DateTimeImmutable( $normalized, wp_timezone() )
			: new \DateTimeImmutable( $normalized );
	} catch ( \Exception $exception ) {
		return null;
	}

	if ( $keep_offset && '' !== $offset ) {
		return $date;
	}

	return $date->setTimezone( wp_timezone() );
}

/**
 * Format a stored card wall-clock value.
 *
 * Datetimes retain the stored local time unless they carry an explicit
 * offset, which converts to the site timezone or, with $keep_offset,
 * prints in that offset. Date-only values retain their calendar day.
 *
 * @since 1.9.0
 *
 * @param string $raw         Stored value.
 * @param string $format      Optional display format.
 * @param bool   $keep_offset Whether a stored offset stays instead of the site timezone.
 * @return array{0: string, 1: string} Machine value and display value, or two empty strings.
 */
function card_wall_clock( string $raw, string $format = '', bool $keep_offset = false ): array {
	$date = card_datetime( $raw, $keep_offset );
	if ( $date instanceof \DateTimeImmutable ) {
		$display_format = '' !== $format
			? $format
			: (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );

		return [
			$date->format( 'c' ),
			(string) wp_date( $display_format, $date->getTimestamp(), $date->getTimezone() ),
		];
	}

	if ( ! preg_match( '/[Tt ]\d{2}:\d{2}/', trim( $raw ) ) ) {
		return card_calendar_date( $raw );
	}

	return [ '', '' ];
}

/**
 * Calculate full, half, and empty star counts for a rating.
 *
 * @since 1.9.0
 *
 * @param float $rating Stored rating.
 * @param int   $best   Best possible rating.
 * @return array{value: float, best: int, full: int, half: bool, empty: int} Normalized rating and star counts.
 */
function card_star_counts( float $rating, int $best = 5 ): array {
	$best  = $best < 1 ? 5 : $best;
	$value = max( 0.0, min( (float) $best, $rating ) );
	$full  = (int) floor( $value );
	$half  = ( $value - $full ) >= 0.5;
	$empty = max( 0, $best - $full - ( $half ? 1 : 0 ) );

	return compact( 'value', 'best', 'full', 'half', 'empty' );
}

/**
 * Build the accessible label for a card rating.
 *
 * @since 1.9.0
 *
 * @param float $rating Stored rating.
 * @param int   $best   Best possible rating.
 * @return string Rating label, or an empty string for no rating.
 */
function card_rating_label( float $rating, int $best = 5 ): string {
	$counts = card_star_counts( $rating, $best );
	if ( $counts['value'] <= 0 ) {
		return '';
	}

	if ( floor( $counts['value'] ) === $counts['value'] ) {
		$number = number_format_i18n( $counts['value'], 0 );
	} else {
		$number = rtrim( rtrim( number_format_i18n( $counts['value'], 2 ), '0' ), '.,' );
	}

	return sprintf(
		/* translators: 1: rating, 2: best possible rating */
		__( 'Rated %1$s of %2$s', 'post-kinds-for-indieweb-in-block-themes' ),
		$number,
		number_format_i18n( $counts['best'], 0 )
	);
}

/**
 * Render a card rating as accessible SVG stars and hidden machine data.
 *
 * A rating above $best prints $best in the label and in p-rating, so the
 * parsed value matches the stars. The stored value doesn't change.
 *
 * A card that prints the rating as visible text ("Rated 4 of 5") passes
 * $decorative, so the stars are hidden from assistive tech and the rating
 * is announced once. p-rating prints either way.
 *
 * @since 1.9.0
 *
 * @param mixed $rating     Stored rating.
 * @param int   $best       Best possible rating.
 * @param bool  $decorative Whether the stars sit beside visible rating text.
 * @return string Rating HTML, or an empty string for no rating.
 */
function card_rating_html( $rating, int $best = 5, bool $decorative = false ): string {
	$counts = card_star_counts( (float) $rating, $best );
	if ( $counts['value'] <= 0 ) {
		return '';
	}

	// The row runs right to left on an RTL site, so the filled half faces
	// the full stars on whichever side they sit.
	$clip    = is_rtl() ? 'inset(0 0 0 50%)' : 'inset(0 50% 0 0)';
	$path    = 'M12 2l3 6.5 7 .6-5.3 4.6 1.6 6.8L12 17l-6.9 3.5 1.6-6.8L1.4 9.1l7-.6z';
	$full    = '<svg class="" viewBox="0 0 24 24" fill="currentColor" focusable="false"><path d="' . $path . '"/></svg>';
	$empty   = '<svg class="off" viewBox="0 0 24 24" fill="currentColor" focusable="false"><path d="' . $path . '"/></svg>';
	$half    = '<svg class="half" viewBox="0 0 24 24" fill="currentColor" focusable="false"><path class="off" d="' . $path . '"/><path d="' . $path . '" style="clip-path:' . $clip . '"/></svg>';
	$machine = rtrim( rtrim( number_format( $counts['value'], 2, '.', '' ), '0' ), '.' );

	$name = $decorative
		? ' aria-hidden="true"'
		: ' role="img" aria-label="' . esc_attr( card_rating_label( $counts['value'], $counts['best'] ) ) . '"';

	return '<div class="pk-stars"' . $name . '>'
		. str_repeat( $full, $counts['full'] )
		. ( $counts['half'] ? $half : '' )
		. str_repeat( $empty, $counts['empty'] )
		. '</div><data class="p-rating" value="' . esc_attr( $machine ) . '" hidden></data>';
}

/**
 * Whether a stored citation name is a URL rather than a name.
 *
 * Micropub stores title = url when a response arrives without a name. A
 * URL isn't a name, and its path or query can carry tokens, so callers
 * fall through to the host instead.
 *
 * @since 1.9.0
 *
 * @param string $name Stored name.
 * @param string $url  The citation URL stored beside it.
 * @return bool True when the name equals the URL or is an absolute http(s) URL.
 */
function card_name_is_url( string $name, string $url = '' ): bool {
	$name = trim( $name );
	if ( '' === $name ) {
		return false;
	}
	if ( trim( $url ) === $name ) {
		return true;
	}
	if ( preg_match( '/\s/', $name ) ) {
		return false;
	}

	$scheme = strtolower( (string) wp_parse_url( $name, PHP_URL_SCHEME ) );

	return in_array( $scheme, [ 'http', 'https' ], true ) && '' !== (string) wp_parse_url( $name, PHP_URL_HOST );
}

/**
 * Seconds in an ISO 8601 duration.
 *
 * Reads days, hours, minutes and seconds (`P1D`, `PT1H2M3S`, `PT29M12S`).
 * Weeks, months, years, fractions and any trailing text read as no duration.
 *
 * @since 1.9.0
 *
 * @param string $duration Duration such as `PT29M12S`.
 * @return int Seconds, or 0 when the text is not a duration.
 */
function card_duration_seconds( string $duration ): int {
	if ( 1 !== preg_match( '/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i', trim( $duration ), $parts ) ) {
		return 0;
	}

	return (int) ( $parts[1] ?? 0 ) * 86400 + (int) ( $parts[2] ?? 0 ) * 3600 + (int) ( $parts[3] ?? 0 ) * 60 + (int) ( $parts[4] ?? 0 );
}

/**
 * Seconds as an ISO 8601 duration: 1752 is `PT29M12S`, 3600 is `PT1H`.
 *
 * Hours stay hours, so a day is `PT24H`.
 *
 * @since 1.9.0
 *
 * @param int $seconds Seconds.
 * @return string Duration, or '' for no time.
 */
function card_duration_iso( int $seconds ): string {
	if ( $seconds <= 0 ) {
		return '';
	}

	$hours   = intdiv( $seconds, 3600 );
	$minutes = intdiv( $seconds % 3600, 60 );
	$rest    = $seconds % 60;

	return 'PT' . ( $hours > 0 ? $hours . 'H' : '' ) . ( $minutes > 0 ? $minutes . 'M' : '' ) . ( $rest > 0 ? $rest . 'S' : '' );
}

/**
 * Seconds as a clock reading: 1752 is `29:12`, 3723 is `1:02:03`.
 *
 * @since 1.9.0
 *
 * @param int $seconds Seconds.
 * @return string Clock form, or '' for no time.
 */
function card_clock_duration( int $seconds ): string {
	if ( $seconds <= 0 ) {
		return '';
	}

	$hours   = intdiv( $seconds, 3600 );
	$minutes = intdiv( $seconds % 3600, 60 );
	$rest    = $seconds % 60;

	return $hours > 0
		? sprintf( '%d:%02d:%02d', $hours, $minutes, $rest )
		: sprintf( '%d:%02d', $minutes, $rest );
}

/**
 * Minutes as an ISO 8601 duration: 45 is `PT45M`, 150 is `PT2H30M`.
 *
 * @since 1.9.0
 *
 * @param int $minutes Minutes.
 * @return string Duration, or '' for no time.
 */
function card_minutes_iso( int $minutes ): string {
	if ( $minutes <= 0 ) {
		return '';
	}

	$hours    = intdiv( $minutes, 60 );
	$rest     = $minutes % 60;
	$duration = 'PT';
	if ( $hours > 0 ) {
		$duration .= $hours . 'H';
	}
	if ( $rest > 0 ) {
		$duration .= $rest . 'M';
	}

	return $duration;
}

/**
 * Minutes in a stored ISO 8601 duration. Seconds are dropped, and a day is
 * 1440 minutes.
 *
 * @since 1.9.0
 *
 * @param string $duration Duration such as `PT1H30M`.
 * @return int Minutes, or 0 when the text is not a duration.
 */
function card_minutes_from_iso( string $duration ): int {
	if ( 1 !== preg_match( '/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:\d+S)?)?$/i', trim( $duration ), $parts ) ) {
		return 0;
	}

	return (int) ( $parts[1] ?? 0 ) * 1440 + (int) ( $parts[2] ?? 0 ) * 60 + (int) ( $parts[3] ?? 0 );
}

/**
 * A time for people: "45 min", "1 hr", "2 hr 30 min".
 *
 * @since 1.9.0
 *
 * @param int $minutes Minutes.
 * @return string Label, or '' for no time.
 */
function card_minutes_label( int $minutes ): string {
	if ( $minutes <= 0 ) {
		return '';
	}

	$hours = intdiv( $minutes, 60 );
	$rest  = $minutes % 60;

	if ( 0 === $hours ) {
		/* translators: %d: number of minutes. */
		return sprintf( __( '%d min', 'post-kinds-for-indieweb-in-block-themes' ), $rest );
	}
	if ( 0 === $rest ) {
		/* translators: %d: number of hours. */
		return sprintf( __( '%d hr', 'post-kinds-for-indieweb-in-block-themes' ), $hours );
	}

	/* translators: 1: number of hours, 2: number of minutes. */
	return sprintf( __( '%1$d hr %2$d min', 'post-kinds-for-indieweb-in-block-themes' ), $hours, $rest );
}

/**
 * The "<Kind>, <date>" name for a title-less post, with its parts.
 *
 * The last step of untitled_name(). A card that prints the date on its
 * own line shows the kind and keeps the date in the link name only.
 *
 * @since 1.9.0
 *
 * @param \WP_Post $post Untitled post.
 * @return array{name: string, kind: string, date: string} Full name, kind label and post date.
 */
function untitled_fallback_name( \WP_Post $post ): array {
	$kind_label = stream_card_kind_label( $post );
	// A kind term created from its slug alone is named "checkin"; print
	// the label the plugin seeds for that term instead.
	if ( 'checkin' === get_post_kind_slug( $post ) && 'checkin' === strtolower( $kind_label ) ) {
		$kind_label = __( 'Check-in', 'post-kinds-for-indieweb-in-block-themes' );
	}
	$date = (string) get_the_date( '', $post );

	return [
		'name' => sprintf(
			/* translators: 1: kind name, 2: post date */
			__( '%1$s, %2$s', 'post-kinds-for-indieweb-in-block-themes' ),
			$kind_label,
			$date
		),
		'kind' => $kind_label,
		'date' => $date,
	];
}

/**
 * A title-less note or question's first 25 words: its name when it has no title.
 *
 * The excerpt when there is one, else the content with blocks and shortcodes
 * removed and tags stripped, with runs of whitespace collapsed. Callers check
 * for a password first: this reads the post's text without asking whether the
 * requester may see it. untitled_name() and the note archive both name a note
 * this way, so the 25-word rule lives here.
 *
 * @since 1.9.0
 *
 * @param \WP_Post $post Post.
 * @return string The thought, cut to 25 words with an ellipsis, or '' when the post has no text.
 */
function untitled_thought( \WP_Post $post ): string {
	$thought = '' !== trim( (string) $post->post_excerpt )
		? (string) $post->post_excerpt
		: excerpt_remove_blocks( strip_shortcodes( (string) $post->post_content ) );
	$thought = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $thought ) ) );

	return '' === $thought ? '' : wp_trim_words( $thought, 25, '…' );
}

// phpcs:disable Generic.Metrics.CyclomaticComplexity.TooHigh -- The order is the public naming contract.
/**
 * Name a title-less post for lists and Stream cards.
 *
 * Citation identity wins for response kinds, followed, when requested,
 * by the weather reading or a note or question's first 25 words, then the
 * kind label and publication date. A post with no kind term has no
 * content name. A citation name that is a URL counts as no name, so its
 * host prints.
 *
 * @since 1.9.0
 *
 * @param \WP_Post $post         Untitled post.
 * @param bool     $from_content Whether kind content may supply the name.
 * @return string Non-empty display name.
 */
function untitled_name( \WP_Post $post, bool $from_content = true ): string {
	$kind     = get_post_kind_slug( $post );
	$fallback = static fn(): string => untitled_fallback_name( $post )['name'];

	if ( post_password_required( $post ) ) {
		return $fallback();
	}

	$cite_kinds = [ 'like', 'reply', 'repost', 'bookmark', 'favorite', 'quote', 'follow' ];
	if ( in_array( $kind, $cite_kinds, true ) ) {
		$block_name = 'post-kinds-indieweb/' . $kind . '-card';
		foreach ( flatten_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
			if ( ( $block['blockName'] ?? '' ) !== $block_name ) {
				continue;
			}

			$card_url = (string) ( $block['attrs']['url'] ?? '' );
			$title    = trim( (string) ( $block['attrs']['title'] ?? '' ) );
			if ( '' !== $title && ! card_name_is_url( $title, $card_url ) ) {
				return $title;
			}

			$host = url_host( $card_url );
			if ( '' !== $host ) {
				return $host;
			}
			break;
		}

		$favorite_url = (string) get_post_meta( $post->ID, Meta_Fields::PREFIX . 'favorite_url', true );
		if ( 'favorite' === $kind ) {
			$favorite_name = trim( (string) get_post_meta( $post->ID, Meta_Fields::PREFIX . 'favorite_name', true ) );
			if ( '' !== $favorite_name && ! card_name_is_url( $favorite_name, $favorite_url ) ) {
				return $favorite_name;
			}
		}

		$target_url = '';
		foreach ( cite_host_keys() as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$target_url = $value;
				break;
			}
		}

		$cite_name = trim( (string) get_post_meta( $post->ID, Meta_Fields::PREFIX . 'cite_name', true ) );
		if ( '' !== $cite_name && ! card_name_is_url( $cite_name, $target_url ) ) {
			return $cite_name;
		}

		$target_host = url_host( $target_url );
		if ( '' !== $target_host ) {
			return $target_host;
		}

		if ( 'favorite' === $kind ) {
			$favorite_host = url_host( $favorite_url );
			if ( '' !== $favorite_host ) {
				return $favorite_host;
			}
		}
	}

	if ( $from_content && 'weather' === $kind ) {
		// render() builds the same "summary, temperature" text as the
		// Stream's p-weather line. format() would mark the weather as
		// printed, which drops Simple Location's own weather line from the
		// post's next the_content pass even when this name is never shown.
		$weather = html_entity_decode(
			wp_strip_all_tags( Integrations\Simple_Location_Weather::render( $post->ID ) ),
			ENT_QUOTES | ENT_HTML5,
			'UTF-8'
		);
		if ( '' !== $weather ) {
			return $weather;
		}
	}

	if ( $from_content && in_array( $kind, [ 'note', 'question' ], true ) ) {
		$thought = untitled_thought( $post );
		if ( '' !== $thought ) {
			return $thought;
		}
	}

	return $fallback();
}
// phpcs:enable Generic.Metrics.CyclomaticComplexity.TooHigh
