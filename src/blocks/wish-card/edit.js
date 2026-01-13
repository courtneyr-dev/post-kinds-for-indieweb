/**
 * Wish Card Block - Edit Component
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
	SelectControl,
} from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';

/**
 * Wish type options with emojis.
 */
const WISH_TYPES = [
	{ label: __( 'Item', 'reactions-for-indieweb' ), value: 'item', emoji: '🛍️' },
	{ label: __( 'Experience', 'reactions-for-indieweb' ), value: 'experience', emoji: '✨' },
	{ label: __( 'Book', 'reactions-for-indieweb' ), value: 'book', emoji: '📚' },
	{ label: __( 'Game', 'reactions-for-indieweb' ), value: 'game', emoji: '🎮' },
	{ label: __( 'Movie/Show', 'reactions-for-indieweb' ), value: 'media', emoji: '🎬' },
	{ label: __( 'Travel', 'reactions-for-indieweb' ), value: 'travel', emoji: '✈️' },
	{ label: __( 'Tech', 'reactions-for-indieweb' ), value: 'tech', emoji: '💻' },
	{ label: __( 'Food', 'reactions-for-indieweb' ), value: 'food', emoji: '🍕' },
	{ label: __( 'Other', 'reactions-for-indieweb' ), value: 'other', emoji: '🎁' },
];

/**
 * Priority options.
 */
const PRIORITY_OPTIONS = [
	{ label: __( 'Low', 'reactions-for-indieweb' ), value: 'low' },
	{ label: __( 'Medium', 'reactions-for-indieweb' ), value: 'medium' },
	{ label: __( 'High', 'reactions-for-indieweb' ), value: 'high' },
];

function getWishTypeInfo( type ) {
	return WISH_TYPES.find( ( t ) => t.value === type ) || WISH_TYPES[ 0 ];
}

