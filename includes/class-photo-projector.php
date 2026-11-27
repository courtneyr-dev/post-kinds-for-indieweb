<?php
/**
 * Photo single projector markup.
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
 * Turns the primary photo gallery, or one loose photo, into projector markup.
 */
final class Photo_Projector {

	/**
	 * Kind slug this projector belongs to.
	 *
	 * @var string
	 */
	public const KIND = 'photo';

	/**
	 * Front-end script handle.
	 *
	 * @var string
	 */
	public const SCRIPT = 'pkiw-photo-projector';

	/**
	 * Front-end style handle.
	 *
	 * @var string
	 */
	public const STYLE = 'pkiw-photo-projector';

	/**
	 * Labelled-by filter name.
	 *
	 * @var string
	 */
	public const LABELLEDBY_FILTER = 'pkiw_photo_projector_labelledby';

	/**
	 * Dynamic Gallery source that lists the post's attached media (WordPress 7.1+).
	 *
	 * @var string
	 */
	public const ATTACHED_MEDIA = 'core/attached-media';

	/**
	 * Per-post rendering plans.
	 *
	 * @var array<int, array{gallery:string,done:bool,image_count:int,has_gallery:bool,single_loose_image:bool,own_image:array{id:int,src:string},images:array<int,array<string,mixed>>,attached:int[]}>
	 */
	private static array $plans = [];

	/**
	 * Posts whose the_content pass is rendering right now.
	 *
	 * @var array<int, bool>
	 */
	private static array $in_content = [];

	/**
	 * How many Query Loop post templates are rendering right now.
	 *
	 * @var int
	 */
	private static int $loop_depth = 0;

	/**
	 * How many Gallery blocks are rendering right now.
	 *
	 * @var int
	 */
	private static int $gallery_depth = 0;

	/**
	 * Last post planned during this request.
	 *
	 * @var int
	 */
	private static int $last_post_id = 0;

	/**
	 * Register block filters and assets.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'the_content', [ $this, 'start_pass' ], 1 );
		add_filter( 'the_content', [ $this, 'end_pass' ], PHP_INT_MAX );
		add_filter( 'pre_render_block', [ $this, 'enter_loop' ], PHP_INT_MAX, 2 );
		add_filter( 'render_block_core/post-template', [ $this, 'leave_loop' ], PHP_INT_MIN );
		add_filter( 'render_block_core/gallery', [ $this, 'leave_gallery' ], PHP_INT_MIN );
		add_filter( 'render_block_core/gallery', [ $this, 'render_gallery' ], 10, 3 );
		add_filter( 'render_block_core/image', [ $this, 'render_image' ], 10, 3 );
		if ( did_action( 'init' ) ) {
			$this->register_assets();
		} else {
			add_action( 'init', [ $this, 'register_assets' ] );
		}
	}

	/**
	 * Register the projector's front-end assets.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register_assets(): void {
		wp_register_script(
			self::SCRIPT,
			\PKIW_URL . 'assets/js/photo-projector.js',
			[ 'wp-i18n' ],
			\PKIW_VERSION,
			[
				'strategy'  => 'defer',
				'in_footer' => true,
			]
		);
		wp_set_script_translations( self::SCRIPT, 'post-kinds-for-indieweb-in-block-themes' );
		wp_register_style(
			self::STYLE,
			\PKIW_URL . 'styles/photo-projector.css',
			[],
			(string) filemtime( \PKIW_PATH . 'styles/photo-projector.css' )
		);
	}

	/**
	 * Clear cached rendering plans.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$plans         = [];
		self::$last_post_id  = 0;
		self::$in_content    = [];
		self::$loop_depth    = 0;
		self::$gallery_depth = 0;
	}

	/**
	 * Start a content render pass by allowing this post's gallery to project again.
	 *
	 * @since 1.9.0
	 *
	 * @param string $content Post content.
	 * @return string Unchanged post content.
	 */
	public function start_pass( $content ): string {
		$post_id = (int) get_the_ID();
		if ( isset( self::$plans[ $post_id ] ) ) {
			self::$plans[ $post_id ]['done'] = false;
		}
		self::$in_content[ $post_id ] = true;

		return $content;
	}

