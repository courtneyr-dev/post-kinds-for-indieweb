/**
 * Missing-alt notice for photo posts.
 *
 * Lists each image with no alt text in the pre-publish panel and raises one
 * dismissible notice, so a photo post doesn't go out with pictures a screen
 * reader can't describe. It never locks publishing and never writes alt text
 * into a block: the author decides what each picture says.
 *
 * @package
 * @since   1.9.0
 */

import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useRef } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { PluginPrePublishPanel } from '@wordpress/editor';
import { Button } from '@wordpress/components';

import { MISSING_ALT_KINDS, findImagesMissingAlt } from './find-missing-alt';

export const NOTICE_ID = 'pkiw-missing-alt';

/**
 * Notice text for a number of images.
 *
 * @param {number} count Images with no alt text.
 * @return {string} Translated message.
 */
const noticeText = ( count ) =>
	sprintf(
		/* translators: %d: number of images with no alt text. */
		_n(
			'%d image has no alt text. Add it so people using a screen reader can tell what the photo shows.',
			'%d images have no alt text. Add it so people using a screen reader can tell what each photo shows.',
			count,
			'post-kinds-for-indieweb-in-block-themes'
		),
		count
	);

export default function MissingAltNotice() {
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
	const { selectBlock } = useDispatch( 'core/block-editor' );

	const missing = useMemo(
		() =>
			MISSING_ALT_KINDS.includes( kindSlug )
				? findImagesMissingAlt( blocks )
				: [],
		[ kindSlug, blocks ]
	);

	// Images already reported. A dismissed notice stays dismissed until a new
	// image is missing its alt text.
	const reported = useRef( new Set() );

	useEffect( () => {
		if ( 0 === missing.length ) {
			if ( reported.current.size > 0 ) {
				reported.current = new Set();
				removeNotice( NOTICE_ID );
			}
			return;
		}

		const fresh = missing.filter(
			( item ) => ! reported.current.has( item.key )
		);

		if ( fresh.length > 0 ) {
			missing.forEach( ( item ) => reported.current.add( item.key ) );
			createNotice( 'warning', noticeText( missing.length ), {
				id: NOTICE_ID,
				isDismissible: true,
			} );
		}
	}, [ missing, createNotice, removeNotice ] );

	useEffect(
		() => () => {
			if ( reported.current.size > 0 ) {
				removeNotice( NOTICE_ID );
			}
		},
		[ removeNotice ]
	);

	if ( 0 === missing.length ) {
		return null;
	}

	return (
		<PluginPrePublishPanel
			title={ __(
				'Photos without alt text',
				'post-kinds-for-indieweb-in-block-themes'
			) }
			initialOpen
		>
			<p>{ noticeText( missing.length ) }</p>
			<ul>
				{ missing.map( ( item, index ) => (
					<li key={ item.key }>
						<Button
							variant="link"
							onClick={ () => selectBlock( item.clientId ) }
						>
							{ item.name ||
								sprintf(
									/* translators: %d: position of the image in the post. */
									__(
										'Image %d',
										'post-kinds-for-indieweb-in-block-themes'
									),
									index + 1
								) }
						</Button>
					</li>
				) ) }
			</ul>
		</PluginPrePublishPanel>
	);
}
