/**
 * Promote-to-main-archive toggle.
 *
 * A document sidebar toggle bound to the `pkiw_promote` post meta. When on,
 * a post that would otherwise be classified as "stream" (by its kind) is
 * treated as "main" instead. Shown only for the `post` type.
 *
 * @package
 * @since 1.3.0
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { ToggleControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

const PromotePanel = () => {
	const { postType, promote } = useSelect(
		( select ) => ( {
			postType: select( 'core/editor' ).getCurrentPostType(),
			promote:
				select( 'core/editor' ).getEditedPostAttribute( 'meta' )
					?.pkiw_promote,
		} ),
		[]
	);
	const { editPost } = useDispatch( 'core/editor' );

	if ( postType !== 'post' ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel
			name="pkiw-promote"
			title={ __( 'Post surface', 'post-kinds-for-indieweb' ) }
		>
			<ToggleControl
				label={ __(
					'Promote to main archive',
					'post-kinds-for-indieweb'
				) }
				help={ __(
					'Show this post on the main archive even if its kind is normally stream-only.',
					'post-kinds-for-indieweb'
				) }
				checked={ !! promote }
				onChange={ ( value ) =>
					editPost( { meta: { pkiw_promote: value } } )
				}
			/>
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'pkiw-promote-panel', { render: PromotePanel } );