	/**
	 * End a content render pass.
	 *
	 * @since 1.11.0
	 *
	 * @param string $content Rendered content.
	 * @return string Unchanged content.
	 */
	public function end_pass( $content ): string {
		unset( self::$in_content[ (int) get_the_ID() ] );

		return (string) $content;
	}

	/**
	 * Count a Query Loop post template or a Gallery starting to render.
	 *
	 * A projector belongs to the photo's own page, not to a listing, so
	 * nothing inside a post template projects, even the photo's own content.
	 * An image inside a Gallery, including one Core renders for an
	 * attached-media Gallery, is never a standalone photo.
	 *
	 * @since 1.11.0
	 *
	 * @param string|null         $pre_render Short-circuit output, or null.
	 * @param array<string,mixed> $block      Parsed block.
	 * @return string|null Unchanged short-circuit value.
	 */
	public function enter_loop( $pre_render, $block ) {
		if ( null !== $pre_render || ! is_array( $block ) ) {
			return $pre_render;
		}
		$name = $block['blockName'] ?? '';
		if ( 'core/post-template' === $name ) {
			++self::$loop_depth;
		} elseif ( 'core/gallery' === $name ) {
			++self::$gallery_depth;
		}

		return $pre_render;
	}

	/**
	 * Count a Query Loop post template finishing.
	 *
	 * @since 1.11.0
	 *
	 * @param string $html Rendered post template.
	 * @return string Unchanged HTML.
	 */
	public function leave_loop( $html ): string {
		self::$loop_depth = max( 0, self::$loop_depth - 1 );

		return (string) $html;
	}

	/**
	 * Count a Gallery finishing.
	 *
	 * @since 1.11.0
	 *
	 * @param string $html Rendered Gallery.
	 * @return string Unchanged HTML.
	 */
	public function leave_gallery( $html ): string {
		self::$gallery_depth = max( 0, self::$gallery_depth - 1 );

		return (string) $html;
	}

	/**
	 * Render the first qualifying gallery as a projector.
	 *
	 * @since 1.9.0
	 *
	 * @param string              $html     Rendered block HTML.
	 * @param array<string,mixed> $block    Parsed block.
	 * @param mixed               $instance Block instance.
	 * @return string Rendered HTML.
	 */
	public function render_gallery( string $html, array $block, $instance = null ): string {
		if ( $this->is_attached_media_gallery( $block ) && $this->attached_source_is_locked( $instance ) ) {
			return '';
		}
		$attached = $this->is_dynamic_gallery( $block );

		$post = $this->context_post( $instance );
		if ( null === $post ) {
			return $html;
		}

		$plan = $this->plan( $post );
		if ( $plan['done'] || '' === $plan['gallery'] || $plan['gallery'] !== $this->signature( $block ) ) {
			return $html;
		}
		if ( $attached && empty( self::$in_content[ $post->ID ] ) ) {
			return $html;
		}

		$allowed = null;
		if ( $attached ) {
			$allowed = $this->resolved_attachment_ids( $block, $instance );
			if ( null === $allowed ) {
				return $html;
			}
		}

		$projector = $this->gallery_projector( $html, $block, $post, $allowed );
		if ( '' === $projector ) {
			return $html;
		}

		self::$plans[ $post->ID ]['done'] = true;
		wp_enqueue_script( self::SCRIPT );
		wp_enqueue_style( self::STYLE );

		return $projector;
	}

	/**
	 * Render a single loose image as a projector mount.
	 *
	 * @since 1.9.0
	 *
	 * @param string              $html     Rendered block HTML.
	 * @param array<string,mixed> $block    Parsed block.
	 * @param mixed               $instance Block instance.
	 * @return string Rendered HTML.
	 */
	public function render_image( string $html, array $block, $instance = null ): string {
		$post = $this->context_post( $instance );
		if ( null === $post ) {
			return $html;
		}

		if ( self::$gallery_depth > 0 ) {
			return $html;
		}

		$plan = $this->plan( $post );
		if ( 1 !== $plan['image_count'] || $plan['has_gallery'] || ! $plan['single_loose_image'] ) {
			return $html;
		}

		$id = $this->image_attachment_id( $html, $block );
		if ( ! $this->is_own_image( $plan['own_image'], $id, $html ) ) {
			return $html;
		}

		$mount = $this->mount_html( $html, $id, 0, true, false );
		if ( '' === $mount ) {
			return $html;
		}

		wp_enqueue_style( self::STYLE );

		return $mount;
	}

