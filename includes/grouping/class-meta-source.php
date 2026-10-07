<?php
/**
 * Group by a post meta value.
 *
 * @package PKIW
 * @since 1.9.0
 */

declare(strict_types=1);

namespace PKIW\Grouping;

use PKIW\Kind_Archive_Layouts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Groups by one meta key, or by the first non-empty of several, read from the
 * post's first stored row of each (lowest meta_id). Values are trimmed of
 * spaces the same way in SQL and PHP, and a post with no row is in the empty
 * group whatever default the key registers.
 *
 * The legacy form is the #230 menu path: its SQL is byte-identical to the
 * shipped clause (untrimmed), and its labels come from
 * Kind_Archive_Layouts::group_label().
 */
final class Meta_Source implements Group_Source {

	use Source_Basics;

	/**
	 * Meta keys, read first-non-empty.
	 *
	 * @var string[]
	 */
	private array $keys;

	/**
	 * Explicit value order, lowercase.
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
	 * Kind slug, for the legacy form's labels.
	 *
	 * @var string
	 */
	private string $kind = '';

	/**
	 * Whether this is the #230 legacy form.
	 *
	 * @var bool
	 */
	private bool $legacy = false;

	/**
	 * Constructor.
	 *
	 * @param string          $id          Source id.
	 * @param string[]        $keys        One meta key, or several read first-non-empty. Any non-empty string.
	 * @param string[]        $order       Explicit value order; values not listed follow, A to Z.
	 * @param Label_Mode|null $label       Label mode. Default verbatim.
	 * @param string|\Closure $empty_label Label for the empty group; '' uses the engine's.
	 * @throws \InvalidArgumentException When the id is malformed or no key is given.
	 */
	public function __construct( string $id, array $keys, array $order = [], ?Label_Mode $label = null, $empty_label = '' ) {
		$this->init_basics( $id, $empty_label );

		$keys = array_values( array_filter( array_map( 'strval', $keys ), static fn( string $key ): bool => '' !== $key ) );
		if ( [] === $keys ) {
			throw new \InvalidArgumentException( 'A meta group source needs at least one meta key.' );
		}
		$this->keys = $keys;

		$order       = array_map( static fn( $value ): string => mb_strtolower( trim( (string) $value, ' ' ) ), $order );
		$this->order = array_values( array_unique( array_filter( $order, static fn( string $value ): bool => '' !== $value ) ) );
		$this->label = $label ?? Label_Mode::verbatim();
	}

	/**
	 * The #230 menu source for a kind's group field. Request-local; never registered.
	 *
	 * @param string $meta_key Meta key from Kind_Archive_Layouts::group_fields(); not empty.
	 * @param string $kind     Kind slug.
	 * @return self
	 */
	public static function legacy( string $meta_key, string $kind ): self {
		$source         = new self( 'legacy', [ $meta_key ] );
		$source->legacy = true;
		$source->kind   = $kind;

		return $source;
	}

	/**
	 * The meta key of the legacy form, or '' for any other meta source.
	 *
	 * @return string
	 */
	public function legacy_key(): string {
		return $this->legacy ? $this->keys[0] : '';
	}

	/**
	 * ORDER BY parts.
	 *
	 * @param \WP_Query $query Query.
	 * @return array{value:string, rank?:string, tiebreak?:string}
	 */
	public function sql( \WP_Query $query ): array {
		global $wpdb;

		if ( $this->legacy ) {
			return [
				'value' => $wpdb->prepare(
					"COALESCE((SELECT pkiw_g.meta_value FROM {$wpdb->postmeta} pkiw_g WHERE pkiw_g.post_id = {$wpdb->posts}.ID AND pkiw_g.meta_key = %s ORDER BY pkiw_g.meta_id ASC LIMIT 1), '')",
					$this->keys[0]
				),
			];
		}

		$firsts = [];
		foreach ( $this->keys as $key ) {
			$firsts[] = 'NULLIF(TRIM(' . $wpdb->prepare(
				"(SELECT pkiw_g.meta_value FROM {$wpdb->postmeta} pkiw_g WHERE pkiw_g.post_id = {$wpdb->posts}.ID AND pkiw_g.meta_key = %s ORDER BY pkiw_g.meta_id ASC LIMIT 1)",
				$key
			) . "), '')";
		}
		$value = 'COALESCE(' . implode( ', ', $firsts ) . ", '')";

		$parts = [
			'value'    => $value,
			'tiebreak' => "CAST(LOWER({$value}) AS BINARY)",
		];
		if ( [] !== $this->order ) {
			$cases = '';
			foreach ( $this->order as $at => $listed ) {
				$cases .= $wpdb->prepare( ' WHEN %s THEN %d', $listed, $at );
			}
			$parts['rank'] = "CASE LOWER({$value}){$cases} ELSE " . count( $this->order ) . ' END';
		}

		return $parts;
	}

	/**
	 * A post's group.
	 *
	 * @param \WP_Post       $post  Post.
	 * @param \WP_Query|null $query Unused.
	 * @return Archive_Group
	 */
	public function group_of( \WP_Post $post, ?\WP_Query $query = null ): Archive_Group { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( $this->legacy ) {
			$raw = trim( self::stored( get_metadata_raw( 'post', (int) $post->ID, $this->keys[0], true ) ) );
		} else {
			$raw = '';
			foreach ( $this->keys as $key ) {
				$raw = trim( self::stored( get_metadata_raw( 'post', (int) $post->ID, $key, true ) ), ' ' );
				if ( '' !== $raw ) {
					break;
				}
			}
		}
		$key = mb_strtolower( $raw );

		return new Archive_Group( $key, $raw, sanitize_title( $key ) );
	}

	/**
	 * Heading text for a group that has a value.
	 *
	 * @param Archive_Group        $group    Group.
	 * @param array<string, mixed> $settings Unused.
	 * @return string
	 */
	public function label( Archive_Group $group, array $settings ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $this->legacy ? Kind_Archive_Layouts::group_label( $this->kind, $group->raw() ) : $this->label->format( $group->raw() );
	}

	/**
	 * Heading text for the empty group.
	 *
	 * @return string
	 */
	public function empty_label(): string {
		return $this->legacy ? Kind_Archive_Layouts::group_label( $this->kind, '' ) : $this->resolve_empty_label();
	}
}
