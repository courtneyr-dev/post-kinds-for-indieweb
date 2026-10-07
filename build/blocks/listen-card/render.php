<?php
/**
 * Listen Card Block - Server-side Render
 *
 * Renders the listen card in the two-layer pk-card system: plugin owns
 * structure (badge → label → title → sub → embed/media → meta), theme owns
 * paint via --pk-* custom properties. Appends a cached oEmbed player when the
 * listen URL matches a registered provider.
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

use function PKIW\get_cached_embed_html;
use function PKIW\get_kind_icon_svg;
use function PKIW\get_kind_label;

$pkiw_track_title    = $attributes['trackTitle'] ?? '';
$pkiw_artist_name    = $attributes['artistName'] ?? '';
$pkiw_album_title    = $attributes['albumTitle'] ?? '';
$pkiw_release_date   = $attributes['releaseDate'] ?? '';
$pkiw_cover_image    = $attributes['coverImage'] ?? '';
$pkiw_cover_alt      = $attributes['coverImageAlt'] ?? '';
$pkiw_listen_url     = $attributes['listenUrl'] ?? '';
$pkiw_musicbrainz_id = $attributes['musicbrainzId'] ?? '';
$pkiw_rating         = (float) ( $attributes['rating'] ?? 0 );
$pkiw_listened_at    = $attributes['listenedAt'] ?? '';

$pkiw_embed                                    = $pkiw_listen_url ? get_cached_embed_html( $pkiw_listen_url ) : false;
$pkiw_release_year                             = preg_match( '/^(\d{4})/', (string) $pkiw_release_date, $pkiw_release_matches ) ? $pkiw_release_matches[1] : '';
[ $pkiw_listened_iso, $pkiw_listened_display ] = \PKIW\card_wall_clock( (string) $pkiw_listened_at, (string) get_option( 'date_format' ) );

$pkiw_cover_html = '';
if ( ! $pkiw_embed && $pkiw_cover_image ) {
	$pkiw_cover_alt_text = $pkiw_cover_alt ? $pkiw_cover_alt : $pkiw_track_title . ' — ' . $pkiw_artist_name;
	// A cover in this site's uploads prints as its media library image, so it
	// gets core's srcset, sizes, width and height. At full size its src, and
	// so the u-photo, is the library image's current file, as in
	// kind_picture(). Any other URL is a hotlink and prints as stored.
	$pkiw_cover_id = \PKIW\is_upload_url( (string) $pkiw_cover_image ) ? \PKIW\cover_local_copy( (int) get_the_ID(), (string) $pkiw_cover_image ) : 0;
	if ( $pkiw_cover_id > 0 ) {
		$pkiw_cover_html = wp_get_attachment_image(
			$pkiw_cover_id,
			'full',
			false,
			[
				'class'   => 'u-photo',
				'alt'     => $pkiw_cover_alt_text,
				'loading' => 'lazy',
			]
		);
	}
	if ( '' === $pkiw_cover_html ) {
		$pkiw_cover_html = '<img class="u-photo" src="' . esc_url( $pkiw_cover_image ) . '" alt="' . esc_attr( $pkiw_cover_alt_text ) . '" loading="lazy" />';
	}
}

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-listen h-cite u-listen-of',
	]
);

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'listen' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<span class="pk-kindlabel"><?php echo esc_html( get_kind_label( __( 'Listen', 'post-kinds-for-indieweb-in-block-themes' ), 'listen', 'listen-card' ) ); ?></span>

		<div class="pk-caption">
			<?php if ( $pkiw_track_title ) : ?>
				<h2 class="pk-title p-name">
					<?php if ( $pkiw_listen_url ) : ?>
						<a class="u-url" href="<?php echo esc_url( $pkiw_listen_url ); ?>" target="_blank" rel="noopener noreferrer"<?php echo \PKIW\pkiw_new_tab_label_attr( $pkiw_track_title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $pkiw_track_title ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $pkiw_track_title ); ?>
					<?php endif; ?>
				</h2>
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
						if ( $pkiw_release_year ) :
							?>
							(<?php echo esc_html( $pkiw_release_year ); ?>)<?php endif; ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>

		<?php echo \PKIW\card_rating_html( $pkiw_rating ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<?php if ( $pkiw_embed ) : ?>
			<div class="pk-embed pk-embed--audio"><?php echo $pkiw_embed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<?php elseif ( '' !== $pkiw_cover_html ) : ?>
			<div class="pk-embed pk-embed--photo"><?php echo $pkiw_cover_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() output, or an <img> escaped above. ?></div>
		<?php endif; ?>

		<div class="pk-meta">
			<?php if ( $pkiw_listen_url ) : ?>
				<a class="pk-link" href="<?php echo esc_url( $pkiw_listen_url ); ?>" target="_blank" rel="noopener noreferrer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 3l14 9-14 9z"/></svg><?php esc_html_e( 'Listen', 'post-kinds-for-indieweb-in-block-themes' ); ?><?php echo \PKIW\pkiw_new_tab_hint(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
			<?php endif; ?>
			<?php
			if ( $pkiw_listen_url && $pkiw_listened_iso ) :
				?>
				<span class="pk-dot"></span><?php endif; ?>
			<?php if ( $pkiw_listened_iso ) : ?>
				<time class="dt-published" datetime="<?php echo esc_attr( $pkiw_listened_iso ); ?>"><?php echo esc_html( $pkiw_listened_display ); ?></time>
			<?php endif; ?>
		</div>
	</div>

	<data value="<?php echo esc_url( $pkiw_listen_url ); ?>" hidden></data>
	<?php if ( $pkiw_musicbrainz_id ) : ?>
		<data class="u-uid" value="<?php echo esc_url( 'https://musicbrainz.org/recording/' . $pkiw_musicbrainz_id ); ?>" hidden></data>
	<?php endif; ?>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
