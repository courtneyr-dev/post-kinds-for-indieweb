<?php
/**
 * Clears the per-post render state a rendered issue archive, single or Stream card leaves behind.
 *
 * The Microformats instance remembers each post ID whose post_class() ran, and the Stream card
 * remembers each ID it rooted as an entry. Both live for the whole PHPUnit process. StreamCardGoldenTest
 * renders fixtures with fixed IDs from 9001 up, and a full run can reach that range, so a leftover ID
 * changes its recorded output.
 *
 * @package PKIW
 */

declare(strict_types=1);

trait Issue_Render_State_Reset {

	/**
	 * Forget which posts have rendered as entry roots.
	 */
	protected function reset_render_state(): void {
		unset( $GLOBALS['pkiw_stream_card_root_seen'] );

		$microformats = \PKIW\Plugin::get_instance()->get_microformats();
		if ( null !== $microformats ) {
			$seen = new ReflectionProperty( $microformats, 'post_class_seen' );
			$seen->setValue( $microformats, [] );
		}
	}
}