export default function Edit( { attributes, setAttributes } ) {
	const {
		title,
		wishType,
		url,
		image,
		imageAlt,
		price,
		reason,
		priority,
	} = attributes;

	const blockProps = useBlockProps( {
		className: 'wish-card-block',
	} );

	const { editPost } = useDispatch( 'core/editor' );
	const currentKind = useSelect(
		( select ) => {
			const terms = select( 'core/editor' ).getEditedPostAttribute( 'indieblocks_kind' );
			return terms && terms.length > 0 ? terms[ 0 ] : null;
		},
		[]
	);

	// Set post kind to "wish" when block is inserted
	useEffect( () => {
		if ( ! currentKind ) {
			wp.apiFetch( { path: '/wp/v2/kind?slug=wish' } )
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
		if ( title !== undefined ) metaUpdates._reactions_wish_title = title || '';
		if ( wishType !== undefined ) metaUpdates._reactions_wish_type = wishType || '';
		if ( url !== undefined ) metaUpdates._reactions_wish_url = url || '';
		if ( price !== undefined ) metaUpdates._reactions_wish_price = price || '';
		if ( priority !== undefined ) metaUpdates._reactions_wish_priority = priority || '';
		if ( image !== undefined ) metaUpdates._reactions_wish_image = image || '';

		if ( Object.keys( metaUpdates ).length > 0 ) {
			editPost( { meta: metaUpdates } );
		}
	}, [ title, wishType, url, price, priority, image ] );

	const handleImageSelect = ( media ) => {
		setAttributes( {
			image: media.url,
			imageAlt: media.alt || title || __( 'Wish image', 'reactions-for-indieweb' ),
		} );
	};

	const handleImageRemove = ( e ) => {
		e.stopPropagation();
		setAttributes( { image: '', imageAlt: '' } );
	};

	const typeInfo = getWishTypeInfo( wishType );

	// Build select options for sidebar
	const wishTypeOptions = WISH_TYPES.map( ( type ) => ( {
		label: `${ type.emoji } ${ type.label }`,
		value: type.value,
	} ) );

	const priorityOptions = PRIORITY_OPTIONS.map( ( p ) => ( {
		label: p.label,
		value: p.value,
	} ) );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Wish Details', 'reactions-for-indieweb' ) } initialOpen={ true }>
					<TextControl
						label={ __( 'Title', 'reactions-for-indieweb' ) }
						value={ title || '' }
						onChange={ ( value ) => setAttributes( { title: value } ) }
						placeholder={ __( 'What do you wish for?', 'reactions-for-indieweb' ) }
					/>
					<SelectControl
						label={ __( 'Type', 'reactions-for-indieweb' ) }
						value={ wishType || 'item' }
						options={ wishTypeOptions }
						onChange={ ( value ) => setAttributes( { wishType: value } ) }
					/>
					<SelectControl
						label={ __( 'Priority', 'reactions-for-indieweb' ) }
						value={ priority || 'medium' }
						options={ priorityOptions }
						onChange={ ( value ) => setAttributes( { priority: value } ) }
					/>
					<TextControl
						label={ __( 'Price', 'reactions-for-indieweb' ) }
						value={ price || '' }
						onChange={ ( value ) => setAttributes( { price: value } ) }
						placeholder={ __( '$0.00', 'reactions-for-indieweb' ) }
					/>
					<TextControl
						label={ __( 'Product URL', 'reactions-for-indieweb' ) }
						value={ url || '' }
						onChange={ ( value ) => setAttributes( { url: value } ) }
						type="url"
						help={ __( 'Link to the product or item', 'reactions-for-indieweb' ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Reason', 'reactions-for-indieweb' ) } initialOpen={ false }>
					<TextControl
						label={ __( 'Reason', 'reactions-for-indieweb' ) }
						value={ reason || '' }
						onChange={ ( value ) => setAttributes( { reason: value } ) }
						placeholder={ __( 'Why do you want this?', 'reactions-for-indieweb' ) }
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
									<button
										type="button"
										className="reactions-card__media-button"
										onClick={ open }
									>
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
												<span className="reactions-card__media-icon">{ typeInfo.emoji }</span>
												<span className="reactions-card__media-text">{ __( 'Add Image', 'reactions-for-indieweb' ) }</span>
											</div>
										) }
									</button>
								) }
							/>
						</MediaUploadCheck>
					</div>

					<div className="reactions-card__content">
						<div className="reactions-card__badges-row">
							<select
								className="reactions-card__type-select"
								value={ wishType || 'item' }
								onChange={ ( e ) => setAttributes( { wishType: e.target.value } ) }
							>
								{ WISH_TYPES.map( ( type ) => (
									<option key={ type.value } value={ type.value }>
										{ type.emoji } { type.label }
									</option>
								) ) }
							</select>

							<select
								className="reactions-card__type-select"
								value={ priority || 'medium' }
								onChange={ ( e ) => setAttributes( { priority: e.target.value } ) }
							>
								{ PRIORITY_OPTIONS.map( ( p ) => (
									<option key={ p.value } value={ p.value }>
										{ p.label }
									</option>
								) ) }
							</select>
						</div>

						<RichText
							tagName="h3"
							className="reactions-card__title"
							value={ title }
							onChange={ ( value ) => setAttributes( { title: value } ) }
							placeholder={ __( 'What do you wish for?', 'reactions-for-indieweb' ) }
						/>

						<div className="reactions-card__input-row">
							<span className="reactions-card__input-icon">💰</span>
							<input
								type="text"
								className="reactions-card__input reactions-card__input--price"
								value={ price || '' }
								onChange={ ( e ) => setAttributes( { price: e.target.value } ) }
								placeholder={ __( '$0.00', 'reactions-for-indieweb' ) }
							/>
						</div>

						<div className="reactions-card__input-row">
							<span className="reactions-card__input-icon">🔗</span>
							<input
								type="url"
								className="reactions-card__input reactions-card__input--url"
								value={ url || '' }
								onChange={ ( e ) => setAttributes( { url: e.target.value } ) }
								placeholder={ __( 'https://...', 'reactions-for-indieweb' ) }
							/>
						</div>

						<RichText
							tagName="p"
							className="reactions-card__notes"
							value={ reason }
							onChange={ ( value ) => setAttributes( { reason: value } ) }
							placeholder={ __( 'Why do you want this?', 'reactions-for-indieweb' ) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
