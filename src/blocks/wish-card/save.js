/**
 * Wish Card Block - Save Component
 *
 * @package
 */

import { useBlockProps } from '@wordpress/block-editor';

const TYPE_LABELS = {
	item: 'Item',
	experience: 'Experience',
	book: 'Book',
	game: 'Game',
	media: 'Media',
	travel: 'Travel',
	other: 'Other',
};
const PRIORITY_LABELS = { low: 'Low', medium: 'Medium', high: 'High' };

export default function Save( { attributes } ) {
	const {
		title,
		wishType,
		url,
		image,
		imageAlt,
		price,
		reason,
		priority,
		wishedAt,
		rel,
		layout,
	} = attributes;
	const blockProps = useBlockProps.save( {
		className: `wish-card layout-${ layout } priority-${ priority }`,
	} );

	// Build rel attribute - always includes noopener noreferrer for security
	const linkRel = rel
		? `noopener noreferrer ${ rel }`
		: 'noopener noreferrer';

	return (
		<div { ...blockProps }>
			<div className="post-kinds-card h-cite">
				{ image && (
					<div className="post-kinds-card__media">
						<img
							src={ image }
							alt={ imageAlt || title }
							className="post-kinds-card__image u-photo"
							loading="lazy"
						/>
					</div>
				) }
				<div className="post-kinds-card__content">
					<div className="post-kinds-card__badges">
						<span className="post-kinds-card__badge">
							{ TYPE_LABELS[ wishType ] || wishType }
						</span>
						<span
							className={ `post-kinds-card__badge post-kinds-card__badge--${ priority }` }
						>
							{ PRIORITY_LABELS[ priority ] }
						</span>
					</div>

					{ title && (
						<h3 className="post-kinds-card__title p-name">
							{ url ? (
								// eslint-disable-next-line react/jsx-no-target-blank -- linkRel always includes noreferrer
								<a
									href={ url }
									className="u-url u-wish-of"
									target="_blank"
									rel={ linkRel }
								>
									{ title }
								</a>
							) : (
								title
							) }
						</h3>
					) }

					{ price && (
						<p className="post-kinds-card__subtitle">{ price }</p>
					) }

					{ reason && (
						<p className="post-kinds-card__notes p-content">
							{ reason }
						</p>
					) }

					{ wishedAt && (
						<time
							className="post-kinds-card__timestamp dt-published"
							dateTime={ new Date( wishedAt ).toISOString() }
						>
							{ new Date( wishedAt ).toLocaleString() }
						</time>
					) }
				</div>
			</div>
		</div>
	);
}
