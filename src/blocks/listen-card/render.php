<?php
/**
 * Listen Card Block - Server-side Render
 *
 * Renders the listen card in the two-layer pk-card system: plugin owns
 * structure (badge → label → title → sub → embed/media → meta), theme owns
 * paint via --pk-* custom properties. Appends a cached oEmbed player when the
 * listen URL matches a registered provider.
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

use function PostKindsForIndieWeb\get_cached_embed_html;
use function PostKindsForIndieWeb\get_kind_icon_svg;

$pkiw_track_title    = $attributes['trackTitle'] ?? '';
$pkiw_artist_name    = $attributes['artistName'] ?? '';
$pkiw_album_title    = $attributes['albumTitle'] ?? '';
$pkiw_release_date   = $attributes['releaseDate'] ?? '';
$pkiw_cover_image    = $attributes['coverImage'] ?? '';
$pkiw_cover_alt      = $attributes['coverImageAlt'] ?? '';
$pkiw_listen_url     = $attributes['listenUrl'] ?? '';
$pkiw_musicbrainz_id = $attributes['musicbrainzId'] ?? '';
$pkiw_rating         = (int) ( $attributes['rating'] ?? 0 );
$pkiw_listened_at    = $attributes['listenedAt'] ?? '';

$pkiw_embed = $pkiw_listen_url ? get_cached_embed_html( $pkiw_listen_url ) : false;

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-listen h-cite',
	]
);

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'listen' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<p class="pk-kindlabel"><?php esc_html_e( 'Listen', 'post-kinds-for-indieweb' ); ?></p>

		<?php if ( $pkiw_track_title ) : ?>
			<h3 class="pk-title p-name">
				<?php if ( $pkiw_listen_url ) : ?>
					<a class="u-url" href="<?php echo esc_url( $pkiw_listen_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pkiw_track_title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $pkiw_track_title ); ?>
				<?php endif; ?>
			</h3>
		<?php endif; ?>

		<?php if ( $pkiw_artist_name || $pkiw_album_title ) : ?>
			<p class="pk-sub">
				<?php if ( $pkiw_artist_name ) : ?>
					<span class="p-author h-card"><span class="p-name"><?php echo esc_html( $pkiw_artist_name ); ?></span></span>
				<?php endif; ?>
				<?php
				if ( $pkiw_artist_name && $pkiw_album_title ) :
					?>
					&mdash; <?php endif; ?>
				<?php if ( $pkiw_album_title ) : ?>
					<em><?php echo esc_html( $pkiw_album_title ); ?></em>
					<?php
					if ( $pkiw_release_date ) :
						?>
						(<?php echo esc_html( gmdate( 'Y', (int) strtotime( $pkiw_release_date ) ) ); ?>)<?php endif; ?>
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

		<?php if ( $pkiw_embed ) : ?>
			<div class="pk-embed pk-embed--audio"><?php echo $pkiw_embed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<?php elseif ( $pkiw_cover_image ) : ?>
			<div class="pk-embed pk-embed--photo"><img class="u-photo" src="<?php echo esc_url( $pkiw_cover_image ); ?>" alt="<?php echo esc_attr( $pkiw_cover_alt ? $pkiw_cover_alt : $pkiw_track_title . ' — ' . $pkiw_artist_name ); ?>" loading="lazy" /></div>
		<?php endif; ?>

		<div class="pk-meta">
			<?php if ( $pkiw_listen_url ) : ?>
				<a class="pk-link" href="<?php echo esc_url( $pkiw_listen_url ); ?>" target="_blank" rel="noopener noreferrer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 3l14 9-14 9z"/></svg><?php esc_html_e( 'Listen', 'post-kinds-for-indieweb' ); ?></a>
			<?php endif; ?>
			<?php
			if ( $pkiw_listen_url && $pkiw_listened_at ) :
				?>
				<span class="pk-dot"></span><?php endif; ?>
			<?php if ( $pkiw_listened_at ) : ?>
				<time class="dt-published" datetime="<?php echo esc_attr( gmdate( 'c', (int) strtotime( $pkiw_listened_at ) ) ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) strtotime( $pkiw_listened_at ) ) ); ?></time>
			<?php endif; ?>
		</div>
	</div>

	<data class="u-listen-of" value="<?php echo esc_url( $pkiw_listen_url ); ?>" hidden></data>
	<?php if ( $pkiw_musicbrainz_id ) : ?>
		<data class="u-uid" value="<?php echo esc_url( 'https://musicbrainz.org/recording/' . $pkiw_musicbrainz_id ); ?>" hidden></data>
	<?php endif; ?>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
