<?php
/**
 * Listen Card Block - Server-side Render
 *
 * Generates the full listen card HTML from block attributes and appends
 * an oEmbed player when the listen URL matches a whitelisted provider.
 *
 * @package PostKindsForIndieWeb
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content (empty for dynamic blocks).
 * @var WP_Block $block      Block instance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pkiw_track_title    = $attributes['trackTitle'] ?? '';
$pkiw_artist_name    = $attributes['artistName'] ?? '';
$pkiw_album_title    = $attributes['albumTitle'] ?? '';
$pkiw_release_date   = $attributes['releaseDate'] ?? '';
$pkiw_cover_image    = $attributes['coverImage'] ?? '';
$pkiw_cover_alt      = $attributes['coverImageAlt'] ?? '';
$pkiw_listen_url     = $attributes['listenUrl'] ?? '';
$pkiw_musicbrainz_id = $attributes['musicbrainzId'] ?? '';
$pkiw_rating         = $attributes['rating'] ?? 0;
$pkiw_listened_at    = $attributes['listenedAt'] ?? '';
$pkiw_layout         = $attributes['layout'] ?? 'horizontal';

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'listen-card layout-' . esc_attr( $pkiw_layout ),
	]
);

ob_start();
?>
<div <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="post-kinds-card h-cite">
		<span class="post-kinds-card__type-icon" aria-hidden="true">&#127925;</span>
		<?php if ( $pkiw_cover_image ) : ?>
			<div class="post-kinds-card__media">
				<img
					src="<?php echo esc_url( $pkiw_cover_image ); ?>"
					alt="<?php echo esc_attr( $pkiw_cover_alt ? $pkiw_cover_alt : $pkiw_track_title . ' by ' . $pkiw_artist_name ); ?>"
					class="post-kinds-card__image u-photo"
					loading="lazy"
				/>
			</div>
		<?php endif; ?>

		<div class="post-kinds-card__content">
			<?php if ( $pkiw_track_title ) : ?>
				<h3 class="post-kinds-card__title p-name">
					<?php if ( $pkiw_listen_url ) : ?>
						<a href="<?php echo esc_url( $pkiw_listen_url ); ?>" class="u-url" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $pkiw_track_title ); ?>
						</a>
					<?php else : ?>
						<?php echo esc_html( $pkiw_track_title ); ?>
					<?php endif; ?>
				</h3>
			<?php endif; ?>

			<?php if ( $pkiw_artist_name ) : ?>
				<p class="post-kinds-card__subtitle">
					<span class="p-author h-card">
						<span class="p-name"><?php echo esc_html( $pkiw_artist_name ); ?></span>
					</span>
				</p>
			<?php endif; ?>

			<?php if ( $pkiw_album_title ) : ?>
				<p class="post-kinds-card__meta">
					<?php echo esc_html( $pkiw_album_title ); ?>
					<?php if ( $pkiw_release_date ) : ?>
						<span class="post-kinds-card__meta-detail">
							(<?php echo esc_html( gmdate( 'Y', strtotime( $pkiw_release_date ) ) ); ?>)
						</span>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( $pkiw_rating > 0 ) : ?>
				<div class="post-kinds-card__rating p-rating" aria-label="<?php echo esc_attr( sprintf( 'Rating: %d out of 5 stars', $pkiw_rating ) ); ?>">
					<?php for ( $pkiw_i = 0; $pkiw_i < 5; $pkiw_i++ ) : ?>
						<span class="star <?php echo $pkiw_i < $pkiw_rating ? 'filled' : ''; ?>" aria-hidden="true">&#9733;</span>
					<?php endfor; ?>
					<span class="post-kinds-card__rating-value"><?php echo esc_html( $pkiw_rating ); ?>/5</span>
				</div>
			<?php endif; ?>

			<?php if ( $pkiw_listened_at ) : ?>
				<time class="post-kinds-card__timestamp dt-published" datetime="<?php echo esc_attr( gmdate( 'c', strtotime( $pkiw_listened_at ) ) ); ?>">
					<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $pkiw_listened_at ) ) ); ?>
				</time>
			<?php endif; ?>
		</div>

		<data class="u-listen-of" value="<?php echo esc_url( $pkiw_listen_url ); ?>" hidden></data>
		<?php if ( $pkiw_musicbrainz_id ) : ?>
			<data class="u-uid" value="<?php echo esc_url( 'https://musicbrainz.org/recording/' . $pkiw_musicbrainz_id ); ?>" hidden></data>
		<?php endif; ?>

	</div>

	<?php
	if ( $pkiw_listen_url ) {
		$pkiw_embed = wp_oembed_get( $pkiw_listen_url );
		if ( $pkiw_embed ) {
			echo '<div class="post-kinds-card__embed-section">' . $pkiw_embed . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
	?>
</div>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
