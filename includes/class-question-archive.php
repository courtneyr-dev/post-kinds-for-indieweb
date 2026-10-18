<?php
/**
 * Question archive grouping, entries, status and Stream cards.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Label_Mode;
use PKIW\Grouping\Meta_Source;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own the public question archive contract.
 *
 * @since 1.9.0
 */
final class Question_Archive {

	/** Kind slug. */
	public const KIND = 'question';

	/** Group source ID. */
	public const SOURCE = 'question_status';

	/** Derived status meta key. */
	public const STATUS_KEY = '_pkiw_question_status';

	/**
	 * Status order.
	 *
	 * @var string[]
	 */
	public const STATUSES = [ 'answered', 'waiting' ];

	/** Entry block name. */
	public const ENTRY_BLOCK = 'post-kinds-indieweb/question-entry';

	/**
	 * Wire question registrations and status invalidation.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public function register(): void {
		Grouped_Archive::register_source( self::source(), self::KIND );
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );

		add_filter( 'pkiw_archive_sections_groups', [ self::class, 'section_groups' ] );
		if ( did_action( 'init' ) ) {
			self::register_blocks();
		} else {
			add_action( 'init', [ self::class, 'register_blocks' ] );
		}

		add_action( 'wp_insert_comment', [ self::class, 'comment_inserted' ], 10, 2 );
		add_action( 'transition_comment_status', [ self::class, 'comment_status_changed' ], 10, 3 );
		add_action( 'edit_comment', [ self::class, 'comment_edited' ], 10, 2 );
		add_action( 'deleted_comment', [ self::class, 'comment_deleted' ], 10, 2 );
		add_action( 'added_comment_meta', [ self::class, 'comment_meta_changed' ], 10, 4 );
		add_action( 'updated_comment_meta', [ self::class, 'comment_meta_changed' ], 10, 4 );
		add_action( 'deleted_comment_meta', [ self::class, 'comment_meta_changed' ], 10, 4 );
		add_action( 'untrashed_post_comments', [ self::class, 'post_comments_untrashed' ] );
		add_action( 'wp_after_insert_post', [ self::class, 'post_inserted' ], 20, 4 );
		add_action( 'set_object_terms', [ self::class, 'terms_set' ], 10, 6 );
		add_action( 'deleted_term_relationships', [ self::class, 'term_relationships_deleted' ], 10, 3 );

		add_filter( 'pkiw_card_meta_backfill_kinds', [ self::class, 'backfill_kinds' ] );
		add_action( 'pkiw_card_meta_backfill_post', [ self::class, 'backfill_post' ], 10, 2 );
		add_filter( 'pkiw_stream_card_kind_render', [ self::class, 'stream_card' ], 10, 4 );
	}

	/**
	 * Status group source.
	 *
	 * @since 1.9.0
	 *
	 * @return Meta_Source
	 */
	public static function source(): Meta_Source {
		return new Meta_Source(
			self::SOURCE,
			[ self::STATUS_KEY ],
			self::STATUSES,
			Label_Mode::map( static fn(): array => self::status_labels() ),
			'',
			true
		);
	}

	/**
	 * Human-readable status labels.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return [
			'answered' => __( 'Answered', 'post-kinds-for-indieweb-in-block-themes' ),
			'waiting'  => __( 'Waiting for an answer', 'post-kinds-for-indieweb-in-block-themes' ),
		];
	}

	/**
	 * Add question labels to the archive editor's localized group maps.
	 *
	 * @internal
	 *
	 * @param mixed $groups Existing maps.
	 * @return array<string, mixed>
	 */
	public static function section_groups( $groups ): array {
		$groups               = is_array( $groups ) ? $groups : [];
		$groups[ self::KIND ] = self::status_labels();

		return $groups;
	}

