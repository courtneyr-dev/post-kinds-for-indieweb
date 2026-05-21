<?php
/**
 * Watch Card Block - Server-side Render
 *
 * Generates the full watch card HTML from block attributes and appends
 * an oEmbed player when the watch URL matches a whitelisted provider.
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
$pkiw_tmdb_id        = $attributes['tmdbId'] ?? '';
$pkiw_imdb_id        = $attributes['imdbId'] ?? '';
$pkiw_rating         = $attributes['rating'] ?? 0;
$pkiw_is_rewatch     = $attributes['isRewatch'] ?? false;
$pkiw_watched_at     = $attributes['watchedAt'] ?? '';
$pkiw_review         = $attributes['review'] ?? '';
$pkiw_layout         = $attributes['layout'] ?? 'horizontal';

if ( 'movie' === $pkiw_media_type ) {
	$pkiw_type_label = 'Movie';
} elseif ( 'tv' === $pkiw_media_type ) {
	$pkiw_type_label = 'TV';
} else {
	$pkiw_type_label = 'Episode';
}

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

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'watch-card layout-' . esc_attr( $pkiw_layout ) . ' type-' . esc_attr( $pkiw_media_type ),
	]
);

ob_start();
?>
<div <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="post-kinds-card h-cite">
		<span class="post-kinds-card__type-icon" aria-hidden="true">&#127916;</span>
		<?php if ( $pkiw_poster_image ) : ?>
			<div class="post-kinds-card__media post-kinds-card__media--portrait">
				<img
					src="<?php echo esc_url( $pkiw_poster_image ); ?>"
					alt="<?php echo esc_attr( $pkiw_poster_alt ? $pkiw_poster_alt : $pkiw_media_title ); ?>"
					class="post-kinds-card__image u-photo"
					loading="lazy"
				/>
			</div>
		<?php endif; ?>

		<div class="post-kinds-card__content">
			<div class="post-kinds-card__badges">
				<span class="post-kinds-card__badge post-kinds-card__badge--<?php echo esc_attr( $pkiw_media_type ); ?>">
					<?php echo esc_html( $pkiw_type_label ); ?>
				</span>
				<?php if ( $pkiw_is_rewatch ) : ?>
					<span class="post-kinds-card__badge post-kinds-card__badge--rewatch">
						Rewatch
					</span>
				<?php endif; ?>
			</div>

			<?php if ( 'episode' === $pkiw_media_type && $pkiw_show_title ) : ?>
				<p class="post-kinds-card__meta"><?php echo esc_html( $pkiw_show_title ); ?></p>
			<?php endif; ?>

			<?php if ( $pkiw_media_title ) : ?>
				<h3 class="post-kinds-card__title p-name">
					<?php if ( $pkiw_watch_url ) : ?>
						<a href="<?php echo esc_url( $pkiw_watch_url ); ?>" class="u-url" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $pkiw_media_title ); ?>
						</a>
					<?php else : ?>
						<?php echo esc_html( $pkiw_media_title ); ?>
					<?php endif; ?>
				</h3>
			<?php endif; ?>

			<?php if ( $pkiw_episode_string ) : ?>
				<p class="post-kinds-card__subtitle">
					<?php echo esc_html( $pkiw_episode_string ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $pkiw_release_year || $pkiw_director ) : ?>
				<p class="post-kinds-card__meta">
					<?php if ( $pkiw_release_year ) : ?>
						<span>(<?php echo esc_html( $pkiw_release_year ); ?>)</span>
					<?php endif; ?>
					<?php if ( $pkiw_release_year && $pkiw_director ) : ?>
						&bull;
					<?php endif; ?>
					<?php if ( $pkiw_director ) : ?>
						<span class="p-author h-card">
							<span class="p-name"><?php echo esc_html( $pkiw_director ); ?></span>
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

			<?php if ( $pkiw_review ) : ?>
				<div class="post-kinds-card__notes p-content">
					<p><?php echo wp_kses_post( $pkiw_review ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $pkiw_watched_at ) : ?>
				<time class="post-kinds-card__timestamp dt-published" datetime="<?php echo esc_attr( gmdate( 'c', strtotime( $pkiw_watched_at ) ) ); ?>">
					<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $pkiw_watched_at ) ) ); ?>
				</time>
			<?php endif; ?>

			<?php if ( $pkiw_imdb_url || $pkiw_tmdb_url ) : ?>
				<div class="post-kinds-card__links">
					<?php if ( $pkiw_imdb_url ) : ?>
						<a href="<?php echo esc_url( $pkiw_imdb_url ); ?>" target="_blank" rel="noopener noreferrer">IMDb</a>
					<?php endif; ?>
					<?php if ( $pkiw_tmdb_url ) : ?>
						<a href="<?php echo esc_url( $pkiw_tmdb_url ); ?>" target="_blank" rel="noopener noreferrer">TMDB</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<data class="u-watch-of" value="<?php echo esc_url( $pkiw_watch_url ); ?>" hidden></data>
		<?php if ( $pkiw_tmdb_url ) : ?>
			<data class="u-uid" value="<?php echo esc_url( $pkiw_tmdb_url ); ?>" hidden></data>
		<?php endif; ?>

	</div>

	<?php
	if ( $pkiw_watch_url ) {
		$pkiw_embed = wp_oembed_get( $pkiw_watch_url );
		if ( $pkiw_embed ) {
			echo '<div class="post-kinds-card__embed-section">' . $pkiw_embed . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
	?>
</div>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
