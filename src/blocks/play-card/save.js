/**
 * Play Card Block - Save Component
 *
 * @package Reactions_For_IndieWeb
 */

import { useBlockProps } from '@wordpress/block-editor';

/**
 * Status labels for display.
 */
const STATUS_LABELS = {
	playing: 'Playing',
	completed: 'Completed',
	abandoned: 'Abandoned',
	backlog: 'Backlog',
	wishlist: 'Wishlist',
};

/**
 * Save component for the Play Card block.
 *
 * @param {Object} props Block props.
 * @returns {JSX.Element} Block save component.
 */
export default function Save( { attributes } ) {
	const {
		title,
		platform,
		cover,
		coverAlt,
		status,
		hoursPlayed,
		rating,
		playedAt,
		review,
		gameUrl,
		bggId,
		rawgId,
		developer,
		publisher,
		releaseYear,
		layout,
	} = attributes;

	const blockProps = useBlockProps.save( {
		className: `play-card layout-${ layout }`,
	} );

	/**
	 * Generate stars for rating.
	 */
	const renderStars = () => {
		if ( ! rating || rating <= 0 ) {
			return null;
		}

		return (
			<div
				className="reactions-card__rating p-rating"
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
				<span className="reactions-card__rating-value">{ rating }/5</span>
			</div>
		);
	};

	return (
		<div { ...blockProps }>
			<div className="reactions-card h-cite">
				{ /* Cover image */ }
				{ cover && (
					<div className="reactions-card__media">
						<img
							src={ cover }
							alt={ coverAlt || title }
							className="reactions-card__image u-photo"
							loading="lazy"
						/>
					</div>
				) }

				<div className="reactions-card__content">
					{ /* Status and platform badges */ }
					<div className="reactions-card__badges">
						{ status && (
							<span className={ `reactions-card__badge reactions-card__badge--${ status }` }>
								{ STATUS_LABELS[ status ] || status }
							</span>
						) }
						{ platform && <span className="reactions-card__badge">{ platform }</span> }
					</div>

					{ /* Game title */ }
					{ title && (
						<h3 className="reactions-card__title p-name">
							{ gameUrl ? (
								<a
									href={ gameUrl }
									className="u-url"
									target="_blank"
									rel="noopener noreferrer"
								>
									{ title }
								</a>
							) : (
								title
							) }
						</h3>
					) }

					{ /* Developer */ }
					{ developer && (
						<p className="reactions-card__subtitle">
							<span className="p-author h-card">
								<span className="p-name">{ developer }</span>
							</span>
						</p>
					) }

					{ /* Publisher and year */ }
					{ ( publisher || releaseYear ) && (
						<p className="reactions-card__meta">
							{ publisher }
							{ releaseYear && (
								<span> ({ releaseYear })</span>
							) }
						</p>
					) }

					{ /* Hours played */ }
					{ hoursPlayed > 0 && (
						<p className="reactions-card__meta">
							<strong>{ hoursPlayed }</strong> hours played
						</p>
					) }

					{ /* Rating */ }
					{ renderStars() }

					{ /* Review */ }
					{ review && <p className="reactions-card__notes p-content">{ review }</p> }

					{ /* Played timestamp */ }
					{ playedAt && (
						<time
							className="reactions-card__timestamp dt-published"
							dateTime={ new Date( playedAt ).toISOString() }
						>
							{ new Date( playedAt ).toLocaleString() }
						</time>
					) }
				</div>

				{ /* Hidden microformat data */ }
				<data className="u-play-of" value={ gameUrl || '' } hidden />
				{ bggId && (
					<data
						className="u-uid"
						value={ `https://boardgamegeek.com/boardgame/${ bggId }` }
						hidden
					/>
				) }
				{ rawgId && (
					<data
						className="u-uid"
						value={ `https://rawg.io/games/${ rawgId }` }
						hidden
					/>
				) }
			</div>
		</div>
	);
}
