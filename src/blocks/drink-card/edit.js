/**
 * Drink Card Block - Edit Component
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
	RangeControl,
} from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { StarRating } from '../shared/components';

/**
 * Drink type options with emojis.
 */
const DRINK_TYPES = [
	{ label: __( 'Select type...', 'reactions-for-indieweb' ), value: '', emoji: '🥤' },
	{ label: __( 'Coffee', 'reactions-for-indieweb' ), value: 'coffee', emoji: '☕' },
	{ label: __( 'Tea', 'reactions-for-indieweb' ), value: 'tea', emoji: '🍵' },
	{ label: __( 'Beer', 'reactions-for-indieweb' ), value: 'beer', emoji: '🍺' },
	{ label: __( 'Wine', 'reactions-for-indieweb' ), value: 'wine', emoji: '🍷' },
	{ label: __( 'Cocktail', 'reactions-for-indieweb' ), value: 'cocktail', emoji: '🍸' },
	{ label: __( 'Juice', 'reactions-for-indieweb' ), value: 'juice', emoji: '🧃' },
	{ label: __( 'Soda', 'reactions-for-indieweb' ), value: 'soda', emoji: '🥤' },
	{ label: __( 'Smoothie', 'reactions-for-indieweb' ), value: 'smoothie', emoji: '🥤' },
	{ label: __( 'Water', 'reactions-for-indieweb' ), value: 'water', emoji: '💧' },
	{ label: __( 'Whiskey', 'reactions-for-indieweb' ), value: 'whiskey', emoji: '🥃' },
	{ label: __( 'Other', 'reactions-for-indieweb' ), value: 'other', emoji: '🥤' },
];

/**
 * Get drink type info.
 */
function getDrinkTypeInfo( type ) {
	return DRINK_TYPES.find( ( t ) => t.value === type ) || DRINK_TYPES[ 0 ];
}

