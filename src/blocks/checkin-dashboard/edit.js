/**
 * Check-in Dashboard Block - Edit Component
 *
 * @package Reactions_For_IndieWeb
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl, RangeControl } from '@wordpress/components';
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

	// Fetch check-ins for preview
	useEffect( () => {
		setLoading( true );
		Promise.all( [
			apiFetch( { path: `/reactions-indieweb/v1/checkins?per_page=${ limit }` } ),
			apiFetch( { path: '/reactions-indieweb/v1/checkins/stats' } ),
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
				<PanelBody title={ __( 'Display Settings', 'reactions-for-indieweb' ) }>
					<SelectControl
						label={ __( 'Default Layout', 'reactions-for-indieweb' ) }
						value={ layout }
						options={ [
							{ label: __( 'Grid', 'reactions-for-indieweb' ), value: 'grid' },
							{ label: __( 'Map', 'reactions-for-indieweb' ), value: 'map' },
							{ label: __( 'Timeline', 'reactions-for-indieweb' ), value: 'timeline' },
						] }
						onChange={ ( v ) => setAttributes( { layout: v } ) }
					/>
					<RangeControl
						label={ __( 'Number of Check-ins', 'reactions-for-indieweb' ) }
						value={ limit }
						onChange={ ( v ) => setAttributes( { limit: v } ) }
						min={ 4 }
						max={ 50 }
					/>
					<ToggleControl
						label={ __( 'Show Map View', 'reactions-for-indieweb' ) }
						checked={ showMap }
						onChange={ ( v ) => setAttributes( { showMap: v } ) }
					/>
					<ToggleControl
						label={ __( 'Show Statistics', 'reactions-for-indieweb' ) }
						checked={ showStats }
						onChange={ ( v ) => setAttributes( { showStats: v } ) }
					/>
					<ToggleControl
						label={ __( 'Show Filters', 'reactions-for-indieweb' ) }
						checked={ showFilters }
						onChange={ ( v ) => setAttributes( { showFilters: v } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div className="checkin-dashboard-preview">
					{ showStats && (
						<div className="checkin-stats-preview">
							<div className="stat-item">
								<span className="stat-value">{ stats.total || 0 }</span>
								<span className="stat-label">{ __( 'Check-ins', 'reactions-for-indieweb' ) }</span>
							</div>
							<div className="stat-item">
								<span className="stat-value">{ stats.unique_venues || 0 }</span>
								<span className="stat-label">{ __( 'Venues', 'reactions-for-indieweb' ) }</span>
							</div>
							<div className="stat-item">
								<span className="stat-value">{ stats.countries?.length || 0 }</span>
								<span className="stat-label">{ __( 'Countries', 'reactions-for-indieweb' ) }</span>
							</div>
						</div>
					) }

					{ loading ? (
						<div className="checkin-loading">
							<span className="spinner is-active"></span>
							{ __( 'Loading check-ins...', 'reactions-for-indieweb' ) }
						</div>
					) : checkins.length === 0 ? (
						<div className="checkin-empty">
							<span className="dashicons dashicons-location"></span>
							<p>{ __( 'No check-ins found. Create check-in posts to see them here.', 'reactions-for-indieweb' ) }</p>
						</div>
					) : (
						<div className="checkin-grid-preview">
							{ checkins.slice( 0, 6 ).map( ( checkin, index ) => (
								<div key={ index } className="checkin-card-preview">
									{ checkin.photo ? (
										<img src={ checkin.photo } alt={ checkin.venue_name } />
									) : (
										<div className="no-photo">
											<span className="dashicons dashicons-location"></span>
										</div>
									) }
									<div className="checkin-card-info">
										<strong>{ checkin.venue_name }</strong>
										<span>{ checkin.address }</span>
									</div>
								</div>
							) ) }
						</div>
					) }

					{ checkins.length > 6 && (
						<p className="checkin-more">
							{ __( '+ more check-ins will be shown on the frontend', 'reactions-for-indieweb' ) }
						</p>
					) }
				</div>
			</div>
		</>
	);
}
