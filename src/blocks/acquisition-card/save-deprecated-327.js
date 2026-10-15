/**
 * Acquisition Card Block - Deprecated Save Component (issue 327)
 *
 * The save from the Show cost publicly toggle (issue 327) until issue 239.
 * It printed the acquired date through the browser's time zone, kept the
 * u-acquired data element, and had no label for an inherited item. Kept so
 * cards stored with it still validate; the editor moves them to the current
 * save.
 *
 * @package
 */

import { useBlockProps } from '@wordpress/block-editor';

import { parseDate } from '../shared/components';

const TYPE_LABELS = {
	purchase: 'Purchase',
	gift: 'Gift',
	found: 'Found',
	won: 'Won',
	trade: 'Trade',
	free: 'Free',
	other: 'Other',
};

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

	return (
		<div { ...blockProps }>
			<div className="post-kinds-card h-cite">
				{ photo && (
					<div className="post-kinds-card__media">
						<img
							src={ photo }
							alt={ photoAlt || title }
							className="post-kinds-card__image u-photo"
							loading="lazy"
						/>
					</div>
				) }
				<div className="post-kinds-card__content">
					<span className="post-kinds-card__badge">
						{ TYPE_LABELS[ acquisitionType ] || acquisitionType }
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

					{ parseDate( acquiredAt ) && (
						<time
							className="post-kinds-card__timestamp dt-published"
							dateTime={ parseDate( acquiredAt ).toISOString() }
						>
							{ parseDate( acquiredAt ).toLocaleString() }
						</time>
					) }
				</div>

				<data className="u-acquired" value={ title || '' } hidden />
			</div>
		</div>
	);
}
