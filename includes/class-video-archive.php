<?php
/**
 * The video kind archive facts and single-entry markup.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads safe video facts and marks the primary player on plain single views.
 *
 * @since 1.9.0
 */
final class Video_Archive {

	/**
	 * Kind slug this archive belongs to.
	 *
	 * @var string
	 */
	public const KIND = 'video';

	/**
	 * Attribute placed on the primary player.
	 *
	 * @var string
	 */
	public const MARKER_ATTRIBUTE = 'data-pkiw-primary-media';

	/**
	 * Posts whose primary player has been marked during the current render.
	 *
	 * @var array<int, true>
	 */
	private static array $marked = [];

	/**
	 * Register the facts reader and single-entry filters.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
		add_filter( 'the_content', [ self::class, 'wrap_prose' ], 8 );
		add_filter( 'the_content', [ self::class, 'append_media_property' ], 98 );
		add_filter( 'render_block_core/video', [ self::class, 'mark_primary_media' ], 240, 2 );
		add_filter( 'render_block_core/embed', [ self::class, 'mark_primary_media' ], 240, 2 );
	}

	// The frozen facts contract keeps one ordered read path.
	// phpcs:disable Generic.Metrics.CyclomaticComplexity.TooHigh
	/**
	 * The facts a theme prints for a video post.
	 *
	 * The saved player can identify an attachment, but public media URLs come
	 * back from that attachment. This keeps stale and remote HTML attributes
	 * out of facts and microformats.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Video facts, or [] when the post cannot be shown.
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) ) {
			return [];
		}

		$match         = first_block_named( $post, [ 'core/video', 'core/embed' ] );
		$has_player    = null !== $match;
		$player        = '';
		$provider      = '';
		$embed_url     = '';
		$attachment_id = 0;
		$src           = '';
		$poster_id     = 0;
		$poster_url    = '';
		$tracks        = [];
		$runtime       = null;

		if ( null !== $match ) {
			$block = $match['block'];
			$name  = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];

			if ( 'core/embed' === $name ) {
				$player    = 'embed';
				$provider  = is_string( $attrs['providerNameSlug'] ?? null ) ? $attrs['providerNameSlug'] : '';
				$embed_url = web_url( is_string( $attrs['url'] ?? null ) ? $attrs['url'] : '' );
			} elseif ( 'core/video' === $name ) {
				$player     = 'video';
				$inner_html = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
				$processor  = new \WP_HTML_Tag_Processor( $inner_html );
				$html_src   = '';
				$poster     = '';

				if ( $processor->next_tag( [ 'tag_name' => 'video' ] ) ) {
					$attribute = $processor->get_attribute( 'src' );
					$html_src  = is_string( $attribute ) ? $attribute : '';
					$attribute = $processor->get_attribute( 'poster' );
					$poster    = is_string( $attribute ) ? $attribute : '';
				}

				while ( $processor->next_tag( [ 'tag_name' => 'track' ] ) ) {
					$track_src = $processor->get_attribute( 'src' );
					if ( ! is_string( $track_src ) || ! media_url_is_local( $track_src ) ) {
						continue;
					}

					$kind     = $processor->get_attribute( 'kind' );
					$srclang  = $processor->get_attribute( 'srclang' );
					$label    = $processor->get_attribute( 'label' );
					$tracks[] = [
						'kind'    => is_string( $kind ) ? $kind : '',
						'srclang' => is_string( $srclang ) ? $srclang : '',
						'label'   => is_string( $label ) ? $label : '',
						'src'     => web_url( $track_src ),
					];
				}

				$block_id = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
				if ( $block_id > 0 && wp_attachment_is( 'video', $block_id ) ) {
					$attachment_id = $block_id;
				} elseif ( '' !== $html_src && is_upload_url( $html_src ) ) {
					$candidate = (int) attachment_url_to_postid( $html_src );
					if ( $candidate > 0 && wp_attachment_is( 'video', $candidate ) ) {
						$attachment_id = $candidate;
					}
				}

				if ( $attachment_id > 0 ) {
					$src     = web_url( (string) wp_get_attachment_url( $attachment_id ) );
					$runtime = attachment_duration( $attachment_id );
				}

				if ( '' !== $poster && is_upload_url( $poster ) ) {
					$candidate = (int) attachment_url_to_postid( $poster );
					if ( $candidate > 0 && wp_attachment_is_image( $candidate ) ) {
						$poster_id  = $candidate;
						$poster_url = web_url( (string) wp_get_attachment_url( $candidate ) );
					}
				}
			}
		}

		$picture = kind_picture( $post->ID );
		if ( 'featured' !== ( $picture['source'] ?? '' ) ) {
			$picture = [
				'source'            => '',
				'attachment_id'     => 0,
				'url'               => '',
				'alt'               => '',
				'remote'            => false,
				'suppress_featured' => false,
			];
			if ( $poster_id > 0 ) {
				$picture = [
					'source'            => 'poster',
					'attachment_id'     => $poster_id,
					'url'               => $poster_url,
					'alt'               => attachment_alt( $poster_id ),
					'remote'            => false,
					'suppress_featured' => false,
				];
			}
		}

		$titled                      = '' !== trim( (string) $post->post_title );
		$name                        = $titled ? visitor_post_title( $post ) : untitled_name( $post, false );
		$name_is_title               = $titled && ! post_title_is_hidden( $post );
		[ $date_iso, $date_display ] = card_calendar_date( $post->post_date );

		return [
			'has_player'      => $has_player,
			'player'          => $player,
			'provider'        => $provider,
			'embed_url'       => $embed_url,
			'attachment_id'   => $attachment_id,
			'src'             => $src,
			'is_local'        => '' !== $src,
			'poster_id'       => $poster_id,
			'poster_url'      => $poster_url,
			'tracks'          => $tracks,
			'picture'         => $picture,
			'runtime_seconds' => null === $runtime ? 0 : $runtime['seconds'],
			'runtime_iso'     => null === $runtime ? '' : $runtime['iso'],
			'runtime_clock'   => null === $runtime ? '' : $runtime['clock'],
			'runtime_human'   => null === $runtime ? '' : $runtime['label'],
			'name'            => $name,
			'name_is_title'   => $name_is_title,
			'date_iso'        => $date_iso,
			'date_display'    => $date_display,
			'published_iso'   => (string) get_post_time( 'c', true, $post ),
			'primary_marker'  => self::MARKER_ATTRIBUTE,
		];
	}
	// phpcs:enable Generic.Metrics.CyclomaticComplexity.TooHigh

	/**
	 * Wrap the prose after a top-level primary player in e-content.
	 *
	 * @since 1.9.0
	 *
	 * @param string $content Stored block markup.
	 * @return string Content, wrapped only on a plain visible video single.
	 */
	public static function wrap_prose( string $content ): string {
		unset( self::$marked[ (int) get_the_ID() ] );

		if ( ! self::is_plain_singular() || post_password_required() || ! self::is_video_post() ) {
			return $content;
		}

		$facts = Kind_Facts::get( (int) get_the_ID() );
		// Post Formats for Block Themes wraps the whole content in one e-content div at priority 5.
		$original = $content;
		$content  = self::unwrap_pfbt_content( $content );
		if ( empty( $facts['has_player'] ) || self::has_class_token( $content, 'e-content' ) ) {
			return $original;
		}

		$blocks       = parse_blocks( $content );
		$player_index = null;
		$top_level    = false;
		foreach ( $blocks as $index => $top_block ) {
			foreach ( flatten_blocks( [ $top_block ] ) as $block ) {
				$name = is_array( $block ) && is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
				if ( ! in_array( $name, [ 'core/video', 'core/embed' ], true ) ) {
					continue;
				}

				$player_index = $index;
				$top_level    = ( $top_block['blockName'] ?? null ) === $name;
				break 2;
			}
		}

		if ( null === $player_index || ! $top_level ) {
			return $original;
		}

		$start = $player_index + 1;
		$end   = count( $blocks ) - 1;
		while ( $start <= $end && self::is_blank_freeform( $blocks[ $start ] ) ) {
			++$start;
		}
		while ( $end >= $start && self::is_blank_freeform( $blocks[ $end ] ) ) {
			--$end;
		}

		if ( $start > $end ) {
			return $original;
		}

		$inner_blocks  = array_slice( $blocks, $start, $end - $start + 1 );
		$opening       = '<div class="wp-block-group e-content">';
		$inner_content = [ $opening ];
		$last          = count( $inner_blocks ) - 1;
		foreach ( $inner_blocks as $index => $unused ) {
			$inner_content[] = null;
			if ( $index < $last ) {
				$inner_content[] = "\n\n";
			}
		}
		$inner_content[] = '</div>';
		$group           = [
			'blockName'    => 'core/group',
			'attrs'        => [ 'className' => 'e-content' ],
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $opening . serialize_blocks( $inner_blocks ) . '</div>',
			'innerContent' => $inner_content,
		];
		$new_blocks      = array_merge(
			array_slice( $blocks, 0, $start ),
			[ $group ],
			array_slice( $blocks, $end + 1 )
		);

		return serialize_blocks( $new_blocks );
	}

