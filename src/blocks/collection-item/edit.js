/**
 * Collection item editor.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	MediaUpload,
	MediaUploadCheck,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	Button,
	Notice,
	PanelBody,
	Placeholder,
	RadioControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';

export default function Edit( { attributes, setAttributes } ) {
	const { postId, title, date, source, url, mediaId, addedAt, headingLevel } =
		attributes;
	const [ mode, setMode ] = useState( postId > 0 ? 'post' : 'external' );
	const [ search, setSearch ] = useState( '' );
	const blockProps = useBlockProps();

	useEffect( () => {
		if ( ! addedAt ) {
			setAttributes( { addedAt: new Date().toISOString() } );
		}
	}, [ addedAt, setAttributes ] );

	const { referencedPost, searchResults } = useSelect(
		( select ) => {
			const core = select( 'core' );
			return {
				referencedPost:
					postId > 0
						? core.getEntityRecord( 'postType', 'post', postId )
						: null,
				searchResults: core.getEntityRecords( 'postType', 'post', {
					search,
					status: 'publish',
					per_page: 10,
				} ),
			};
		},
		[ postId, search ]
	);

	const changeMode = ( value ) => {
		setMode( value );
		if ( 'external' === value ) {
			setAttributes( { postId: 0 } );
		}
	};

	const choosePost = ( post ) => {
		setAttributes( {
			postId: post.id,
			title: '',
			date: '',
			url: '',
			mediaId: 0,
		} );
	};

	const isHiddenReference =
		postId > 0 &&
		( ! referencedPost ||
			'publish' !== referencedPost.status ||
			referencedPost.password );

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Collection item',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<SelectControl
						label={ __(
							'Heading level',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ headingLevel }
						options={ [
							{ label: '2', value: 2 },
							{ label: '3', value: 3 },
							{ label: '4', value: 4 },
						] }
						onChange={ ( value ) =>
							setAttributes( { headingLevel: Number( value ) } )
						}
					/>
					<TextControl
						label={ __(
							'Source',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ source }
						onChange={ ( value ) =>
							setAttributes( { source: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<RadioControl
				label={ __(
					'Item type',
					'post-kinds-for-indieweb-in-block-themes'
				) }
				selected={ mode }
				options={ [
					{
						label: __(
							'Post on this site',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: 'post',
					},
					{
						label: __(
							'Something else',
							'post-kinds-for-indieweb-in-block-themes'
						),
						value: 'external',
					},
				] }
				onChange={ changeMode }
			/>

			{ 'post' === mode && (
				<>
					<TextControl
						label={ __(
							'Search posts',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ search }
						onChange={ setSearch }
					/>
					{ Array.isArray( searchResults ) &&
						searchResults.map( ( post ) => (
							<Button
								key={ post.id }
								onClick={ () => choosePost( post ) }
							>
								{ post.title?.rendered ||
									__(
										'Untitled',
										'post-kinds-for-indieweb-in-block-themes'
									) }
							</Button>
						) ) }
				</>
			) }

			{ isHiddenReference && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						"This post isn't public, so it won't show on the site.",
						'post-kinds-for-indieweb-in-block-themes'
					) }
				</Notice>
			) }

			{ 'external' === mode && (
				<>
					<TextControl
						label={ __(
							'Title',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ title }
						onChange={ ( value ) =>
							setAttributes( { title: value } )
						}
					/>
					<TextControl
						label={ __(
							'Date',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						type="date"
						value={ date }
						onChange={ ( value ) =>
							setAttributes( { date: value } )
						}
					/>
					<TextControl
						label={ __(
							'URL',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ url }
						onChange={ ( value ) =>
							setAttributes( { url: value } )
						}
					/>
					<MediaUploadCheck>
						<MediaUpload
							allowedTypes={ [ 'image' ] }
							value={ mediaId }
							onSelect={ ( media ) =>
								setAttributes( { mediaId: media.id } )
							}
							render={ ( { open } ) => (
								<Button onClick={ open }>
									{ mediaId
										? __(
												'Replace image',
												'post-kinds-for-indieweb-in-block-themes'
											)
										: __(
												'Choose image',
												'post-kinds-for-indieweb-in-block-themes'
											) }
								</Button>
							) }
						/>
					</MediaUploadCheck>
				</>
			) }

			<ServerSideRender
				block="post-kinds-indieweb/collection-item"
				attributes={ attributes }
				EmptyResponsePlaceholder={ () => (
					<Placeholder
						label={ __(
							'Collection item',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					>
						{ __(
							'Choose a public post or enter item details.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					</Placeholder>
				) }
			/>
		</div>
	);
}
