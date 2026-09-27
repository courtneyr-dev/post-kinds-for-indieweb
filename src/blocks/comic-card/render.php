<?php
/**
 * Comic Card Block - Server-side Render
 *
 * @package PKIW
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content (empty for dynamic blocks).
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- render.php variables are scoped by WordPress block rendering.

use function PKIW\get_kind_icon_svg;
use function PKIW\get_kind_label;

$pkiw_series_title  = $attributes['seriesTitle'] ?? '';
$pkiw_issue_title   = $attributes['issueTitle'] ?? '';
$pkiw_volume        = $attributes['volumeNumber'] ?? '';
$pkiw_issue_number  = $attributes['issueNumber'] ?? '';
$pkiw_creators      = $attributes['creatorNames'] ?? '';
$pkiw_publisher     = $attributes['publisher'] ?? '';
$pkiw_publish_date  = $attributes['publishDate'] ?? '';
$pkiw_cover_image   = $attributes['coverImage'] ?? '';
$pkiw_cover_alt     = $attributes['coverImageAlt'] ?? '';
$pkiw_comic_url     = $attributes['comicUrl'] ?? '';
$pkiw_read_status   = $attributes['readStatus'] ?? 'finished';
$pkiw_rating        = isset( $attributes['rating'] ) ? (int) $attributes['rating'] : 0;
$pkiw_read_at       = $attributes['readAt'] ?? '';
$pkiw_review        = $attributes['review'] ?? '';
$pkiw_layout        = $attributes['layout'] ?? 'horizontal';
$pkiw_status_labels = [
	'to-read'   => __( 'To Read', 'post-kinds-for-indieweb-in-block-themes' ),
	'reading'   => __( 'Currently Reading', 'post-kinds-for-indieweb-in-block-themes' ),
	'finished'  => __( 'Finished', 'post-kinds-for-indieweb-in-block-themes' ),
	'abandoned' => __( 'Abandoned', 'post-kinds-for-indieweb-in-block-themes' ),
];
$pkiw_status_label  = $pkiw_status_labels[ $pkiw_read_status ] ?? '';

$pkiw_read_iso     = '';
$pkiw_read_display = '';
if ( $pkiw_read_at ) {
	$pkiw_timestamp = strtotime( $pkiw_read_at );
	if ( $pkiw_timestamp ) {
		$pkiw_read_iso     = gmdate( 'c', $pkiw_timestamp );
		$pkiw_read_display = wp_date( get_option( 'date_format' ), $pkiw_timestamp );
	}
}

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-comics h-cite u-read-of layout-' . sanitize_html_class( $pkiw_layout ),
	]
);

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'comics' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<p class="pk-kindlabel"><?php echo esc_html( get_kind_label( __( 'Comic', 'post-kinds-for-indieweb-in-block-themes' ), 'comics', 'comic-card' ) ); ?></p>

		<?php if ( $pkiw_series_title ) : ?>
			<h2 class="pk-title p-name">
				<?php if ( $pkiw_comic_url ) : ?>
					<a class="u-url" href="<?php echo esc_url( $pkiw_comic_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pkiw_series_title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $pkiw_series_title ); ?>
				<?php endif; ?>
			</h2>
		<?php endif; ?>

		<?php if ( $pkiw_issue_title ) : ?>
			<p class="pk-sub"><?php echo esc_html( $pkiw_issue_title ); ?></p>
		<?php endif; ?>

		<?php if ( $pkiw_volume || $pkiw_issue_number ) : ?>
			<p class="pk-sub">
				<?php
				if ( $pkiw_volume ) {
					printf(
						/* translators: %s: comic volume identifier. */
						esc_html__( 'Vol. %s', 'post-kinds-for-indieweb-in-block-themes' ),
						esc_html( $pkiw_volume )
					);
				}
				if ( $pkiw_volume && $pkiw_issue_number ) {
					echo ' &middot; ';
				}
				if ( $pkiw_issue_number ) {
					printf(
						/* translators: %s: comic issue number. */
						esc_html__( '#%s', 'post-kinds-for-indieweb-in-block-themes' ),
						esc_html( $pkiw_issue_number )
					);
				}
				?>
			</p>
		<?php endif; ?>

		<?php if ( $pkiw_creators ) : ?>
			<p class="pk-sub">
				<span class="p-author h-card"><span class="p-name"><?php echo esc_html( $pkiw_creators ); ?></span></span>
			</p>
		<?php endif; ?>

		<?php if ( $pkiw_status_label || $pkiw_publisher || $pkiw_publish_date ) : ?>
			<p class="pk-sub">
				<?php echo esc_html( implode( ' — ', array_filter( [ $pkiw_status_label, trim( $pkiw_publisher . ( $pkiw_publisher && $pkiw_publish_date ? ', ' : '' ) . $pkiw_publish_date ) ] ) ) ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $pkiw_rating > 0 ) : ?>
			<div class="pk-stars p-rating" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: rating out of five. */ __( 'Rated %d of 5', 'post-kinds-for-indieweb-in-block-themes' ), $pkiw_rating ) ); ?>">
				<?php for ( $pkiw_i = 1; $pkiw_i <= 5; $pkiw_i++ ) : ?>
					<svg class="<?php echo $pkiw_i <= $pkiw_rating ? '' : 'off'; ?>" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3 6.5 7 .6-5.3 4.6 1.6 6.8L12 17l-6.9 3.5 1.6-6.8L1.4 9.1l7-.6z"/></svg>
				<?php endfor; ?>
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_cover_image ) : ?>
			<div class="pk-media">
				<img class="pk-thumb--poster u-photo" src="<?php echo esc_url( $pkiw_cover_image ); ?>" alt="<?php echo esc_attr( $pkiw_cover_alt ? $pkiw_cover_alt : sprintf( /* translators: %s: comic series title. */ __( 'Cover of %s', 'post-kinds-for-indieweb-in-block-themes' ), $pkiw_series_title ) ); ?>" loading="lazy" />
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_review ) : ?>
			<div class="pk-note p-content"><?php echo wp_kses_post( $pkiw_review ); ?></div>
		<?php endif; ?>

		<?php if ( $pkiw_read_iso ) : ?>
			<div class="pk-meta">
				<time class="dt-published" datetime="<?php echo esc_attr( $pkiw_read_iso ); ?>">
					<?php
					printf(
						/* translators: %s: date. */
						esc_html__( 'Read: %s', 'post-kinds-for-indieweb-in-block-themes' ),
						esc_html( $pkiw_read_display )
					);
					?>
				</time>
			</div>
		<?php endif; ?>
	</div>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
