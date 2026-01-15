/**
 * Like Card Block - Edit Component
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
		className: 'like-card-block',
	} );

	const { editPost } = useDispatch( 'core/editor' );
	const currentKind = useSelect( ( select ) => {
		const terms =
			select( 'core/editor' ).getEditedPostAttribute(
				'indieblocks_kind'
			);
		return terms && terms.length > 0 ? terms[ 0 ] : null;
	}, [] );

	// Set post kind to "like" when block is inserted
	useEffect( () => {
		if ( ! currentKind ) {
			wp.apiFetch( { path: '/wp/v2/kind?slug=like' } )
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
			metaUpdates._postkind_like_title = title || '';
		}
		if ( url !== undefined ) {
			metaUpdates._postkind_like_url = url || '';
		}
		if ( author !== undefined ) {
			metaUpdates._postkind_like_author = author || '';
		}
		if ( image !== undefined ) {
			metaUpdates._postkind_like_image = image || '';
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
				__( 'Liked content image', 'post-kinds-for-indieweb' ),
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
					title={ __( 'Like Details', 'post-kinds-for-indieweb' ) }
					initialOpen={ true }
				>
					<TextControl
						label={ __( 'Title', 'post-kinds-for-indieweb' ) }
						value={ title || '' }
						onChange={ ( value ) =>
							setAttributes( { title: value } )
						}
						placeholder={ __(
							'What did you like?',
							'post-kinds-for-indieweb'
						) }
					/>
					<TextControl
						label={ __( 'URL', 'post-kinds-for-indieweb' ) }
						value={ url || '' }
						onChange={ ( value ) =>
							setAttributes( { url: value } )
						}
						type="url"
						placeholder={ __(
							'https://…',
							'post-kinds-for-indieweb'
						) }
					/>
					<TextControl
						label={ __( 'Author', 'post-kinds-for-indieweb' ) }
						value={ author || '' }
						onChange={ ( value ) =>
							setAttributes( { author: value } )
						}
						placeholder={ __(
							'Original author',
							'post-kinds-for-indieweb'
						) }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Description', 'post-kinds-for-indieweb' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Description', 'post-kinds-for-indieweb' ) }
						value={ description || '' }
						onChange={ ( value ) =>
							setAttributes( { description: value } )
						}
						placeholder={ __(
							'Why did you like this?',
							'post-kinds-for-indieweb'
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
														'post-kinds-for-indieweb'
													) }
												>
													&times;
												</button>
											</>
										) : (
											<div className="post-kinds-card__media-placeholder">
												<span className="post-kinds-card__media-icon">
													&hearts;
												</span>
												<span className="post-kinds-card__media-text">
													{ __(
														'Add Image',
														'post-kinds-for-indieweb'
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
							&hearts;{ ' ' }
							{ __( 'Liked', 'post-kinds-for-indieweb' ) }
						</span>

						<RichText
							tagName="h3"
							className="post-kinds-card__title"
							value={ title }
							onChange={ ( value ) =>
								setAttributes( { title: value } )
							}
							placeholder={ __(
								'What did you like?',
								'post-kinds-for-indieweb'
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
									'post-kinds-for-indieweb'
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
								'post-kinds-for-indieweb'
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
								'Why did you like this?',
								'post-kinds-for-indieweb'
							) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
