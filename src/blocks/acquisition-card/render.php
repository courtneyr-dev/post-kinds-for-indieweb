<?php
/**
 * Acquisition Card Block - Server-side Render
 *
 * Renders the acquisition card in the two-layer pk-card system: plugin owns
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
use function PKIW\get_kind_label;

$pkiw_title       = \PKIW\Acquisition_Archive::plain( (string) ( $attributes['title'] ?? '' ) );
$pkiw_type        = array_key_exists( 'acquisitionType', $attributes ) ? (string) $attributes['acquisitionType'] : 'purchase';
$pkiw_cost        = trim( (string) ( $attributes['cost'] ?? '' ) );
$pkiw_where       = \PKIW\Acquisition_Archive::plain( (string) ( $attributes['where'] ?? '' ) );
$pkiw_where_url   = (string) ( $attributes['whereUrl'] ?? '' );
$pkiw_photo       = $attributes['photo'] ?? '';
$pkiw_photo_alt   = $attributes['photoAlt'] ?? '';
$pkiw_notes       = \PKIW\Acquisition_Archive::plain( (string) ( $attributes['notes'] ?? '' ), true );
$pkiw_acquired_at = $attributes['acquiredAt'] ?? '';
$pkiw_post_id     = ( $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ) ? (int) $block->context['postId'] : (int) get_the_ID();
$pkiw_in_stream   = function_exists( 'PKIW\is_rendering_stream_card' ) && \PKIW\is_rendering_stream_card();

// issue 239: cost is private unless this card, request and the post's synced toggle say public.
if (
	empty( $attributes['showCostPublicly'] )
	|| ! \PKIW\Meta_Fields::acquisition_cost_visible( $pkiw_post_id )
	|| $pkiw_in_stream
	|| is_search()
	|| ( is_archive() && ! is_feed() )
) {
	$pkiw_cost = '';
}

$pkiw_type_labels = \PKIW\Acquisition_Archive::type_labels();
$pkiw_type_label  = $pkiw_type_labels[ $pkiw_type ] ?? ( $pkiw_type ? $pkiw_type : __( 'Acquired', 'post-kinds-for-indieweb-in-block-themes' ) );

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class' => 'pk-card k-acquisition h-cite u-acquisition-of',
	]
);

[ $pkiw_acquired_iso, $pkiw_acquired_display ] = \PKIW\card_calendar_date( (string) $pkiw_acquired_at );

if ( $pkiw_in_stream ) {
	$pkiw_where_url = '';
	$pkiw_notes     = '';
	// Stream and archive cards print local pictures only, so a visitor's browser never asks a third-party host for one.
	if ( ! \PKIW\is_upload_url( \PKIW\web_url( (string) $pkiw_photo ) ) ) {
		$pkiw_photo = '';
	}
	if ( is_archive() || is_search() ) {
		$pkiw_type_label       = '';
		$pkiw_where            = '';
		$pkiw_acquired_iso     = '';
		$pkiw_acquired_display = '';
	}
}

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'acquisition' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<?php if ( $pkiw_type_label ) : ?>
			<span class="pk-kindlabel"><?php echo esc_html( get_kind_label( $pkiw_type_label, 'acquisition', 'acquisition-card' ) ); ?></span>
		<?php endif; ?>

		<div class="pk-caption">
			<?php if ( $pkiw_title ) : ?>
				<h2 class="pk-title p-name">
					<?php if ( $pkiw_where_url ) : ?>
						<a class="u-url" href="<?php echo esc_url( $pkiw_where_url ); ?>" target="_blank" rel="noopener noreferrer"<?php echo \PKIW\pkiw_new_tab_label_attr( $pkiw_title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $pkiw_title ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $pkiw_title ); ?>
					<?php endif; ?>
				</h2>
			<?php endif; ?>

			<?php if ( $pkiw_cost || $pkiw_where ) : ?>
				<p class="pk-sub">
					<?php if ( $pkiw_cost ) : ?>
						<span><?php echo esc_html( $pkiw_cost ); ?></span>
					<?php endif; ?>
					<?php
					if ( $pkiw_cost && $pkiw_where ) :
						?>
						<span class="pk-dot"></span><?php endif; ?>
					<?php if ( $pkiw_where ) : ?>
						<span class="p-location"><?php printf( /* translators: %s: place the item was acquired. */ esc_html__( 'from %s', 'post-kinds-for-indieweb-in-block-themes' ), esc_html( $pkiw_where ) ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>

		<?php if ( $pkiw_photo ) : ?>
			<div class="pk-media">
				<img class="pk-thumb u-photo" src="<?php echo esc_url( $pkiw_photo ); ?>" alt="<?php echo esc_attr( $pkiw_photo_alt ); ?>" loading="lazy" />
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_notes ) : ?>
			<p class="pk-note p-content"><?php echo esc_html( $pkiw_notes ); ?></p>
		<?php endif; ?>

		<?php if ( $pkiw_acquired_iso ) : ?>
			<div class="pk-meta">
				<time class="dt-published" datetime="<?php echo esc_attr( $pkiw_acquired_iso ); ?>"><?php echo esc_html( $pkiw_acquired_display ); ?></time>
			</div>
		<?php endif; ?>
	</div>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
