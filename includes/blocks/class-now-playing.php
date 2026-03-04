<?php
/**
 * Now Playing Block (PHP-only, WP 7.0+)
 *
 * Sidebar widget showing the most recent listen or watch post.
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
 * Now Playing block.
 *
 * @since 1.3.0
 */
final class Now_Playing {

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
			'post-kinds/now-playing',
			[
				'api_version'     => 3,
				'title'           => __( 'Now Playing', 'post-kinds-for-indieweb' ),
				'description'     => __( 'Shows the most recent listen or watch post.', 'post-kinds-for-indieweb' ),
				'category'        => 'widgets',
				'icon'            => 'format-audio',
				'keywords'        => [ 'music', 'listen', 'watch', 'media', 'indieweb' ],
				'supports'        => [
					'html'       => false,
					'align'      => true,
					'alignWide'  => false,
					'color'      => [
						'background' => true,
						'text'       => true,
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
					'kind' => [
						'type'    => 'string',
						'default' => 'listen',
						'enum'    => [ 'listen', 'watch' ],
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
		$kind = in_array( $attributes['kind'], [ 'listen', 'watch' ], true )
			? $attributes['kind']
			: 'listen';

		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				'posts_per_page' => 1,
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

		$post    = $query->posts[0];
		$post_id = $post->ID;
		$prefix  = Meta_Fields::PREFIX;

		if ( 'listen' === $kind ) {
			$title = get_post_meta( $post_id, $prefix . 'listen_track', true );
			$sub   = get_post_meta( $post_id, $prefix . 'listen_artist', true );
			$cover = get_post_meta( $post_id, $prefix . 'listen_cover', true );
			$url   = get_post_meta( $post_id, $prefix . 'listen_url', true );
			$icon  = '&#127925;';
			$label = __( 'Listening', 'post-kinds-for-indieweb' );
		} else {
			$title = get_post_meta( $post_id, $prefix . 'watch_title', true );
			$sub   = get_post_meta( $post_id, $prefix . 'watch_year', true );
			$cover = get_post_meta( $post_id, $prefix . 'watch_poster', true );
			$url   = get_post_meta( $post_id, $prefix . 'watch_url', true );
			$icon  = '&#127916;';
			$label = __( 'Watching', 'post-kinds-for-indieweb' );
		}

		if ( ! $title ) {
			return '';
		}

		$wrapper_attrs = get_block_wrapper_attributes(
			[
				'class' => 'pk-now-playing pk-now-playing--' . esc_attr( $kind ),
			]
		);

		ob_start();
		?>
		<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<p class="pk-now-playing__label">
				<span aria-hidden="true"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php echo esc_html( $label ); ?>
			</p>
			<?php if ( $cover ) : ?>
				<img
					src="<?php echo esc_url( $cover ); ?>"
					alt="<?php echo esc_attr( $title ); ?>"
					class="pk-now-playing__cover"
					loading="lazy"
					width="64"
					height="64"
				/>
			<?php endif; ?>
			<div class="pk-now-playing__info">
				<?php if ( $url ) : ?>
					<a href="<?php echo esc_url( $url ); ?>" class="pk-now-playing__title" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $title ); ?>
					</a>
				<?php else : ?>
					<span class="pk-now-playing__title"><?php echo esc_html( $title ); ?></span>
				<?php endif; ?>
				<?php if ( $sub ) : ?>
					<span class="pk-now-playing__sub"><?php echo esc_html( $sub ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
