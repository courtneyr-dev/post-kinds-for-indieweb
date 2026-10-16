<?php
/**
 * Craft Card Block - Server-side Render
 *
 * What the author stored about a craft project: the craft type, verbatim, and
 * the time spent. One description list that the front end, the editor's
 * ServerSideRender, feeds and the text ATmosphere reads all print. It carries
 * no microformats classes (the post's h-entry holds them), no date, no status
 * and no icon. A card with neither a type nor a time prints nothing.
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

use function PKIW\card_minutes_iso;
use function PKIW\card_minutes_label;

$pkiw_craft_type    = trim( (string) ( $attributes['craftType'] ?? '' ) );
$pkiw_craft_minutes = max( 0, (int) ( $attributes['timeMinutes'] ?? 0 ) );

if ( '' === $pkiw_craft_type && $pkiw_craft_minutes <= 0 ) {
	return;
}

$pkiw_craft_iso = card_minutes_iso( $pkiw_craft_minutes );

$pkiw_wrapper_attrs = get_block_wrapper_attributes( [ 'class' => 'pkiw-craft-facts' ] );
?>
<dl <?php echo $pkiw_wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( '' !== $pkiw_craft_type ) : ?>
		<div class="pkiw-craft-facts__row">
			<dt><?php echo esc_html__( 'Craft type', 'post-kinds-for-indieweb-in-block-themes' ); ?></dt>
			<dd class="pkiw-craft-facts__type"><?php echo esc_html( $pkiw_craft_type ); ?></dd>
		</div>
	<?php endif; ?>
	<?php if ( '' !== $pkiw_craft_iso ) : ?>
		<div class="pkiw-craft-facts__row">
			<dt><?php echo esc_html__( 'Time spent', 'post-kinds-for-indieweb-in-block-themes' ); ?></dt>
			<dd><time datetime="<?php echo esc_attr( $pkiw_craft_iso ); ?>"><?php echo esc_html( card_minutes_label( $pkiw_craft_minutes ) ); ?></time></dd>
		</div>
	<?php endif; ?>
</dl>
