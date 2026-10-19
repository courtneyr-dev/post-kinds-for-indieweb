/**
 * Transcript notice for audio posts.
 *
 * @package
 * @since   1.9.0
 */

import { __ } from '@wordpress/i18n';
import { useEffect, useRef } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { PluginPrePublishPanel } from '@wordpress/editor';

import { hasAudioPlayer, hasFilledTranscript } from './find-transcript';

export const NOTICE_ID = 'pkiw-transcript';
export const NOTICE_TEXT = __(
	"This audio post has no transcript. Add one for readers who can't listen (WCAG 1.2.1).",
	'post-kinds-for-indieweb-in-block-themes'
);

export default function TranscriptNotice() {
	const { kindSlug, blocks } = useSelect( ( select ) => {
		const kindIds =
			select( 'core/editor' ).getEditedPostAttribute( 'kind' ) || [];
		const kinds =
			kindIds.length > 0
				? select( 'core' ).getEntityRecords( 'taxonomy', 'kind', {
						per_page: -1,
					} )
				: null;
		const kind = ( kinds || [] ).find(
			( item ) => item.id === kindIds[ 0 ]
		);

		return {
			kindSlug: kind ? kind.slug : null,
			blocks: select( 'core/block-editor' ).getBlocks(),
		};
	}, [] );
	const { createNotice, removeNotice } = useDispatch( 'core/notices' );
	const needsTranscript =
		'audio' === kindSlug &&
		hasAudioPlayer( blocks ) &&
		! hasFilledTranscript( blocks );
	const reported = useRef( false );

	useEffect( () => {
		if ( ! needsTranscript ) {
			if ( reported.current ) {
				reported.current = false;
				removeNotice( NOTICE_ID );
			}
			return;
		}

		if ( ! reported.current ) {
			reported.current = true;
			createNotice( 'warning', NOTICE_TEXT, {
				id: NOTICE_ID,
				isDismissible: true,
			} );
		}
	}, [ needsTranscript, createNotice, removeNotice ] );

	useEffect(
		() => () => {
			if ( reported.current ) {
				removeNotice( NOTICE_ID );
			}
		},
		[ removeNotice ]
	);

	if ( ! needsTranscript ) {
		return null;
	}

	return (
		<PluginPrePublishPanel
			title={ __(
				'Transcript',
				'post-kinds-for-indieweb-in-block-themes'
			) }
			initialOpen
		>
			<p>{ NOTICE_TEXT }</p>
		</PluginPrePublishPanel>
	);
}
