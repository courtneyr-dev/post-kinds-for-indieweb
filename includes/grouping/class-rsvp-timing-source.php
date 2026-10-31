<?php
/**
 * Group RSVPs into upcoming and past sections.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW\Grouping;

use PKIW\Rsvp_Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order RSVP archive posts by their derived event start.
 */
final class Rsvp_Timing_Source implements Group_Source {

	use Source_Basics;

	/**
	 * Set the frozen source identifier.
	 */
	public function __construct() {
		$this->init_basics( Rsvp_Archive::SOURCE, '', false );
	}

	/**
	 * Build the prepared grouping and within-section order expressions.
	 *
	 * @param \WP_Query $query Query being ordered.
	 * @return array{value:string, rank:string, within:string[]}
	 */
	public function sql( \WP_Query $query ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		global $wpdb;

		$sort  = "(SELECT pkiw_r.meta_value FROM {$wpdb->postmeta} pkiw_r WHERE pkiw_r.post_id = {$wpdb->posts}.ID AND pkiw_r.meta_key = '_pkiw_rsvp_sort' ORDER BY pkiw_r.meta_id ASC LIMIT 1)";
		$now   = $wpdb->prepare( '%s', Rsvp_Archive::now() );
		$value = "CASE WHEN LENGTH({$wpdb->posts}.post_password) = 0 AND {$sort} >= {$now} THEN 'upcoming' ELSE 'past' END";

		return [
			'value'  => $value,
			'rank'   => "CASE {$value} WHEN 'upcoming' THEN 0 ELSE 1 END",
			'within' => [
				"CASE WHEN {$value} = 'upcoming' THEN {$sort} END ASC",
				"CASE WHEN {$value} = 'past' THEN COALESCE(IF(LENGTH({$wpdb->posts}.post_password) > 0, NULL, {$sort}), {$wpdb->posts}.post_date_gmt) END DESC",
			],
		];
	}

	/**
	 * Resolve the same group in PHP.
	 *
	 * @param \WP_Post       $post  Post.
	 * @param \WP_Query|null $query Query the post came from.
	 * @return Archive_Group
	 */
	public function group_of( \WP_Post $post, ?\WP_Query $query = null ): Archive_Group { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$group = Rsvp_Archive::group_of_post( $post );

		return new Archive_Group( $group, $group, $group );
	}

	/**
	 * Translate a frozen group key.
	 *
	 * @param Archive_Group        $group    Group.
	 * @param array<string, mixed> $settings Entry settings.
	 * @return string
	 */
	public function label( Archive_Group $group, array $settings ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return Label_Mode::map( static fn(): array => Rsvp_Archive::group_labels() )->format( $group->raw() );
	}

	/**
	 * This source never produces an empty group.
	 *
	 * @return string
	 */
	public function empty_label(): string {
		return '';
	}
}
