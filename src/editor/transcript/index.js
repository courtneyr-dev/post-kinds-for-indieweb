/**
 * Transcript notice and group variation registration.
 *
 * @package
 * @since   1.9.0
 */

import { __ } from '@wordpress/i18n';
import { registerBlockVariation } from '@wordpress/blocks';
import { registerPlugin } from '@wordpress/plugins';

import TranscriptNotice from './TranscriptNotice';

registerBlockVariation( 'core/group', {
	name: 'post-kinds-indieweb/transcript',
	title: __( 'Transcript', 'post-kinds-for-indieweb-in-block-themes' ),
	description: __(
		'Add a written transcript for audio content.',
		'post-kinds-for-indieweb-in-block-themes'
	),
	attributes: { className: 'pkiw-transcript' },
	isActive: [ 'className' ],
	innerBlocks: [ [ 'core/paragraph' ] ],
	scope: [ 'inserter' ],
} );

registerPlugin( 'pkiw-transcript-notice', { render: TranscriptNotice } );
