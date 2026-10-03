<?php
/**
 * Comic Card Block - Server-side Render
 *
 * A comic someone read. Renders in the two-layer pk-card system: plugin owns
 * structure (badge → label → title → issue → creators → status → media →
 * note → meta), theme owns paint via --pk-* custom properties. The card is an
 * h-cite carrying u-read-of, so the post's h-entry gets read-of; a `comics`
 * post with no card (a strip its author drew) gets none.
 *
 * Only stored fields render. Dates are labelled by the stored status:
 * a comic still being read shows "Started" and never a completion date.
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

use function PKIW\card_calendar_date;
use function PKIW\get_kind_icon_svg;
use function PKIW\get_kind_label;

$pkiw_title       = $attributes['title'] ?? '';
$pkiw_creators    = $attributes['creators'] ?? '';
$pkiw_series      = $attributes['series'] ?? '';
$pkiw_volume      = $attributes['volume'] ?? '';
$pkiw_issue       = $attributes['issueNumber'] ?? '';
$pkiw_publisher   = $attributes['publisher'] ?? '';
$pkiw_cover_image = $attributes['coverImage'] ?? '';
$pkiw_cover_alt   = $attributes['coverImageAlt'] ?? '';
$pkiw_source_url  = $attributes['sourceUrl'] ?? '';
$pkiw_read_status = $attributes['readStatus'] ?? 'reading';
$pkiw_rating      = isset( $attributes['rating'] ) ? (int) $attributes['rating'] : 0;
$pkiw_review      = $attributes['review'] ?? '';

$pkiw_status_labels = [
	'to-read'   => __( 'To read', 'post-kinds-for-indieweb-in-block-themes' ),
	'reading'   => __( 'Currently reading', 'post-kinds-for-indieweb-in-block-themes' ),
	'finished'  => __( 'Finished', 'post-kinds-for-indieweb-in-block-themes' ),
	'abandoned' => __( 'Set aside', 'post-kinds-for-indieweb-in-block-themes' ),
];
$pkiw_status_label  = $pkiw_status_labels[ $pkiw_read_status ] ?? '';

// A comic not started has no dates; one still being read has no end date.
$pkiw_is_ended = in_array( $pkiw_read_status, [ 'finished', 'abandoned' ], true );

list( $pkiw_started_iso, $pkiw_started_display ) = 'to-read' === $pkiw_read_status
	? [ '', '' ]
	: card_calendar_date( (string) ( $attributes['startedAt'] ?? '' ) );

list( $pkiw_finished_iso, $pkiw_finished_display ) = $pkiw_is_ended
	? card_calendar_date( (string) ( $attributes['finishedAt'] ?? '' ) )
	: [ '', '' ];

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-comics h-cite u-read-of',
	]
);

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'comics' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<span class="pk-kindlabel"><?php echo esc_html( get_kind_label( __( 'Comic', 'post-kinds-for-indieweb-in-block-themes' ), 'comics', 'comic-card' ) ); ?></span>

		<div class="pk-caption">
			<?php if ( $pkiw_title ) : ?>
				<h2 class="pk-title p-name">
					<?php if ( $pkiw_source_url ) : ?>
						<a class="u-url" href="<?php echo esc_url( $pkiw_source_url ); ?>" target="_blank" rel="noopener noreferrer"<?php echo \PKIW\pkiw_new_tab_label_attr( $pkiw_title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $pkiw_title ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $pkiw_title ); ?>
					<?php endif; ?>
				</h2>
			<?php endif; ?>

			<?php if ( $pkiw_series || $pkiw_volume || $pkiw_issue ) : ?>
				<p class="pk-sub pk-comic-issue">
					<?php if ( $pkiw_series ) : ?>
						<span class="pk-comic-series"><?php echo esc_html( $pkiw_series ); ?></span>
					<?php endif; ?>
					<?php if ( $pkiw_volume ) : ?>
						<span class="pk-comic-volume"><?php echo esc_html( sprintf( /* translators: %s: volume number */ __( 'Vol. %s', 'post-kinds-for-indieweb-in-block-themes' ), $pkiw_volume ) ); ?></span>
					<?php endif; ?>
					<?php if ( $pkiw_issue ) : ?>
						<span class="pk-comic-number"><?php echo esc_html( sprintf( /* translators: %s: issue number */ __( '#%s', 'post-kinds-for-indieweb-in-block-themes' ), $pkiw_issue ) ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( $pkiw_creators ) : ?>
				<p class="pk-sub">
					<span class="p-author h-card"><span class="p-name"><?php echo esc_html( $pkiw_creators ); ?></span></span>
				</p>
			<?php endif; ?>

			<?php if ( $pkiw_status_label || $pkiw_publisher ) : ?>
				<p class="pk-sub">
					<?php if ( $pkiw_status_label ) : ?>
						<span class="pk-comic-status"><?php echo esc_html( $pkiw_status_label ); ?></span>
					<?php endif; ?>
					<?php if ( $pkiw_publisher ) : ?>
						<span class="pk-comic-publisher"><?php echo esc_html( $pkiw_publisher ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>

		<?php if ( $pkiw_rating > 0 ) : ?>
			<div class="pk-stars" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: rating out of five. */ __( 'Rated %d of 5', 'post-kinds-for-indieweb-in-block-themes' ), $pkiw_rating ) ); ?>">
				<?php for ( $pkiw_i = 1; $pkiw_i <= 5; $pkiw_i++ ) : ?>
					<svg class="<?php echo $pkiw_i <= $pkiw_rating ? '' : 'off'; ?>" viewBox="0 0 24 24" fill="currentColor" focusable="false"><path d="M12 2l3 6.5 7 .6-5.3 4.6 1.6 6.8L12 17l-6.9 3.5 1.6-6.8L1.4 9.1l7-.6z"/></svg>
				<?php endfor; ?>
			</div>
			<data class="p-rating" value="<?php echo esc_attr( $pkiw_rating ); ?>" hidden></data>
		<?php endif; ?>

		<?php if ( $pkiw_cover_image ) : ?>
			<div class="pk-media">
				<img class="pk-thumb--poster u-photo" src="<?php echo esc_url( $pkiw_cover_image ); ?>" alt="<?php echo esc_attr( $pkiw_cover_alt ? $pkiw_cover_alt : sprintf( /* translators: %s: comic title */ __( 'Cover of %s', 'post-kinds-for-indieweb-in-block-themes' ), $pkiw_title ? $pkiw_title : get_the_title() ) ); ?>" loading="lazy" />
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_review ) : ?>
			<div class="pk-note p-content"><?php echo wp_kses_post( $pkiw_review ); ?></div>
		<?php endif; ?>

		<div class="pk-meta">
			<?php if ( $pkiw_started_iso ) : ?>
				<time class="pk-comic-started" datetime="<?php echo esc_attr( $pkiw_started_iso ); ?>">
					<?php
					printf(
						/* translators: %s: date */
						esc_html__( 'Started: %s', 'post-kinds-for-indieweb-in-block-themes' ),
						esc_html( $pkiw_started_display )
					);
					?>
				</time>
			<?php endif; ?>
			<?php if ( $pkiw_started_iso && $pkiw_finished_iso ) : ?>
				<span class="pk-dot"></span>
			<?php endif; ?>
			<?php if ( $pkiw_finished_iso && 'finished' === $pkiw_read_status ) : ?>
				<time class="pk-comic-finished dt-published" datetime="<?php echo esc_attr( $pkiw_finished_iso ); ?>">
					<?php
					printf(
						/* translators: %s: date */
						esc_html__( 'Finished: %s', 'post-kinds-for-indieweb-in-block-themes' ),
						esc_html( $pkiw_finished_display )
					);
					?>
				</time>
			<?php elseif ( $pkiw_finished_iso ) : ?>
				<time class="pk-comic-finished" datetime="<?php echo esc_attr( $pkiw_finished_iso ); ?>">
					<?php
					printf(
						/* translators: %s: date */
						esc_html__( 'Set aside: %s', 'post-kinds-for-indieweb-in-block-themes' ),
						esc_html( $pkiw_finished_display )
					);
					?>
				</time>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $pkiw_source_url ) : ?>
		<data value="<?php echo esc_attr( $pkiw_source_url ); ?>" hidden></data>
	<?php endif; ?>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
