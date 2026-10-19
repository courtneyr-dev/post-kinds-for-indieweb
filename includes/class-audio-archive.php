<?php
/**
 * Audio archive facts, Stream duration, and primary-player marker.
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
 * Reads the public facts for an audio post and marks its primary player.
 *
 * @since 1.9.0
 */
final class Audio_Archive {

	/**
	 * Kind slug this archive belongs to.
	 *
	 * @var string
	 */
	public const KIND = 'audio';

	/**
	 * Attribute placed on the primary media block.
	 *
	 * @var string
	 */
	public const PRIMARY_MARKER = 'data-pkiw-primary-media';

	/**
	 * Posts whose primary player was marked in the current content pass.
	 *
	 * @var array<int, bool>
	 */
	private static array $marked = [];

	/**
	 * First qualifying player cached by post ID and content hash.
	 *
	 * @var array<int, array{hash: string, block: array<string, mixed>|null}>
	 */
	private static array $first_blocks = [];

	/**
	 * Register the facts reader and rendering filters.
	 *
	 * @return void
	 */
	public function register(): void {
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
		add_filter( 'pkiw_stream_card_before_date', [ self::class, 'stream_duration_line' ], 10, 2 );
		add_filter( 'the_content', [ self::class, 'reset_marker' ], 1 );

		foreach ( [ 'core/audio', 'core/embed', 'core/paragraph' ] as $block_name ) {
			add_filter( 'render_block_' . $block_name, [ self::class, 'mark_primary_media' ], 240, 2 );
		}
	}

	/**
	 * The public facts a theme prints for an audio post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Audio facts, or an empty array when hidden.
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) || self::KIND !== Kind_Facts::kind_of( $post ) ) {
			return [];
		}

		$player         = first_media_block( $post, self::KIND );
		$player_kind    = is_array( $player ) ? (string) $player['type'] : '';
		$attachment_id  = 'attachment' === $player_kind ? (int) $player['attachment_id'] : 0;
		$embed_provider = 'embed' === $player_kind ? (string) $player['provider'] : '';
		$duration       = $attachment_id > 0 ? attachment_duration( $attachment_id ) : null;
		$name_is_title  = '' !== trim( (string) $post->post_title ) && ! post_title_is_hidden( $post );

		return [
			'name'                => $name_is_title ? visitor_post_title( $post ) : untitled_name( $post, false ),
			'name_is_title'       => $name_is_title,
			'kind_label'          => stream_card_kind_label( $post ),
			'published_day'       => (string) get_post_time( 'Y-m-d', false, $post ),
			'published_iso'       => (string) get_post_time( 'c', true, $post ),
			'player_count'        => count_media_blocks( $post, self::KIND ),
			'player_kind'         => $player_kind,
			'player_block'        => is_array( $player ) ? (string) $player['block'] : '',
			'audio_attachment_id' => $attachment_id,
			'embed_provider'      => $embed_provider,
			'duration_seconds'    => is_array( $duration ) ? (int) $duration['seconds'] : null,
			'duration_iso'        => is_array( $duration ) ? (string) $duration['iso'] : '',
			'duration_clock'      => is_array( $duration ) ? (string) $duration['clock'] : '',
			'duration_label'      => is_array( $duration ) ? (string) $duration['label'] : '',
			'has_transcript'      => Audio_Transcript::has_transcript( $post ),
			'featured_in_content' => featured_in_content( $post->ID ),
			'primary_marker'      => self::PRIMARY_MARKER,
		];
	}

	/**
	 * Append an attachment-backed duration before the Stream card date.
	 *
	 * @param string   $html Existing escaped markup.
	 * @param \WP_Post $post Stream post.
	 * @return string Existing markup with an optional duration line.
	 */
	public static function stream_duration_line( string $html, \WP_Post $post ): string {
		if ( self::KIND !== Kind_Facts::kind_of( $post ) || ! Kind_Facts::can_show( $post ) ) {
			return $html;
		}

		$player = first_media_block( $post, self::KIND );
		if ( ! is_array( $player ) || 'attachment' !== $player['type'] ) {
			return $html;
		}

		$duration = attachment_duration( (int) $player['attachment_id'] );
		if ( null === $duration ) {
			return $html;
		}

		return $html . '<p class="pk-sub pk-audio-duration">' . duration_time_html( $duration ) . '</p>';
	}