	/**
	 * Whether an attached-media Gallery's own post still needs a password.
	 *
	 * Core's attached-media query lists inherit-status children without
	 * checking the parent's password, so a template Gallery in a public Query
	 * Loop would otherwise show a protected post's images and captions.
	 *
	 * @param mixed $instance Block instance.
	 * @return bool True when the Gallery's post is password protected for this visitor.
	 */
	private function attached_source_is_locked( $instance ): bool {
		$post_id = $instance instanceof \WP_Block ? ( $instance->context['postId'] ?? get_the_ID() ) : get_the_ID();
		$post_id = is_numeric( $post_id ) ? (int) $post_id : 0;
		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );

		return $post instanceof \WP_Post && post_password_required( $post );
	}

	/**
	 * The attachments Core resolved for this attached-media Gallery.
	 *
	 * Asks Core's resolver with the Gallery's own arguments and post context,
	 * so sort order and the 100-image cap match what Core rendered.
	 *
	 * @param array<string,mixed> $block    Parsed Gallery block.
	 * @param mixed               $instance Block instance.
	 * @return int[]|null Attachment IDs, or null when they can't be resolved.
	 */
	private function resolved_attachment_ids( array $block, $instance ): ?array {
		if ( ! $instance instanceof \WP_Block || ! function_exists( 'block_core_gallery_resolve_dynamic_source' ) ) {
			return null;
		}

		return array_map( 'intval', (array) block_core_gallery_resolve_dynamic_source( $block['attrs']['dynamicContent'] ?? null, $instance ) );
	}

	/**
	 * Return the photo single post currently being rendered, if this render may be enhanced.
	 *
	 * @param mixed $instance Block instance.
	 * @return \WP_Post|null Photo post, or null outside the projector context.
	 */
	private function context_post( $instance ): ?\WP_Post {
		if ( ! is_singular() || is_feed() || self::$loop_depth > 0 ) {
			return null;
		}

		$post_id = 0;
		if ( $instance instanceof \WP_Block ) {
			$context_id = $instance->context['postId'] ?? 0;
			$post_id    = is_int( $context_id ) && 0 < $context_id ? $context_id : 0;
		}
		if ( $post_id <= 0 ) {
			$post_id = (int) get_the_ID();
		}
		if ( 0 >= $post_id || (int) get_queried_object_id() !== $post_id ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || self::KIND !== Kind_Facts::kind_of( $post ) || post_password_required( $post ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * The per-post rendering plan.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{gallery:string,done:bool,image_count:int,has_gallery:bool,single_loose_image:bool,own_image:array{id:int,src:string},images:array<int,array<string,mixed>>,attached:int[]}
	 */
	private function plan( \WP_Post $post ): array {
		if ( self::$last_post_id !== $post->ID ) {
			self::$plans        = [];
			self::$last_post_id = $post->ID;
		}

		if ( isset( self::$plans[ $post->ID ] ) ) {
			return self::$plans[ $post->ID ];
		}

		$blocks   = $this->without_loops( parse_blocks( (string) $post->post_content ) );
		$attached = str_contains( (string) $post->post_content, self::ATTACHED_MEDIA ) ? $this->attached_image_ids( $post->ID ) : [];
		$gallery  = $this->first_qualifying_gallery( $blocks, count( $attached ) );
		$images   = collect_image_blocks( $blocks );
		$single   = 1 === count( $images ) && 1 === $this->loose_image_count( $blocks );

		self::$plans[ $post->ID ] = [
			'gallery'            => null === $gallery ? '' : $this->signature( $gallery ),
			'done'               => false,
			'image_count'        => count( $images ),
			'has_gallery'        => null !== $gallery,
			'single_loose_image' => $single,
			'own_image'          => $single ? $this->saved_image_identity( $images[0] ) : [
				'id'  => 0,
				'src' => '',
			],
			'images'             => $images,
			'attached'           => $attached,
		];

		return self::$plans[ $post->ID ];
	}

	/**
	 * Parsed blocks with every Query Loop post template removed.
	 *
	 * Nothing inside a post template projects, so a Gallery or Image there
	 * never belongs to the plan.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array<string,mixed>> Blocks outside post templates.
	 */
	private function without_loops( array $blocks ): array {
		$kept = [];
		foreach ( $blocks as $block ) {
			if ( 'core/post-template' === ( $block['blockName'] ?? '' ) ) {
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->without_loops( $block['innerBlocks'] );
			}
			$kept[] = $block;
		}

		return $kept;
	}

	/**
	 * First core/gallery block that shows two or more images.
	 *
	 * A static Gallery counts its direct image children; a dynamic
	 * attached-media Gallery counts the images attached to the post.
	 *
	 * @param array<int,array<string,mixed>> $blocks         Parsed blocks.
	 * @param int                            $attached_count Images attached to the post.
	 * @return array<string,mixed>|null Gallery block, or null.
	 */
	private function first_qualifying_gallery( array $blocks, int $attached_count ): ?array {
		foreach ( $blocks as $block ) {
			if ( 'core/gallery' === ( $block['blockName'] ?? '' ) ) {
				$count = $this->is_dynamic_gallery( $block ) ? $attached_count : $this->direct_image_count( $block );
				if ( $count >= 2 ) {
					return $block;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = $this->first_qualifying_gallery( $block['innerBlocks'], $attached_count );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Attachment ID and source of an image block as saved in the post content.
	 *
	 * @param array<string,mixed> $block Parsed image block.
	 * @return array{id:int,src:string} Identity; id is 0 for a remote image.
	 */
	private function saved_image_identity( array $block ): array {
		$html = (string) ( $block['innerHTML'] ?? '' );

		return [
			'id'  => $this->image_attachment_id( $html, $block ),
			'src' => extract_first_img_src( $html ),
		];
	}

	/**
	 * Whether a rendered image block is the post's own image.
	 *
	 * A block rendered from a pattern, a reusable block or a template part isn't in the saved
	 * content, so it matches neither the saved attachment nor the saved source.
	 *
	 * @param array{id:int,src:string} $own     Identity of the post's own image.
	 * @param int                      $id      Attachment ID of the rendered block, or 0.
	 * @param string                   $html    Rendered image HTML.
	 * @return bool True when the rendered block shows the post's own image.
	 */
	private function is_own_image( array $own, int $id, string $html ): bool {
		if ( $own['id'] > 0 && $id === $own['id'] ) {
			return true;
		}

		return '' !== $own['src'] && extract_first_img_src( $html ) === $own['src'];
	}

	/**
	 * Count core/image blocks that are not inside a gallery block.
	 *
	 * @param array<int,array<string,mixed>> $blocks         Parsed blocks.
	 * @param bool                           $inside_gallery Whether the current branch is inside a gallery.
	 * @return int Loose image count.
	 */
	private function loose_image_count( array $blocks, bool $inside_gallery = false ): int {
		$count = 0;
		foreach ( $blocks as $block ) {
			$is_gallery = 'core/gallery' === ( $block['blockName'] ?? '' );
			if ( ! $inside_gallery && 'core/image' === ( $block['blockName'] ?? '' ) ) {
				++$count;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$count += $this->loose_image_count( $block['innerBlocks'], $inside_gallery || $is_gallery );
			}
		}

		return $count;
	}

	/**
	 * Count direct core/image children in a parsed gallery block.
	 *
	 * @param array<string,mixed> $block Parsed block.
	 * @return int Direct image child count.
	 */
	private function direct_image_count( array $block ): int {
		$count = 0;
		foreach ( (array) ( $block['innerBlocks'] ?? [] ) as $inner ) {
			if ( is_array( $inner ) && 'core/image' === ( $inner['blockName'] ?? '' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Whether a parsed Gallery takes its images from the post's attached media.
	 *
	 * @param array<string,mixed> $block Parsed block.
	 * @return bool
	 */
	private function is_attached_media_gallery( array $block ): bool {
		$source = $block['attrs']['dynamicContent'] ?? null;

		return is_array( $source ) && self::ATTACHED_MEDIA === ( $source['source'] ?? null );
	}

	/**
	 * Whether Core renders this Gallery from the post's attached media.
	 *
	 * Without Core's resolver (WordPress 7.0) an attached-media Gallery renders
	 * its saved image children, so it is planned and signed as a static Gallery.
	 *
	 * @param array<string,mixed> $block Parsed block.
	 * @return bool
	 */
	private function is_dynamic_gallery( array $block ): bool {
		if ( ! $this->is_attached_media_gallery( $block ) ) {
			return false;
		}

		/**
		 * Filters whether Core resolves attached-media Galleries.
		 *
		 * @since 1.11.0
		 *
		 * @param bool $resolves True when block_core_gallery_resolve_dynamic_source() exists.
		 */
		return (bool) apply_filters( 'pkiw_photo_projector_core_resolves_attached_media', function_exists( 'block_core_gallery_resolve_dynamic_source' ) );
	}

	/**
	 * Image attachments of a post, the set Core's attached-media Gallery may show.
	 *
	 * Mirrors block_core_gallery_resolve_dynamic_source(): children of the
	 * post with status inherit and an image MIME type, capped at 100. A
	 * private or unrelated attachment is never in it. The plan uses it only to
	 * count; the render allowlist comes from Core's resolver, which applies the
	 * Gallery's sort order before the cap.
	 *
	 * @param int $post_id Post ID.
	 * @return int[] Attachment IDs.
	 */
	private function attached_image_ids( int $post_id ): array {
		$query = new \WP_Query(
			[
				'post_parent'    => $post_id,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Stable signature for a parsed gallery block.
	 *
	 * @param array<string,mixed> $block Parsed gallery block.
	 * @return string Signature.
	 */
	private function signature( array $block ): string {
		if ( $this->is_dynamic_gallery( $block ) ) {
			$args = (array) ( $block['attrs']['dynamicContent']['args'] ?? [] );
			ksort( $args );

			return md5( (string) wp_json_encode( [ (string) $block['blockName'], self::ATTACHED_MEDIA, $args ] ) );
		}

		$signature = [
			'blockName' => (string) ( $block['blockName'] ?? '' ),
			'images'    => [],
		];

		foreach ( (array) ( $block['innerBlocks'] ?? [] ) as $inner ) {
			if ( ! is_array( $inner ) || 'core/image' !== ( $inner['blockName'] ?? '' ) ) {
				continue;
			}

			$signature['images'][] = [
				'id'  => (int) ( $inner['attrs']['id'] ?? 0 ),
				'src' => extract_first_img_src( (string) ( $inner['innerHTML'] ?? '' ) ),
			];
		}

		return md5( (string) wp_json_encode( $signature ) );
	}

	/**
	 * Build projector markup from a rendered core/gallery.
	 *
	 * @param string              $html     Rendered gallery HTML.
	 * @param array<string,mixed> $block    Parsed gallery block.
	 * @param \WP_Post            $post     Post.
	 * @param int[]|null          $attached For an attached-media Gallery, the only attachments it may show.
	 * @return string Projector HTML, or '' when the gallery shape is not usable.
	 */
	private function gallery_projector( string $html, array $block, \WP_Post $post, ?array $attached = null ): string {
		$parts = $this->gallery_parts( $html );
		if ( null === $parts ) {
			return '';
		}

		$figures = $parts['figures'];
		if ( count( $figures ) < 2 ) {
			return '';
		}

		$inner_blocks = array_values(
			array_filter(
				(array) ( $block['innerBlocks'] ?? [] ),
				static fn( $inner ): bool => is_array( $inner ) && 'core/image' === ( $inner['blockName'] ?? '' )
			)
		);
		$mounts       = [];
		foreach ( $figures as $index => $figure ) {
			$inner = is_array( $inner_blocks[ $index ] ?? null ) ? $inner_blocks[ $index ] : [];
			$id    = $this->image_attachment_id( $figure, $inner );
			if ( null !== $attached && ! in_array( $id, $attached, true ) ) {
				return '';
			}

			$mount = $this->mount_html( $figure, $id, $index, 0 === $index, true );
			if ( '' === $mount ) {
				return '';
			}
			$mounts[] = $mount;
		}

		$out  = $this->projector_open_tag( $parts['open'], $post );
		$out .= implode( '', $mounts );
		if ( '' !== $parts['caption'] ) {
			$out .= '<p class="pkiw-projector__note wp-element-caption">' . wp_kses_post( $parts['caption'] ) . '</p>';
		}
		$out .= '</div>';

		return $out;
	}

	/**
	 * Split a rendered gallery into outer tag, direct figures and gallery caption.
	 *
	 * @param string $html Gallery HTML.
	 * @return array{open:string,figures:string[],caption:string}|null Gallery parts, or null.
	 */
	private function gallery_parts( string $html ): ?array {
		if ( ! preg_match( '/^\s*(<figure\b[^>]*>)(.*)<\/figure>\s*$/is', $html, $matches ) ) {
			return null;
		}

		$inner = (string) $matches[2];
		if ( ! preg_match_all( '/<figure\b[^>]*>.*?<\/figure>/is', $inner, $figure_matches ) ) {
			return null;
		}

		$remaining = (string) preg_replace( '/<figure\b[^>]*>.*?<\/figure>/is', '', $inner );
		$caption   = '';
		if ( preg_match( '/<figcaption\b[^>]*>(.*?)<\/figcaption>/is', $remaining, $caption_matches ) ) {
			$caption = trim( (string) $caption_matches[1] );
		}

		return [
			'open'    => (string) $matches[1],
			'figures' => array_map( 'strval', $figure_matches[0] ),
			'caption' => $caption,
		];
	}

	/**
	 * Build the projector wrapper opening tag.
	 *
	 * @param string   $outer_open Original gallery opening tag.
	 * @param \WP_Post $post       Post.
	 * @return string Opening div tag.
	 */
	private function projector_open_tag( string $outer_open, \WP_Post $post ): string {
		$processor = new \WP_HTML_Tag_Processor( $outer_open . '</figure>' );
		$classes   = [ 'pkiw-projector' ];
		$id        = '';
		if ( $processor->next_tag( [ 'tag_name' => 'figure' ] ) ) {
			$id      = trim( (string) ( $processor->get_attribute( 'id' ) ?? '' ) );
			$classes = array_merge( $classes, $this->filtered_gallery_classes( (string) ( $processor->get_attribute( 'class' ) ?? '' ) ) );
		}

		$out = '<div class="' . esc_attr( $this->classes( $classes ) ) . '"';
		if ( '' !== $id ) {
			$out .= ' id="' . esc_attr( $id ) . '"';
		}
		$out .= ' role="region" aria-roledescription="carousel"';

		$labelledby = trim( (string) apply_filters( self::LABELLEDBY_FILTER, '', $post->ID ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
		if ( '' !== $labelledby ) {
			$out .= ' aria-labelledby="' . esc_attr( $labelledby ) . '"';
		} else {
			$label = '' !== trim( (string) $post->post_title ) ? Title_Privacy::safe_title( $post->ID ) : stream_card_kind_label( $post );
			$out  .= ' aria-label="' . esc_attr( $label ) . '"';
		}

		return $out . '>';
	}

	/**
	 * Classes from a core gallery that can stay on the projector wrapper.
	 *
	 * @param string $classes Original class attribute.
	 * @return string[] Kept classes.
	 */
	private function filtered_gallery_classes( string $classes ): array {
		$kept  = [];
		$split = preg_split( '/\s+/', trim( $classes ) );
		foreach ( false === $split ? [] : $split as $class ) {
			if ( '' === $class ) {
				continue;
			}
			foreach ( [ 'wp-block-gallery', 'has-nested-images', 'columns-', 'is-cropped', 'is-layout-', 'wp-container-core-gallery' ] as $prefix ) {
				if ( str_starts_with( $class, $prefix ) ) {
					continue 2;
				}
			}
			$kept[] = $class;
		}

		return $kept;
	}

	/**
	 * Update one figure as a projector mount.
	 *
	 * @param string $figure       Rendered figure HTML.
	 * @param int    $attachment_id Attachment ID, or 0.
	 * @param int    $index        Zero-based mount index.
	 * @param bool   $first        Whether this is the first mount.
	 * @param bool   $thumbnails   Whether to add thumbnail data attributes.
	 * @return string Updated figure HTML, or '' when no figure or image exists.
	 */
	private function mount_html( string $figure, int $attachment_id, int $index, bool $first, bool $thumbnails ): string {
		$processor = new \WP_HTML_Tag_Processor( $figure );
		if ( ! $processor->next_tag( [ 'tag_name' => 'figure' ] ) ) {
			return '';
		}

		$processor->set_attribute( 'class', $this->classes( [ (string) ( $processor->get_attribute( 'class' ) ?? '' ), 'pkiw-projector__mount' ] ) );
		if ( $thumbnails && $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
			$thumb = wp_get_attachment_image_url( $attachment_id, 'medium' );
			if ( is_string( $thumb ) && '' !== $thumb ) {
				$processor->set_attribute( 'data-pkiw-thumb-src', $thumb );
			}
			$srcset = wp_get_attachment_image_srcset( $attachment_id, 'medium' );
			if ( is_string( $srcset ) && '' !== $srcset ) {
				$processor->set_attribute( 'data-pkiw-thumb-srcset', $srcset );
			}
		}

		if ( ! $processor->next_tag( [ 'tag_name' => 'img' ] ) ) {
			return '';
		}

		$processor->set_attribute( 'class', $this->classes( [ (string) ( $processor->get_attribute( 'class' ) ?? '' ), 'u-photo' ] ) );
		$is_local = $attachment_id > 0 && wp_attachment_is_image( $attachment_id );
		if ( $first && $is_local ) {
			$processor->set_attribute( 'loading', 'eager' );
			$processor->set_attribute( 'fetchpriority', 'high' );
			$this->strip_auto_sizes( $processor );
		} elseif ( ! $first || $index > 0 ) {
			$processor->set_attribute( 'loading', 'lazy' );
		}

		$html = $processor->get_updated_html();
		if ( str_contains( $html, '<figcaption' ) || ! $is_local ) {
			return $html;
		}

		$caption = trim( (string) get_post_field( 'post_excerpt', $attachment_id ) );
		if ( '' === $caption ) {
			return $html;
		}

		return (string) preg_replace( '#</figure>\s*$#', '<figcaption class="wp-element-caption">' . stream_card_caption_kses( $caption ) . '</figcaption></figure>', $html );
	}

	/**
	 * Remove a leading `auto` token from an image sizes attribute.
	 *
	 * @param \WP_HTML_Tag_Processor $processor Processor positioned on an img tag.
	 * @return void
	 */
	private function strip_auto_sizes( \WP_HTML_Tag_Processor $processor ): void {
		$sizes = trim( (string) ( $processor->get_attribute( 'sizes' ) ?? '' ) );
		if ( '' === $sizes ) {
			return;
		}

		$sizes = trim( (string) preg_replace( '/^auto\s*,?\s*/i', '', $sizes ) );
		if ( '' === $sizes ) {
			$processor->remove_attribute( 'sizes' );
			return;
		}

		$processor->set_attribute( 'sizes', $sizes );
	}

	/**
	 * Resolve an image attachment ID from rendered HTML or the parsed block attrs.
	 *
	 * @param string              $html  Rendered image or figure HTML.
	 * @param array<string,mixed> $block Parsed image block.
	 * @return int Attachment ID, or 0.
	 */
	private function image_attachment_id( string $html, array $block ): int {
		if ( preg_match( '/\bwp-image-(\d+)\b/', $html, $matches ) ) {
			$id = (int) $matches[1];
			if ( $id > 0 && wp_attachment_is_image( $id ) ) {
				return $id;
			}
		}

		$id = (int) ( $block['attrs']['id'] ?? 0 );
		return $id > 0 && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/**
	 * Merge class lists, remove empty entries and deduplicate.
	 *
	 * @param string[] $sets Class strings or class names.
	 * @return string Class attribute value.
	 */
	private function classes( array $sets ): string {
		$classes = [];
		foreach ( $sets as $set ) {
			$split = preg_split( '/\s+/', trim( $set ) );
			foreach ( false === $split ? [] : $split as $class ) {
				if ( '' !== $class ) {
					$classes[ $class ] = true;
				}
			}
		}

		return implode( ' ', array_keys( $classes ) );
	}
}
