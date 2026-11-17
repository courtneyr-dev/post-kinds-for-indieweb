<?php
/**
 * Neutral printable zine document.
 *
 * @package PKIW
 * @since 1.9.2
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PKIW\Zine_Export;

$pkiw_zine_saved_user = get_current_user_id();
wp_set_current_user( 0 );
try {
	$pkiw_zine_booklet = Zine_Export::render_booklet();
	Zine_Export::prepare_print_assets();
	$pkiw_zine_manifest       = Zine_Export::manifest();
	$pkiw_zine_title          = 'stream' === ( $pkiw_zine_manifest['source'] ?? '' )
		? get_the_title( (int) ( $pkiw_zine_manifest['page_id'] ?? 0 ) )
		: single_term_title( '', false );
	$pkiw_zine_document_title = current_theme_supports( 'title-tag' )
		? ''
		/* translators: %s: zine source title. */
		: sprintf( __( '%s: zine', 'post-kinds-for-indieweb-in-block-themes' ), $pkiw_zine_title );
	?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php if ( '' !== $pkiw_zine_document_title ) : ?>
		<title><?php echo esc_html( $pkiw_zine_document_title ); ?></title>
	<?php endif; ?>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'pkiw-zine-print' ); ?>>
	<?php echo $pkiw_zine_booklet; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every dynamic value is escaped by the renderer. ?>
	<?php wp_footer(); ?>
</body>
</html>
	<?php
} finally {
	wp_set_current_user( $pkiw_zine_saved_user );
}
