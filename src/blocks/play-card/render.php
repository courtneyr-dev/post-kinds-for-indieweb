<?php
/**
 * Play Card Block - Server-side Render
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

$pkiw_status_labels = [
	'playing'   => __( 'Playing', 'post-kinds-for-indieweb' ),
	'completed' => __( 'Completed', 'post-kinds-for-indieweb' ),
	'abandoned' => __( 'Abandoned', 'post-kinds-for-indieweb' ),
	'backlog'   => __( 'Backlog', 'post-kinds-for-indieweb' ),
	'wishlist'  => __( 'Wishlist', 'post-kinds-for-indieweb' ),
];

$pkiw_title        = $attributes['title'] ?? '';
$pkiw_platform     = $attributes['platform'] ?? '';
$pkiw_cover        = $attributes['cover'] ?? '';
$pkiw_cover_alt    = $attributes['coverAlt'] ?? '';
$pkiw_status       = $attributes['status'] ?? '';
$pkiw_hours_played = isset( $attributes['hoursPlayed'] ) ? (float) $attributes['hoursPlayed'] : 0.0;
$pkiw_rating       = isset( $attributes['rating'] ) ? (int) $attributes['rating'] : 0;
$pkiw_played_at    = $attributes['playedAt'] ?? '';
$pkiw_review       = $attributes['review'] ?? '';
$pkiw_game_url     = $attributes['gameUrl'] ?? '';
$pkiw_bgg_id       = $attributes['bggId'] ?? '';
$pkiw_rawg_id      = $attributes['rawgId'] ?? '';
$pkiw_official_url = $attributes['officialUrl'] ?? '';
$pkiw_purchase_url = $attributes['purchaseUrl'] ?? '';
$pkiw_layout       = $attributes['layout'] ?? 'horizontal';

$pkiw_status_label = $pkiw_status ? ( $pkiw_status_labels[ $pkiw_status ] ?? $pkiw_status ) : '';

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'play-card layout-' . esc_attr( $pkiw_layout ),
	]
);

$pkiw_played_iso     = '';
$pkiw_played_display = '';
if ( $pkiw_played_at ) {
	$pkiw_ts = strtotime( $pkiw_played_at );
	if ( $pkiw_ts ) {
		$pkiw_played_iso     = gmdate( 'c', $pkiw_ts );
		$pkiw_played_display = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $pkiw_ts );
	}
}

ob_start();
?>
<div <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="post-kinds-card h-cite">
		<?php if ( $pkiw_cover ) : ?>
			<div class="post-kinds-card__media">
				<img
					src="<?php echo esc_url( $pkiw_cover ); ?>"
					alt="<?php echo esc_attr( $pkiw_cover_alt ? $pkiw_cover_alt : $pkiw_title ); ?>"
					class="post-kinds-card__image u-photo"
					loading="lazy"
				/>
			</div>
		<?php endif; ?>

		<div class="post-kinds-card__content">
			<div class="post-kinds-card__badges">
				<?php if ( $pkiw_status ) : ?>
					<span class="post-kinds-card__badge post-kinds-card__badge--<?php echo esc_attr( $pkiw_status ); ?>">
						<?php echo esc_html( $pkiw_status_label ); ?>
					</span>
				<?php endif; ?>
				<?php if ( $pkiw_platform ) : ?>
					<span class="post-kinds-card__badge"><?php echo esc_html( $pkiw_platform ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( $pkiw_title ) : ?>
				<h3 class="post-kinds-card__title p-name">
					<?php if ( $pkiw_game_url ) : ?>
						<a href="<?php echo esc_url( $pkiw_game_url ); ?>" class="u-url" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pkiw_title ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $pkiw_title ); ?>
					<?php endif; ?>
				</h3>
			<?php endif; ?>

			<?php if ( $pkiw_hours_played > 0 ) : ?>
				<p class="post-kinds-card__meta">
					<?php
					printf(
						/* translators: %s: hours played */
						esc_html__( '%s hours played', 'post-kinds-for-indieweb' ),
						'<strong>' . esc_html( (string) $pkiw_hours_played ) . '</strong>'
					);
					?>
				</p>
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
				<p class="post-kinds-card__notes p-content"><?php echo wp_kses_post( $pkiw_review ); ?></p>
			<?php endif; ?>

			<?php if ( $pkiw_official_url || $pkiw_purchase_url || $pkiw_game_url ) : ?>
				<div class="post-kinds-card__links">
					<?php if ( $pkiw_game_url ) : ?>
						<a href="<?php echo esc_url( $pkiw_game_url ); ?>" class="post-kinds-card__link" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View on BGG', 'post-kinds-for-indieweb' ); ?></a>
					<?php endif; ?>
					<?php if ( $pkiw_official_url ) : ?>
						<a href="<?php echo esc_url( $pkiw_official_url ); ?>" class="post-kinds-card__link" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Official Site', 'post-kinds-for-indieweb' ); ?></a>
					<?php endif; ?>
					<?php if ( $pkiw_purchase_url ) : ?>
						<a href="<?php echo esc_url( $pkiw_purchase_url ); ?>" class="post-kinds-card__link post-kinds-card__link--buy" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Buy', 'post-kinds-for-indieweb' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $pkiw_played_iso ) : ?>
				<time class="post-kinds-card__timestamp dt-published" datetime="<?php echo esc_attr( $pkiw_played_iso ); ?>">
					<?php echo esc_html( $pkiw_played_display ); ?>
				</time>
			<?php endif; ?>
		</div>

		<data class="u-play-of" value="<?php echo esc_attr( $pkiw_game_url ); ?>" hidden></data>
		<?php if ( $pkiw_bgg_id ) : ?>
			<data class="u-uid" value="<?php echo esc_attr( 'https://boardgamegeek.com/boardgame/' . $pkiw_bgg_id ); ?>" hidden></data>
		<?php endif; ?>
		<?php if ( $pkiw_rawg_id ) : ?>
			<data class="u-uid" value="<?php echo esc_attr( 'https://rawg.io/games/' . $pkiw_rawg_id ); ?>" hidden></data>
		<?php endif; ?>
	</div>
</div>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
