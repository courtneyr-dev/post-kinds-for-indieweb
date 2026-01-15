/**
 * Listen Card Block - Save Component
 *
 * @package
 */

import { useBlockProps } from '@wordpress/block-editor';

/**
 * Save component for the Listen Card block.
 *
 * @param {Object} props            Block props.
 * @param {Object} props.attributes Block attributes.
 * @return {JSX.Element} Block save component.
 */
export default function Save( { attributes } ) {
	const {
		trackTitle,
		artistName,
		albumTitle,
		releaseDate,
		coverImage,
		coverImageAlt,
		listenUrl,
		musicbrainzId,
		rating,
		listenedAt,
		layout,
	} = attributes;

	const blockProps = useBlockProps.save( {
		className: `listen-card layout-${ layout }`,
	} );

	// Generate stars for rating
	const renderStars = () => {
		if ( ! rating || rating <= 0 ) {
			return null;
		}

		return (
			<div
				className="post-kinds-card__rating p-rating"
				aria-label={ `Rating: ${ rating } out of 5 stars` }
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
			<div className="post-kinds-card h-cite">
				{ /* Cover image */ }
				{ coverImage && (
					<div className="post-kinds-card__media">
						<img
							src={ coverImage }
							alt={
								coverImageAlt ||
								`${ trackTitle } by ${ artistName }`
							}
							className="post-kinds-card__image u-photo"
							loading="lazy"
						/>
					</div>
				) }

				<div className="post-kinds-card__content">
					{ /* Track title */ }
					{ trackTitle && (
						<h3 className="post-kinds-card__title p-name">
							{ listenUrl ? (
								<a
									href={ listenUrl }
									className="u-url"
									target="_blank"
									rel="noopener noreferrer"
								>
									{ trackTitle }
								</a>
							) : (
								trackTitle
							) }
						</h3>
					) }

					{ /* Artist */ }
					{ artistName && (
						<p className="post-kinds-card__subtitle">
							<span className="p-author h-card">
								<span className="p-name">{ artistName }</span>
							</span>
						</p>
					) }

					{ /* Album */ }
					{ albumTitle && (
						<p className="post-kinds-card__meta">
							{ albumTitle }
							{ releaseDate && (
								<span className="post-kinds-card__meta-detail">
									{ ' ' }
									({ new Date( releaseDate ).getFullYear() })
								</span>
							) }
						</p>
					) }

					{ /* Rating */ }
					{ renderStars() }

					{ /* Listened timestamp */ }
					{ listenedAt && (
						<time
							className="post-kinds-card__timestamp dt-published"
							dateTime={ new Date( listenedAt ).toISOString() }
						>
							{ new Date( listenedAt ).toLocaleString() }
						</time>
					) }
				</div>

				{ /* Hidden microformat data */ }
				<data
					className="u-listen-of"
					value={ listenUrl || '' }
					hidden
				/>
				{ musicbrainzId && (
					<data
						className="u-uid"
						value={ `https://musicbrainz.org/recording/${ musicbrainzId }` }
						hidden
					/>
				) }
			</div>
		</div>
	);
}
