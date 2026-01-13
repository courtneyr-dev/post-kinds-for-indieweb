/**
 * Favorite Card Block - Save Component
 *
 * @package Reactions_For_IndieWeb
 */

import { useBlockProps } from '@wordpress/block-editor';

export default function Save( { attributes } ) {
	const { title, url, description, image, imageAlt, author, favoritedAt, layout } = attributes;
	const blockProps = useBlockProps.save( { className: `favorite-card layout-${ layout }` } );

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
					<span className="reactions-card__badge">★ Favorited</span>

					{ title && (
						<h3 className="reactions-card__title p-name">
							{ url ? (
								<a href={ url } className="u-url u-favorite-of" target="_blank" rel="noopener noreferrer">
									{ title }
								</a>
							) : (
								title
							) }
						</h3>
					) }

					{ author && (
						<p className="reactions-card__subtitle p-author h-card">
							<span className="p-name">{ author }</span>
						</p>
					) }

					{ description && <p className="reactions-card__notes p-content">{ description }</p> }

					{ favoritedAt && (
						<time
							className="reactions-card__timestamp dt-published"
							dateTime={ new Date( favoritedAt ).toISOString() }
						>
							{ new Date( favoritedAt ).toLocaleString() }
						</time>
					) }
				</div>
			</div>
		</div>
	);
}
