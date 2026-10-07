<?php
/**
 * One group of a grouped kind archive.
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
 * A post's group: the key that decides where a section starts, the value its
 * label is made from, and a slug a theme can select on.
 */
final class Archive_Group {

	/**
	 * Section identity. '' is the empty group.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Label input: a stored value, a term name, a case id or a bucket's first day (Y-m-d).
	 *
	 * @var string
	 */
	private string $raw;

	/**
	 * Slug for `data-pkiw-group`.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Constructor.
	 *
	 * @param string $key  Section identity; '' is the empty group.
	 * @param string $raw  Label input.
	 * @param string $slug Slug for `data-pkiw-group`.
	 */
	public function __construct( string $key, string $raw, string $slug = '' ) {
		$this->key  = $key;
		$this->raw  = $raw;
		$this->slug = $slug;
	}

	/**
	 * Section identity; '' is the empty group.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Label input.
	 *
	 * @return string
	 */
	public function raw(): string {
		return $this->raw;
	}

	/**
	 * Slug for `data-pkiw-group`.
	 *
	 * @return string
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * Whether this is the group of posts with no value.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return '' === $this->key;
	}
}
