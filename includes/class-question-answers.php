<?php
/**
 * Question answer settings, rendering and core-comment coordination.
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
 * Render classified answers inside the question entry and keep them out of core's list.
 *
 * @since 1.9.0
 */
final class Question_Answers {

	/** Answers settings block name. */
	private const BLOCK = 'post-kinds-indieweb/answers';

	/**
	 * Posts whose answer section printed during this request.
	 *
	 * @var array<int, true>
	 */
	private static array $section_posts = [];

	/**
	 * Cached block attributes by post ID.
	 *
	 * @var array<int, array{headingLabel:string, headingLevel:int, responseLinkLabel:string}>
	 */
	private static array $attributes = [];

	/**
	 * Whether the answer renderer is running its own threaded query.
	 *
	 * @var bool
	 */
	private static bool $rendering = false;

	/**
	 * Whether the comments-number filter is measuring the remaining list.
	 *
	 * @var bool
	 */
	private static bool $measuring = false;

	/**
	 * Wire the block, content decorator and comment-list coordination.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( did_action( 'init' ) ) {
			self::register_block();
		} else {
			add_action( 'init', [ self::class, 'register_block' ] );
		}

		add_filter( 'the_content', [ self::class, 'decorate_content' ], 99 );
		add_action( 'pre_get_comments', [ self::class, 'exclude_answers' ], 12 );
		add_filter( 'comments_template_query_args', [ self::class, 'exclude_answers_from_template' ] );
		add_filter( 'get_comments_number', [ self::class, 'remaining_comment_count' ], 1001, 2 );
	}

	/**
	 * Register the editor-only settings block and its front-end style.
	 *
	 * @return void
	 */
	public static function register_block(): void {
		wp_register_script(
			'pkiw-question-answers-editor',
			\PKIW_URL . 'assets/js/question-answers-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-question-answers-editor', 'post-kinds-for-indieweb-in-block-themes' );
		wp_register_style(
			'pkiw-question-answers',
			\PKIW_URL . 'styles/question-answers.css',
			[],
			\PKIW_VERSION
		);

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			return;
		}

		/**
		 * Block type arguments.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Answers', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render_block' ],
			'uses_context'    => [ 'postId' ],
			'attributes'      => [
				'headingLabel'      => [
					'type'    => 'string',
					'default' => 'Answers',
					'role'    => 'content',
				],
				'headingLevel'      => [
					'type'    => 'integer',
					'default' => 2,
				],
				'responseLinkLabel' => [
					'type'    => 'string',
					'default' => 'Leave an answer',
					'role'    => 'content',
				],
			],
			'supports'        => [
				'multiple' => false,
				'html'     => false,
				'reusable' => false,
			],
			'editor_script'   => 'pkiw-question-answers-editor',
			'style'           => 'pkiw-question-answers',
		];
		register_block_type( self::BLOCK, $args );
	}

	/**
	 * The settings block never owns front-end markup.
	 *
	 * @return string
	 */
	public static function render_block(): string {
		return '';
	}

	/**
	 * Put the question body, hidden name and answers inside one h-entry content pass.
	 *
	 * @param string $content Rendered post content.
	 * @return string
	 */
	public static function decorate_content( string $content ): string {
		$post = get_post();
		if (
			! is_singular( 'post' )
			|| ! $post instanceof \WP_Post
			|| get_the_ID() !== get_queried_object_id()
			|| is_feed()
			|| wp_is_serving_rest_request()
			|| null !== get_query_var( 'atproto', null )
			|| '' !== (string) get_query_var( 'markdown_request', '' )
			|| 'markdown' === get_query_var( 'format', '' )
			|| doing_filter( 'get_excerpt' )
			|| doing_filter( 'get_the_excerpt' )
			|| doing_action( 'wp_head' )
			|| post_password_required( $post )
			|| ! has_term( Question_Archive::KIND, Taxonomy::TAXONOMY, $post )
			|| self::has_class( $content, 'pkiw-question-body' )
		) {
			return $content;
		}

		$decorated = self::has_class( $content, 'e-content' )
			? $content
			: '<div class="pkiw-question-body e-content">' . $content . '</div>';
		$title     = trim( (string) $post->post_title );
		if ( '' !== $title && ! self::has_class( $content, 'p-name' ) ) {
			$decorated .= '<span class="p-name" hidden>' . esc_html( visitor_post_title( $post ) ) . '</span>';
		}

		return $decorated . self::section( $post );
	}

