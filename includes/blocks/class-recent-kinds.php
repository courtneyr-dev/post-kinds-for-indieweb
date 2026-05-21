<?php
/**
 * Recent Kinds Block (PHP-only, WP 7.0+)
 *
 * Query block showing recent posts of a specific kind.
 * Registered entirely in PHP — no JavaScript build step required.
 *
 * @package PostKindsForIndieWeb
 * @since   1.3.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb\Blocks;

use PostKindsForIndieWeb\Meta_Fields;
use PostKindsForIndieWeb\Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recent Kinds block.
 *
 * @since 1.3.0
 */
final class Recent_Kinds {

	/**
	 * Initialize the block.
	 *
	 * @since 1.3.0
	 */
	public function __construct() {
		if ( ! self::is_supported() ) {
			return;
		}

		add_action( 'init', [ $this, 'register' ] );
	}

	/**
	 * Check if the current WordPress version supports PHP-only blocks.
	 *
	 * @since 1.3.0
	 *
	 * @return bool True if WordPress 7.0 or later.
	 */
	public static function is_supported(): bool {
		return version_compare( get_bloginfo( 'version' ), '7.0', '>=' );
	}

	/**
	 * Register the block type.
	 *
	 * @since 1.3.0
	 */
	public function register(): void {
		register_block_type(
			'post-kinds/recent-kinds',
			[
				'api_version'     => 3,
				'title'           => __( 'Recent Kinds', 'post-kinds-for-indieweb' ),
				'description'     => __( 'Shows recent posts of a specific kind.', 'post-kinds-for-indieweb' ),
				'category'        => 'widgets',
				'icon'            => 'list-view',
				'keywords'        => [ 'recent', 'posts', 'kind', 'listen', 'watch', 'read', 'indieweb' ],
				'supports'        => [
					'html'       => false,
					'align'      => true,
					'alignWide'  => false,
					'color'      => [
						'background' => true,
						'text'       => true,
						'link'       => true,
					],
					'spacing'    => [
						'padding' => true,
						'margin'  => true,
					],
					'typography' => [
						'fontSize' => true,
					],
				],
				'attributes'      => [
					'kind'      => [
						'type'    => 'string',
						'default' => 'listen',
					],
					'count'     => [
						'type'    => 'integer',
						'default' => 5,
					],
					'showCover' => [
						'type'    => 'boolean',
						'default' => true,
					],
				],
				'render_callback' => [ $this, 'render' ],
			]
		);
	}

	/**
	 * Render the block on the front end.
	 *
	 * @since 1.3.0
	 *
	 * @param array     $attributes Block attributes.
	 * @param string    $content    Block content.
	 * @param \WP_Block $block      Block instance.
	 * @return string Rendered HTML.
	 */
	public function render( array $attributes, string $content, \WP_Block $block ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$kind       = sanitize_key( $attributes['kind'] );
		$count      = max( 1, min( 20, absint( $attributes['count'] ) ) );
		$show_cover = (bool) $attributes['showCover'];

		if ( ! Taxonomy::is_valid_kind( $kind ) ) {
			$kind = 'listen';
		}

		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				'posts_per_page' => $count,
				'post_status'    => 'publish',
				'no_found_rows'  => true,
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => Taxonomy::TAXONOMY,
						'field'    => 'slug',
						'terms'    => $kind,
					],
				],
			]
		);

		if ( ! $query->have_posts() ) {
			return '';
		}

		$wrapper_attrs = get_block_wrapper_attributes(
			[
				'class' => 'pk-recent-kinds pk-recent-kinds--' . esc_attr( $kind ),
			]
		);

		$prefix = Meta_Fields::PREFIX;

		ob_start();
		?>
		<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<ul class="pk-recent-kinds__list">
				<?php foreach ( $query->posts as $post ) : ?>
					<?php
					$title = $this->get_display_title( $post->ID, $kind, $prefix );
					$cover = $show_cover ? $this->get_cover_url( $post->ID, $kind, $prefix ) : '';

					if ( ! $title ) {
						$title = get_the_title( $post );
					}
					?>
					<li class="pk-recent-kinds__item">
						<?php if ( $cover ) : ?>
							<img
								src="<?php echo esc_url( $cover ); ?>"
								alt="<?php echo esc_attr( $title ); ?>"
								class="pk-recent-kinds__cover"
								loading="lazy"
								width="48"
								height="48"
							/>
						<?php endif; ?>
						<a href="<?php echo esc_url( get_permalink( $post ) ); ?>" class="pk-recent-kinds__link">
							<?php echo esc_html( $title ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get the display title for a post based on its kind.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $kind    Kind slug.
	 * @param string $prefix  Meta key prefix.
	 * @return string Display title or empty string.
	 */
	private function get_display_title( int $post_id, string $kind, string $prefix ): string {
		$map = [
			'listen' => 'listen_track',
			'watch'  => 'watch_title',
			'read'   => 'read_title',
			'jam'    => 'jam_track',
			'play'   => 'play_title',
		];

		if ( ! isset( $map[ $kind ] ) ) {
			return '';
		}

		return (string) get_post_meta( $post_id, $prefix . $map[ $kind ], true );
	}

	/**
	 * Get the cover image URL for a post based on its kind.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $kind    Kind slug.
	 * @param string $prefix  Meta key prefix.
	 * @return string Cover URL or empty string.
	 */
	private function get_cover_url( int $post_id, string $kind, string $prefix ): string {
		$map = [
			'listen' => 'listen_cover',
			'watch'  => 'watch_poster',
			'read'   => 'read_cover',
			'jam'    => 'jam_cover',
			'play'   => 'play_cover',
		];

		if ( ! isset( $map[ $kind ] ) ) {
			return '';
		}

		return (string) get_post_meta( $post_id, $prefix . $map[ $kind ], true );
	}
}
