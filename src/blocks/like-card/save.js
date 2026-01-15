/**
 * Like Card Block - Save Component
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
		likedAt,
		rel,
		layout,
	} = attributes;
	const blockProps = useBlockProps.save( {
		className: `like-card layout-${ layout }`,
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
					<span className="post-kinds-card__badge">
						&hearts; Liked
					</span>

					{ title && (
						<h3 className="post-kinds-card__title p-name">
							{ url ? (
								// eslint-disable-next-line react/jsx-no-target-blank -- linkRel always includes noreferrer
								<a
									href={ url }
									className="u-url u-like-of"
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

					{ likedAt && (
						<time
							className="post-kinds-card__timestamp dt-published"
							dateTime={ new Date( likedAt ).toISOString() }
						>
							{ new Date( likedAt ).toLocaleString() }
						</time>
					) }
				</div>
			</div>
		</div>
	);
}
