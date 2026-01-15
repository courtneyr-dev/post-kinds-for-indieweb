/**
 * Eat Card Block - Edit Component
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
	{
		label: __( 'Select cuisine…', 'post-kinds-for-indieweb' ),
		value: '',
		emoji: '🍽️',
	},
	{
		label: __( 'American', 'post-kinds-for-indieweb' ),
		value: 'american',
		emoji: '🍔',
	},
	{
		label: __( 'Chinese', 'post-kinds-for-indieweb' ),
		value: 'chinese',
		emoji: '🥡',
	},
	{
		label: __( 'French', 'post-kinds-for-indieweb' ),
		value: 'french',
		emoji: '🥐',
	},
	{
		label: __( 'Indian', 'post-kinds-for-indieweb' ),
		value: 'indian',
		emoji: '🍛',
	},
	{
		label: __( 'Italian', 'post-kinds-for-indieweb' ),
		value: 'italian',
		emoji: '🍝',
	},
	{
		label: __( 'Japanese', 'post-kinds-for-indieweb' ),
		value: 'japanese',
		emoji: '🍱',
	},
	{
		label: __( 'Korean', 'post-kinds-for-indieweb' ),
		value: 'korean',
		emoji: '🍜',
	},
	{
		label: __( 'Mexican', 'post-kinds-for-indieweb' ),
		value: 'mexican',
		emoji: '🌮',
	},
	{
		label: __( 'Thai', 'post-kinds-for-indieweb' ),
		value: 'thai',
		emoji: '🍲',
	},
	{
		label: __( 'Vietnamese', 'post-kinds-for-indieweb' ),
		value: 'vietnamese',
		emoji: '🍜',
	},
	{
		label: __( 'Mediterranean', 'post-kinds-for-indieweb' ),
		value: 'mediterranean',
		emoji: '🥙',
	},
	{
		label: __( 'Seafood', 'post-kinds-for-indieweb' ),
		value: 'seafood',
		emoji: '🦐',
	},
	{
		label: __( 'Breakfast', 'post-kinds-for-indieweb' ),
		value: 'breakfast',
		emoji: '🥞',
	},
	{
		label: __( 'Dessert', 'post-kinds-for-indieweb' ),
		value: 'dessert',
		emoji: '🍰',
	},
	{
		label: __( 'Other', 'post-kinds-for-indieweb' ),
		value: 'other',
		emoji: '🍽️',
	},
];

function getCuisineTypeInfo( type ) {
	return (
		CUISINE_TYPES.find( ( t ) => t.value === type ) || CUISINE_TYPES[ 0 ]
	);
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
		locationName,
		locationAddress,
		locationLocality,
		locationRegion,
		locationCountry,
		geoLatitude,
		geoLongitude,
	} = attributes;

	const blockProps = useBlockProps( {
		className: 'eat-card-block',
	} );

	const { editPost } = useDispatch( 'core/editor' );
	const currentKind = useSelect( ( select ) => {
		const terms =
			select( 'core/editor' ).getEditedPostAttribute(
				'indieblocks_kind'
			);
		return terms && terms.length > 0 ? terms[ 0 ] : null;
	}, [] );

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
		if ( name !== undefined ) {
			metaUpdates._postkind_eat_name = name || '';
		}
		if ( cuisine !== undefined ) {
			metaUpdates._postkind_eat_cuisine = cuisine || '';
		}
		if ( restaurant !== undefined ) {
			metaUpdates._postkind_eat_restaurant = restaurant || '';
		}
		if ( restaurantUrl !== undefined ) {
			metaUpdates._postkind_eat_restaurant_url = restaurantUrl || '';
		}
		if ( photo !== undefined ) {
			metaUpdates._postkind_eat_photo = photo || '';
		}
		if ( rating !== undefined ) {
			metaUpdates._postkind_eat_rating = rating || 0;
		}
		// Location fields
		if ( locationName !== undefined ) {
			metaUpdates._postkind_eat_location_name = locationName || '';
		}
		if ( locationAddress !== undefined ) {
			metaUpdates._postkind_eat_location_address = locationAddress || '';
		}
		if ( locationLocality !== undefined ) {
			metaUpdates._postkind_eat_location_locality =
				locationLocality || '';
		}
		if ( locationRegion !== undefined ) {
			metaUpdates._postkind_eat_location_region = locationRegion || '';
		}
		if ( locationCountry !== undefined ) {
			metaUpdates._postkind_eat_location_country = locationCountry || '';
		}
		if ( geoLatitude !== undefined ) {
			metaUpdates._postkind_eat_geo_latitude = geoLatitude || 0;
		}
		if ( geoLongitude !== undefined ) {
			metaUpdates._postkind_eat_geo_longitude = geoLongitude || 0;
		}

		if ( Object.keys( metaUpdates ).length > 0 ) {
			editPost( { meta: metaUpdates } );
		}
	}, [
		name,
		cuisine,
		restaurant,
		restaurantUrl,
		photo,
		rating,
		locationName,
		locationAddress,
		locationLocality,
		locationRegion,
		locationCountry,
		geoLatitude,
		geoLongitude,
	] );

	const handleImageSelect = ( media ) => {
		setAttributes( {
			photo: media.url,
			photoAlt:
				media.alt ||
				name ||
				__( 'Food photo', 'post-kinds-for-indieweb' ),
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
				<PanelBody
					title={ __( 'Meal Details', 'post-kinds-for-indieweb' ) }
					initialOpen={ true }
				>
					<TextControl
						label={ __( 'Dish Name', 'post-kinds-for-indieweb' ) }
						value={ name || '' }
						onChange={ ( value ) =>
							setAttributes( { name: value } )
						}
						placeholder={ __(
							'What did you eat?',
							'post-kinds-for-indieweb'
						) }
					/>
					<SelectControl
						label={ __( 'Cuisine', 'post-kinds-for-indieweb' ) }
						value={ cuisine || '' }
						options={ cuisineOptions }
						onChange={ ( value ) =>
							setAttributes( { cuisine: value } )
						}
					/>
					<RangeControl
						label={ __( 'Rating', 'post-kinds-for-indieweb' ) }
						value={ rating || 0 }
						onChange={ ( value ) =>
							setAttributes( { rating: value } )
						}
						min={ 0 }
						max={ 5 }
						step={ 1 }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Location', 'post-kinds-for-indieweb' ) }
					initialOpen={ true }
				>
					<TextControl
						label={ __(
							'Restaurant/Venue Name',
							'post-kinds-for-indieweb'
						) }
						value={ locationName || '' }
						onChange={ ( value ) =>
							setAttributes( { locationName: value } )
						}
						placeholder={ __(
							'Where did you eat?',
							'post-kinds-for-indieweb'
						) }
					/>
					<TextControl
						label={ __( 'Address', 'post-kinds-for-indieweb' ) }
						value={ locationAddress || '' }
						onChange={ ( value ) =>
							setAttributes( { locationAddress: value } )
						}
					/>
					<TextControl
						label={ __( 'City', 'post-kinds-for-indieweb' ) }
						value={ locationLocality || '' }
						onChange={ ( value ) =>
							setAttributes( { locationLocality: value } )
						}
					/>
					<TextControl
						label={ __(
							'State/Region',
							'post-kinds-for-indieweb'
						) }
						value={ locationRegion || '' }
						onChange={ ( value ) =>
							setAttributes( { locationRegion: value } )
						}
					/>
					<TextControl
						label={ __( 'Country', 'post-kinds-for-indieweb' ) }
						value={ locationCountry || '' }
						onChange={ ( value ) =>
							setAttributes( { locationCountry: value } )
						}
					/>
					<TextControl
						label={ __( 'Website URL', 'post-kinds-for-indieweb' ) }
						value={ restaurantUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { restaurantUrl: value } )
						}
						type="url"
					/>
					<TextControl
						label={ __( 'Latitude', 'post-kinds-for-indieweb' ) }
						value={ geoLatitude || '' }
						onChange={ ( value ) =>
							setAttributes( {
								geoLatitude: parseFloat( value ) || 0,
							} )
						}
						type="number"
						step="0.0000001"
					/>
					<TextControl
						label={ __( 'Longitude', 'post-kinds-for-indieweb' ) }
						value={ geoLongitude || '' }
						onChange={ ( value ) =>
							setAttributes( {
								geoLongitude: parseFloat( value ) || 0,
							} )
						}
						type="number"
						step="0.0000001"
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Notes', 'post-kinds-for-indieweb' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Notes', 'post-kinds-for-indieweb' ) }
						value={ notes || '' }
						onChange={ ( value ) =>
							setAttributes( { notes: value } )
						}
						placeholder={ __(
							'How was it?',
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
										{ photo ? (
											<>
												<img
													src={ photo }
													alt={ photoAlt || name }
													className="post-kinds-card__image"
												/>
												<button
													type="button"
													className="post-kinds-card__media-remove"
													onClick={
														handleImageRemove
													}
													aria-label={ __(
														'Remove photo',
														'post-kinds-for-indieweb'
													) }
												>
													×
												</button>
											</>
										) : (
											<div className="post-kinds-card__media-placeholder">
												<span className="post-kinds-card__media-icon">
													{ cuisineInfo.emoji }
												</span>
												<span className="post-kinds-card__media-text">
													{ __(
														'Add Photo',
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
						<div className="post-kinds-card__type-row">
							<select
								className="post-kinds-card__type-select"
								value={ cuisine || '' }
								onChange={ ( e ) =>
									setAttributes( { cuisine: e.target.value } )
								}
							>
								{ CUISINE_TYPES.map( ( type ) => (
									<option
										key={ type.value }
										value={ type.value }
									>
										{ type.emoji } { type.label }
									</option>
								) ) }
							</select>
						</div>

						<RichText
							tagName="h3"
							className="post-kinds-card__title"
							value={ name }
							onChange={ ( value ) =>
								setAttributes( { name: value } )
							}
							placeholder={ __(
								'What did you eat?',
								'post-kinds-for-indieweb'
							) }
						/>

						<RichText
							tagName="p"
							className="post-kinds-card__subtitle"
							value={ locationName }
							onChange={ ( value ) =>
								setAttributes( { locationName: value } )
							}
							placeholder={ __(
								'Restaurant name…',
								'post-kinds-for-indieweb'
							) }
						/>

						{ ( locationLocality ||
							locationRegion ||
							locationCountry ) && (
							<p className="post-kinds-card__location">
								{ [
									locationLocality,
									locationRegion,
									locationCountry,
								]
									.filter( Boolean )
									.join( ', ' ) }
							</p>
						) }
						{ ! locationLocality &&
							! locationRegion &&
							! locationCountry && (
								<RichText
									tagName="p"
									className="post-kinds-card__location"
									value={ locationLocality }
									onChange={ ( value ) =>
										setAttributes( {
											locationLocality: value,
										} )
									}
									placeholder={ __(
										'City…',
										'post-kinds-for-indieweb'
									) }
								/>
							) }

						<div className="post-kinds-card__rating">
							<StarRating
								value={ rating }
								onChange={ ( value ) =>
									setAttributes( { rating: value } )
								}
								max={ 5 }
							/>
						</div>

						<RichText
							tagName="p"
							className="post-kinds-card__notes"
							value={ notes }
							onChange={ ( value ) =>
								setAttributes( { notes: value } )
							}
							placeholder={ __(
								'How was it?',
								'post-kinds-for-indieweb'
							) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
