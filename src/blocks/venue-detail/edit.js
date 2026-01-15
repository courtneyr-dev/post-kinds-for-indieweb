/**
 * Venue Detail Block - Edit Component
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	ToggleControl,
	SelectControl,
	Spinner,
	Placeholder,
} from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Edit component for the Venue Detail block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to update attributes.
 * @return {JSX.Element} Block edit component.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { venueId, showMap, showAddress, showCheckins, checkinCount } =
		attributes;

	const [ venues, setVenues ] = useState( [] );
	const [ selectedVenue, setSelectedVenue ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );

	const blockProps = useBlockProps( {
		className: 'venue-detail',
	} );

	// Fetch all venues.
	useEffect( () => {
		apiFetch( { path: '/wp/v2/venue?per_page=100' } )
			.then( ( data ) => {
				setVenues( data || [] );
				setIsLoading( false );
			} )
			.catch( () => {
				setVenues( [] );
				setIsLoading( false );
			} );
	}, [] );

	// Fetch selected venue details.
	useEffect( () => {
		if ( venueId > 0 ) {
			apiFetch( { path: `/wp/v2/venue/${ venueId }` } )
				.then( ( data ) => {
					setSelectedVenue( data );
				} )
				.catch( () => {
					setSelectedVenue( null );
				} );
		} else {
			setSelectedVenue( null );
		}
	}, [ venueId ] );

	// Build venue options for dropdown.
	const venueOptions = [
		{
			value: 0,
			label: __( '— Select a Venue —', 'post-kinds-for-indieweb' ),
		},
		...venues.map( ( venue ) => ( {
			value: venue.id,
			label: venue.name,
		} ) ),
	];

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Venue Settings', 'post-kinds-for-indieweb' ) }
				>
					<SelectControl
						label={ __(
							'Select Venue',
							'post-kinds-for-indieweb'
						) }
						value={ venueId }
						options={ venueOptions }
						onChange={ ( value ) =>
							setAttributes( { venueId: parseInt( value, 10 ) } )
						}
						help={ __(
							'Choose the venue to display. On venue archive pages, this will automatically use the current venue.',
							'post-kinds-for-indieweb'
						) }
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Display Options', 'post-kinds-for-indieweb' ) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __( 'Show Map', 'post-kinds-for-indieweb' ) }
						checked={ showMap }
						onChange={ ( value ) =>
							setAttributes( { showMap: value } )
						}
					/>

					<ToggleControl
						label={ __(
							'Show Address',
							'post-kinds-for-indieweb'
						) }
						checked={ showAddress }
						onChange={ ( value ) =>
							setAttributes( { showAddress: value } )
						}
					/>

					<ToggleControl
						label={ __(
							'Show Recent Check-ins',
							'post-kinds-for-indieweb'
						) }
						checked={ showCheckins }
						onChange={ ( value ) =>
							setAttributes( { showCheckins: value } )
						}
					/>

					{ showCheckins && (
						<RangeControl
							label={ __(
								'Number of Check-ins',
								'post-kinds-for-indieweb'
							) }
							value={ checkinCount }
							onChange={ ( value ) =>
								setAttributes( { checkinCount: value } )
							}
							min={ 1 }
							max={ 20 }
						/>
					) }
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ isLoading && (
					<Placeholder
						icon="store"
						label={ __(
							'Venue Detail',
							'post-kinds-for-indieweb'
						) }
					>
						<Spinner />
					</Placeholder>
				) }
				{ ! isLoading && venueId === 0 && (
					<Placeholder
						icon="store"
						label={ __(
							'Venue Detail',
							'post-kinds-for-indieweb'
						) }
						instructions={ __(
							'Select a venue from the block settings, or this block will automatically display the current venue on venue archive pages.',
							'post-kinds-for-indieweb'
						) }
					>
						<SelectControl
							value={ venueId }
							options={ venueOptions }
							onChange={ ( value ) =>
								setAttributes( {
									venueId: parseInt( value, 10 ),
								} )
							}
						/>
					</Placeholder>
				) }
				{ ! isLoading && venueId > 0 && selectedVenue && (
					<div className="venue-detail__preview">
						{ showMap && (
							<div className="venue-detail__map-placeholder">
								<div className="venue-detail__map-preview">
									<span className="dashicons dashicons-location-alt"></span>
									<span>
										{ __(
											'Map will display here on the frontend',
											'post-kinds-for-indieweb'
										) }
									</span>
								</div>
							</div>
						) }

						<div className="venue-detail__info">
							<h2 className="venue-detail__name">
								{ selectedVenue.name }
							</h2>

							{ showAddress && selectedVenue.meta && (
								<div className="venue-detail__address">
									{ [
										selectedVenue.meta.address,
										selectedVenue.meta.city,
										selectedVenue.meta.country,
									]
										.filter( Boolean )
										.join( ', ' ) ||
										__(
											'No address available',
											'post-kinds-for-indieweb'
										) }
								</div>
							) }

							{ showCheckins && (
								<div className="venue-detail__checkins-preview">
									<h3>
										{ __(
											'Recent Check-ins',
											'post-kinds-for-indieweb'
										) }
									</h3>
									<p className="description">
										{ __(
											'Check-ins will be displayed here on the frontend.',
											'post-kinds-for-indieweb'
										) }
									</p>
								</div>
							) }
						</div>
					</div>
				) }
				{ ! isLoading && venueId > 0 && ! selectedVenue && (
					<Placeholder
						icon="store"
						label={ __(
							'Venue Detail',
							'post-kinds-for-indieweb'
						) }
						instructions={ __(
							'Venue not found. Please select a different venue.',
							'post-kinds-for-indieweb'
						) }
					/>
				) }
			</div>
		</>
	);
}