export default function Edit( { attributes, setAttributes } ) {
	const {
		name,
		drinkType,
		brand,
		photo,
		photoAlt,
		rating,
		notes,
		venue,
	} = attributes;

	const blockProps = useBlockProps( {
		className: 'drink-card-block',
	} );

	// Get post meta and kind for syncing
	const { editPost } = useDispatch( 'core/editor' );
	const currentKind = useSelect(
		( select ) => {
			const terms = select( 'core/editor' ).getEditedPostAttribute( 'indieblocks_kind' );
			return terms && terms.length > 0 ? terms[ 0 ] : null;
		},
		[]
	);

	// When block is inserted, set the post kind to "drink" if not already set
	useEffect( () => {
		if ( ! currentKind ) {
			wp.apiFetch( { path: '/wp/v2/kind?slug=drink' } )
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
		if ( name !== undefined ) metaUpdates._reactions_drink_name = name || '';
		if ( drinkType !== undefined ) metaUpdates._reactions_drink_type = drinkType || '';
		if ( brand !== undefined ) metaUpdates._reactions_drink_brand = brand || '';
		if ( photo !== undefined ) metaUpdates._reactions_drink_photo = photo || '';
		if ( rating !== undefined ) metaUpdates._reactions_drink_rating = rating || 0;
		if ( venue !== undefined ) metaUpdates._reactions_drink_venue = venue || '';

		if ( Object.keys( metaUpdates ).length > 0 ) {
			editPost( { meta: metaUpdates } );
		}
	}, [ name, drinkType, brand, photo, rating, venue ] );

	const handleImageSelect = ( media ) => {
		setAttributes( {
			photo: media.url,
			photoAlt: media.alt || name || __( 'Drink photo', 'reactions-for-indieweb' ),
		} );
	};

	const handleImageRemove = ( e ) => {
		e.stopPropagation();
		setAttributes( { photo: '', photoAlt: '' } );
	};

	const typeInfo = getDrinkTypeInfo( drinkType );

	// Build select options for sidebar
	const drinkTypeOptions = DRINK_TYPES.map( ( type ) => ( {
		label: `${ type.emoji } ${ type.label }`,
		value: type.value,
	} ) );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Drink Details', 'reactions-for-indieweb' ) } initialOpen={ true }>
					<TextControl
						label={ __( 'Name', 'reactions-for-indieweb' ) }
						value={ name || '' }
						onChange={ ( value ) => setAttributes( { name: value } ) }
						placeholder={ __( 'What are you drinking?', 'reactions-for-indieweb' ) }
					/>
					<SelectControl
						label={ __( 'Type', 'reactions-for-indieweb' ) }
						value={ drinkType || '' }
						options={ drinkTypeOptions }
						onChange={ ( value ) => setAttributes( { drinkType: value } ) }
					/>
					<TextControl
						label={ __( 'Brand/Brewery', 'reactions-for-indieweb' ) }
						value={ brand || '' }
						onChange={ ( value ) => setAttributes( { brand: value } ) }
					/>
					<TextControl
						label={ __( 'Venue', 'reactions-for-indieweb' ) }
						value={ venue || '' }
						onChange={ ( value ) => setAttributes( { venue: value } ) }
						help={ __( 'Where you had this drink', 'reactions-for-indieweb' ) }
					/>
					<RangeControl
						label={ __( 'Rating', 'reactions-for-indieweb' ) }
						value={ rating || 0 }
						onChange={ ( value ) => setAttributes( { rating: value } ) }
						min={ 0 }
						max={ 5 }
						step={ 1 }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Notes', 'reactions-for-indieweb' ) } initialOpen={ false }>
					<TextControl
						label={ __( 'Tasting Notes', 'reactions-for-indieweb' ) }
						value={ notes || '' }
						onChange={ ( value ) => setAttributes( { notes: value } ) }
						placeholder={ __( 'Your thoughts...', 'reactions-for-indieweb' ) }
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
										{ photo ? (
											<>
												<img src={ photo } alt={ photoAlt || name } className="reactions-card__image" />
												<button
													type="button"
													className="reactions-card__media-remove"
													onClick={ handleImageRemove }
													aria-label={ __( 'Remove photo', 'reactions-for-indieweb' ) }
												>
													×
												</button>
											</>
										) : (
											<div className="reactions-card__media-placeholder">
												<span className="reactions-card__media-icon">{ typeInfo.emoji }</span>
												<span className="reactions-card__media-text">{ __( 'Add Photo', 'reactions-for-indieweb' ) }</span>
											</div>
										) }
									</button>
								) }
							/>
						</MediaUploadCheck>
					</div>

					<div className="reactions-card__content">
						<div className="reactions-card__type-row">
							<select
								className="reactions-card__type-select"
								value={ drinkType || '' }
								onChange={ ( e ) => setAttributes( { drinkType: e.target.value } ) }
							>
								{ DRINK_TYPES.map( ( type ) => (
									<option key={ type.value } value={ type.value }>
										{ type.emoji } { type.label }
									</option>
								) ) }
							</select>
						</div>

						<RichText
							tagName="h3"
							className="reactions-card__title"
							value={ name }
							onChange={ ( value ) => setAttributes( { name: value } ) }
							placeholder={ __( 'What are you drinking?', 'reactions-for-indieweb' ) }
						/>

						<RichText
							tagName="p"
							className="reactions-card__subtitle"
							value={ brand }
							onChange={ ( value ) => setAttributes( { brand: value } ) }
							placeholder={ __( 'Brand or brewery...', 'reactions-for-indieweb' ) }
						/>

						<RichText
							tagName="p"
							className="reactions-card__location"
							value={ venue }
							onChange={ ( value ) => setAttributes( { venue: value } ) }
							placeholder={ __( 'Where? (optional)', 'reactions-for-indieweb' ) }
						/>

						<div className="reactions-card__rating">
							<StarRating
								value={ rating }
								onChange={ ( value ) => setAttributes( { rating: value } ) }
								max={ 5 }
							/>
						</div>

						<RichText
							tagName="p"
							className="reactions-card__notes"
							value={ notes }
							onChange={ ( value ) => setAttributes( { notes: value } ) }
							placeholder={ __( 'Tasting notes...', 'reactions-for-indieweb' ) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
