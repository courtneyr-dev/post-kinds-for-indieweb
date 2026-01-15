/**
 * Drink Card Block - Save Component
 *
 * @package
 */

import { useBlockProps } from '@wordpress/block-editor';

const DRINK_LABELS = {
	coffee: 'Coffee',
	tea: 'Tea',
	beer: 'Beer',
	wine: 'Wine',
	cocktail: 'Cocktail',
	juice: 'Juice',
	soda: 'Soda',
	smoothie: 'Smoothie',
	water: 'Water',
	other: 'Drink',
};

export default function Save( { attributes } ) {
	const {
		name,
		drinkType,
		brand,
		photo,
		photoAlt,
		rating,
		drankAt,
		notes,
		venueUrl,
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
		className: `drink-card layout-${ layout }`,
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
					<span className="post-kinds-card__badge">
						{ DRINK_LABELS[ drinkType ] || drinkType }
					</span>

					{ name && (
						<h3 className="post-kinds-card__title p-name">
							{ name }
						</h3>
					) }

					{ brand && (
						<p className="post-kinds-card__subtitle p-author h-card">
							<span className="p-name">{ brand }</span>
						</p>
					) }

					{ locationName && (
						<div className="post-kinds-card__location p-location h-card">
							<p className="post-kinds-card__venue">
								{ venueUrl ? (
									<a
										href={ venueUrl }
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

					{ drankAt && (
						<time
							className="post-kinds-card__timestamp dt-published"
							dateTime={ new Date( drankAt ).toISOString() }
						>
							{ new Date( drankAt ).toLocaleString() }
						</time>
					) }
				</div>

				<data className="u-drank" value={ name || '' } hidden />
			</div>
		</div>
	);
}
