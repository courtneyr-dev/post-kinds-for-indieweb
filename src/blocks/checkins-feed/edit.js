/**
 * Check-ins Feed Block - Edit Component
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
 * Edit component for the Check-ins Feed block.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Function to update attributes.
 * @return {JSX.Element} Block edit component.
 */
export default function Edit( { attributes, setAttributes } ) {
	const {
		count,
		showMap,
		showVenue,
		showDate,
		showExcerpt,
		venueId,
		layout,
		columns,
	} = attributes;

	const [ checkins, setCheckins ] = useState( [] );
	const [ venues, setVenues ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );

	const blockProps = useBlockProps( {
		className: `checkins-feed layout-${ layout }`,
	} );

	// Fetch venues for the filter dropdown.
	useEffect( () => {
		apiFetch( { path: '/wp/v2/venue?per_page=100' } )
			.then( ( data ) => {
				setVenues( data || [] );
			} )
			.catch( () => {
				setVenues( [] );
			} );
	}, [] );

	// Fetch check-ins for preview.
	useEffect( () => {
		setIsLoading( true );

		let path = `/wp/v2/checkin?per_page=${ count }&_embed`;

		if ( venueId > 0 ) {
			path += `&venue=${ venueId }`;
		}

		apiFetch( { path } )
			.then( ( data ) => {
				setCheckins( data || [] );
				setIsLoading( false );
			} )
			.catch( () => {
				// Try falling back to posts with checkin kind.
				apiFetch( {
					path: `/wp/v2/posts?per_page=${ count }&indieblocks_kind=checkin&_embed`,
				} )
					.then( ( data ) => {
						setCheckins( data || [] );
						setIsLoading( false );
					} )
					.catch( () => {
						setCheckins( [] );
						setIsLoading( false );
					} );
			} );
	}, [ count, venueId ] );

	// Build venue options for dropdown.
	const venueOptions = [
		{
			value: 0,
			label: __(
				'All Venues',
				'post-kinds-for-indieweb-in-block-themes'
			),
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
					title={ __(
						'Feed Settings',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<RangeControl
						label={ __(
							'Number of Check-ins',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ count }
						onChange={ ( value ) =>
							setAttributes( { count: value } )
						}
						min={ 1 }
						max={ 50 }
					/>

					<SelectControl
						label={ __(
							'Filter by Venue',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ venueId }
						options={ venueOptions }
						onChange={ ( value ) =>
							setAttributes( { venueId: parseInt( value, 10 ) } )
						}
					/>

					<SelectControl
						label={ __(
							'Layout',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ layout }
						options={ [
							{
								value: 'list',
								label: __(
									'List',
									'post-kinds-for-indieweb-in-block-themes'
								),
							},
							{
								value: 'grid',
								label: __(
									'Grid',
									'post-kinds-for-indieweb-in-block-themes'
								),
							},
							{
								value: 'compact',
								label: __(
									'Compact',
									'post-kinds-for-indieweb-in-block-themes'
								),
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { layout: value } )
						}
					/>

					{ layout === 'grid' && (
						<RangeControl
							label={ __(
								'Columns',
								'post-kinds-for-indieweb-in-block-themes'
							) }
							value={ columns }
							onChange={ ( value ) =>
								setAttributes( { columns: value } )
							}
							min={ 2 }
							max={ 4 }
						/>
					) }
				</PanelBody>

				<PanelBody
					title={ __(
						'Display Options',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __(
							'Show Map',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ showMap }
						onChange={ ( value ) =>
							setAttributes( { showMap: value } )
						}
						help={ __(
							'Display a map with check-in locations.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>

					<ToggleControl
						label={ __(
							'Show Venue Name',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ showVenue }
						onChange={ ( value ) =>
							setAttributes( { showVenue: value } )
						}
					/>

					<ToggleControl
						label={ __(
							'Show Date',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ showDate }
						onChange={ ( value ) =>
							setAttributes( { showDate: value } )
						}
					/>

					<ToggleControl
						label={ __(
							'Show Excerpt',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ showExcerpt }
						onChange={ ( value ) =>
							setAttributes( { showExcerpt: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ isLoading && (
					<Placeholder
						icon="location-alt"
						label={ __(
							'Check-ins Feed',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					>
						<Spinner />
					</Placeholder>
				) }
				{ ! isLoading && checkins.length === 0 && (
					<Placeholder
						icon="location-alt"
						label={ __(
							'Check-ins Feed',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						instructions={ __(
							'No check-ins found. Create some check-ins to see them here.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				) }
				{ ! isLoading && checkins.length > 0 && (
					<>
						{ showMap && (
							<div className="checkins-feed__map-placeholder">
								<div className="checkins-feed__map-preview">
									<span className="dashicons dashicons-location-alt"></span>
									<span>
										{ __(
											'Map will display here on the frontend',
											'post-kinds-for-indieweb-in-block-themes'
										) }
									</span>
								</div>
							</div>
						) }

						<div
							className={ `checkins-feed__list ${
								layout === 'grid' ? `columns-${ columns }` : ''
							}` }
						>
							{ checkins.map( ( checkin ) => (
								<article
									key={ checkin.id }
									className="checkins-feed__item"
								>
									<h3 className="checkins-feed__title">
										{ checkin.title?.rendered ||
											__(
												'(no title)',
												'post-kinds-for-indieweb-in-block-themes'
											) }
									</h3>

									{ showVenue &&
										checkin._embedded?.[ 'wp:term' ] && (
											<div className="checkins-feed__venue">
												<span className="dashicons dashicons-location"></span>
												{ checkin._embedded[ 'wp:term' ]
													.flat()
													.find(
														( t ) =>
															t.taxonomy ===
															'venue'
													)?.name ||
													__(
														'Unknown venue',
														'post-kinds-for-indieweb-in-block-themes'
													) }
											</div>
										) }

									{ showDate && (
										<time className="checkins-feed__date">
											{ new Date(
												checkin.date
											).toLocaleDateString() }
										</time>
									) }

									{ showExcerpt &&
										checkin.excerpt?.rendered && (
											<div
												className="checkins-feed__excerpt"
												dangerouslySetInnerHTML={ {
													__html: checkin.excerpt
														.rendered,
												} }
											/>
										) }
								</article>
							) ) }
						</div>
					</>
				) }
			</div>
		</>
	);
}
