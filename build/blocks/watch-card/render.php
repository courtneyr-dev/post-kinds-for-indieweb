<?php
/**
 * Watch Card Block - Server-side Render
 *
 * Renders the watch card in the two-layer pk-card system: plugin owns
 * structure (badge → label → title → sub → embed/media → meta), theme owns
 * paint via --pk-* custom properties. Appends a cached oEmbed player when the
 * watch URL matches a registered provider.
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

use function PKIW\get_card_embed_html;
use function PKIW\get_kind_icon_svg;

$pkiw_media_title    = $attributes['mediaTitle'] ?? '';
$pkiw_media_type     = $attributes['mediaType'] ?? 'movie';
$pkiw_show_title     = $attributes['showTitle'] ?? '';
$pkiw_season_number  = $attributes['seasonNumber'] ?? 0;
$pkiw_episode_number = $attributes['episodeNumber'] ?? 0;
$pkiw_episode_title  = $attributes['episodeTitle'] ?? '';
$pkiw_release_year   = $attributes['releaseYear'] ?? 0;
$pkiw_director       = $attributes['director'] ?? '';
$pkiw_poster_image   = $attributes['posterImage'] ?? '';
$pkiw_poster_alt     = $attributes['posterImageAlt'] ?? '';
$pkiw_watch_url      = $attributes['watchUrl'] ?? '';
$pkiw_captions_url   = $attributes['captionsUrl'] ?? '';
$pkiw_tmdb_id        = $attributes['tmdbId'] ?? '';
$pkiw_imdb_id        = $attributes['imdbId'] ?? '';
$pkiw_rating         = $attributes['rating'] ?? 0;
$pkiw_is_rewatch     = $attributes['isRewatch'] ?? false;
$pkiw_watched_at     = $attributes['watchedAt'] ?? '';
$pkiw_review         = $attributes['review'] ?? '';

$pkiw_episode_string = '';
if ( 'episode' === $pkiw_media_type ) {
	if ( $pkiw_season_number ) {
		$pkiw_episode_string .= 'S' . str_pad( $pkiw_season_number, 2, '0', STR_PAD_LEFT );
	}
	if ( $pkiw_episode_number ) {
		$pkiw_episode_string .= 'E' . str_pad( $pkiw_episode_number, 2, '0', STR_PAD_LEFT );
	}
	if ( $pkiw_episode_title ) {
		$pkiw_episode_string .= ' - ' . $pkiw_episode_title;
	}
}

$pkiw_tmdb_url = '';
if ( $pkiw_tmdb_id ) {
	$pkiw_tmdb_type = ( 'movie' === $pkiw_media_type ) ? 'movie' : 'tv';
	$pkiw_tmdb_url  = 'https://www.themoviedb.org/' . $pkiw_tmdb_type . '/' . $pkiw_tmdb_id;
}

$pkiw_imdb_url = '';
if ( $pkiw_imdb_id ) {
	$pkiw_imdb_url = 'https://www.imdb.com/title/' . $pkiw_imdb_id;
}

$pkiw_embed = $pkiw_watch_url ? get_card_embed_html( $pkiw_watch_url, 'watch', [ 'captions' => $pkiw_captions_url ] ) : false;

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-watch h-cite',
	]
);

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'watch' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<p class="pk-kindlabel"><?php esc_html_e( 'Watch', 'post-kinds-for-indieweb-in-block-themes' ); ?></p>

		<?php if ( $pkiw_media_title ) : ?>
			<h2 class="pk-title p-name">
				<?php if ( $pkiw_watch_url ) : ?>
					<a class="u-url" href="<?php echo esc_url( $pkiw_watch_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pkiw_media_title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $pkiw_media_title ); ?>
				<?php endif; ?>
			</h2>
		<?php endif; ?>

		<?php if ( 'episode' === $pkiw_media_type && $pkiw_show_title ) : ?>
			<p class="pk-sub"><?php echo esc_html( $pkiw_show_title ); ?></p>
		<?php endif; ?>

		<?php if ( $pkiw_episode_string ) : ?>
			<p class="pk-sub"><?php echo esc_html( $pkiw_episode_string ); ?></p>
		<?php endif; ?>

		<?php if ( $pkiw_release_year || $pkiw_director || $pkiw_is_rewatch ) : ?>
			<p class="pk-sub">
				<?php if ( $pkiw_release_year ) : ?>
					<span>(<?php echo esc_html( $pkiw_release_year ); ?>)</span>
				<?php endif; ?>
				<?php
				if ( $pkiw_release_year && $pkiw_director ) :
					?>
					&bull; <?php endif; ?>
				<?php if ( $pkiw_director ) : ?>
					<span class="p-author h-card"><span class="p-name"><?php echo esc_html( $pkiw_director ); ?></span></span>
				<?php endif; ?>
				<?php if ( $pkiw_is_rewatch ) : ?>
					<span class="pk-rewatch"><?php esc_html_e( 'Rewatch', 'post-kinds-for-indieweb-in-block-themes' ); ?></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( $pkiw_rating > 0 ) : ?>
			<div class="pk-stars p-rating" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: rating out of five. */ __( 'Rated %d of 5', 'post-kinds-for-indieweb-in-block-themes' ), $pkiw_rating ) ); ?>">
				<?php for ( $pkiw_i = 1; $pkiw_i <= 5; $pkiw_i++ ) : ?>
					<svg class="<?php echo $pkiw_i <= $pkiw_rating ? '' : 'off'; ?>" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3 6.5 7 .6-5.3 4.6 1.6 6.8L12 17l-6.9 3.5 1.6-6.8L1.4 9.1l7-.6z"/></svg>
				<?php endfor; ?>
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_embed ) : ?>
			<div class="pk-embed pk-embed--video"><?php echo $pkiw_embed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<?php elseif ( $pkiw_poster_image ) : ?>
			<div class="pk-media">
				<img class="pk-thumb--poster u-photo" src="<?php echo esc_url( $pkiw_poster_image ); ?>" alt="<?php echo esc_attr( $pkiw_poster_alt ? $pkiw_poster_alt : $pkiw_media_title ); ?>" loading="lazy" />
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_review ) : ?>
			<div class="pk-note p-content"><?php echo wp_kses_post( $pkiw_review ); ?></div>
		<?php endif; ?>

		<div class="pk-meta">
			<?php if ( $pkiw_imdb_url ) : ?>
				<a class="pk-link" href="<?php echo esc_url( $pkiw_imdb_url ); ?>" target="_blank" rel="noopener noreferrer">IMDb</a>
			<?php endif; ?>
			<?php
			if ( $pkiw_imdb_url && $pkiw_tmdb_url ) :
				?>
				<span class="pk-dot"></span><?php endif; ?>
			<?php if ( $pkiw_tmdb_url ) : ?>
				<a class="pk-link" href="<?php echo esc_url( $pkiw_tmdb_url ); ?>" target="_blank" rel="noopener noreferrer">TMDB</a>
			<?php endif; ?>
			<?php
			if ( ( $pkiw_imdb_url || $pkiw_tmdb_url ) && $pkiw_watched_at ) :
				?>
				<span class="pk-dot"></span><?php endif; ?>
			<?php if ( $pkiw_watched_at ) : ?>
				<time class="dt-published" datetime="<?php echo esc_attr( gmdate( 'c', strtotime( $pkiw_watched_at ) ) ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $pkiw_watched_at ) ) ); ?></time>
			<?php endif; ?>
		</div>
	</div>

	<data class="u-watch-of" value="<?php echo esc_url( $pkiw_watch_url ); ?>" hidden></data>
	<?php if ( $pkiw_tmdb_url ) : ?>
		<data class="u-uid" value="<?php echo esc_url( $pkiw_tmdb_url ); ?>" hidden></data>
	<?php endif; ?>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
