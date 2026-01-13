/**
 * Mood Card Block - Save Component
 *
 * @package Reactions_For_IndieWeb
 */

import { useBlockProps } from '@wordpress/block-editor';

export default function Save( { attributes } ) {
	const { mood, emoji, note, intensity, moodAt, layout } = attributes;
	const blockProps = useBlockProps.save( { className: `mood-card layout-${ layout }` } );

	return (
		<div { ...blockProps }>
			<div className="reactions-card reactions-card--mood h-entry">
				<div className="reactions-card__emoji-section">
					<div className="reactions-card__emoji-display">
						<span className="reactions-card__emoji-large" role="img" aria-label={ mood || 'mood' }>{ emoji || '😊' }</span>
					</div>
					<div className="reactions-card__intensity-dots" aria-label={ `Intensity: ${ intensity } out of 5` }>
						{ Array.from( { length: 5 }, ( _, i ) => (
							<span key={ i } className={ `reactions-card__intensity-dot ${ i < intensity ? 'filled' : '' }` } />
						) ) }
					</div>
				</div>
				<div className="reactions-card__content">
					{ mood && <h3 className="reactions-card__title p-name">{ mood }</h3> }
					{ note && <p className="reactions-card__notes p-content">{ note }</p> }
					{ moodAt && (
						<time className="reactions-card__timestamp dt-published" dateTime={ new Date( moodAt ).toISOString() }>
							{ new Date( moodAt ).toLocaleString() }
						</time>
					) }
				</div>
			</div>
		</div>
	);
}
