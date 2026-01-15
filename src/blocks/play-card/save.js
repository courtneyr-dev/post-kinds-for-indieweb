/**
 * Play Card Block - Save Component
 *
 * @package
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
 * @param {Object} props            Block props.
 * @param {Object} props.attributes Block attributes.
 * @return {JSX.Element} Block save component.
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
		officialUrl,
		purchaseUrl,
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
				{ cover && (
					<div className="post-kinds-card__media">
						<img
							src={ cover }
							alt={ coverAlt || title }
							className="post-kinds-card__image u-photo"
							loading="lazy"
						/>
					</div>
				) }

				<div className="post-kinds-card__content">
					{ /* Status and platform badges */ }
					<div className="post-kinds-card__badges">
						{ status && (
							<span
								className={ `post-kinds-card__badge post-kinds-card__badge--${ status }` }
							>
								{ STATUS_LABELS[ status ] || status }
							</span>
						) }
						{ platform && (
							<span className="post-kinds-card__badge">
								{ platform }
							</span>
						) }
					</div>

					{ /* Game title */ }
					{ title && (
						<h3 className="post-kinds-card__title p-name">
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

					{ /* Hours played */ }
					{ hoursPlayed > 0 && (
						<p className="post-kinds-card__meta">
							<strong>{ hoursPlayed }</strong> hours played
						</p>
					) }

					{ /* Rating */ }
					{ renderStars() }

					{ /* Review */ }
					{ review && (
						<p className="post-kinds-card__notes p-content">
							{ review }
						</p>
					) }

					{ /* Links */ }
					{ ( officialUrl || purchaseUrl || gameUrl ) && (
						<div className="post-kinds-card__links">
							{ gameUrl && (
								<a
									href={ gameUrl }
									className="post-kinds-card__link"
									target="_blank"
									rel="noopener noreferrer"
								>
									View on BGG
								</a>
							) }
							{ officialUrl && (
								<a
									href={ officialUrl }
									className="post-kinds-card__link"
									target="_blank"
									rel="noopener noreferrer"
								>
									Official Site
								</a>
							) }
							{ purchaseUrl && (
								<a
									href={ purchaseUrl }
									className="post-kinds-card__link post-kinds-card__link--buy"
									target="_blank"
									rel="noopener noreferrer"
								>
									Buy
								</a>
							) }
						</div>
					) }

					{ /* Played timestamp */ }
					{ playedAt && (
						<time
							className="post-kinds-card__timestamp dt-published"
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
