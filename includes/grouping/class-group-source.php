<?php
/**
 * What a grouped kind archive groups its posts by.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW\Grouping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A group source: the SQL that orders posts by group and the PHP rule that
 * names each post's group. The two must agree, or a group splits into two
 * sections.
 *
 * Implementations are trusted PHP. Only plugin or site code builds one, and
 * nothing a visitor or editor types reaches the SQL. The bundled sources bind
 * every value through `$wpdb->prepare()`; the engine can't check that a
 * third-party source does. The engine adds directions, empty-group placement
 * and the date and ID tail.
 */
interface Group_Source {

	/**
	 * Source id, matching /^[a-z0-9_:\-]{1,64}$/.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Prepared SQL parts for ORDER BY, or null to order by date and ID only.
	 *
	 * - value: scalar expression, '' for the empty group.
	 * - last: optional 0/1 expression; 1 files a group after every other
	 *   group that has a value, in either section order.
	 * - rank: optional, sorts before LOWER(value) in the section order.
	 * - tiebreak: optional, sorts after LOWER(value) so groups that collate
	 *   equal stay contiguous.
	 *
	 * @param \WP_Query $query Query being ordered.
	 * @return array{value:string, last?:string, rank?:string, tiebreak?:string}|null
	 */
	public function sql( \WP_Query $query ): ?array;

	/**
	 * The group a post belongs to, by the same rule as sql().
	 *
	 * @param \WP_Post       $post  Post.
	 * @param \WP_Query|null $query Query the post came from, when there is one.
	 * @return Archive_Group
	 */
	public function group_of( \WP_Post $post, ?\WP_Query $query = null ): Archive_Group;

	/**
	 * Heading text for a group that has a value.
	 *
	 * @param Archive_Group        $group    Group.
	 * @param array<string, mixed> $settings Entry settings; `date_format` is a date format or ''.
	 * @return string
	 */
	public function label( Archive_Group $group, array $settings ): string;

	/**
	 * Heading text for the empty group, or '' for the engine's "Other".
	 *
	 * @return string
	 */
	public function empty_label(): string;
}
