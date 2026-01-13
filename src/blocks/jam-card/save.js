/**
 * Jam Card Block - Save Component
 *
 * @package Reactions_For_IndieWeb
 */

import { useBlockProps } from '@wordpress/block-editor';

export default function Save( { attributes } ) {
	const { title, artist, album, cover, coverAlt, url, note, jammedAt, layout } = attributes;
	const blockProps = useBlockProps.save( { className: `jam-card layout-${ layout }` } );

	return (
		<div { ...blockProps }>
			<div className="reactions-card h-cite">
				{ cover && (
					<div className="reactions-card__media">
						<img
							src={ cover }
							alt={ coverAlt || `${ title } by ${ artist }` }
							className="reactions-card__image u-photo"
							loading="lazy"
						/>
					</div>
				) }
				<div className="reactions-card__content">
					<span className="reactions-card__badge">🎵 Now Playing</span>

					{ title && (
						<h3 className="reactions-card__title p-name">
							{ url ? (
								<a href={ url } className="u-url u-jam-of" target="_blank" rel="noopener noreferrer">
									{ title }
								</a>
							) : (
								title
							) }
						</h3>
					) }

					{ artist && (
						<p className="reactions-card__subtitle p-author h-card">
							<span className="p-name">{ artist }</span>
						</p>
					) }

					{ album && <p className="reactions-card__meta">{ album }</p> }

					{ note && <p className="reactions-card__notes p-content">{ note }</p> }

					{ jammedAt && (
						<time
							className="reactions-card__timestamp dt-published"
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