	/**
	 * Read and filter the first answers block's settings.
	 *
	 * @param \WP_Post $post Question post.
	 * @return array{headingLabel:string, headingLevel:int, responseLinkLabel:string}
	 */
	public static function attributes_for( \WP_Post $post ): array {
		if ( isset( self::$attributes[ $post->ID ] ) ) {
			return self::$attributes[ $post->ID ];
		}

		$defaults = [
			'headingLabel'      => 'Answers',
			'headingLevel'      => 2,
			'responseLinkLabel' => 'Leave an answer',
		];
		$found    = self::find_attributes( parse_blocks( $post->post_content ) );
		$attrs    = array_merge( $defaults, null === $found ? [] : $found );
		$attrs    = apply_filters( 'pkiw_answers_attributes', $attrs, $post );
		$level    = (int) ( $attrs['headingLevel'] ?? 2 );

		self::$attributes[ $post->ID ] = [
			'headingLabel'      => (string) ( $attrs['headingLabel'] ?? $defaults['headingLabel'] ),
			'headingLevel'      => in_array( $level, [ 2, 3, 4 ], true ) ? $level : 2,
			'responseLinkLabel' => (string) ( $attrs['responseLinkLabel'] ?? $defaults['responseLinkLabel'] ),
		];

		return self::$attributes[ $post->ID ];
	}

	/**
	 * Return the classifier's answer count without a second rule set.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function answer_count_for( int $post_id ): int {
		return Answer_Classifier::count( $post_id );
	}

	/**
	 * Keep rendered answer roots out of later core comment queries.
	 *
	 * @param mixed $query Comment query.
	 * @return void
	 */
	public static function exclude_answers( $query ): void {
		if ( self::$rendering || ! $query instanceof \WP_Comment_Query || true === ( $query->query_vars['count'] ?? false ) ) {
			return;
		}

		$post_id = (int) ( $query->query_vars['post_id'] ?? 0 );
		if ( ! isset( self::$section_posts[ $post_id ] ) ) {
			return;
		}

		$query->query_vars['comment__not_in'] = self::excluded_ids(
			$post_id,
			(array) ( $query->query_vars['comment__not_in'] ?? [] )
		);
	}

	/**
	 * Add the same exclusions to the classic comments-template query.
	 *
	 * @param mixed $args Query arguments.
	 * @return array<string, mixed>
	 */
	public static function exclude_answers_from_template( $args ): array {
		$args = is_array( $args ) ? $args : [];
		if ( self::$rendering ) {
			return $args;
		}

		$post_id = (int) ( $args['post_id'] ?? get_queried_object_id() );
		if ( isset( self::$section_posts[ $post_id ] ) ) {
			$args['comment__not_in'] = self::excluded_ids( $post_id, (array) ( $args['comment__not_in'] ?? [] ) );
		}

		return $args;
	}

	/**
	 * Measure the list core will print after answer exclusion.
	 *
	 * @param mixed $count   Earlier filtered count.
	 * @param mixed $post_id Post ID.
	 * @return mixed
	 */
	public static function remaining_comment_count( $count, $post_id ) {
		$post_id = (int) $post_id;
		if ( self::$measuring || ! isset( self::$section_posts[ $post_id ] ) ) {
			return $count;
		}

		$block = self::comment_template_block( $post_id );
		$args  = self::measured_query_args( $block );

		self::$measuring = true;
		try {
			$query = new \WP_Comment_Query( $args );
			return (string) count( $query->comments );
		} finally {
			self::$measuring = false;
		}
	}

	/**
	 * Build an unpaged ID query from core's comment-template arguments.
	 *
	 * @param \WP_Block $block Contextual comment-template block.
	 * @return array{fields:string, no_found_rows:bool, number:int, hierarchical:string|false, ...}
	 */
	private static function measured_query_args( \WP_Block $block ): array {
		$args = build_comment_query_vars_from_block( $block );
		unset( $args['count'] );
		$args['fields']        = 'ids';
		$args['no_found_rows'] = true;
		$args['number']        = 0;
		$args['hierarchical']  = get_option( 'thread_comments' ) ? 'flat' : false;

		return $args;
	}