	/**
	 * Inner content of the single e-content div Post Formats for Block Themes adds.
	 *
	 * Its PFBT_Format_Mf2::wrap_content_mf2 wraps the whole the_content in
	 * <div class="e-content"> at priority 5, so the label and player would sit inside
	 * e-content and mf2 would parse published twice.
	 *
	 * @param string $content Stored block markup.
	 * @return string The inner content, or the original when it is not exactly one such wrapper.
	 */
	private static function unwrap_pfbt_content( string $content ): string {
		if ( ! class_exists( 'PFBT_Format_Mf2' )
			|| 1 !== preg_match( '/^\s*<div class="([^"]*)">(.*)<\/div>\s*$/s', $content, $match )
			|| ! in_array( 'e-content', preg_split( '/\s+/', trim( $match[1] ) ), true )
			|| self::has_class_token( $match[2], 'e-content' )
		) {
			return $content;
		}

		// The first div must close at the very end, not earlier in the content.
		$depth = 0;
		preg_match_all( '/<div\b|<\/div>/i', $match[2], $tags );
		foreach ( $tags[0] as $tag ) {
			$depth += str_starts_with( $tag, '</' ) ? -1 : 1;
			if ( $depth < 0 ) {
				return $content;
			}
		}

		return 0 === $depth ? $match[2] : $content;
	}

