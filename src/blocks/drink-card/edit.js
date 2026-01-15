/**
 * Drink Card Block - Edit Component
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
 * Drink type options with emojis.
 */
const DRINK_TYPES = [
	{
		label: __( 'Select type…', 'post-kinds-for-indieweb' ),
		value: '',
		emoji: '🥤',
	},
	{
		label: __( 'Coffee', 'post-kinds-for-indieweb' ),
		value: 'coffee',
		emoji: '☕',
	},
	{
		label: __( 'Tea', 'post-kinds-for-indieweb' ),
		value: 'tea',
		emoji: '🍵',
	},
	{
		label: __( 'Beer', 'post-kinds-for-indieweb' ),
		value: 'beer',
		emoji: '🍺',
	},
	{
		label: __( 'Wine', 'post-kinds-for-indieweb' ),
		value: 'wine',
		emoji: '🍷',
	},
	{
		label: __( 'Cocktail', 'post-kinds-for-indieweb' ),
		value: 'cocktail',
		emoji: '🍸',
	},
	{
		label: __( 'Juice', 'post-kinds-for-indieweb' ),
		value: 'juice',
		emoji: '🧃',
	},
	{
		label: __( 'Soda', 'post-kinds-for-indieweb' ),
		value: 'soda',
		emoji: '🥤',
	},
	{
		label: __( 'Smoothie', 'post-kinds-for-indieweb' ),
		value: 'smoothie',
		emoji: '🥤',
	},
	{
		label: __( 'Water', 'post-kinds-for-indieweb' ),
		value: 'water',
		emoji: '💧',
	},
	{
		label: __( 'Whiskey', 'post-kinds-for-indieweb' ),
		value: 'whiskey',
		emoji: '🥃',
	},
	{
		label: __( 'Other', 'post-kinds-for-indieweb' ),
		value: 'other',
		emoji: '🥤',
	},
];

/**
 * Get drink type info.
 *
 * @param {string} type Drink type value.
 * @return {Object} Drink type info object.
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
		venueUrl,
		locationName,
		locationAddress,
		locationLocality,
		locationRegion,
		locationCountry,
		geoLatitude,
		geoLongitude,
	} = attributes;

	const blockProps = useBlockProps( {
		className: 'drink-card-block',
	} );

	// Get post meta and kind for syncing
	const { editPost } = useDispatch( 'core/editor' );
	const currentKind = useSelect( ( select ) => {
		const terms =
			select( 'core/editor' ).getEditedPostAttribute(
				'indieblocks_kind'
			);
		return terms && terms.length > 0 ? terms[ 0 ] : null;
	}, [] );

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
		if ( name !== undefined ) {
			metaUpdates._postkind_drink_name = name || '';
		}
		if ( drinkType !== undefined ) {
			metaUpdates._postkind_drink_type = drinkType || '';
		}
		if ( brand !== undefined ) {
			metaUpdates._postkind_drink_brewery = brand || '';
		}
		if ( photo !== undefined ) {
			metaUpdates._postkind_drink_photo = photo || '';
		}
		if ( rating !== undefined ) {
			metaUpdates._postkind_drink_rating = rating || 0;
		}
		// Location fields
		if ( locationName !== undefined ) {
			metaUpdates._postkind_drink_location_name = locationName || '';
		}
		if ( locationAddress !== undefined ) {
			metaUpdates._postkind_drink_location_address =
				locationAddress || '';
		}
		if ( locationLocality !== undefined ) {
			metaUpdates._postkind_drink_location_locality =
				locationLocality || '';
		}
		if ( locationRegion !== undefined ) {
			metaUpdates._postkind_drink_location_region = locationRegion || '';
		}
		if ( locationCountry !== undefined ) {
			metaUpdates._postkind_drink_location_country =
				locationCountry || '';
		}
		if ( geoLatitude !== undefined ) {
			metaUpdates._postkind_drink_geo_latitude = geoLatitude || 0;
		}
		if ( geoLongitude !== undefined ) {
			metaUpdates._postkind_drink_geo_longitude = geoLongitude || 0;
		}

		if ( Object.keys( metaUpdates ).length > 0 ) {
			editPost( { meta: metaUpdates } );
		}
	}, [
		name,
		drinkType,
		brand,
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
				__( 'Drink photo', 'post-kinds-for-indieweb' ),
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
				<PanelBody
					title={ __( 'Drink Details', 'post-kinds-for-indieweb' ) }
					initialOpen={ true }
				>
					<TextControl
						label={ __( 'Name', 'post-kinds-for-indieweb' ) }
						value={ name || '' }
						onChange={ ( value ) =>
							setAttributes( { name: value } )
						}
						placeholder={ __(
							'What are you drinking?',
							'post-kinds-for-indieweb'
						) }
					/>
					<SelectControl
						label={ __( 'Type', 'post-kinds-for-indieweb' ) }
						value={ drinkType || '' }
						options={ drinkTypeOptions }
						onChange={ ( value ) =>
							setAttributes( { drinkType: value } )
						}
					/>
					<TextControl
						label={ __(
							'Brand/Brewery',
							'post-kinds-for-indieweb'
						) }
						value={ brand || '' }
						onChange={ ( value ) =>
							setAttributes( { brand: value } )
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
							'Bar/Cafe/Venue Name',
							'post-kinds-for-indieweb'
						) }
						value={ locationName || '' }
						onChange={ ( value ) =>
							setAttributes( { locationName: value } )
						}
						placeholder={ __(
							'Where are you drinking?',
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
						value={ venueUrl || '' }
						onChange={ ( value ) =>
							setAttributes( { venueUrl: value } )
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
						label={ __(
							'Tasting Notes',
							'post-kinds-for-indieweb'
						) }
						value={ notes || '' }
						onChange={ ( value ) =>
							setAttributes( { notes: value } )
						}
						placeholder={ __(
							'Your thoughts…',
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
													{ typeInfo.emoji }
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
								value={ drinkType || '' }
								onChange={ ( e ) =>
									setAttributes( {
										drinkType: e.target.value,
									} )
								}
							>
								{ DRINK_TYPES.map( ( type ) => (
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
								'What are you drinking?',
								'post-kinds-for-indieweb'
							) }
						/>

						<RichText
							tagName="p"
							className="post-kinds-card__subtitle"
							value={ brand }
							onChange={ ( value ) =>
								setAttributes( { brand: value } )
							}
							placeholder={ __(
								'Brand or brewery…',
								'post-kinds-for-indieweb'
							) }
						/>

						<RichText
							tagName="p"
							className="post-kinds-card__location"
							value={ locationName }
							onChange={ ( value ) =>
								setAttributes( { locationName: value } )
							}
							placeholder={ __(
								'Venue name…',
								'post-kinds-for-indieweb'
							) }
						/>

						{ ( locationLocality ||
							locationRegion ||
							locationCountry ) && (
							<p className="post-kinds-card__city">
								{ [
									locationLocality,
									locationRegion,
									locationCountry,
								]
									.filter( Boolean )
									.join( ', ' ) }
							</p>
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
								'Tasting notes…',
								'post-kinds-for-indieweb'
							) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
