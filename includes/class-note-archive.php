<?php
/**
 * Note archive: the note entry block and the naming of a note.
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
 * The note archive's one-note markup (issue 265).
 *
 * /kind/note/ is a board of notes in week sections. The week sections, the
 * page size and the paging are the grouped archive engine's: the template's
 * archive-sections marker sets 12 notes a page and the shared `post_week`
 * source groups them (Kind_Archive_Layouts). This class prints what each
 * note shows: a name, a thought cut to 25 words, and the date.
 *
 * A note's name is never read from anything that could expose more than the
 * note's own words. A password-protected note, a note that also carries
 * another kind (an exercise, sleep or check-in body is not a note) and a
 * title built from a hidden location all print a name that holds none of it.
 * Nothing here runs the_content or get_the_excerpt(), so a content filter
 * (Simple Location appends the address) never reaches the thought.
 *
 * There is no facts reader for note: parts() belongs to this render.
 *
 * @since 1.9.0
 */
final class Note_Archive {

	/**
	 * Kind slug.
	 *
	 * @var string
	 */
	public const KIND = 'note';

	/**
	 * Note entry block.
	 *
	 * @var string
	 */
	public const BLOCK = 'post-kinds-indieweb/note-entry';

	/**
	 * Post Template block style the board paints under.
	 *
	 * @var string
	 */
	public const STYLE = 'pkiw-note-board';

