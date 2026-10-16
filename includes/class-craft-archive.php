<?php
/**
 * The craft kind archive: sections by craft type, and the facts a theme prints.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Grouping\Label_Mode;
use PKIW\Grouping\Meta_Source;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sections the craft archive by the craft type a post stores, and reads a
 * craft post's facts for the theme (issue 249).
 *
 * The sections are the grouped archive engine's: a meta source on
 * `_pkiw_craft_type` with verbatim labels, so a type prints as the author
 * wrote it, case kept. Case variants share a section, and its heading prints
 * the first post's stored value. A post with no type files in the empty
 * group, which the archive-sections marker in the template labels. The
 * source turns on the protected-post flag, so a password-protected post
 * files in that empty group too and its type never prints as a heading. The
 * code names no craft type: the author's words are the only vocabulary.
 *
 * The archive-sections marker in the template's loop turns the sections on
 * and sizes the page. Craft has no group-fields row, which would route the
 * labels through the legacy path that capitalizes them.
 */
final class Craft_Archive {

	/**
	 * Kind slug this archive belongs to.
	 *
	 * @var string
	 */
	public const KIND = 'craft';

	/**
	 * Group source id.
	 *
	 * @var string
	 */
	public const SOURCE = 'craft-type';

	/**
	 * Meta key the craft type is stored under.
	 *
	 * @var string
	 */
	public const TYPE_META = '_pkiw_craft_type';

	/**
	 * Meta key the minutes spent are stored under.
	 *
	 * @var string
	 */
	public const MINUTES_META = '_pkiw_craft_minutes';

	/**
	 * Register the source and the facts reader.
	 *
	 * @return void
	 */
	public function register(): void {
		Grouped_Archive::register_source( self::source(), self::KIND );
		Kind_Facts::register( self::KIND, [ self::class, 'facts' ] );
	}

	/**
	 * The craft type source the archive is sectioned by.
	 *
	 * Labels are the stored value, verbatim. Spaces around a value are
	 * trimmed in PHP and SQL alike, and protected posts file in the empty
	 * group.
	 *
	 * @return Meta_Source
	 */
	public static function source(): Meta_Source {
		return new Meta_Source( self::SOURCE, [ self::TYPE_META ], [], Label_Mode::verbatim(), '', true );
	}

	/**
	 * The facts a theme prints for a craft post.
	 *
	 * Read through Kind_Facts::get(), which hides them for a post the
	 * requester can't see. The reader asks the same question, so a direct call
	 * is as private. The type is the stored text, trimmed, case kept. A
	 * synthetic name (the kind and date of an untitled post) is never a title:
	 * `name_is_title` says which it is, so a theme gives only a real title
	 * p-name. The reader never reads the post's content for a name.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Craft facts, or [] when the post can't be shown.
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) ) {
			return [];
		}

		$type    = self::stored_text( $post->ID, self::TYPE_META );
		$minutes = max( 0, (int) self::stored_text( $post->ID, self::MINUTES_META ) );

		$name_is_title = '' !== trim( (string) $post->post_title );
		$name          = $name_is_title ? get_the_title( $post ) : untitled_name( $post, false );

		return [
			'craft_type'          => $type,
			'minutes'             => $minutes,
			'duration_iso'        => card_minutes_iso( $minutes ),
			'duration_label'      => card_minutes_label( $minutes ),
			'name'                => $name,
			'name_is_title'       => $name_is_title,
			'picture'             => kind_picture( $post->ID ),
			'featured_in_content' => featured_in_content( $post->ID ),
		];
	}

	/**
	 * A post's first stored row of a key, as trimmed text.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return string
	 */
	private static function stored_text( int $post_id, string $key ): string {
		$value = get_metadata_raw( 'post', $post_id, $key, true );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
