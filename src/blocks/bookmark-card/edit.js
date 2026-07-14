/**
 * Bookmark Card Block - Edit Component
 *
 * Full inline editing with theme-aware styling and full sidebar controls.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
	RichText,
	MediaUpload,
	MediaUploadCheck,
} from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';

export default function Edit( { attributes, setAttributes } ) {
	const { title, url, description, image, imageAlt, author } = attributes;

	const blockProps = useBlockProps( {
		className: 'bookmark-card-block pk-card k-bookmark',
	} );

	const { editPost } = useDispatch( 'core/editor' );
	const currentKind = useSelect( ( select ) => {
		const terms =
			select( 'core/editor' ).getEditedPostAttribute(
				'indieblocks_kind'
			);
		return terms && terms.length > 0 ? terms[ 0 ] : null;
	}, [] );

	// Set post kind to "bookmark" when block is inserted
	useEffect( () => {
		if ( ! currentKind ) {
			wp.apiFetch( { path: '/wp/v2/kind?slug=bookmark' } )
				.then( ( terms ) => {
					if ( terms && terms.length > 0 ) {
						editPost( { indieblocks_kind: [ terms[ 0 ].id ] } );
					}
				} )
				.catch( () => {} );
		}
	}, [] );

	// Sync block attributes to post meta
	useEffect( () => {
		const metaUpdates = {};
		if ( title !== undefined ) {
			metaUpdates._pkiw_bookmark_title = title || '';
		}
		if ( url !== undefined ) {
			metaUpdates._pkiw_bookmark_url = url || '';
		}
		if ( author !== undefined ) {
			metaUpdates._pkiw_bookmark_author = author || '';
		}
		if ( image !== undefined ) {
			metaUpdates._pkiw_bookmark_image = image || '';
		}

		if ( Object.keys( metaUpdates ).length > 0 ) {
			editPost( { meta: metaUpdates } );
		}
	}, [ title, url, author, image ] );

	const handleImageSelect = ( media ) => {
		setAttributes( {
			image: media.url,
			imageAlt:
				media.alt ||
				title ||
				__(
					'Bookmarked content image',
					'post-kinds-for-indieweb-in-block-themes'
				),
		} );
	};

	const handleImageRemove = ( e ) => {
		e.stopPropagation();
		setAttributes( { image: '', imageAlt: '' } );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Bookmark Details',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ true }
				>
					<TextControl
						label={ __(
							'Title',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ title || '' }
						onChange={ ( value ) =>
							setAttributes( { title: value } )
						}
						placeholder={ __(
							'What did you bookmark?',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					<TextControl
						label={ __(
							'URL',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ url || '' }
						onChange={ ( value ) =>
							setAttributes( { url: value } )
						}
						type="url"
						placeholder={ __(
							'https://…',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					<TextControl
						label={ __(
							'Author',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ author || '' }
						onChange={ ( value ) =>
							setAttributes( { author: value } )
						}
						placeholder={ __(
							'Original author',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'Description',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ false }
				>
					<TextControl
						label={ __(
							'Description',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ description || '' }
						onChange={ ( value ) =>
							setAttributes( { description: value } )
						}
						placeholder={ __(
							'Why is this worth saving?',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="post-kinds-card">
					<div className="post-kinds-card__media">
						<MediaUploadCheck>
							<MediaUpload
								onSelect={ handleImageSelect }
								allowedTypes={ [ 'image' ] }
								render={ ( { open } ) => (
									<button
										type="button"
										className="post-kinds-card__media-button"
										onClick={ open }
									>
										{ image ? (
											<>
												<img
													src={ image }
													alt={ imageAlt || title }
													className="post-kinds-card__image"
												/>
												<button
													type="button"
													className="post-kinds-card__media-remove"
													onClick={
														handleImageRemove
													}
													aria-label={ __(
														'Remove image',
														'post-kinds-for-indieweb-in-block-themes'
													) }
												>
													&times;
												</button>
											</>
										) : (
											<div className="post-kinds-card__media-placeholder">
												<span className="post-kinds-card__media-icon">
													&#9873;
												</span>
												<span className="post-kinds-card__media-text">
													{ __(
														'Add Image',
														'post-kinds-for-indieweb-in-block-themes'
													) }
												</span>
											</div>
										) }
									</button>
								) }
							/>
						</MediaUploadCheck>
					</div>

					<div className="post-kinds-card__content">
						<span className="post-kinds-card__badge">
							&#9873;{ ' ' }
							{ __(
								'Bookmarked',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						</span>

						<RichText
							tagName="h3"
							className="post-kinds-card__title"
							value={ title }
							onChange={ ( value ) =>
								setAttributes( { title: value } )
							}
							placeholder={ __(
								'What did you bookmark?',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>

						<div className="post-kinds-card__input-row">
							<span className="post-kinds-card__input-icon">
								&#128279;
							</span>
							<input
								type="url"
								className="post-kinds-card__input post-kinds-card__input--url"
								value={ url || '' }
								onChange={ ( e ) =>
									setAttributes( { url: e.target.value } )
								}
								placeholder={ __(
									'https://example.com/…',
									'post-kinds-for-indieweb-in-block-themes'
								) }
							/>
						</div>

						<RichText
							tagName="p"
							className="post-kinds-card__subtitle"
							value={ author }
							onChange={ ( value ) =>
								setAttributes( { author: value } )
							}
							placeholder={ __(
								'By whom?',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>

						<RichText
							tagName="p"
							className="post-kinds-card__notes"
							value={ description }
							onChange={ ( value ) =>
								setAttributes( { description: value } )
							}
							placeholder={ __(
								'Why is this worth saving?',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