	/**
	 * Note counts by normalized name for the current page, by cache key.
	 *
	 * @var array<string, array<string, int>>
	 */
	private static array $page_names = [];

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( did_action( 'init' ) ) {
			$this->register_block();
		} else {
			add_action( 'init', [ $this, 'register_block' ] );
		}
	}

	/**
	 * Register the stylesheet, the editor script, the board style and the block.
	 *
	 * The stylesheet registers before the block, so the block's `style`
	 * handle resolves when the block registers.
	 *
	 * @return void
	 */
	public function register_block(): void {
		$css = \PKIW_PATH . 'styles/note-entry.css';
		if ( file_exists( $css ) ) {
			wp_register_style( 'pkiw-note-entry', \PKIW_URL . 'styles/note-entry.css', [], (string) filemtime( $css ) );
			wp_style_add_data( 'pkiw-note-entry', 'path', $css );
		}

		wp_register_script(
			'pkiw-note-entry-editor',
			\PKIW_URL . 'assets/js/note-entry-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-note-entry-editor', 'post-kinds-for-indieweb-in-block-themes' );

		register_block_style(
			'core/post-template',
			[
				'name'  => self::STYLE,
				'label' => __( 'Note board', 'post-kinds-for-indieweb-in-block-themes' ),
			]
		);

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			return;
		}

		/**
		 * Block type arguments. No block.json, so apiVersion 3 is declared here.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Note entry', 'post-kinds-for-indieweb-in-block-themes' ),
			'description'     => __( 'One note for the current post: its name, a short thought and the date. Use inside a Query Loop.', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'render_callback' => [ self::class, 'render' ],
			'uses_context'    => [ 'postId', 'postType', 'queryId', 'templateSlug' ],
			'attributes'      => [
				'headingLevel' => [
					'type'    => 'integer',
					'default' => 3,
				],
			],
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
			'ancestor'        => [ 'core/post-template' ],
			'editor_script'   => 'pkiw-note-entry-editor',
			'style'           => 'pkiw-note-entry',
		];
		register_block_type( self::BLOCK, $args );
	}

	/**
	 * What one note prints.
	 *
	 * - `protected` reads the raw `post_password` column. post_password_required()
	 *   passes a visitor who holds a valid cookie, and the archive shows every
	 *   visitor the same card.
	 * - `thought` is '' for a protected note and for a post with any kind but
	 *   note. Otherwise it is untitled_thought(): the excerpt or the first 25
	 *   words of the body, read from the stored columns.
	 * - `name_is_title` is true only for a stored title that is shown as stored.
	 *   A "Check-in, <date>" or "Note, <date>" name is synthetic and never p-name.
	 *
	 * @since 1.9.0
	 *
	 * @param \WP_Post $post Note.
	 * @return array{name:string, name_is_title:bool, thought:string, mode:string, protected:bool, dup_title:bool, date:string, datetime:string}
	 */
	public static function parts( \WP_Post $post ): array {
		$protected = '' !== (string) $post->post_password;
		$terms     = get_the_terms( $post, Taxonomy::TAXONOMY );
		$kinds     = [];
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			$kinds[] = (string) $term->slug;
		}
		$note_only = [ self::KIND ] === $kinds;

		$thought = $protected || ! $note_only ? '' : untitled_thought( $post );
		$name    = '';
		$is_real = false;
		$mode    = 'fallback';

		if ( '' !== trim( (string) $post->post_title ) ) {
			// A stored title that came from a location that isn't public is not the title to print.
			// The safe title is read directly: the_title hands back the stored one on an admin screen.
			$safe   = Title_Privacy::safe_title( $post->ID );
			$hidden = $safe !== (string) $post->post_title;
			if ( $hidden ) {
				$name = trim( wp_strip_all_tags( $safe ) );
				if ( $protected && '' !== $name ) {
					// The prefix core adds to a protected title; the safe title carries none.
					/** This filter is documented in wp-includes/post-template.php */
					/* translators: %s: the post title. Core's string. */
					$format = (string) apply_filters( 'protected_title_format', __( 'Protected: %s', 'default' ), $post ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own string and hook.
					$name   = sprintf( $format, $name );
				}
			} else {
				$name = trim( wp_strip_all_tags( get_the_title( $post ) ) );
			}
			if ( '' !== $name ) {
				$is_real = ! $hidden;
				$mode    = 'titled';
			}
		}

		if ( 'titled' !== $mode ) {
			if ( '' !== $thought ) {
				$name = $thought;
				$mode = 'thought';
			} else {
				$name = untitled_fallback_name( $post )['name'];
			}
		}

		$datetime = get_post_datetime( $post );

		return [
			'name'          => $name,
			'name_is_title' => $is_real,
			'thought'       => $thought,
			'mode'          => $mode,
			'protected'     => $protected,
			'dup_title'     => 'titled' === $mode && '' !== $thought && str_starts_with( self::plain( $thought ), self::plain( $name ) ),
			'date'          => (string) get_the_date( '', $post ),
			'datetime'      => false !== $datetime ? $datetime->format( 'c' ) : '',
		];
	}

	/**
	 * Render the note entry block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Unused.
	 * @param \WP_Block|null       $block      Block instance.
	 * @return string Markup.
	 */
	public static function render( array $attributes = [], string $content = '', ?\WP_Block $block = null ): string {
		$post = get_post( (int) ( $block->context['postId'] ?? get_the_ID() ) );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$parts   = self::parts( $post );
		$level   = max( 2, min( 6, (int) ( $attributes['headingLevel'] ?? 3 ) ) );
		$name_id = 'pkiw-note-n-' . $post->ID;
		$date_id = 'pkiw-note-d-' . $post->ID;
		$repeats = ( self::page_names()[ self::plain( $parts['name'] ) ] ?? 0 ) > 1;
		$wrapper = [ 'pkiw-note-entry', 'pkiw-note-entry--' . $parts['mode'] ];
		if ( $parts['dup_title'] ) {
			$wrapper[] = 'pkiw-note-entry--dup-title';
		}
		if ( $parts['protected'] ) {
			$wrapper[] = 'pkiw-note-entry--protected';
		}

		if ( 'thought' === $parts['mode'] ) {
			$link_text = sprintf( '<span class="pkiw-note-entry__thought p-summary" id="%1$s">%2$s</span>', esc_attr( $name_id ), esc_html( $parts['name'] ) );
		} else {
			$link_text = sprintf( '<span class="pkiw-note-entry__name" id="%1$s">%2$s</span>', esc_attr( $name_id ), esc_html( $parts['name'] ) );
		}

		$summary = 'titled' === $parts['mode'] && '' !== $parts['thought']
			? '<p class="pkiw-note-entry__thought p-summary">' . esc_html( $parts['thought'] ) . '</p>'
			: '';
		$author  = function_exists( __NAMESPACE__ . '\\entry_author_html' ) ? entry_author_html( $post ) : '';

		return sprintf(
			'<div %1$s><h%2$d class="pkiw-note-entry__title%3$s"><a class="u-url" href="%4$s"%5$s>%6$s</a></h%2$d>%7$s<p class="pkiw-note-entry__date"><time class="dt-published" id="%8$s" datetime="%9$s">%10$s</time></p><span class="pk-entry-props" hidden>%11$s</span></div>',
			get_block_wrapper_attributes( [ 'class' => implode( ' ', $wrapper ) ] ),
			$level,
			$parts['name_is_title'] ? ' p-name' : '',
			esc_url( (string) get_permalink( $post ) ),
			$repeats ? ' aria-labelledby="' . esc_attr( $name_id . ' ' . $date_id ) . '"' : '',
			$link_text,
			$summary,
			esc_attr( $date_id ),
			esc_attr( $parts['datetime'] ),
			esc_html( $parts['date'] ),
			$author
		);
	}

	/**
	 * A name or thought as plain lowercase text for comparing.
	 *
	 * Entities decode, typographic quotes become plain ones, runs of
	 * whitespace collapse.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function plain( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = strtr(
			$text,
			[
				'‘' => "'",
				'’' => "'",
				'“' => '"',
				'”' => '"',
			]
		);

		return mb_strtolower( trim( (string) preg_replace( '/\s+/', ' ', $text ) ) );
	}

	/**
	 * How many notes on the current page carry each name.
	 *
	 * Counted from the posts the page already holds, so it runs no query.
	 * The editor previews one note at a time and gets none.
	 *
	 * @return array<string, int> Normalized name => count.
	 */
	private static function page_names(): array {
		if ( Grouped_Archive::is_block_preview() ) {
			return [];
		}

		$ids = Grouped_Archive::current_page_ids();
		if ( [] === $ids ) {
			return [];
		}

		// Any post save moves last_changed, so an edited name is counted again.
		$key = wp_cache_get_last_changed( 'posts' ) . '|' . implode( ',', $ids );
		if ( ! isset( self::$page_names[ $key ] ) ) {
			$counts = [];
			foreach ( $ids as $id ) {
				$member = get_post( $id );
				if ( ! $member instanceof \WP_Post ) {
					continue;
				}
				$name            = self::plain( self::parts( $member )['name'] );
				$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
			}
			self::$page_names[ $key ] = $counts;
		}

		return self::$page_names[ $key ];
	}
}
