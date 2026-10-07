<?php
/**
 * Kind facts and the kind picture, for themes.
 *
 * A theme's kind adapters read these, never block attributes or raw meta.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The public facts for a kind post.
 *
 * @since 1.9.0
 *
 * @see Kind_Facts::get()
 *
 * @param int $post_id Post ID.
 * @return array<string, mixed> Facts, or [] when the post's kind has no reader or the post can't be shown.
 */
function kind_facts( int $post_id ): array {
	return Kind_Facts::get( $post_id );
}