	/**
	 * Render the answer section for a question.
	 *
	 * @param \WP_Post $post Question post.
	 * @return string
	 */
	private static function section( \WP_Post $post ): string {
		$answers = Answer_Classifier::answers( $post->ID );
		$attrs   = self::attributes_for( $post );
		if ( [] === $answers ) {
			if ( ! comments_open( $post->ID ) ) {
				return '';
			}

			wp_enqueue_style( 'pkiw-question-answers' );
			return '<div class="pkiw-answers pkiw-answers--empty">' . self::response_link( $attrs['responseLinkLabel'] ) . '</div>';
		}

		self::$section_posts[ $post->ID ] = true;
		wp_enqueue_style( 'pkiw-question-answers' );
		$children = self::answer_children( $post->ID, $answers );
		$heading  = 'pkiw-answers-' . $post->ID;
		$level    = $attrs['headingLevel'];
		$html     = '<section class="pkiw-answers" aria-labelledby="' . esc_attr( $heading ) . '">';
		$html    .= '<h' . $level . ' id="' . esc_attr( $heading ) . '" class="pkiw-answers__heading">';
		$html    .= '<span class="pkiw-answers__label">' . esc_html( $attrs['headingLabel'] ) . '</span> ';
		$html    .= '<span class="pkiw-answers__count">' . esc_html( number_format_i18n( count( $answers ) ) ) . '</span>';
		$html    .= '</h' . $level . '><ol class="pkiw-answers__list">';
		foreach ( $answers as $answer ) {
			$html .= self::comment_html( $answer, $children[ $answer->comment_ID ] ?? [], false );
		}
		$html .= '</ol>';
		if ( comments_open( $post->ID ) ) {
			$html .= self::response_link( $attrs['responseLinkLabel'] );
		}

		return $html . '</section>';
	}

	/**
	 * Render one answer or descendant and its populated children.
	 *
	 * @param \WP_Comment             $comment  Comment.
	 * @param array<int, \WP_Comment> $children Direct children.
	 * @param bool                    $reply    Whether this is a descendant.
	 * @return string
	 */
	private static function comment_html( \WP_Comment $comment, array $children, bool $reply ): string {
		$classes = $reply
			? 'pkiw-answer pkiw-answer--reply p-comment h-cite'
			: 'pkiw-answer p-comment h-cite';
		$name    = '' !== trim( $comment->comment_author ) ? $comment->comment_author : __( 'Anonymous', 'post-kinds-for-indieweb-in-block-themes' );
		$html    = '<li class="' . esc_attr( $classes ) . '" id="comment-' . (int) $comment->comment_ID . '">';
		$html   .= '<span class="p-author h-card">';
		if ( self::http_url( $comment->comment_author_url ) ) {
			$html .= wp_unslash( wp_rel_ugc( '<a class="p-name u-url" rel="external nofollow ugc" href="' . esc_url( $comment->comment_author_url ) . '">' . esc_html( $name ) . '</a>' ) );
		} else {
			$html .= '<span class="p-name">' . esc_html( $name ) . '</span>';
		}
		$html .= '</span>';
		$html .= '<a class="u-url" href="' . esc_url( self::comment_url( $comment ) ) . '"><time class="dt-published" datetime="' . esc_attr( get_comment_date( 'c', $comment ) ) . '">' . esc_html( get_comment_date( '', $comment ) ) . '</time></a>';
		$html .= '<div class="e-content">' . self::comment_text( $comment ) . '</div>';
		if ( [] !== $children ) {
			$html .= '<ol class="pkiw-answer__replies">';
			foreach ( $children as $child ) {
				if ( $child instanceof \WP_Comment ) {
					$html .= self::comment_html( $child, $child->get_children(), true );
				}
			}
			$html .= '</ol>';
		}

		return $html . '</li>';
	}

	/**
	 * Collect each answer root's core-visible descendants with one threaded query.
	 *
	 * @param int                     $post_id Post ID.
	 * @param array<int, \WP_Comment> $answers Answer roots.
	 * @return array<int, array<int, \WP_Comment>>
	 */
	private static function answer_children( int $post_id, array $answers ): array {
		if ( ! get_option( 'thread_comments' ) ) {
			return [];
		}

		$result             = [];
		$answer_ids         = array_map( static fn( \WP_Comment $comment ): int => (int) $comment->comment_ID, $answers );
		$previous_rendering = self::$rendering;
		self::$rendering    = true;
		try {
			$query = new \WP_Comment_Query( build_comment_query_vars_from_block( self::comment_template_block( $post_id ) ) );
			foreach ( $query->comments as $comment ) {
				if ( $comment instanceof \WP_Comment && in_array( (int) $comment->comment_ID, $answer_ids, true ) ) {
					$result[ $comment->comment_ID ] = $comment->get_children();
				}
			}
		} finally {
			self::$rendering = $previous_rendering;
		}

		return $result;
	}