	/**
	 * Register the question entry editor and dynamic block.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function register_blocks(): void {
		wp_register_script(
			'pkiw-question-entry-editor',
			\PKIW_URL . 'assets/js/question-entry-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-question-entry-editor', 'post-kinds-for-indieweb-in-block-themes' );

		$before = wp_scripts()->get_data( 'pkiw-question-entry-editor', 'before' );
		$inline = implode( "\n", is_array( $before ) ? $before : [] );
		if ( ! str_contains( $inline, 'window.pkiwQuestionEntry' ) ) {
			wp_add_inline_script(
				'pkiw-question-entry-editor',
				'window.pkiwQuestionEntry = ' . wp_json_encode( [ 'groups' => self::status_labels() ] ) . ';',
				'before'
			);
		}

		Grouped_Archive::register_entry_block(
			self::ENTRY_BLOCK,
			[
				'shape'       => Grouped_Archive::ENTRY_LINE,
				'heading_ids' => true,
				'expose_key'  => true,
				'classes'     => [
					'wrapper'     => 'pkiw-question--sectioned',
					'section'     => 'pkiw-question-section',
					'heading'     => 'pkiw-question-section__heading',
					'items'       => 'pkiw-question-section__items',
					'placeholder' => 'pkiw-question-entry pkiw-question-entry--continued',
				],
			]
		);

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::ENTRY_BLOCK ) ) {
			return;
		}

		/**
		 * Block type arguments.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Question entry', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render_entry_block' ],
			'uses_context'    => [ 'postId', 'postType', 'queryId', 'templateSlug' ],
			'attributes'      => Grouped_Archive::entry_attributes(
				[
					'linesPerPage' => [
						'default' => 12,
					],
					'groupLabels'  => [
						'type'    => 'object',
						'default' => [],
						'role'    => 'content',
					],
					'emptyLabel'   => [
						'role' => 'content',
					],
				]
			),
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'editor_script'   => 'pkiw-question-entry-editor',
			'style'           => 'pkiw-kind-layouts',
		];
		register_block_type( self::ENTRY_BLOCK, $args );
	}

	/**
	 * Compute a question's current answer status.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function compute_status( int $post_id ): string {
		return Answer_Classifier::count( $post_id ) > 0 ? 'answered' : 'waiting';
	}

	/**
	 * Refresh the private derived status row, or remove it off questions.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function refresh_status( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			delete_post_meta( $post_id, self::STATUS_KEY );
			return;
		}

		$status = self::compute_status( $post_id );
		if ( get_metadata_raw( 'post', $post_id, self::STATUS_KEY, true ) !== $status ) {
			update_post_meta( $post_id, self::STATUS_KEY, $status );
		}
	}

	/**
	 * Backfill one post through the shared card-meta runner.
	 *
	 * @since 1.9.0
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public static function backfill_post( int $post_id, \WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Shared action signature.
		self::refresh_status( $post_id );
	}

	/**
	 * Include question in the shared backfill kind list once.
	 *
	 * @internal
	 *
	 * @param mixed $kinds Kind slugs.
	 * @return string[]
	 */
	public static function backfill_kinds( $kinds ): array {
		$kinds = is_array( $kinds ) ? array_map( 'strval', $kinds ) : [];
		if ( ! in_array( self::KIND, $kinds, true ) ) {
			$kinds[] = self::KIND;
		}

		return array_values( array_unique( $kinds ) );
	}

