/**
 * Jam Card Block - Save Component
 *
 * @package
 */

import { useBlockProps } from '@wordpress/block-editor';

export default function Save( { attributes } ) {
	const {
		title,
		artist,
		album,
		cover,
		coverAlt,
		url,
		note,
		jammedAt,
		rel,
		layout,
	} = attributes;
	const blockProps = useBlockProps.save( {
		className: `jam-card layout-${ layout }`,
	} );

	// Build rel attribute - always includes noopener noreferrer for security
	const linkRel = rel
		? `noopener noreferrer ${ rel }`
		: 'noopener noreferrer';

	return (
		<div { ...blockProps }>
			<div className="post-kinds-card h-cite">
				{ cover && (
					<div className="post-kinds-card__media">
						<img
							src={ cover }
							alt={ coverAlt || `${ title } by ${ artist }` }
							className="post-kinds-card__image u-photo"
							loading="lazy"
						/>
					</div>
				) }
				<div className="post-kinds-card__content">
					<span className="post-kinds-card__badge">
						🎵 Now Playing
					</span>

					{ title && (
						<h3 className="post-kinds-card__title p-name">
							{ url ? (
								// eslint-disable-next-line react/jsx-no-target-blank -- linkRel always includes noreferrer
								<a
									href={ url }
									className="u-url u-jam-of"
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

					{ artist && (
						<p className="post-kinds-card__subtitle p-author h-card">
							<span className="p-name">{ artist }</span>
						</p>
					) }

					{ album && (
						<p className="post-kinds-card__meta">{ album }</p>
					) }

					{ note && (
						<p className="post-kinds-card__notes p-content">
							{ note }
						</p>
					) }

					{ jammedAt && (
						<time
							className="post-kinds-card__timestamp dt-published"
							dateTime={ new Date( jammedAt ).toISOString() }
						>
							{ new Date( jammedAt ).toLocaleString() }
						</time>
					) }
				</div>
			</div>
		</div>
	);
}
