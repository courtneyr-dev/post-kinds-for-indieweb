<?php
/**
 * Group by a key computed from which meta a post has.
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
 * Files each post under the first case whose meta keys it has a non-blank
 * value for, in the order the cases are given, so an earlier case wins: a play
 * with a RAWG or Steam id is a video game even when it also has a BGG id. The
 * SQL is a CASE over EXISTS subqueries the plugin writes; no SQL comes from
 * outside code.
 */
final class Cases_Source implements Group_Source {

	use Source_Basics;

	/**
	 * Case id => meta keys, in precedence order.
	 *
	 * @var array<string, string[]>
	 */
	private array $cases = [];

	/**
	 * Explicit section order of case ids.
	 *
	 * @var string[]
	 */
	private array $order;

	/**
	 * Label mode.
	 *
	 * @var Label_Mode
	 */
	private Label_Mode $label;

	/**
	 * Constructor.
	 *
	 * @param string                  $id          Source id.
	 * @param array<string, string[]> $cases       Case id (/^[a-z0-9_\-]{1,32}$/) => meta keys, in precedence order.
	 * @param string[]                $order       Section order of case ids; unlisted cases follow, A to Z.
	 * @param Label_Mode|null         $label       Label mode for case ids. Default verbatim.
	 * @param string|\Closure         $empty_label Label for posts in no case; '' uses the engine's.
	 * @throws \InvalidArgumentException On a malformed id or case id, or an empty case map or key list.
	 */
	public function __construct( string $id, array $cases, array $order = [], ?Label_Mode $label = null, $empty_label = '' ) {
		$this->init_basics( $id, $empty_label );
		if ( [] === $cases ) {
			throw new \InvalidArgumentException( 'A cases group source needs at least one case.' );
		}
		foreach ( $cases as $case => $keys ) {
			$case = (string) $case;
			if ( 1 !== preg_match( '/^[a-z0-9_\-]{1,32}$/', $case ) ) {
				throw new \InvalidArgumentException( sprintf( 'Case id "%s" must match [a-z0-9_-], 1 to 32 characters.', esc_html( $case ) ) );
			}
			$keys = array_values( array_filter( array_map( 'strval', (array) $keys ), static fn( string $key ): bool => '' !== $key ) );
			if ( [] === $keys ) {
				throw new \InvalidArgumentException( sprintf( 'Case "%s" needs at least one meta key.', esc_html( $case ) ) );
			}
			$this->cases[ $case ] = $keys;
		}
		$this->order = array_values( array_intersect( array_map( 'strval', $order ), array_keys( $this->cases ) ) );
		$this->label = $label ?? Label_Mode::verbatim();
	}

	/**
	 * ORDER BY parts.
	 *
	 * @param \WP_Query $query Query.
	 * @return array{value:string, rank?:string}
	 */
	public function sql( \WP_Query $query ): array {
		global $wpdb;

		$when = '';
		foreach ( $this->cases as $case => $keys ) {
			$when .= $wpdb->prepare(
				" WHEN EXISTS (SELECT 1 FROM {$wpdb->postmeta} pkiw_m WHERE pkiw_m.post_id = {$wpdb->posts}.ID AND pkiw_m.meta_key IN (" . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ") AND TRIM(pkiw_m.meta_value) <> '') THEN %s",
				...array_merge( $keys, [ (string) $case ] )
			);
		}
		$parts = [ 'value' => "(CASE{$when} ELSE '' END)" ];

		if ( [] !== $this->order ) {
			$cases = '';
			foreach ( $this->order as $at => $listed ) {
				$cases .= $wpdb->prepare( ' WHEN %s THEN %d', $listed, $at );
			}
			$parts['rank'] = "CASE {$parts['value']}{$cases} ELSE " . count( $this->order ) . ' END';
		}

		return $parts;
	}

	/**
	 * A post's group: the first case it has a non-blank value for.
	 *
	 * @param \WP_Post       $post  Post.
	 * @param \WP_Query|null $query Unused.
	 * @return Archive_Group
	 */
	public function group_of( \WP_Post $post, ?\WP_Query $query = null ): Archive_Group { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		foreach ( $this->cases as $case => $keys ) {
			foreach ( $keys as $key ) {
				foreach ( (array) get_metadata_raw( 'post', (int) $post->ID, $key, false ) as $value ) {
					if ( '' !== trim( self::stored( $value ), ' ' ) ) {
						return new Archive_Group( (string) $case, (string) $case, (string) $case );
					}
				}
			}
		}

		return new Archive_Group( '', '', '' );
	}

	/**
	 * Heading text for a case.
	 *
	 * @param Archive_Group        $group    Group.
	 * @param array<string, mixed> $settings Unused.
	 * @return string
	 */
	public function label( Archive_Group $group, array $settings ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $this->label->format( $group->raw() );
	}

	/**
	 * Heading text for posts in no case.
	 *
	 * @return string
	 */
	public function empty_label(): string {
		return $this->resolve_empty_label();
	}
}
