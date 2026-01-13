/**
 * Eat Card Block - Save Component
 *
 * @package Reactions_For_IndieWeb
 */

import { useBlockProps } from '@wordpress/block-editor';

export default function Save( { attributes } ) {
	const {
		name,
		restaurant,
		cuisine,
		photo,
		photoAlt,
		rating,
		ateAt,
		notes,
		restaurantUrl,
		locality,
		layout,
	} = attributes;

	const blockProps = useBlockProps.save( { className: `eat-card layout-${ layout }` } );

	const renderStars = () => {
		if ( ! rating || rating <= 0 ) return null;
		return (
			<div className="reactions-card__rating p-rating" aria-label={ `Rating: ${ rating } out of 5` }>
				{ Array.from( { length: 5 }, ( _, i ) => (
					<span key={ i } className={ `star ${ i < rating ? 'filled' : '' }` } aria-hidden="true">★</span>
				) ) }
				<span className="reactions-card__rating-value">{ rating }/5</span>
			</div>
		);
	};

	return (
		<div { ...blockProps }>
			<div className="reactions-card h-food">
				{ photo && (
					<div className="reactions-card__media">
						<img
							src={ photo }
							alt={ photoAlt || name }
							className="reactions-card__image u-photo"
							loading="lazy"
						/>
					</div>
				) }
				<div className="reactions-card__content">
					{ cuisine && <span className="reactions-card__badge">{ cuisine }</span> }

					{ name && (
						<h3 className="reactions-card__title p-name">
							{ restaurantUrl ? (
								<a href={ restaurantUrl } className="u-url" target="_blank" rel="noopener noreferrer">
									{ name }
								</a>
							) : (
								name
							) }
						</h3>
					) }

					{ restaurant && (
						<p className="reactions-card__subtitle p-location h-card">
							<span className="p-name">{ restaurant }</span>
							{ locality && <span className="p-locality">, { locality }</span> }
						</p>
					) }

					{ renderStars() }

					{ notes && <p className="reactions-card__notes p-content">{ notes }</p> }

					{ ateAt && (
						<time
							className="reactions-card__timestamp dt-published"
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
