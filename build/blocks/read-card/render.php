<?php
/**
 * Read Card Block - Server-side Render
 *
 * Renders the read card in the two-layer pk-card system: plugin owns
 * structure (badge → label → title → sub → media → note → meta), theme owns
 * paint via --pk-* custom properties.
 *
 * @package PostKindsForIndieWeb
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content (empty for dynamic blocks).
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- render.php variables are scoped by WordPress block rendering.

use function PostKindsForIndieWeb\get_kind_icon_svg;

$pkiw_book_title     = $attributes['bookTitle'] ?? '';
$pkiw_author_name    = $attributes['authorName'] ?? '';
$pkiw_isbn           = $attributes['isbn'] ?? '';
$pkiw_publisher      = $attributes['publisher'] ?? '';
$pkiw_publish_date   = $attributes['publishDate'] ?? '';
$pkiw_page_count     = isset( $attributes['pageCount'] ) ? (int) $attributes['pageCount'] : 0;
$pkiw_current_page   = isset( $attributes['currentPage'] ) ? (int) $attributes['currentPage'] : 0;
$pkiw_cover_image    = $attributes['coverImage'] ?? '';
$pkiw_cover_alt      = $attributes['coverImageAlt'] ?? '';
$pkiw_book_url       = $attributes['bookUrl'] ?? '';
$pkiw_openlibrary_id = $attributes['openlibraryId'] ?? '';
$pkiw_read_status    = $attributes['readStatus'] ?? 'to-read';
$pkiw_rating         = isset( $attributes['rating'] ) ? (int) $attributes['rating'] : 0;
$pkiw_started_at     = $attributes['startedAt'] ?? '';
$pkiw_finished_at    = $attributes['finishedAt'] ?? '';
$pkiw_review         = $attributes['review'] ?? '';

$pkiw_progress_percent = ( $pkiw_page_count > 0 && $pkiw_current_page > 0 )
	? min( 100, (int) round( ( $pkiw_current_page / $pkiw_page_count ) * 100 ) )
	: 0;

$pkiw_status_labels = [
	'to-read'   => __( 'To Read', 'post-kinds-for-indieweb' ),
	'reading'   => __( 'Currently Reading', 'post-kinds-for-indieweb' ),
	'finished'  => __( 'Finished', 'post-kinds-for-indieweb' ),
	'abandoned' => __( 'Abandoned', 'post-kinds-for-indieweb' ),
];
$pkiw_status_label  = $pkiw_status_labels[ $pkiw_read_status ] ?? '';

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-read h-cite',
	]
);

// Format dates.
$pkiw_started_iso     = '';
$pkiw_started_display = '';
if ( $pkiw_started_at ) {
	$pkiw_ts = strtotime( $pkiw_started_at );
	if ( $pkiw_ts ) {
		$pkiw_started_iso     = gmdate( 'c', $pkiw_ts );
		$pkiw_started_display = wp_date( get_option( 'date_format' ), $pkiw_ts );
	}
}
$pkiw_finished_iso     = '';
$pkiw_finished_display = '';
if ( $pkiw_finished_at ) {
	$pkiw_ts = strtotime( $pkiw_finished_at );
	if ( $pkiw_ts ) {
		$pkiw_finished_iso     = gmdate( 'c', $pkiw_ts );
		$pkiw_finished_display = wp_date( get_option( 'date_format' ), $pkiw_ts );
	}
}

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'read' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<p class="pk-kindlabel"><?php esc_html_e( 'Read', 'post-kinds-for-indieweb' ); ?></p>

		<?php if ( $pkiw_book_title ) : ?>
			<h2 class="pk-title p-name">
				<?php if ( $pkiw_book_url ) : ?>
					<a class="u-url" href="<?php echo esc_url( $pkiw_book_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pkiw_book_title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $pkiw_book_title ); ?>
				<?php endif; ?>
			</h2>
		<?php endif; ?>

		<?php if ( $pkiw_author_name ) : ?>
			<p class="pk-sub">
				<span class="p-author h-card"><span class="p-name"><?php echo esc_html( $pkiw_author_name ); ?></span></span>
			</p>
		<?php endif; ?>

		<?php if ( $pkiw_status_label || $pkiw_publisher || $pkiw_publish_date ) : ?>
			<p class="pk-sub">
				<?php if ( $pkiw_status_label ) : ?>
					<span><?php echo esc_html( $pkiw_status_label ); ?></span>
				<?php endif; ?>
				<?php
				if ( $pkiw_status_label && ( $pkiw_publisher || $pkiw_publish_date ) ) :
					?>
					&mdash; <?php endif; ?>
				<?php if ( $pkiw_publisher ) : ?>
					<span><?php echo esc_html( $pkiw_publisher ); ?></span>
				<?php endif; ?>
				<?php
				if ( $pkiw_publisher && $pkiw_publish_date ) {
					echo ', ';
				}
				?>
				<?php if ( $pkiw_publish_date ) : ?>
					<span><?php echo esc_html( $pkiw_publish_date ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( 'reading' === $pkiw_read_status && $pkiw_progress_percent > 0 ) : ?>
			<div class="pk-progress">
				<div class="pk-progress-bar">
					<div
						class="pk-progress-fill"
						style="width: <?php echo esc_attr( (string) $pkiw_progress_percent ); ?>%"
						role="progressbar"
						aria-valuenow="<?php echo esc_attr( (string) $pkiw_progress_percent ); ?>"
						aria-valuemin="0"
						aria-valuemax="100"
					></div>
				</div>
				<span class="pk-progress-text">
					<?php
					printf(
						/* translators: 1: current page, 2: total pages, 3: percent */
						esc_html__( '%1$d of %2$d pages (%3$d%%)', 'post-kinds-for-indieweb' ),
						(int) $pkiw_current_page,
						(int) $pkiw_page_count,
						(int) $pkiw_progress_percent
					);
					?>
				</span>
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_rating > 0 ) : ?>
			<div class="pk-stars p-rating" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: rating out of five. */ __( 'Rated %d of 5', 'post-kinds-for-indieweb' ), $pkiw_rating ) ); ?>">
				<?php for ( $pkiw_i = 1; $pkiw_i <= 5; $pkiw_i++ ) : ?>
					<svg class="<?php echo $pkiw_i <= $pkiw_rating ? '' : 'off'; ?>" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3 6.5 7 .6-5.3 4.6 1.6 6.8L12 17l-6.9 3.5 1.6-6.8L1.4 9.1l7-.6z"/></svg>
				<?php endfor; ?>
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_cover_image ) : ?>
			<div class="pk-media">
				<img class="pk-thumb--poster u-photo" src="<?php echo esc_url( $pkiw_cover_image ); ?>" alt="<?php echo esc_attr( $pkiw_cover_alt ? $pkiw_cover_alt : sprintf( /* translators: %s: book title */ __( 'Cover of %s', 'post-kinds-for-indieweb' ), $pkiw_book_title ) ); ?>" loading="lazy" />
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_review ) : ?>
			<div class="pk-note p-content"><?php echo wp_kses_post( $pkiw_review ); ?></div>
		<?php endif; ?>

		<div class="pk-meta">
			<?php if ( $pkiw_started_iso ) : ?>
				<time datetime="<?php echo esc_attr( $pkiw_started_iso ); ?>">
					<?php
					printf(
						/* translators: %s: date */
						esc_html__( 'Started: %s', 'post-kinds-for-indieweb' ),
						esc_html( $pkiw_started_display )
					);
					?>
				</time>
			<?php endif; ?>
			<?php
			if ( $pkiw_started_iso && $pkiw_finished_iso && in_array( $pkiw_read_status, [ 'finished', 'abandoned' ], true ) ) :
				?>
				<span class="pk-dot"></span><?php endif; ?>
			<?php if ( $pkiw_finished_iso && in_array( $pkiw_read_status, [ 'finished', 'abandoned' ], true ) ) : ?>
				<time class="dt-published" datetime="<?php echo esc_attr( $pkiw_finished_iso ); ?>">
					<?php
					printf(
						/* translators: %s: date */
						esc_html__( 'Finished: %s', 'post-kinds-for-indieweb' ),
						esc_html( $pkiw_finished_display )
					);
					?>
				</time>
			<?php endif; ?>
		</div>
	</div>

	<data class="u-read-of" value="<?php echo esc_attr( $pkiw_book_url ); ?>" hidden></data>
	<?php if ( $pkiw_isbn ) : ?>
		<data class="p-isbn" value="<?php echo esc_attr( $pkiw_isbn ); ?>" hidden></data>
	<?php endif; ?>
	<?php if ( $pkiw_openlibrary_id ) : ?>
		<data class="u-uid" value="<?php echo esc_attr( 'https://openlibrary.org' . $pkiw_openlibrary_id ); ?>" hidden></data>
	<?php endif; ?>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
