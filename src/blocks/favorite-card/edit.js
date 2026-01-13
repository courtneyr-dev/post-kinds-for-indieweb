/**
 * Favorite Card Block - Edit Component
 *
 * Full inline editing with theme-aware styling and full sidebar controls.
 *
 * @package Reactions_For_IndieWeb
 */

import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
	RichText,
	MediaUpload,
	MediaUploadCheck,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
} from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';

export default function Edit( { attributes, setAttributes } ) {
	const {
		title,
		url,
		description,
		image,
		imageAlt,
		author,
	} = attributes;

	const blockProps = useBlockProps( {
		className: 'favorite-card-block',
	} );

	const { editPost } = useDispatch( 'core/editor' );
	const currentKind = useSelect(
		( select ) => {
			const terms = select( 'core/editor' ).getEditedPostAttribute( 'indieblocks_kind' );
			return terms && terms.length > 0 ? terms[ 0 ] : null;
		},
		[]
	);

	// Set post kind to "favorite" when block is inserted
	useEffect( () => {
		if ( ! currentKind ) {
			wp.apiFetch( { path: '/wp/v2/kind?slug=favorite' } )
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
		if ( title !== undefined ) metaUpdates._reactions_favorite_title = title || '';
		if ( url !== undefined ) metaUpdates._reactions_favorite_url = url || '';
		if ( author !== undefined ) metaUpdates._reactions_favorite_author = author || '';
		if ( image !== undefined ) metaUpdates._reactions_favorite_image = image || '';

		if ( Object.keys( metaUpdates ).length > 0 ) {
			editPost( { meta: metaUpdates } );
		}
	}, [ title, url, author, image ] );

	const handleImageSelect = ( media ) => {
		setAttributes( {
			image: media.url,
			imageAlt: media.alt || title || __( 'Favorite image', 'reactions-for-indieweb' ),
		} );
	};

	const handleImageRemove = ( e ) => {
		e.stopPropagation();
		setAttributes( { image: '', imageAlt: '' } );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Favorite Details', 'reactions-for-indieweb' ) } initialOpen={ true }>
					<TextControl
						label={ __( 'Title', 'reactions-for-indieweb' ) }
						value={ title || '' }
						onChange={ ( value ) => setAttributes( { title: value } ) }
						placeholder={ __( 'What did you favorite?', 'reactions-for-indieweb' ) }
					/>
					<TextControl
						label={ __( 'URL', 'reactions-for-indieweb' ) }
						value={ url || '' }
						onChange={ ( value ) => setAttributes( { url: value } ) }
						type="url"
						placeholder={ __( 'https://...', 'reactions-for-indieweb' ) }
					/>
					<TextControl
						label={ __( 'Author', 'reactions-for-indieweb' ) }
						value={ author || '' }
						onChange={ ( value ) => setAttributes( { author: value } ) }
						placeholder={ __( 'Original author', 'reactions-for-indieweb' ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Description', 'reactions-for-indieweb' ) } initialOpen={ false }>
					<TextControl
						label={ __( 'Description', 'reactions-for-indieweb' ) }
						value={ description || '' }
						onChange={ ( value ) => setAttributes( { description: value } ) }
						placeholder={ __( 'Why did you favorite this?', 'reactions-for-indieweb' ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="reactions-card">
					<div className="reactions-card__media">
						<MediaUploadCheck>
							<MediaUpload
								onSelect={ handleImageSelect }
								allowedTypes={ [ 'image' ] }
								render={ ( { open } ) => (
									<button type="button" className="reactions-card__media-button" onClick={ open }>
										{ image ? (
											<>
												<img src={ image } alt={ imageAlt || title } className="reactions-card__image" />
												<button
													type="button"
													className="reactions-card__media-remove"
													onClick={ handleImageRemove }
													aria-label={ __( 'Remove image', 'reactions-for-indieweb' ) }
												>
													×
												</button>
											</>
										) : (
											<div className="reactions-card__media-placeholder">
												<span className="reactions-card__media-icon">⭐</span>
												<span className="reactions-card__media-text">{ __( 'Add Image', 'reactions-for-indieweb' ) }</span>
											</div>
										) }
									</button>
								) }
							/>
						</MediaUploadCheck>
					</div>

					<div className="reactions-card__content">
						<span className="reactions-card__badge">★ { __( 'Favorited', 'reactions-for-indieweb' ) }</span>

						<RichText
							tagName="h3"
							className="reactions-card__title"
							value={ title }
							onChange={ ( value ) => setAttributes( { title: value } ) }
							placeholder={ __( 'What did you favorite?', 'reactions-for-indieweb' ) }
						/>

						<div className="reactions-card__input-row">
							<span className="reactions-card__input-icon">🔗</span>
							<input
								type="url"
								className="reactions-card__input reactions-card__input--url"
								value={ url || '' }
								onChange={ ( e ) => setAttributes( { url: e.target.value } ) }
								placeholder={ __( 'https://example.com/...', 'reactions-for-indieweb' ) }
							/>
						</div>

						<RichText
							tagName="p"
							className="reactions-card__subtitle"
							value={ author }
							onChange={ ( value ) => setAttributes( { author: value } ) }
							placeholder={ __( 'By whom?', 'reactions-for-indieweb' ) }
						/>

						<RichText
							tagName="p"
							className="reactions-card__notes"
							value={ description }
							onChange={ ( value ) => setAttributes( { description: value } ) }
							placeholder={ __( 'Why did you favorite this?', 'reactions-for-indieweb' ) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
