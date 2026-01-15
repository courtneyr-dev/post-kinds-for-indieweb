/**
 * Acquisition Card Block - Save Component
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
	other: 'Other',
};

export default function Save( { attributes } ) {
	const {
		title,
		acquisitionType,
		cost,
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

					{ cost && (
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

					{ acquiredAt && (
						<time
							className="post-kinds-card__timestamp dt-published"
							dateTime={ new Date( acquiredAt ).toISOString() }
						>
							{ new Date( acquiredAt ).toLocaleString() }
						</time>
					) }
				</div>

				<data className="u-acquired" value={ title || '' } hidden />
			</div>
		</div>
	);
}