	/**
	 * Build the same contextual comment-template block core uses.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Block
	 */
	private static function comment_template_block( int $post_id ): \WP_Block {
		return new \WP_Block(
			[
				'blockName'    => 'core/comment-template',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[ 'postId' => $post_id ]
		);
	}

	/**
	 * Render comment text without Simple Location's address injection.
	 *
	 * @param \WP_Comment $comment Comment.
	 * @return string
	 */
	private static function comment_text( \WP_Comment $comment ): string {
		$removed = self::remove( 'get_comment_text', 12, 'Geo_Data', 'location_comment' );
		$level   = ob_get_level();
		$html    = '';
		ob_start();
		try {
			comment_text( $comment );
			$html = (string) ob_get_contents();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			foreach ( $removed as $entry ) {
				add_filter( $entry['hook'], $entry['callable'], $entry['priority'], $entry['accepted_args'] );
			}
		}

		$allowed         = array_fill_keys( [ 'b', 'blockquote', 'br', 'cite', 'code', 'del', 'em', 'i', 'p', 'pre', 'q', 'strong', 'ul', 'ol', 'li' ], [] );
		$allowed['a']    = [
			'href' => true,
			'rel'  => true,
		];
		$allowed['abbr'] = [ 'title' => true ];

		return wp_kses( $html, $allowed );
	}

	/**
	 * Render the core comment-form jump link.
	 *
	 * @param string $label Saved link label.
	 * @return string
	 */
	private static function response_link( string $label ): string {
		return '<p class="pkiw-answers__respond"><a href="#respond">' . esc_html( $label ) . '<span aria-hidden="true"> &rarr;</span></a></p>';
	}

	/**
	 * Select the Webmention source only for a valid Webmention URL.
	 *
	 * @param \WP_Comment $comment Comment.
	 * @return string
	 */
	private static function comment_url( \WP_Comment $comment ): string {
		$protocol = (string) get_comment_meta( $comment->comment_ID, 'protocol', true );
		$source   = (string) get_comment_meta( $comment->comment_ID, 'webmention_source_url', true );
		if ( 'webmention' === $protocol && self::http_url( $source ) ) {
			return $source;
		}

		return get_comment_link( $comment );
	}

	/**
	 * Whether a URL uses the HTTP or HTTPS scheme.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function http_url( string $url ): bool {
		$scheme = wp_parse_url( trim( $url ), PHP_URL_SCHEME );

		return is_string( $scheme ) && in_array( strtolower( $scheme ), [ 'http', 'https' ], true );
	}

	/**
	 * Merge answer IDs into an existing exclusion list.
	 *
	 * @param int        $post_id Post ID.
	 * @param array<int> $current Existing IDs.
	 * @return array<int>
	 */
	private static function excluded_ids( int $post_id, array $current ): array {
		$ids = array_merge( array_map( 'intval', $current ), Answer_Classifier::answer_ids( $post_id ) );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Find the first answers block depth-first.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<string, mixed>|null
	 */
	private static function find_attributes( array $blocks ): ?array {
		foreach ( $blocks as $block ) {
			if ( self::BLOCK === ( $block['blockName'] ?? null ) ) {
				return is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			}
			$inner = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : [];
			$found = self::find_attributes( $inner );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Whether rendered HTML already carries a class token.
	 *
	 * @param string $html  HTML.
	 * @param string $class_name Class token.
	 * @return bool
	 */
	private static function has_class( string $html, string $class_name ): bool {
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			if ( $processor->has_class( $class_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove matching callbacks and remember exactly what changed.
	 *
	 * @param string $hook       Hook name.
	 * @param int    $priority   Priority.
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @return array<int, array{hook:string, callable:mixed, priority:int, accepted_args:int}>
	 */
	private static function remove( string $hook, int $priority, string $class_name, string $method ): array {
		$wp_hook = $GLOBALS['wp_filter'][ $hook ] ?? null;
		if ( ! $wp_hook instanceof \WP_Hook || empty( $wp_hook->callbacks[ $priority ] ) ) {
			return [];
		}

		$removed = [];
		foreach ( $wp_hook->callbacks[ $priority ] as $callback ) {
			$callable = $callback['function'] ?? null;
			if ( ! self::is_callback_of( $callable, $class_name, $method ) ) {
				continue;
			}
			if ( remove_filter( $hook, $callable, $priority ) ) {
				$removed[] = [
					'hook'          => $hook,
					'callable'      => $callable,
					'priority'      => $priority,
					'accepted_args' => (int) ( $callback['accepted_args'] ?? 1 ),
				];
			}
		}

		return $removed;
	}

	/**
	 * Match static and object callback spellings.
	 *
	 * @param mixed  $candidate  Registered callback.
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @return bool
	 */
	private static function is_callback_of( $candidate, string $class_name, string $method ): bool {
		$class_name = ltrim( $class_name, '\\' );
		if ( is_string( $candidate ) ) {
			return ltrim( $candidate, '\\' ) === $class_name . '::' . $method;
		}
		if ( ! is_array( $candidate ) || 2 !== count( $candidate ) || $method !== (string) $candidate[1] ) {
			return false;
		}
		$candidate_class = is_object( $candidate[0] ) ? get_class( $candidate[0] ) : (string) $candidate[0];

		return ltrim( $candidate_class, '\\' ) === $class_name;
	}
}