	/**
	 * Public facts shared by archive entries and Stream cards.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return [];
		}

		$titled = '' !== trim( (string) $post->post_title );
		$name   = $titled ? visitor_post_title( $post ) : self::thought( $post );
		if ( '' === $name ) {
			$name = untitled_name( $post, false );
		}

		$count = Answer_Classifier::count( $post_id );

		return [
			'name'          => $name,
			'name_is_title' => $titled && ! post_title_is_hidden( $post ),
			'status'        => $count > 0 ? 'answered' : 'waiting',
			'answer_count'  => $count,
			'date_iso'      => get_post_time( 'c', true, $post ),
			'day_short'     => mysql2date( _x( 'M j', 'short Stream day', 'post-kinds-for-indieweb-in-block-themes' ), $post->post_date ),
		];
	}

	/**
	 * Derive an untitled question's thought, skipping a leading salutation.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public static function thought( \WP_Post $post ): string {
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			return untitled_thought( $post );
		}

		$blocks = parse_blocks( (string) $post->post_content );
		$first  = $blocks[0] ?? null;
		$class  = is_array( $first ) ? (string) ( $first['attrs']['className'] ?? '' ) : '';
		if ( 1 !== preg_match( '/(?:^|\s)is-style-cr-salutation(?:\s|$)/', $class ) ) {
			return untitled_thought( $post );
		}

		$copy               = clone $post;
		$copy->post_content = serialize_blocks( array_slice( $blocks, 1 ) );

		return untitled_thought( $copy );
	}

	/**
	 * Render the question entry block through the grouped archive engine.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Unused.
	 * @param \WP_Block|null       $block      Block instance.
	 * @return string
	 */
	public static function render_entry_block( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return Grouped_Archive::render_entry(
			self::ENTRY_BLOCK,
			$attributes,
			$block,
			static fn( \WP_Post $post, bool $in_section, string $kind = '' ): string => self::entry_html( $post, $attributes, $in_section ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		);
	}

	/**
	 * One question archive entry.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Post             $post       Post.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param bool                 $in_section Whether a section heading precedes it.
	 * @return string
	 */
	public static function entry_html( \WP_Post $post, array $attributes, bool $in_section ): string {
		$level     = max( 2, min( 6, (int) ( $attributes['headingLevel'] ?? 2 ) + ( $in_section ? 1 : 0 ) ) );
		$protected = post_password_required( $post );
		$facts     = $protected ? [] : self::facts( $post->ID );
		$titled    = '' !== trim( (string) $post->post_title );
		$name      = $protected
			? ( $titled ? visitor_post_title( $post ) : untitled_name( $post, false ) )
			: (string) ( $facts['name'] ?? '' );
		$is_title  = $protected
			? $titled && ! post_title_is_hidden( $post )
			: ! empty( $facts['name_is_title'] );
		$date_iso  = $protected
			? get_post_time( 'c', true, $post )
			: (string) ( $facts['date_iso'] ?? get_post_time( 'c', true, $post ) );

		$html  = '<div class="pkiw-question-entry">';
		$html .= '<h' . $level . ' class="pkiw-question-entry__title' . ( $is_title ? ' p-name' : '' ) . '">';
		$html .= '<a class="u-url" href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( $name ) . '</a>';
		$html .= '</h' . $level . '>';
		$html .= '<data class="dt-published" value="' . esc_attr( $date_iso ) . '" hidden></data>';

		$author_html = function_exists( __NAMESPACE__ . '\\entry_author_html' ) ? entry_author_html( $post ) : '';
		if ( '' !== $author_html ) {
			$html .= '<span hidden>' . $author_html . '</span>';
		}

		return $html . '</div>';
	}

	/**
	 * Render a question-owned Stream card.
	 *
	 * @internal Hooked to pkiw_stream_card_kind_render.
	 *
	 * @param mixed $html          Card HTML returned by an earlier callback.
	 * @param mixed $post          Post.
	 * @param mixed $heading_level Heading level.
	 * @param mixed $attributes    Block attributes, unused.
	 * @return mixed Question card HTML, or the first argument unchanged.
	 */
	public static function stream_card( $html, $post = null, $heading_level = 2, $attributes = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $post instanceof \WP_Post || self::KIND !== get_post_kind_slug( $post ) ) {
			return $html;
		}

		$facts = self::facts( $post->ID );
		if ( [] === $facts ) {
			return $html;
		}

		$level    = (int) $heading_level;
		$name     = (string) $facts['name'];
		$is_title = ! empty( $facts['name_is_title'] );
		$count    = (int) $facts['answer_count'];
		$label    = get_kind_label( stream_card_kind_label( $post ), self::KIND, 'stream-card' );
		/* translators: %s: number of answers. */
		$count_text = sprintf( _n( '%s answer', '%s answers', $count, 'post-kinds-for-indieweb-in-block-themes' ), number_format_i18n( $count ) );

		$out  = '<article class="pk-card pk-card--stream k-question h-entry">';
		$out .= '<div class="pk-body">';
		$out .= '<span class="pk-kindlabel">' . esc_html( $label ) . '</span>';
		$out .= '<div class="pk-caption">';
		$out .= '<h' . $level . ' class="pk-title' . ( $is_title ? ' p-name' : '' ) . '">';
		$out .= '<a class="u-url" href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( $name ) . '</a>';
		$out .= '</h' . $level . '>';
		$out .= '<p class="pk-sub pk-stream-date"><time class="dt-published" datetime="' . esc_attr( (string) $facts['date_iso'] ) . '">' . esc_html( (string) $facts['day_short'] ) . '</time></p>';
		$out .= '<p class="pk-sub pk-answer-count">' . esc_html( $count_text ) . '</p>';
		$out .= '</div>';

		if ( $is_title ) {
			$excerpt = trim( wp_strip_all_tags( get_the_excerpt( $post ) ) );
			if ( '' !== $excerpt ) {
				$out .= '<p class="pk-excerpt p-summary">' . esc_html( wp_trim_words( $excerpt, 20, '…' ) ) . '</p>';
			}
		}

		return $out . '</div></article>';
	}

