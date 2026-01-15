/**
 * Eat Card Block - Save Component
 *
 * @package
 */

import { useBlockProps } from '@wordpress/block-editor';

export default function Save( { attributes } ) {
	const {
		name,
		cuisine,
		photo,
		photoAlt,
		rating,
		ateAt,
		notes,
		restaurantUrl,
		locationName,
		locationAddress,
		locationLocality,
		locationRegion,
		locationCountry,
		geoLatitude,
		geoLongitude,
		layout,
	} = attributes;

	const blockProps = useBlockProps.save( {
		className: `eat-card layout-${ layout }`,
	} );

	const renderStars = () => {
		if ( ! rating || rating <= 0 ) {
			return null;
		}
		return (
			<div
				className="post-kinds-card__rating p-rating"
				aria-label={ `Rating: ${ rating } out of 5` }
			>
				{ Array.from( { length: 5 }, ( _, i ) => (
					<span
						key={ i }
						className={ `star ${ i < rating ? 'filled' : '' }` }
						aria-hidden="true"
					>
						★
					</span>
				) ) }
				<span className="post-kinds-card__rating-value">
					{ rating }/5
				</span>
			</div>
		);
	};

	return (
		<div { ...blockProps }>
			<div className="post-kinds-card h-food">
				{ photo && (
					<div className="post-kinds-card__media">
						<img
							src={ photo }
							alt={ photoAlt || name }
							className="post-kinds-card__image u-photo"
							loading="lazy"
						/>
					</div>
				) }
				<div className="post-kinds-card__content">
					{ cuisine && (
						<span className="post-kinds-card__badge">
							{ cuisine }
						</span>
					) }

					{ name && (
						<h3 className="post-kinds-card__title p-name">
							{ name }
						</h3>
					) }

					{ locationName && (
						<div className="post-kinds-card__location p-location h-card">
							<p className="post-kinds-card__venue">
								{ restaurantUrl ? (
									<a
										href={ restaurantUrl }
										className="p-name u-url"
										target="_blank"
										rel="noopener noreferrer"
									>
										{ locationName }
									</a>
								) : (
									<span className="p-name">
										{ locationName }
									</span>
								) }
							</p>
							{ locationAddress && (
								<p className="post-kinds-card__address p-street-address">
									{ locationAddress }
								</p>
							) }
							{ ( locationLocality ||
								locationRegion ||
								locationCountry ) && (
								<p className="post-kinds-card__city">
									{ locationLocality && (
										<span className="p-locality">
											{ locationLocality }
										</span>
									) }
									{ locationLocality &&
										locationRegion &&
										', ' }
									{ locationRegion && (
										<span className="p-region">
											{ locationRegion }
										</span>
									) }
									{ ( locationLocality || locationRegion ) &&
										locationCountry &&
										', ' }
									{ locationCountry && (
										<span className="p-country-name">
											{ locationCountry }
										</span>
									) }
								</p>
							) }
							{ ( geoLatitude !== 0 || geoLongitude !== 0 ) && (
								<data
									className="p-geo h-geo"
									value={ `${ geoLatitude },${ geoLongitude }` }
									hidden
								>
									<span className="p-latitude">
										{ geoLatitude }
									</span>
									<span className="p-longitude">
										{ geoLongitude }
									</span>
								</data>
							) }
						</div>
					) }

					{ renderStars() }

					{ notes && (
						<p className="post-kinds-card__notes p-content">
							{ notes }
						</p>
					) }

					{ ateAt && (
						<time
							className="post-kinds-card__timestamp dt-published"
							dateTime={ new Date( ateAt ).toISOString() }
						>
							{ new Date( ateAt ).toLocaleString() }
						</time>
					) }
				</div>

				<data className="u-ate" value={ name || '' } hidden />
			</div>
		</div>
	);
}
