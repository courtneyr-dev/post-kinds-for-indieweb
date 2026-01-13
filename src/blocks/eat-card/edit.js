/**
 * Eat Card Block - Edit Component
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
 * Cuisine options with emojis.
 */
const CUISINE_TYPES = [
	{ label: __( 'Select cuisine...', 'reactions-for-indieweb' ), value: '', emoji: '🍽️' },
	{ label: __( 'American', 'reactions-for-indieweb' ), value: 'american', emoji: '🍔' },
	{ label: __( 'Chinese', 'reactions-for-indieweb' ), value: 'chinese', emoji: '🥡' },
	{ label: __( 'French', 'reactions-for-indieweb' ), value: 'french', emoji: '🥐' },
	{ label: __( 'Indian', 'reactions-for-indieweb' ), value: 'indian', emoji: '🍛' },
	{ label: __( 'Italian', 'reactions-for-indieweb' ), value: 'italian', emoji: '🍝' },
	{ label: __( 'Japanese', 'reactions-for-indieweb' ), value: 'japanese', emoji: '🍱' },
	{ label: __( 'Korean', 'reactions-for-indieweb' ), value: 'korean', emoji: '🍜' },
	{ label: __( 'Mexican', 'reactions-for-indieweb' ), value: 'mexican', emoji: '🌮' },
	{ label: __( 'Thai', 'reactions-for-indieweb' ), value: 'thai', emoji: '🍲' },
	{ label: __( 'Vietnamese', 'reactions-for-indieweb' ), value: 'vietnamese', emoji: '🍜' },
	{ label: __( 'Mediterranean', 'reactions-for-indieweb' ), value: 'mediterranean', emoji: '🥙' },
	{ label: __( 'Seafood', 'reactions-for-indieweb' ), value: 'seafood', emoji: '🦐' },
	{ label: __( 'Breakfast', 'reactions-for-indieweb' ), value: 'breakfast', emoji: '🥞' },
	{ label: __( 'Dessert', 'reactions-for-indieweb' ), value: 'dessert', emoji: '🍰' },
	{ label: __( 'Other', 'reactions-for-indieweb' ), value: 'other', emoji: '🍽️' },
];

function getCuisineTypeInfo( type ) {
	return CUISINE_TYPES.find( ( t ) => t.value === type ) || CUISINE_TYPES[ 0 ];
}

export default function Edit( { attributes, setAttributes } ) {
	const {
		name,
		restaurant,
		cuisine,
		photo,
		photoAlt,
		rating,
		notes,
		restaurantUrl,
		locality,
	} = attributes;

	const blockProps = useBlockProps( {
		className: 'eat-card-block',
	} );

	const { editPost } = useDispatch( 'core/editor' );
	const currentKind = useSelect(
		( select ) => {
			const terms = select( 'core/editor' ).getEditedPostAttribute( 'indieblocks_kind' );
			return terms && terms.length > 0 ? terms[ 0 ] : null;
		},
		[]
	);

	// Set post kind to "eat" when block is inserted
	useEffect( () => {
		if ( ! currentKind ) {
			wp.apiFetch( { path: '/wp/v2/kind?slug=eat' } )
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
		if ( name !== undefined ) metaUpdates._reactions_eat_name = name || '';
		if ( cuisine !== undefined ) metaUpdates._reactions_eat_cuisine = cuisine || '';
		if ( restaurant !== undefined ) metaUpdates._reactions_eat_restaurant = restaurant || '';
		if ( restaurantUrl !== undefined ) metaUpdates._reactions_eat_restaurant_url = restaurantUrl || '';
		if ( photo !== undefined ) metaUpdates._reactions_eat_photo = photo || '';
		if ( rating !== undefined ) metaUpdates._reactions_eat_rating = rating || 0;
		if ( locality !== undefined ) metaUpdates._reactions_eat_locality = locality || '';

		if ( Object.keys( metaUpdates ).length > 0 ) {
			editPost( { meta: metaUpdates } );
		}
	}, [ name, cuisine, restaurant, restaurantUrl, photo, rating, locality ] );

	const handleImageSelect = ( media ) => {
		setAttributes( {
			photo: media.url,
			photoAlt: media.alt || name || __( 'Food photo', 'reactions-for-indieweb' ),
		} );
	};

	const handleImageRemove = ( e ) => {
		e.stopPropagation();
		setAttributes( { photo: '', photoAlt: '' } );
	};

	const cuisineInfo = getCuisineTypeInfo( cuisine );

	// Build select options for sidebar
	const cuisineOptions = CUISINE_TYPES.map( ( type ) => ( {
		label: `${ type.emoji } ${ type.label }`,
		value: type.value,
	} ) );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Meal Details', 'reactions-for-indieweb' ) } initialOpen={ true }>
					<TextControl
						label={ __( 'Dish Name', 'reactions-for-indieweb' ) }
						value={ name || '' }
						onChange={ ( value ) => setAttributes( { name: value } ) }
						placeholder={ __( 'What did you eat?', 'reactions-for-indieweb' ) }
					/>
					<SelectControl
						label={ __( 'Cuisine', 'reactions-for-indieweb' ) }
						value={ cuisine || '' }
						options={ cuisineOptions }
						onChange={ ( value ) => setAttributes( { cuisine: value } ) }
					/>
					<TextControl
						label={ __( 'Restaurant', 'reactions-for-indieweb' ) }
						value={ restaurant || '' }
						onChange={ ( value ) => setAttributes( { restaurant: value } ) }
					/>
					<TextControl
						label={ __( 'Location', 'reactions-for-indieweb' ) }
						value={ locality || '' }
						onChange={ ( value ) => setAttributes( { locality: value } ) }
						placeholder={ __( 'City or neighborhood', 'reactions-for-indieweb' ) }
					/>
					<TextControl
						label={ __( 'Restaurant URL', 'reactions-for-indieweb' ) }
						value={ restaurantUrl || '' }
						onChange={ ( value ) => setAttributes( { restaurantUrl: value } ) }
						type="url"
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
						label={ __( 'Notes', 'reactions-for-indieweb' ) }
						value={ notes || '' }
						onChange={ ( value ) => setAttributes( { notes: value } ) }
						placeholder={ __( 'How was it?', 'reactions-for-indieweb' ) }
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
												<span className="reactions-card__media-icon">{ cuisineInfo.emoji }</span>
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
								value={ cuisine || '' }
								onChange={ ( e ) => setAttributes( { cuisine: e.target.value } ) }
							>
								{ CUISINE_TYPES.map( ( type ) => (
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
							placeholder={ __( 'What did you eat?', 'reactions-for-indieweb' ) }
						/>

						<RichText
							tagName="p"
							className="reactions-card__subtitle"
							value={ restaurant }
							onChange={ ( value ) => setAttributes( { restaurant: value } ) }
							placeholder={ __( 'Restaurant name...', 'reactions-for-indieweb' ) }
						/>

						<RichText
							tagName="p"
							className="reactions-card__location"
							value={ locality }
							onChange={ ( value ) => setAttributes( { locality: value } ) }
							placeholder={ __( 'City or neighborhood...', 'reactions-for-indieweb' ) }
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
							placeholder={ __( 'How was it?', 'reactions-for-indieweb' ) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
