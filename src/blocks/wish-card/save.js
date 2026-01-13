/**
 * Wish Card Block - Save Component
 *
 * @package Reactions_For_IndieWeb
 */

import { useBlockProps } from '@wordpress/block-editor';

const TYPE_LABELS = { item: 'Item', experience: 'Experience', book: 'Book', game: 'Game', media: 'Media', travel: 'Travel', other: 'Other' };
const PRIORITY_LABELS = { low: 'Low', medium: 'Medium', high: 'High' };

export default function Save( { attributes } ) {
	const { title, wishType, url, image, imageAlt, price, reason, priority, wishedAt, layout } = attributes;
	const blockProps = useBlockProps.save( { className: `wish-card layout-${ layout } priority-${ priority }` } );

	return (
		<div { ...blockProps }>
			<div className="reactions-card h-cite">
				{ image && (
					<div className="reactions-card__media">
						<img
							src={ image }
							alt={ imageAlt || title }
							className="reactions-card__image u-photo"
							loading="lazy"
						/>
					</div>
				) }
				<div className="reactions-card__content">
					<div className="reactions-card__badges">
						<span className="reactions-card__badge">
							{ TYPE_LABELS[ wishType ] || wishType }
						</span>
						<span className={ `reactions-card__badge reactions-card__badge--${ priority }` }>
							{ PRIORITY_LABELS[ priority ] }
						</span>
					</div>

					{ title && (
						<h3 className="reactions-card__title p-name">
							{ url ? (
								<a href={ url } className="u-url u-wish-of" target="_blank" rel="noopener noreferrer">
									{ title }
								</a>
							) : (
								title
							) }
						</h3>
					) }

					{ price && <p className="reactions-card__subtitle">{ price }</p> }

					{ reason && <p className="reactions-card__notes p-content">{ reason }</p> }

					{ wishedAt && (
						<time
							className="reactions-card__timestamp dt-published"
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