	/**
	 * Reset the per-pass primary-player marker for the current post.
	 *
	 * @param string $content Filtered post content.
	 * @return string Content unchanged.
	 */
	public static function reset_marker( string $content ): string {
		unset( self::$marked[ (int) get_the_ID() ] );

		return $content;
	}

	/**
	 * Mark the first qualifying player on a plain singular audio view.
	 *
	 * @param string               $html  Rendered block HTML.
	 * @param array<string, mixed> $block Parsed block.
	 * @return string Rendered HTML, with the primary marker when eligible.
	 */
	public static function mark_primary_media( string $html, array $block ): string {
		if ( '' === $html || ! self::is_plain_singular() ) {
			return $html;
		}

		$post = get_post( get_queried_object_id() );
		if (
			! $post instanceof \WP_Post
			|| get_the_ID() !== $post->ID
			|| self::KIND !== Kind_Facts::kind_of( $post )
			|| post_password_required( $post )
			|| ! Kind_Facts::can_show( $post )
			|| isset( self::$marked[ $post->ID ] )
			|| null === media_block_result( $block, self::KIND )
		) {
			return $html;
		}

		$first = self::first_player_block( $post );
		if ( null === $first || ! self::same_block( $block, $first ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag() ) {
			return $html;
		}

		$processor->set_attribute( self::PRIMARY_MARKER, '1' );
		self::$marked[ $post->ID ] = true;

		return $processor->get_updated_html();
	}

	/**
	 * Whether the current request is a plain singular front-end view.
	 *
	 * @return bool True for a plain singular request.
	 */
	private static function is_plain_singular(): bool {
		return is_singular()
			&& ! is_feed()
			&& ! wp_is_rest_endpoint()
			&& null === get_query_var( 'atproto', null )
			&& empty( get_query_var( 'markdown_request' ) )
			&& 'markdown' !== get_query_var( 'format' );
	}

	/**
	 * The first qualifying player block in stored content.
	 *
	 * @param \WP_Post $post Audio post.
	 * @return array<string, mixed>|null First player block, or null.
	 */
	private static function first_player_block( \WP_Post $post ): ?array {
		$hash = sha1( (string) $post->post_content );
		if ( isset( self::$first_blocks[ $post->ID ] ) && $hash === self::$first_blocks[ $post->ID ]['hash'] ) {
			return self::$first_blocks[ $post->ID ]['block'];
		}

		$first = null;
		foreach ( flatten_blocks( parse_blocks( (string) $post->post_content ) ) as $block ) {
			if ( is_array( $block ) && null !== media_block_result( $block, self::KIND ) ) {
				$first = $block;
				break;
			}
		}

		self::$first_blocks[ $post->ID ] = [
			'hash'  => $hash,
			'block' => $first,
		];

		return $first;
	}

	/**
	 * Whether a rendered block is the stored first player block.
	 *
	 * @param array<string, mixed> $block Rendered parsed block.
	 * @param array<string, mixed> $first Stored first player block.
	 * @return bool True when the identifying block fields match.
	 */
	private static function same_block( array $block, array $first ): bool {
		return ( $block['blockName'] ?? null ) === ( $first['blockName'] ?? null )
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- The frozen contract requires loose equality for parsed attributes.
			&& ( $block['attrs'] ?? [] ) == ( $first['attrs'] ?? [] )
			&& ( $block['innerHTML'] ?? null ) === ( $first['innerHTML'] ?? null );
	}
}
