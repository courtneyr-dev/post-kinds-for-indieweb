<?php
/**
 * How a group source turns a stored value into heading text.
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
 * Label modes: verbatim (hosts, tags, authors, series), title (first letter
 * upper, the #230 rule) and a plugin label map (statuses, types).
 */
final class Label_Mode {

	/**
	 * Mode: verbatim, title or map.
	 *
	 * @var string
	 */
	private string $mode;

	/**
	 * Lowercase value => label, or a closure returning that map.
	 *
	 * @var array<string, string>|\Closure|null
	 */
	private $map;

	/**
	 * What a value missing from the map prints as: verbatim or title.
	 *
	 * @var string
	 */
	private string $fallback;

	/**
	 * Constructor.
	 *
	 * @param string                              $mode     Mode.
	 * @param array<string, string>|\Closure|null $map      Label map.
	 * @param string                              $fallback Fallback mode.
	 */
	private function __construct( string $mode, $map = null, string $fallback = 'verbatim' ) {
		$this->mode     = $mode;
		$this->map      = $map;
		$this->fallback = $fallback;
	}

	/**
	 * Print the value as stored.
	 *
	 * @return self
	 */
	public static function verbatim(): self {
		return new self( 'verbatim' );
	}

	/**
	 * Upper-case the first letter, as the #230 menus do.
	 *
	 * @return self
	 */
	public static function title(): self {
		return new self( 'title' );
	}

	/**
	 * Look the value up in a label map.
	 *
	 * @param array<string, string>|\Closure $map      Lowercase value => label, or a closure returning it.
	 * @param string                         $fallback 'verbatim' or 'title' for values the map lacks.
	 * @return self
	 * @throws \InvalidArgumentException When the fallback is neither.
	 */
	public static function map( $map, string $fallback = 'verbatim' ): self {
		if ( ! in_array( $fallback, [ 'verbatim', 'title' ], true ) ) {
			throw new \InvalidArgumentException( 'A label map falls back to "verbatim" or "title".' );
		}

		return new self( 'map', $map, $fallback );
	}

	/**
	 * Heading text for a stored value.
	 *
	 * @param string $raw Stored value.
	 * @return string
	 */
	public function format( string $raw ): string {
		$raw  = trim( $raw );
		$mode = $this->mode;
		if ( 'map' === $mode ) {
			$map = $this->map instanceof \Closure ? ( $this->map )() : $this->map;
			foreach ( is_array( $map ) ? $map : [] as $value => $label ) {
				if ( mb_strtolower( trim( (string) $value ) ) === mb_strtolower( $raw ) && is_string( $label ) && '' !== $label ) {
					return $label;
				}
			}
			$mode = $this->fallback;
		}

		return 'title' === $mode ? mb_strtoupper( mb_substr( $raw, 0, 1 ) ) . mb_substr( $raw, 1 ) : $raw;
	}
}
