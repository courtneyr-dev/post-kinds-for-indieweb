<?php
/**
 * Wish Card Block - Server-side Render
 *
 * Renders detailed wish facts on single and feed surfaces, and compact chips
 * inside the Stream. The post's own view relies on the page heading and moves
 * the outbound item URL to one explicit action.
 *
 * @package PKIW
 * @since 1.9.0
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content (empty for dynamic blocks).
 * @var WP_Block $block      Block instance.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- render.php variables are scoped by WordPress block rendering.

use function PKIW\get_kind_icon_svg;
use function PKIW\get_kind_label;

$pkiw_title       = trim( is_string( $attributes['title'] ?? null ) ? $attributes['title'] : '' );
$pkiw_url         = is_string( $attributes['url'] ?? null ) ? $attributes['url'] : '';
$pkiw_image       = is_string( $attributes['image'] ?? null ) ? $attributes['image'] : '';
$pkiw_image_alt   = is_string( $attributes['imageAlt'] ?? null ) ? $attributes['imageAlt'] : '';
$pkiw_price       = trim( is_string( $attributes['price'] ?? null ) ? $attributes['price'] : '' );
$pkiw_reason      = trim( is_string( $attributes['reason'] ?? null ) ? $attributes['reason'] : '' );
$pkiw_wished_at   = is_string( $attributes['wishedAt'] ?? null ) ? $attributes['wishedAt'] : '';
$pkiw_rel         = is_string( $attributes['rel'] ?? null ) ? $attributes['rel'] : '';
$pkiw_type        = array_key_exists( 'wishType', $attributes ) && is_string( $attributes['wishType'] ) ? $attributes['wishType'] : 'item';
$pkiw_priority    = array_key_exists( 'priority', $attributes ) && is_string( $attributes['priority'] ) ? $attributes['priority'] : 'medium';
$pkiw_post_id     = ( $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ) ? (int) $block->context['postId'] : (int) get_the_ID();
$pkiw_post        = $pkiw_post_id > 0 ? get_post( $pkiw_post_id ) : null;
$pkiw_own_view    = $pkiw_post instanceof \WP_Post && is_singular() && (int) get_queried_object_id() === (int) $pkiw_post->ID;
$pkiw_in_stream   = function_exists( 'PKIW\is_rendering_stream_card' ) && \PKIW\is_rendering_stream_card();
$pkiw_href        = \PKIW\Wish_Archive::link_url( $pkiw_url );
$pkiw_picture     = \PKIW\Wish_Archive::picture(
	$pkiw_post_id,
	[
		'image'    => $pkiw_image,
		'imageAlt' => $pkiw_image_alt,
	]
);
$pkiw_priority    = \PKIW\Wish_Archive::priority( $pkiw_priority );
$pkiw_type_label  = \PKIW\Wish_Archive::type_label( $pkiw_type );
$pkiw_link_rel    = trim( (string) preg_replace( '/\s+/', ' ', $pkiw_rel ) );
$pkiw_link_rel    = '' !== $pkiw_link_rel ? 'noopener noreferrer ' . $pkiw_link_rel : 'noopener noreferrer';
$pkiw_has_picture = '' !== $pkiw_picture['url'];
$pkiw_has_reason  = ! $pkiw_in_stream && '' !== $pkiw_reason;
$pkiw_classes     = 'pk-card k-wish h-cite pk-wish';
if ( $pkiw_has_picture ) {
	$pkiw_classes .= ' pk-wish--has-photo';
}
if ( $pkiw_has_reason ) {
	$pkiw_classes .= ' pk-wish--has-reason';
}

$pkiw_wrapper_attrs = get_block_wrapper_attributes(
	[
		'class'         => $pkiw_classes,
		'data-priority' => $pkiw_priority,
	]
);

[ $pkiw_wished_iso, $pkiw_wished_display ] = \PKIW\card_calendar_date( $pkiw_wished_at );

ob_start();
?>
<article <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="pk-badge"><?php echo get_kind_icon_svg( 'wish' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
	<div class="pk-body">
		<span class="pk-kindlabel"><?php echo esc_html( get_kind_label( __( 'Wish', 'post-kinds-for-indieweb-in-block-themes' ), 'wish', 'wish-card' ) ); ?></span>

		<div class="pk-caption">
			<?php if ( $pkiw_own_view ) : ?>
				<?php if ( '' !== $pkiw_title ) : ?>
					<data class="p-name" value="<?php echo esc_attr( $pkiw_title ); ?>" hidden></data>
				<?php endif; ?>
			<?php elseif ( '' !== $pkiw_title ) : ?>
				<h2 class="pk-title p-name">
					<?php if ( '' !== $pkiw_href ) : ?>
						<a class="u-url u-wish-of" href="<?php echo esc_url( $pkiw_href ); ?>" target="_blank" rel="<?php echo esc_attr( $pkiw_link_rel ); ?>"<?php echo \PKIW\pkiw_new_tab_label_attr( $pkiw_title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $pkiw_title ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $pkiw_title ); ?>
					<?php endif; ?>
				</h2>
			<?php endif; ?>

			<?php if ( $pkiw_in_stream ) : ?>
				<p class="pk-sub">
					<span class="pk-wish-chip"><?php echo esc_html( $pkiw_type_label ); ?></span>
					<span class="pk-wish-chip"><?php echo esc_html( \PKIW\Wish_Archive::priority_text( $pkiw_priority ) ); ?></span>
					<?php if ( '' !== $pkiw_price ) : ?>
						<span class="pk-wish-chip"><?php echo esc_html( $pkiw_price ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>

		<?php if ( $pkiw_has_picture ) : ?>
			<div class="pk-media pk-wish-photo">
				<img class="pk-thumb u-photo" src="<?php echo esc_url( $pkiw_picture['url'] ); ?>" alt="<?php echo esc_attr( $pkiw_picture['alt'] ); ?>" loading="lazy"<?php echo $pkiw_picture['remote'] ? ' referrerpolicy="no-referrer"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
			</div>
		<?php endif; ?>

		<?php if ( ! $pkiw_in_stream ) : ?>
			<div class="pk-wish-listing">
				<dl class="pk-wish-rows">
					<div class="pk-wish-row">
						<dt><?php echo esc_html__( 'Type', 'post-kinds-for-indieweb-in-block-themes' ); ?></dt>
						<dd><?php echo esc_html( $pkiw_type_label ); ?></dd>
					</div>
					<?php if ( '' !== $pkiw_price ) : ?>
						<div class="pk-wish-row">
							<dt><?php echo esc_html__( 'Price', 'post-kinds-for-indieweb-in-block-themes' ); ?></dt>
							<dd><span class="pk-wish-price"><?php echo esc_html( $pkiw_price ); ?></span></dd>
						</div>
					<?php endif; ?>
					<div class="pk-wish-row">
						<dt><?php echo esc_html__( 'Priority', 'post-kinds-for-indieweb-in-block-themes' ); ?></dt>
						<dd><span class="pk-wish-priority"><?php echo esc_html( \PKIW\Wish_Archive::priority_label( $pkiw_priority ) ); ?></span></dd>
					</div>
					<?php if ( '' !== $pkiw_wished_iso ) : ?>
						<div class="pk-wish-row">
							<dt><?php echo esc_html__( 'Wished', 'post-kinds-for-indieweb-in-block-themes' ); ?></dt>
							<dd><time class="dt-published" datetime="<?php echo esc_attr( $pkiw_wished_iso ); ?>"><?php echo esc_html( $pkiw_wished_display ); ?></time></dd>
						</div>
					<?php endif; ?>
				</dl>
			</div>
		<?php endif; ?>

		<?php if ( $pkiw_own_view && '' !== $pkiw_href ) : ?>
			<?php $pkiw_view_item = __( 'View item', 'post-kinds-for-indieweb-in-block-themes' ); ?>
			<p class="pk-original"><a class="u-url u-wish-of" href="<?php echo esc_url( $pkiw_href ); ?>" target="_blank" rel="<?php echo esc_attr( $pkiw_link_rel ); ?>"<?php echo \PKIW\pkiw_new_tab_label_attr( $pkiw_view_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_html( $pkiw_view_item ); ?></a></p>
		<?php endif; ?>

		<?php if ( $pkiw_has_reason ) : ?>
			<div class="pk-note pk-wish-reason">
				<span class="pk-wish-reason__label"><?php echo esc_html__( 'Reason', 'post-kinds-for-indieweb-in-block-themes' ); ?></span>
				<p class="p-content"><?php echo esc_html( $pkiw_reason ); ?></p>
			</div>
		<?php endif; ?>
	</div>
</article>
<?php
echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
