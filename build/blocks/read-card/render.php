<?php
/**
 * Read Card Block - Server-side Render
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
$pkiw_layout         = $attributes['layout'] ?? 'horizontal';

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
		'class' => sprintf( 'read-card layout-%s status-%s', esc_attr( $pkiw_layout ), esc_attr( $pkiw_read_status ) ),
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
<div <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="post-kinds-card h-cite">
		<?php if ( $pkiw_cover_image ) : ?>
			<div class="post-kinds-card__media post-kinds-card__media--portrait">
				<img
					src="<?php echo esc_url( $pkiw_cover_image ); ?>"
					alt="<?php echo esc_attr( $pkiw_cover_alt ? $pkiw_cover_alt : sprintf( /* translators: %s: book title */ __( 'Cover of %s', 'post-kinds-for-indieweb' ), $pkiw_book_title ) ); ?>"
					class="post-kinds-card__image u-photo"
					loading="lazy"
				/>
			</div>
		<?php endif; ?>

		<div class="post-kinds-card__content">
			<span class="post-kinds-card__badge post-kinds-card__badge--<?php echo esc_attr( $pkiw_read_status ); ?>">
				<?php echo esc_html( $pkiw_status_label ); ?>
			</span>

			<?php if ( $pkiw_book_title ) : ?>
				<h3 class="post-kinds-card__title p-name">
					<?php if ( $pkiw_book_url ) : ?>
						<a href="<?php echo esc_url( $pkiw_book_url ); ?>" class="u-url" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pkiw_book_title ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $pkiw_book_title ); ?>
					<?php endif; ?>
				</h3>
			<?php endif; ?>

			<?php if ( $pkiw_author_name ) : ?>
				<p class="post-kinds-card__subtitle">
					<span class="p-author h-card">
						<span class="p-name"><?php echo esc_html( $pkiw_author_name ); ?></span>
					</span>
				</p>
			<?php endif; ?>

			<?php if ( $pkiw_publisher || $pkiw_publish_date ) : ?>
				<p class="post-kinds-card__meta">
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
				<div class="post-kinds-card__progress">
					<div class="post-kinds-card__progress-bar">
						<div
							class="post-kinds-card__progress-fill"
							style="width: <?php echo esc_attr( (string) $pkiw_progress_percent ); ?>%"
							role="progressbar"
							aria-valuenow="<?php echo esc_attr( (string) $pkiw_progress_percent ); ?>"
							aria-valuemin="0"
							aria-valuemax="100"
						></div>
					</div>
					<span class="post-kinds-card__progress-text">
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
				<div class="post-kinds-card__rating p-rating" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: rating */ __( 'Rating: %d out of 5 stars', 'post-kinds-for-indieweb' ), $pkiw_rating ) ); ?>">
					<?php
					for ( $pkiw_i = 0; $pkiw_i < 5; $pkiw_i++ ) :
						$pkiw_filled = $pkiw_i < $pkiw_rating ? ' filled' : '';
						?>
						<span class="star<?php echo esc_attr( $pkiw_filled ); ?>" aria-hidden="true">★</span>
					<?php endfor; ?>
					<span class="post-kinds-card__rating-value"><?php echo esc_html( $pkiw_rating . '/5' ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( $pkiw_review ) : ?>
				<div class="post-kinds-card__notes p-content">
					<p><?php echo wp_kses_post( $pkiw_review ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $pkiw_started_iso || $pkiw_finished_iso ) : ?>
				<div class="post-kinds-card__dates">
					<?php if ( $pkiw_started_iso ) : ?>
						<time class="post-kinds-card__timestamp" datetime="<?php echo esc_attr( $pkiw_started_iso ); ?>">
							<?php
							printf(
								/* translators: %s: date */
								esc_html__( 'Started: %s', 'post-kinds-for-indieweb' ),
								esc_html( $pkiw_started_display )
							);
							?>
						</time>
					<?php endif; ?>
					<?php if ( $pkiw_finished_iso && in_array( $pkiw_read_status, [ 'finished', 'abandoned' ], true ) ) : ?>
						<time class="post-kinds-card__timestamp dt-published" datetime="<?php echo esc_attr( $pkiw_finished_iso ); ?>">
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
			<?php endif; ?>
		</div>

		<data class="u-read-of" value="<?php echo esc_attr( $pkiw_book_url ); ?>" hidden></data>
		<?php if ( $pkiw_isbn ) : ?>
			<data class="p-isbn" value="<?php echo esc_attr( $pkiw_isbn ); ?>" hidden></data>
		<?php endif; ?>
		<?php if ( $pkiw_openlibrary_id ) : ?>
			<data class="u-uid" value="<?php echo esc_attr( 'https://openlibrary.org' . $pkiw_openlibrary_id ); ?>" hidden></data>
		<?php endif; ?>
	</div>
</div>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