	/**
	 * Append the local primary video as an h-entry media property.
	 *
	 * @since 1.9.0
	 *
	 * @param string $content Rendered content.
	 * @return string Content with one hidden u-video value when eligible.
	 */
	public static function append_media_property( string $content ): string {
		if ( ! self::is_plain_singular() || post_password_required() || ! self::is_video_post() || str_contains( $content, 'u-video' ) ) {
			return $content;
		}

		$facts = Kind_Facts::get( (int) get_the_ID() );
		$src   = is_string( $facts['src'] ?? null ) ? $facts['src'] : '';
		if ( '' === $src ) {
			return $content;
		}

		return $content . '<data class="u-video" value="' . esc_url( $src ) . '" hidden></data>';
	}

	/**
	 * Mark the first rendered tag of the post's primary player.
	 *
	 * @since 1.9.0
	 *
	 * @param string               $html  Rendered block HTML.
	 * @param array<string, mixed> $block Parsed block.
	 * @return string Rendered block HTML, marked once when it is the primary player.
	 */
	public static function mark_primary_media( string $html, array $block ): string {
		if ( ! self::is_plain_singular() || post_password_required() || '' === $html || ! self::is_video_post() ) {
			return $html;
		}

		$post_id = (int) get_the_ID();
		$facts   = Kind_Facts::get( $post_id );
		$post    = get_post( $post_id );
		if ( empty( $facts['has_player'] ) || ! $post instanceof \WP_Post || isset( self::$marked[ $post_id ] ) ) {
			return $html;
		}

		$match = first_block_named( $post, [ 'core/video', 'core/embed' ] );
		if ( null === $match ) {
			return $html;
		}

		$primary       = $match['block'];
		$primary_name  = is_string( $primary['blockName'] ?? null ) ? $primary['blockName'] : '';
		$rendered_name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
		$primary_attrs = is_array( $primary['attrs'] ?? null ) ? $primary['attrs'] : [];
		$block_attrs   = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
		// The block contract requires loose equality for parsed attributes.
		if ( $primary_name !== $rendered_name || $primary_attrs != $block_attrs ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
			return $html;
		}

		if ( 'core/video' === $primary_name ) {
			$primary_html = is_string( $primary['innerHTML'] ?? null ) ? $primary['innerHTML'] : '';
			$block_html   = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
			if ( $primary_html !== $block_html ) {
				return $html;
			}
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag() ) {
			return $html;
		}

		$processor->set_attribute( self::MARKER_ATTRIBUTE, '1' );
		self::$marked[ $post_id ] = true;

		return $processor->get_updated_html();
	}

	/**
	 * Whether content HTML contains a whole class token.
	 *
	 * @param string $html  HTML fragment.
	 * @param string $token Class token.
	 * @return bool True when the token is present.
	 */
	private static function has_class_token( string $html, string $token ): bool {
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			if ( $processor->has_class( $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a parsed block is blank freeform markup.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return bool True for a blank freeform block.
	 */
	private static function is_blank_freeform( array $block ): bool {
		$html = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';

		return null === ( $block['blockName'] ?? null ) && '' === trim( $html );
	}

	/**
	 * Whether the loop post's first kind term is video.
	 *
	 * The three filters run on every singular post of every kind, so this runs
	 * before any facts read: another kind's reader never runs for a video check.
	 *
	 * @return bool True for a video post.
	 */
	private static function is_video_post(): bool {
		$post = get_post( (int) get_the_ID() );

		return $post instanceof \WP_Post && self::KIND === Kind_Facts::kind_of( $post );
	}

	/**
	 * Whether the current loop post is the plain queried singular post.
	 *
	 * @return bool True only on a front-end HTML singular request.
	 */
	private static function is_plain_singular(): bool {
		$post_id = (int) get_the_ID();

		return is_singular()
			&& ! is_admin()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! is_feed()
			&& ! is_embed()
			&& $post_id > 0
			&& (int) get_queried_object_id() === $post_id
			&& null === get_query_var( 'atproto', null )
			&& empty( get_query_var( 'markdown_request' ) )
			&& 'markdown' !== get_query_var( 'format' );
	}
}
