<?php
/**
 * Play Card Block - Server-side Render
 *
 * Renders the play card in the two-layer pk-card system: plugin owns
 * structure (badge → label → title → sub → media → note → meta), theme owns
 * paint via --pk-* custom properties.
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
$pkiw_steam_id     = $attributes['steamId'] ?? '';
$pkiw_official_url = $attributes['officialUrl'] ?? '';
$pkiw_purchase_url = $attributes['purchaseUrl'] ?? '';

$pkiw_status_label = $pkiw_status ? ( $pkiw_status_labels[ $pkiw_status ] ?? $pkiw_status ) : '';

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-play h-cite',
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
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<p class="pk-kindlabel"><?php esc_html_e( 'Play', 'post-kinds-for-indieweb' ); ?></p>

		<?php if ( $pkiw_title ) : ?>
			<h2 class="pk-title p-name">
				<?php if ( $pkiw_game_url ) : ?>
					<a class="u-url" href="<?php echo esc_url( $pkiw_game_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pkiw_title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $pkiw_title ); ?>
				<?php endif; ?>
			</h2>
		<?php endif; ?>

		<?php if ( $pkiw_status_label || $pkiw_platform || $pkiw_hours_played > 0 ) : ?>
			<p class="pk-sub">
				<?php if ( $pkiw_status_label ) : ?>
					<span><?php echo esc_html( $pkiw_status_label ); ?></span>
				<?php endif; ?>
				<?php
				if ( $pkiw_status_label && $pkiw_platform ) :
					?>
					&mdash; <?php endif; ?>
				<?php if ( $pkiw_platform ) : ?>
					<span><?php echo esc_html( $pkiw_platform ); ?></span>
				<?php endif; ?>
				<?php
				if ( ( $pkiw_status_label || $pkiw_platform ) && $pkiw_hours_played > 0 ) :
					?>
					&bull; <?php endif; ?>
				<?php if ( $pkiw_hours_played > 0 ) : ?>
					<?php
					printf(
						/* translators: %s: hours played */
						esc_html__( '%s hours played', 'post-kinds-for-indieweb' ),
						'<strong>' . esc_html( (string) $pkiw_hours_played ) . '</strong>'
					);
					?>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( $pkiw_rating > 0 ) : ?>
			<div class="pk-stars p-rating" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: rating out of five. */ __( 'Rated %d of 5', 'post-kinds-for-indieweb' ), $pkiw_rating ) ); ?>">
				<?php for ( $pkiw_i = 1; $pkiw_i <= 5; $pkiw_i++ ) : ?>
					<svg class="<?php echo $pkiw_i <= $pkiw_rating ? '' : 'off'; ?>" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3 6.5 7 .6-5.3 4.6 1.6 6.8L12 17l-6.9 3.5 1.6-6.8L1.4 9.1l7-.6z"/></svg>
				<?php endfor; ?>
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_cover ) : ?>
			<div class="pk-media">
				<img class="pk-thumb--poster u-photo" src="<?php echo esc_url( $pkiw_cover ); ?>" alt="<?php echo esc_attr( $pkiw_cover_alt ? $pkiw_cover_alt : $pkiw_title ); ?>" loading="lazy" />
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_review ) : ?>
			<div class="pk-note p-content"><?php echo wp_kses_post( $pkiw_review ); ?></div>
		<?php endif; ?>

		<div class="pk-meta">
			<?php if ( $pkiw_game_url ) : ?>
				<a class="pk-link" href="<?php echo esc_url( $pkiw_game_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View on BGG', 'post-kinds-for-indieweb' ); ?></a>
			<?php endif; ?>
			<?php
			if ( $pkiw_game_url && $pkiw_official_url ) :
				?>
				<span class="pk-dot"></span><?php endif; ?>
			<?php if ( $pkiw_official_url ) : ?>
				<a class="pk-link" href="<?php echo esc_url( $pkiw_official_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Official Site', 'post-kinds-for-indieweb' ); ?></a>
			<?php endif; ?>
			<?php
			if ( ( $pkiw_game_url || $pkiw_official_url ) && $pkiw_purchase_url ) :
				?>
				<span class="pk-dot"></span><?php endif; ?>
			<?php if ( $pkiw_purchase_url ) : ?>
				<a class="pk-link pk-link--buy" href="<?php echo esc_url( $pkiw_purchase_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Buy', 'post-kinds-for-indieweb' ); ?></a>
			<?php endif; ?>
			<?php
			if ( ( $pkiw_game_url || $pkiw_official_url || $pkiw_purchase_url ) && $pkiw_played_iso ) :
				?>
				<span class="pk-dot"></span><?php endif; ?>
			<?php if ( $pkiw_played_iso ) : ?>
				<time class="dt-published" datetime="<?php echo esc_attr( $pkiw_played_iso ); ?>"><?php echo esc_html( $pkiw_played_display ); ?></time>
			<?php endif; ?>
		</div>
	</div>

	<data class="u-play-of" value="<?php echo esc_attr( $pkiw_game_url ); ?>" hidden></data>
	<?php if ( $pkiw_bgg_id ) : ?>
		<data class="u-uid" value="<?php echo esc_attr( 'https://boardgamegeek.com/boardgame/' . $pkiw_bgg_id ); ?>" hidden></data>
	<?php endif; ?>
	<?php if ( $pkiw_rawg_id ) : ?>
		<data class="u-uid" value="<?php echo esc_attr( 'https://rawg.io/games/' . $pkiw_rawg_id ); ?>" hidden></data>
	<?php endif; ?>
	<?php if ( $pkiw_steam_id ) : ?>
		<data class="u-uid" value="<?php echo esc_attr( 'https://store.steampowered.com/app/' . $pkiw_steam_id ); ?>" hidden></data>
	<?php endif; ?>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
