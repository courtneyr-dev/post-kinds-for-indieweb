/**
 * Favorite Card Block - Save Component
 *
 * @package
 */

import { useBlockProps } from '@wordpress/block-editor';

export default function Save( { attributes } ) {
	const {
		title,
		url,
		description,
		image,
		imageAlt,
		author,
		favoritedAt,
		rel,
		layout,
	} = attributes;
	const blockProps = useBlockProps.save( {
		className: `favorite-card layout-${ layout }`,
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
					<span className="post-kinds-card__badge">★ Favorited</span>

					{ title && (
						<h3 className="post-kinds-card__title p-name">
							{ url ? (
								// eslint-disable-next-line react/jsx-no-target-blank -- linkRel always includes noreferrer
								<a
									href={ url }
									className="u-url u-favorite-of"
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

					{ author && (
						<p className="post-kinds-card__subtitle p-author h-card">
							<span className="p-name">{ author }</span>
						</p>
					) }

					{ description && (
						<p className="post-kinds-card__notes p-content">
							{ description }
						</p>
					) }

					{ favoritedAt && (
						<time
							className="post-kinds-card__timestamp dt-published"
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
