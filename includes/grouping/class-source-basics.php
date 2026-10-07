<?php
/**
 * Id and empty-label handling shared by the bundled group sources.
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
 * Validates a source id and resolves an empty-group label given as a string
 * or as a closure that returns one (so translations load when it's read).
 */
trait Source_Basics {

	/**
	 * Source id.
	 *
	 * @var string
	 */
	private string $id = '';

	/**
	 * Empty-group label, or a closure returning it.
	 *
	 * @var string|\Closure
	 */
	private $empty = '';

	/**
	 * Store the id and empty label.
	 *
	 * @param string          $id          Source id.
	 * @param string|\Closure $empty_label Label for the empty group.
	 * @return void
	 * @throws \InvalidArgumentException When the id doesn't match /^[a-z0-9_:\-]{1,64}$/.
	 */
	private function init_basics( string $id, $empty_label ): void {
		if ( 1 !== preg_match( '/^[a-z0-9_:\-]{1,64}$/', $id ) ) {
			throw new \InvalidArgumentException( sprintf( 'Group source id "%s" must match [a-z0-9_:-], 1 to 64 characters.', esc_html( $id ) ) );
		}
		$this->id    = $id;
		$this->empty = $empty_label;
	}

	/**
	 * Source id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The empty-group label, trimmed.
	 *
	 * @return string
	 */
	private function resolve_empty_label(): string {
		$label = $this->empty instanceof \Closure ? ( $this->empty )() : $this->empty;

		return is_scalar( $label ) ? trim( (string) $label ) : '';
	}

	/**
	 * A stored meta value as the database holds it, for comparing with SQL.
	 *
	 * @param mixed $value Value from get_metadata_raw().
	 * @return string
	 */
	private static function stored( $value ): string {
		if ( null === $value ) {
			return '';
		}

		return is_scalar( $value ) ? (string) $value : (string) maybe_serialize( $value );
	}
}
