<?php
/**
 * Media Stats Block (PHP-only, WP 7.0+)
 *
 * Shows total listens, watches, and reads for a given period.
 * Registered entirely in PHP — no JavaScript build step required.
 *
 * @package PostKindsForIndieWeb
 * @since   1.3.0
 */

declare(strict_types=1);

namespace PostKindsForIndieWeb\Blocks;

use PostKindsForIndieWeb\Taxonomy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media Stats block.
 *
 * @since 1.3.0
 */
final class Media_Stats {

	/**
	 * Transient TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	private const CACHE_TTL = HOUR_IN_SECONDS;

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
			'post-kinds/media-stats',
			[
				'api_version'     => 3,
				'title'           => __( 'Media Stats', 'post-kinds-for-indieweb' ),
				'description'     => __( 'Shows total listens, watches, and reads for a period.', 'post-kinds-for-indieweb' ),
				'category'        => 'widgets',
				'icon'            => 'chart-bar',
				'keywords'        => [ 'stats', 'statistics', 'listen', 'watch', 'read', 'indieweb' ],
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
					'period' => [
						'type'    => 'string',
						'default' => 'month',
						'enum'    => [ 'week', 'month', 'year', 'all' ],
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
		$period = in_array( $attributes['period'], [ 'week', 'month', 'year', 'all' ], true )
			? $attributes['period']
			: 'month';

		$stats = $this->get_stats( $period );

		$wrapper_attrs = get_block_wrapper_attributes(
			[
				'class' => 'pk-media-stats',
			]
		);

		$period_labels = [
			'week'  => __( 'This Week', 'post-kinds-for-indieweb' ),
			'month' => __( 'This Month', 'post-kinds-for-indieweb' ),
			'year'  => __( 'This Year', 'post-kinds-for-indieweb' ),
			'all'   => __( 'All Time', 'post-kinds-for-indieweb' ),
		];

		$kinds = [
			'listen' => [
				'icon'  => '&#127925;',
				'label' => __( 'Listens', 'post-kinds-for-indieweb' ),
			],
			'watch'  => [
				'icon'  => '&#127916;',
				'label' => __( 'Watches', 'post-kinds-for-indieweb' ),
			],
			'read'   => [
				'icon'  => '&#128214;',
				'label' => __( 'Reads', 'post-kinds-for-indieweb' ),
			],
		];

		ob_start();
		?>
		<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<p class="pk-media-stats__period"><?php echo esc_html( $period_labels[ $period ] ); ?></p>
			<dl class="pk-media-stats__list">
				<?php foreach ( $kinds as $kind => $meta ) : ?>
					<div class="pk-media-stats__item pk-media-stats__item--<?php echo esc_attr( $kind ); ?>">
						<dt>
							<span aria-hidden="true"><?php echo $meta['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<?php echo esc_html( $meta['label'] ); ?>
						</dt>
						<dd><?php echo esc_html( number_format_i18n( $stats[ $kind ] ) ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get cached post counts per kind for a period.
	 *
	 * @since 1.3.0
	 *
	 * @param string $period One of week, month, year, all.
	 * @return array<string, int> Counts keyed by kind slug.
	 */
	private function get_stats( string $period ): array {
		$cache_key = 'pk_media_stats_' . $period;
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$stats = [];

		foreach ( [ 'listen', 'watch', 'read' ] as $kind ) {
			$args = [
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => Taxonomy::TAXONOMY,
						'field'    => 'slug',
						'terms'    => $kind,
					],
				],
			];

			if ( 'all' !== $period ) {
				$args['date_query'] = [
					[
						'after' => $this->get_date_boundary( $period ),
					],
				];
			}

			$query          = new \WP_Query( $args );
			$stats[ $kind ] = $query->post_count;
		}

		set_transient( $cache_key, $stats, self::CACHE_TTL );

		return $stats;
	}

	/**
	 * Get the date boundary string for a period.
	 *
	 * @since 1.3.0
	 *
	 * @param string $period One of week, month, year.
	 * @return string Date string for WP_Query date_query 'after'.
	 */
	private function get_date_boundary( string $period ): string {
		switch ( $period ) {
			case 'week':
				return '1 week ago';
			case 'year':
				return '1 year ago';
			case 'month':
			default:
				return '1 month ago';
		}
	}
}
