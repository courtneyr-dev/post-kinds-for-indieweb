<?php
/**
 * Group by a taxonomy term.
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
 * Files each post under one of its terms: the term the query is filtered to
 * when it names exactly one (`?tag=<slug>`), otherwise the first by name (case
 * folded, then term id), with an optional term filed after all others under
 * its own name (the default category). The heading is the term name as
 * stored. Two terms with the same name stay separate sections.
 */
final class Term_Source implements Group_Source {

	use Source_Basics;

	/**
	 * Taxonomy name.
	 *
	 * @var string
	 */
	private string $taxonomy;

	/**
	 * Returns the id of the term filed last, or null.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $last_term;

	/**
	 * Constructor.
	 *
	 * @param string          $id          Source id.
	 * @param string          $taxonomy    Taxonomy name.
	 * @param \Closure|null   $last_term   Returns the id of a term filed after all others, under its own name.
	 * @param string|\Closure $empty_label Label for posts with no term; '' uses the engine's.
	 * @throws \InvalidArgumentException On a malformed id or an empty taxonomy.
	 */
	public function __construct( string $id, string $taxonomy, ?\Closure $last_term = null, $empty_label = '' ) {
		$this->init_basics( $id, $empty_label );
		if ( '' === $taxonomy ) {
			throw new \InvalidArgumentException( 'A term group source needs a taxonomy.' );
		}
		$this->taxonomy  = $taxonomy;
		$this->last_term = $last_term;
	}

	/**
	 * ORDER BY parts.
	 *
	 * @param \WP_Query $query Query.
	 * @return array{value:string, last?:string, tiebreak:string}
	 */
	public function sql( \WP_Query $query ): array {
		$last  = $this->last_term_id();
		$id    = 'COALESCE(' . $this->pick( 'pkiw_t.term_id', $query, $last ) . ', 0)';
		$parts = [
			'value'    => 'COALESCE(' . $this->pick( 'pkiw_t.name', $query, $last ) . ", '')",
			'tiebreak' => $id,
		];
		if ( $last > 0 ) {
			$parts['last'] = "({$id} = " . $last . ')';
		}

		return $parts;
	}

	/**
	 * Subquery for one column of the term a post files under.
	 *
	 * @param string    $column 'pkiw_t.name' or 'pkiw_t.term_id'.
	 * @param \WP_Query $query  Query.
	 * @param int       $last   Id of the term filed last, or 0.
	 * @return string
	 */
	private function pick( string $column, \WP_Query $query, int $last ): string {
		global $wpdb;

		$column = 'pkiw_t.name' === $column ? 'pkiw_t.name' : 'pkiw_t.term_id';

		return (string) $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $column is one of two literal column names.
			"(SELECT {$column} FROM {$wpdb->term_relationships} pkiw_tr INNER JOIN {$wpdb->term_taxonomy} pkiw_tt ON pkiw_tt.term_taxonomy_id = pkiw_tr.term_taxonomy_id INNER JOIN {$wpdb->terms} pkiw_t ON pkiw_t.term_id = pkiw_tt.term_id WHERE pkiw_tr.object_id = {$wpdb->posts}.ID AND pkiw_tt.taxonomy = %s ORDER BY (pkiw_t.term_id = %d) DESC, (pkiw_t.term_id = %d) ASC, CAST(LOWER(pkiw_t.name) AS BINARY) ASC, pkiw_t.term_id ASC LIMIT 1)",
			$this->taxonomy,
			$this->pinned_term( $query ),
			$last
		);
	}

	/**
	 * A post's group: the term it files under.
	 *
	 * @param \WP_Post       $post  Post.
	 * @param \WP_Query|null $query Query the post came from.
	 * @return Archive_Group
	 */
	public function group_of( \WP_Post $post, ?\WP_Query $query = null ): Archive_Group {
		$terms = get_the_terms( $post, $this->taxonomy );
		if ( ! is_array( $terms ) || [] === $terms ) {
			return new Archive_Group( '', '', '' );
		}

		$pin  = $this->pinned_term( $query );
		$last = $this->last_term_id();
		usort(
			$terms,
			static function ( \WP_Term $a, \WP_Term $b ) use ( $pin, $last ): int {
				if ( ( $a->term_id === $pin ) !== ( $b->term_id === $pin ) ) {
					return $a->term_id === $pin ? -1 : 1;
				}
				if ( ( $a->term_id === $last ) !== ( $b->term_id === $last ) ) {
					return $a->term_id === $last ? 1 : -1;
				}
				$by_name = strcmp( mb_strtolower( $a->name ), mb_strtolower( $b->name ) );

				return 0 !== $by_name ? $by_name : $a->term_id <=> $b->term_id;
			}
		);
		$term = $terms[0];

		return new Archive_Group( (string) $term->term_id, (string) $term->name, (string) $term->slug );
	}

	/**
	 * The id of the one term the query is filtered to in this taxonomy, or 0.
	 *
	 * @param \WP_Query|null $query Query.
	 * @return int
	 */
	private function pinned_term( ?\WP_Query $query ): int {
		if ( ! $query instanceof \WP_Query || ! $query->tax_query instanceof \WP_Tax_Query ) {
			return 0;
		}
		$queried = $query->tax_query->queried_terms[ $this->taxonomy ] ?? null;
		$terms   = is_array( $queried ) ? (array) ( $queried['terms'] ?? [] ) : [];
		if ( 1 !== count( $terms ) ) {
			return 0;
		}

		$fields = [
			'term_id'          => 'id',
			'id'               => 'id',
			'slug'             => 'slug',
			'name'             => 'name',
			'term_taxonomy_id' => 'term_taxonomy_id',
		];
		$field  = $fields[ (string) ( $queried['field'] ?? 'term_id' ) ] ?? '';
		$term   = '' !== $field ? get_term_by( $field, reset( $terms ), $this->taxonomy ) : false;

		return $term instanceof \WP_Term ? (int) $term->term_id : 0;
	}

	/**
	 * The id of the term filed last, or 0.
	 *
	 * @return int
	 */
	private function last_term_id(): int {
		return $this->last_term instanceof \Closure ? max( 0, (int) ( $this->last_term )() ) : 0;
	}

	/**
	 * Heading text: the term name as stored.
	 *
	 * @param Archive_Group        $group    Group.
	 * @param array<string, mixed> $settings Unused.
	 * @return string
	 */
	public function label( Archive_Group $group, array $settings ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $group->raw();
	}

	/**
	 * Heading text for posts with no term.
	 *
	 * @return string
	 */
	public function empty_label(): string {
		return $this->resolve_empty_label();
	}
}
