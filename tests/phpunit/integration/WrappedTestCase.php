<?php
/** Shared Wrapped integration fixtures. @package PKIW */
declare(strict_types=1);

use PKIW\Taxonomy;
use PKIW\Wrapped_Blocks;
use PKIW\Wrapped_Post;

/** Shared fixture helpers for the X-P267 acceptance classes. */
abstract class WrappedTestCase extends WP_UnitTestCase {
	/** Ensure every kind used by the suite exists. */
	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		foreach ( [ 'read', 'listen', 'watch', 'comics', 'play', 'exercise', 'sleep', 'acquisition', 'checkin', 'follow', 'note' ] as $slug ) {
			if ( ! term_exists( $slug, Taxonomy::TAXONOMY ) ) {
				wp_insert_term( ucfirst( $slug ), Taxonomy::TAXONOMY, [ 'slug' => $slug ] );
			}
		}
		$note = get_term_by( 'slug', 'note', Taxonomy::TAXONOMY );
		if ( $note instanceof WP_Term ) {
			update_option( 'default_term_kind', $note->term_id );
		}
	}

	/** Create one kind post with explicit status, date, and stored meta. */
	protected function kind_post( string $kind, int $year = 2026, array $meta = [], array $post = [] ): int {
		$defaults = [
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_title'    => ucfirst( $kind ) . ' fixture',
			'post_date'     => $year . '-06-15 12:00:00',
			'post_date_gmt' => $year . '-06-15 12:00:00',
		];
		$id       = (int) self::factory()->post->create( array_merge( $defaults, $post ) );
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, str_starts_with( (string) $key, '_pkiw_' ) ? (string) $key : '_pkiw_' . $key, $value );
		}

		return $id;
	}

	/** Create one finished Read post. */
	protected function read_post( int $year = 2026, array $meta = [], array $post = [] ): int {
		return $this->kind_post(
			'read',
			$year,
			array_merge(
				[
					'read_status'      => 'finished',
					'read_finished_at' => $year . '-06-15',
					'read_pages'       => 100,
					'read_rating'      => 4,
				],
				$meta
			),
			$post
		);
	}

	/** Create a Wrapped post whose save hook can synchronize the target meta. */
	protected function wrapped_post( string $kind = 'read', int $year = 2026, array $attrs = [], array $post = [] ): int {
		$attributes = array_merge( [ 'kind' => $kind, 'year' => $year ], $attrs );
		$content    = '<!-- wp:post-kinds-indieweb/wrapped ' . wp_json_encode( $attributes ) . ' /-->';
		$defaults   = [
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_title'    => ucfirst( $kind ) . ' ' . $year . ' Wrapped',
			'post_content'  => $content,
			'post_date'     => $year . '-01-15 12:00:00',
			'post_date_gmt' => $year . '-01-15 12:00:00',
		];

		return (int) self::factory()->post->create( array_merge( $defaults, $post ) );
	}

	/** Return one metric row by key. */
	protected function metric( array $data, string $key ): ?array {
		foreach ( $data['metrics'] ?? [] as $metric ) {
			if ( $key === ( $metric['key'] ?? null ) ) {
				return $metric;
			}
		}

		return null;
	}

	/** Render the main block against an explicit host post. */
	protected function render_wrapped( int $post_id, array $attrs = [] ): string {
		$kind = (string) get_post_meta( $post_id, Wrapped_Post::KIND_META, true );
		$year = (int) get_post_meta( $post_id, Wrapped_Post::YEAR_META, true );
		$block = new WP_Block(
			[
				'blockName'    => 'post-kinds-indieweb/wrapped',
				'attrs'        => array_merge( [ 'kind' => $kind, 'year' => $year ], $attrs ),
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[ 'postId' => $post_id, 'postType' => 'post' ]
		);

		return $block->render();
	}

	/** Resolve one binding key against an explicit post context. */
	protected function binding( int $post_id, string $key, array $args = [], array $context = [] ) {
		$block = new WP_Block(
			[
				'blockName'    => 'core/paragraph',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '<p></p>',
				'innerContent' => [ '<p></p>' ],
			],
			array_merge( [ 'postId' => $post_id, 'postType' => 'post' ], $context )
		);

		// Core adds a source's uses_context while it processes bindings (class-wp-block.php:343-349); a direct call has to do the same.
		$block->context = array_merge( $block->context, array_merge( [ 'postId' => $post_id, 'postType' => 'post' ], $context ) );

		return Wrapped_Blocks::binding_value( array_merge( [ 'key' => $key ], $args ), $block, 'content' );
	}

	/** Render the strip block through WP_Block so block supports have a current block. */
	protected function render_strip( array $attrs = [], string $inner = '' ): string {
		$parsed = parse_blocks( '<!-- wp:post-kinds-indieweb/wrapped-strip ' . ( [] === $attrs ? '' : wp_json_encode( $attrs ) . ' ' ) . '-->' . $inner . '<!-- /wp:post-kinds-indieweb/wrapped-strip -->' );

		return ( new WP_Block( $parsed[0] ) )->render();
	}

	/** Return accessible text with tags removed. */
	protected function html_text( string $html ): string {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) ?? '' );
	}

	/** Count matching tags through the HTML Tag Processor. */
	protected function tag_count( string $html, string $tag, ?string $class_name = null ): int {
		$processor = new WP_HTML_Tag_Processor( $html );
		$count     = 0;
		$query     = [ 'tag_name' => $tag ];
		if ( null !== $class_name ) {
			$query['class_name'] = $class_name;
		}
		while ( $processor->next_tag( $query ) ) {
			++$count;
		}

		return $count;
	}
}
