/**
 * Acquisition Card Block - Save Component
 *
 * The static markup keeps a private cost out of post_content, and agrees
 * with render.php: the same type labels, the h-cite marked as the entry's
 * acquisition-of property, and an acquired date printed as the stored
 * Y-m-d so the saved bytes don't depend on the author's time zone.
 *
 * @package
 */

import { useBlockProps } from '@wordpress/block-editor';

const TYPE_LABELS = {
	purchase: 'Purchase',
	gift: 'Gift',
	found: 'Found',
	won: 'Won',
	trade: 'Trade',
	free: 'Free',
	inherited: 'Inherited',
	other: 'Other',
};

/**
 * The calendar day in an acquiredAt value, as render.php reads it.
 *
 * @param {string} raw Stored acquiredAt value.
 * @return {string} Y-m-d, or '' when the value doesn't start with a real day.
 */
function calendarDay( raw ) {
	const match = /^(\d{4})-(\d{2})-(\d{2})/.exec( String( raw || '' ).trim() );

	if ( ! match ) {
		return '';
	}

	const [ year, month, day ] = match.slice( 1 ).map( Number );
	const date = new Date( 0 );
	date.setUTCFullYear( year, month - 1, day );

	if (
		date.getUTCFullYear() !== year ||
		date.getUTCMonth() !== month - 1 ||
		date.getUTCDate() !== day
	) {
		return '';
	}

	return match.slice( 1 ).join( '-' );
}

export default function Save( { attributes } ) {
	const {
		title,
		acquisitionType,
		cost,
		showCostPublicly,
		where,
		whereUrl,
		photo,
		photoAlt,
		notes,
		acquiredAt,
		layout,
	} = attributes;
	const blockProps = useBlockProps.save( {
		className: `acquisition-card layout-${ layout }`,
	} );
	const acquiredDay = calendarDay( acquiredAt );

	return (
		<div { ...blockProps }>
			<div className="post-kinds-card h-cite u-acquisition-of">
				{ photo && (
					<div className="post-kinds-card__media">
						<img
							src={ photo }
							alt={ photoAlt || '' }
							className="post-kinds-card__image u-photo"
							loading="lazy"
						/>
					</div>
				) }
				<div className="post-kinds-card__content">
					<span className="post-kinds-card__badge">
						{ TYPE_LABELS[ acquisitionType ] ||
							acquisitionType ||
							'Acquired' }
					</span>

					{ title && (
						<h3 className="post-kinds-card__title p-name">
							{ whereUrl ? (
								<a
									href={ whereUrl }
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

					{ cost && showCostPublicly && (
						<p className="post-kinds-card__subtitle">{ cost }</p>
					) }

					{ where && (
						<p className="post-kinds-card__meta p-location">
							from { where }
						</p>
					) }

					{ notes && (
						<p className="post-kinds-card__notes p-content">
							{ notes }
						</p>
					) }

					{ acquiredDay && (
						<time
							className="post-kinds-card__timestamp dt-published"
							dateTime={ acquiredDay }
						>
							{ acquiredDay }
						</time>
					) }
				</div>
			</div>
		</div>
	);
}
