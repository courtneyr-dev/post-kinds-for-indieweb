/**
 * Check-in Dashboard Block - Edit Component
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	ToggleControl,
	RangeControl,
} from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export default function Edit( { attributes, setAttributes } ) {
	const { layout, showMap, showStats, limit, showFilters } = attributes;
	const [ checkins, setCheckins ] = useState( [] );
	const [ stats, setStats ] = useState( {} );
	const [ loading, setLoading ] = useState( true );

	const blockProps = useBlockProps( {
		className: `checkin-dashboard-block layout-${ layout }`,
	} );

	/**
	 * Render the main content area based on loading/data state.
	 *
	 * @return {JSX.Element} Content element.
	 */
	const renderContent = () => {
		if ( loading ) {
			return (
				<div className="checkin-loading">
					<span className="spinner is-active"></span>
					{ __(
						'Loading check-ins…',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				</div>
			);
		}

		if ( checkins.length === 0 ) {
			return (
				<div className="checkin-empty">
					<span className="dashicons dashicons-location"></span>
					<p>
						{ __(
							'No check-ins found. Create check-in posts to see them here.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					</p>
				</div>
			);
		}

		return (
			<div className="checkin-grid-preview">
				{ checkins.slice( 0, 6 ).map( ( checkin, index ) => (
					<div key={ index } className="checkin-card-preview">
						{ checkin.photo ? (
							<img
								src={ checkin.photo }
								alt={ checkin.venue_name }
							/>
						) : (
							<div className="no-photo">
								<span className="dashicons dashicons-location"></span>
							</div>
						) }
						<div className="checkin-info">
							<strong>{ checkin.venue_name }</strong>
							<span>{ checkin.city }</span>
						</div>
					</div>
				) ) }
			</div>
		);
	};

	// Fetch check-ins for preview
	useEffect( () => {
		setLoading( true );
		Promise.all( [
			apiFetch( {
				path: `/post-kinds-indieweb/v1/checkins?per_page=${ limit }`,
			} ),
			apiFetch( { path: '/post-kinds-indieweb/v1/checkins/stats' } ),
		] )
			.then( ( [ checkinsData, statsData ] ) => {
				setCheckins( checkinsData || [] );
				setStats( statsData || {} );
				setLoading( false );
			} )
			.catch( () => {
				setLoading( false );
			} );
	}, [ limit ] );

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Display Settings',
						'post-kinds-for-indieweb-in-block-themes'
					) }
				>
					<SelectControl
						label={ __(
							'Default Layout',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ layout }
						options={ [
							{
								label: __(
									'Grid',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'grid',
							},
							{
								label: __(
									'Map',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'map',
							},
							{
								label: __(
									'Timeline',
									'post-kinds-for-indieweb-in-block-themes'
								),
								value: 'timeline',
							},
						] }
						onChange={ ( v ) => setAttributes( { layout: v } ) }
					/>
					<RangeControl
						label={ __(
							'Number of Check-ins',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ limit }
						onChange={ ( v ) => setAttributes( { limit: v } ) }
						min={ 4 }
						max={ 50 }
					/>
					<ToggleControl
						label={ __(
							'Show Map View',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ showMap }
						onChange={ ( v ) => setAttributes( { showMap: v } ) }
					/>
					<ToggleControl
						label={ __(
							'Show Statistics',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ showStats }
						onChange={ ( v ) => setAttributes( { showStats: v } ) }
					/>
					<ToggleControl
						label={ __(
							'Show Filters',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ showFilters }
						onChange={ ( v ) =>
							setAttributes( { showFilters: v } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div className="checkin-dashboard-preview">
					{ showStats && (
						<div className="checkin-stats-preview">
							<div className="stat-item">
								<span className="stat-value">
									{ stats.total || 0 }
								</span>
								<span className="stat-label">
									{ __(
										'Check-ins',
										'post-kinds-for-indieweb-in-block-themes'
									) }
								</span>
							</div>
							<div className="stat-item">
								<span className="stat-value">
									{ stats.unique_venues || 0 }
								</span>
								<span className="stat-label">
									{ __(
										'Venues',
										'post-kinds-for-indieweb-in-block-themes'
									) }
								</span>
							</div>
							<div className="stat-item">
								<span className="stat-value">
									{ stats.countries?.length || 0 }
								</span>
								<span className="stat-label">
									{ __(
										'Countries',
										'post-kinds-for-indieweb-in-block-themes'
									) }
								</span>
							</div>
						</div>
					) }

					{ renderContent() }

					{ checkins.length > 6 && (
						<p className="checkin-more">
							{ __(
								'+ more check-ins will be shown on the frontend',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						</p>
					) }
				</div>
			</div>
		</>
	);
}