	/**
	 * Handle a newly inserted comment.
	 *
	 * @param int               $comment_id Comment ID.
	 * @param \WP_Comment|mixed $comment    Comment object when supplied.
	 * @return void
	 */
	public static function comment_inserted( int $comment_id, $comment ): void {
		self::refresh_for_comment( $comment instanceof \WP_Comment ? $comment : $comment_id );
	}

	/**
	 * Handle an approval status transition.
	 *
	 * @param string            $new_status New status.
	 * @param string            $old_status Previous status.
	 * @param \WP_Comment|mixed $comment    Comment object.
	 * @return void
	 */
	public static function comment_status_changed( string $new_status, string $old_status, $comment ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		self::refresh_for_comment( $comment );
	}

	/**
	 * Handle an edited comment.
	 *
	 * @param int                 $comment_id Comment ID.
	 * @param array<string,mixed> $data       Updated comment data.
	 * @return void
	 */
	public static function comment_edited( int $comment_id, $data = [] ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		self::refresh_for_comment( $comment_id );
	}

	/**
	 * Handle a deleted comment while its object remains available.
	 *
	 * @param int               $comment_id Comment ID.
	 * @param \WP_Comment|mixed $comment    Deleted comment object.
	 * @return void
	 */
	public static function comment_deleted( int $comment_id, $comment ): void {
		self::refresh_for_comment( $comment instanceof \WP_Comment ? $comment : $comment_id );
	}

	/**
	 * Handle classification metadata changes only.
	 *
	 * @param mixed  $meta_id    Meta ID or IDs.
	 * @param int    $comment_id Comment ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public static function comment_meta_changed( $meta_id, int $comment_id, string $meta_key, $meta_value = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Three comment-meta hooks share this signature.
		if ( ! in_array( $meta_key, [ 'protocol', 'indieblocks_webmention_kind', 'webmention_source_url' ], true ) ) {
			return;
		}

		self::refresh_for_comment( $comment_id );
	}

	/**
	 * Refresh after core restores a trashed post's comments.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function post_comments_untrashed( int $post_id ): void {
		self::refresh_if_post( $post_id );
	}

	/**
	 * Refresh after post author or kind-relevant post data changes.
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Inserted post.
	 * @param bool          $update      Whether WordPress updated a post.
	 * @param \WP_Post|null $post_before Previous post.
	 * @return void
	 */
	public static function post_inserted( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $post_before ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( 'post' !== $post->post_type || wp_is_post_revision( $post_id ) ) {
			return;
		}

		Answer_Classifier::reset();
		self::refresh_status( $post_id );
	}

	/**
	 * Refresh after replacing an object's kind terms.
	 *
	 * @param int        $object_id  Object ID.
	 * @param mixed      $terms      Assigned terms.
	 * @param array<int> $tt_ids     New term taxonomy IDs.
	 * @param string     $taxonomy   Taxonomy name.
	 * @param bool       $append     Whether terms were appended.
	 * @param array<int> $old_tt_ids Previous term taxonomy IDs.
	 * @return void
	 */
	public static function terms_set( int $object_id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( Taxonomy::TAXONOMY === $taxonomy ) {
			self::refresh_if_post( $object_id );
		}
	}

	/**
	 * Refresh after removing kind relationships.
	 *
	 * @param int        $object_id Object ID.
	 * @param array<int> $tt_ids    Deleted term taxonomy IDs.
	 * @param string     $taxonomy  Taxonomy name.
	 * @return void
	 */
	public static function term_relationships_deleted( int $object_id, array $tt_ids, string $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( Taxonomy::TAXONOMY === $taxonomy ) {
			self::refresh_if_post( $object_id );
		}
	}

	/**
	 * Refresh a question status from a comment object or ID.
	 *
	 * @param \WP_Comment|mixed $comment Comment object or ID.
	 * @return void
	 */
	private static function refresh_for_comment( $comment ): void {
		$comment = $comment instanceof \WP_Comment ? $comment : get_comment( (int) $comment );
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		$post = get_post( (int) $comment->comment_post_ID );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || ! has_term( self::KIND, Taxonomy::TAXONOMY, $post ) ) {
			return;
		}

		Answer_Classifier::reset();
		self::refresh_status( $post->ID );
	}

	/**
	 * Refresh an ordinary post and skip other object types cheaply.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function refresh_if_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || wp_is_post_revision( $post_id ) ) {
			return;
		}

		Answer_Classifier::reset();
		self::refresh_status( $post_id );
	}
}
