/**
 * Checkin Card Block - Save Component
 *
 * Privacy-aware rendering for IndieWeb microformats.
 *
 * @package
 */

import { useBlockProps, RichText } from '@wordpress/block-editor';

/**
 * Save component for the Checkin Card block.
 *
 * @param {Object} props            Block props.
 * @param {Object} props.attributes Block attributes.
 * @return {JSX.Element} Block save component.
 */
export default function Save( { attributes } ) {
	const {
		venueName,
		venueType,
		address,
		locality,
		region,
		country,
		postalCode,
		latitude,
		longitude,
		locationPrivacy = 'approximate',
		osmId,
		venueUrl,
		foursquareId,
		checkinAt,
		note,
		photo,
		photoAlt,
		showMap,
		layout,
	} = attributes;

	const blockProps = useBlockProps.save( {
		className: `checkin-card layout-${ layout }`,
	} );

	// Don't render location content for private privacy
	const isPrivate = locationPrivacy === 'private';
	const isPublic = locationPrivacy === 'public';
	const showCoords = isPublic && latitude && longitude;
	const showAddress = isPublic && address;
	const showMapEmbed = showMap && latitude && longitude && ! isPrivate;

	/**
	 * Get venue type icon
	 */
	const getVenueIcon = () => {
		const icons = {
			place: '📍',
			restaurant: '🍽️',
			cafe: '☕',
			bar: '🍺',
			hotel: '🏨',
			airport: '✈️',
			park: '🌳',
			museum: '🏛️',
			theater: '🎭',
			store: '🛍️',
			office: '🏢',
			home: '🏠',
			other: '📌',
		};
		return icons[ venueType ] || icons.place;
	};

	/**
	 * Get venue type label
	 */
	const getVenueTypeLabel = () => {
		const labels = {
			place: 'Place',
			restaurant: 'Restaurant',
			cafe: 'Cafe',
			bar: 'Bar',
			hotel: 'Hotel',
			airport: 'Airport',
			park: 'Park',
			museum: 'Museum',
			theater: 'Theater',
			store: 'Store',
			office: 'Office',
			home: 'Home',
			other: 'Other',
		};
		return labels[ venueType ] || labels.place;
	};

	/**
	 * Generate map URL - adjust zoom based on privacy
	 */
	const getMapUrl = () => {
		if ( ! latitude || ! longitude ) {
			return null;
		}
		// Show wider area for approximate privacy
		const bbox = isPublic ? 0.01 : 0.1;
		return `https://www.openstreetmap.org/export/embed.html?bbox=${
			longitude - bbox
		},${ latitude - bbox },${ longitude + bbox },${
			latitude + bbox
		}&layer=mapnik&marker=${ latitude },${ longitude }`;
	};

	/**
	 * Generate geo URI
	 */
	const getGeoUri = () => {
		if ( ! latitude || ! longitude ) {
			return null;
		}
		return `geo:${ latitude },${ longitude }`;
	};

	// Private posts show a placeholder
	if ( isPrivate ) {
		return (
			<div { ...blockProps }>
				<div className="post-kinds-card post-kinds-card--private h-entry">
					{ /* Photo */ }
					{ photo && (
						<div className="post-kinds-card__media">
							<img
								src={ photo }
								alt={ photoAlt || `Photo` }
								className="post-kinds-card__image u-photo"
								loading="lazy"
							/>
						</div>
					) }

					<div className="post-kinds-card__content">
						<span className="post-kinds-card__badge">
							<span
								className="post-kinds-card__badge-icon"
								aria-hidden="true"
							>
								{ getVenueIcon() }
							</span>
							{ getVenueTypeLabel() }
						</span>

						{ /* For private: show vague indicator, no location data */ }
						<p className="post-kinds-card__private-notice">
							<span
								className="dashicons dashicons-lock"
								aria-hidden="true"
							></span>
							Location saved privately
						</p>

						{ /* Checkin time */ }
						{ checkinAt && (
							<time
								className="post-kinds-card__timestamp dt-published"
								dateTime={ new Date( checkinAt ).toISOString() }
							>
								{ new Date( checkinAt ).toLocaleString() }
							</time>
						) }

						{ /* Note can still be shown */ }
						{ note && (
							<div className="post-kinds-card__notes p-content">
								<RichText.Content tagName="p" value={ note } />
							</div>
						) }
					</div>
				</div>
			</div>
		);
	}

	return (
		<div { ...blockProps }>
			<div className="post-kinds-card h-entry">
				{ /* Photo */ }
				{ photo && (
					<div className="post-kinds-card__media">
						<img
							src={ photo }
							alt={ photoAlt || `Photo at ${ venueName }` }
							className="post-kinds-card__image u-photo"
							loading="lazy"
						/>
					</div>
				) }

				<div className="post-kinds-card__content">
					{ /* Venue type badge */ }
					<span className="post-kinds-card__badge">
						<span
							className="post-kinds-card__badge-icon"
							aria-hidden="true"
						>
							{ getVenueIcon() }
						</span>
						{ getVenueTypeLabel() }
					</span>

					{ /* Venue name */ }
					{ venueName && (
						<h3 className="post-kinds-card__title">
							{ venueUrl ? (
								<a
									href={ venueUrl }
									className="p-name u-url"
									target="_blank"
									rel="noopener noreferrer"
								>
									{ venueName }
								</a>
							) : (
								<span className="p-name">{ venueName }</span>
							) }
						</h3>
					) }

					{ /* Location with microformats - privacy aware */ }
					<div className="post-kinds-card__location p-location h-card">
						{ /* Street address only for public */ }
						{ showAddress && (
							<span className="p-street-address">
								{ address }
							</span>
						) }

						{ /* City/region/country shown for public and approximate */ }
						{ ( locality || region || country ) && (
							<span className="post-kinds-card__location-parts">
								{ locality && (
									<span className="p-locality">
										{ locality }
									</span>
								) }
								{ locality && region && ', ' }
								{ region && (
									<span className="p-region">{ region }</span>
								) }
								{ ( locality || region ) && country && ', ' }
								{ country && (
									<span className="p-country-name">
										{ country }
									</span>
								) }
							</span>
						) }

						{ /* Postal code only for public */ }
						{ isPublic && postalCode && (
							<span className="p-postal-code">
								{ postalCode }
							</span>
						) }

						{ /* Geo coordinates only for public */ }
						{ showCoords && (
							<data className="p-geo h-geo" value={ getGeoUri() }>
								<data
									className="p-latitude"
									value={ latitude }
									hidden
								/>
								<data
									className="p-longitude"
									value={ longitude }
									hidden
								/>
							</data>
						) }
					</div>

					{ /* Checkin time */ }
					{ checkinAt && (
						<time
							className="post-kinds-card__timestamp dt-published"
							dateTime={ new Date( checkinAt ).toISOString() }
						>
							{ new Date( checkinAt ).toLocaleString() }
						</time>
					) }

					{ /* Note */ }
					{ note && (
						<div className="post-kinds-card__notes p-content">
							<RichText.Content tagName="p" value={ note } />
						</div>
					) }
				</div>

				{ /* Map embed - not shown for private, shows wider area for approximate */ }
				{ showMapEmbed && (
					<div className="post-kinds-card__map">
						<iframe
							title={ `Map of ${ venueName || 'location' }` }
							width="100%"
							height="200"
							frameBorder="0"
							scrolling="no"
							marginHeight="0"
							marginWidth="0"
							src={ getMapUrl() }
							loading="lazy"
						/>
						{ isPublic && (
							<a
								href={ `https://www.openstreetmap.org/?mlat=${ latitude }&mlon=${ longitude }#map=16/${ latitude }/${ longitude }` }
								className="post-kinds-card__map-link"
								target="_blank"
								rel="noopener noreferrer"
							>
								View larger map
							</a>
						) }
						{ ! isPublic && (
							<p className="post-kinds-card__map-note">
								Showing approximate area
							</p>
						) }
					</div>
				) }

				{ /* Hidden microformat data - privacy aware */ }
				<data className="u-checkin" value={ venueUrl || '' } hidden />
				{ foursquareId && (
					<data
						className="u-uid"
						value={ `https://foursquare.com/v/${ foursquareId }` }
						hidden
					/>
				) }
				{ osmId && (
					<data
						className="u-uid"
						value={ `https://www.openstreetmap.org/${ osmId }` }
						hidden
					/>
				) }
			</div>
		</div>
	);
}
