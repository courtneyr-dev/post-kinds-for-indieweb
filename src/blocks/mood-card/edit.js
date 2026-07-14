/**
 * Mood Card Block - Edit Component
 *
 * Full inline editing with theme-aware styling and full sidebar controls.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
	RichText,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	RangeControl,
	SelectControl,
} from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';

/**
 * Mood emojis with labels organized by category.
 */
const MOOD_EMOJIS = {
	// Happy - Unicode CLDR short names
	'😊': __(
		'smiling face with smiling eyes',
		'post-kinds-for-indieweb-in-block-themes'
	),
	'😃': __(
		'grinning face with big eyes',
		'post-kinds-for-indieweb-in-block-themes'
	),
	'😄': __(
		'grinning face with smiling eyes',
		'post-kinds-for-indieweb-in-block-themes'
	),
	'🥳': __( 'partying face', 'post-kinds-for-indieweb-in-block-themes' ),
	'😎': __(
		'smiling face with sunglasses',
		'post-kinds-for-indieweb-in-block-themes'
	),
	'🤗': __(
		'smiling face with open hands',
		'post-kinds-for-indieweb-in-block-themes'
	),
	'😌': __( 'relieved face', 'post-kinds-for-indieweb-in-block-themes' ),
	'🥰': __(
		'smiling face with hearts',
		'post-kinds-for-indieweb-in-block-themes'
	),
	// Neutral
	'😐': __( 'neutral face', 'post-kinds-for-indieweb-in-block-themes' ),
	'🤔': __( 'thinking face', 'post-kinds-for-indieweb-in-block-themes' ),
	'😑': __(
		'expressionless face',
		'post-kinds-for-indieweb-in-block-themes'
	),
	'🙄': __(
		'face with rolling eyes',
		'post-kinds-for-indieweb-in-block-themes'
	),
	'😶': __( 'face without mouth', 'post-kinds-for-indieweb-in-block-themes' ),
	'😏': __( 'smirking face', 'post-kinds-for-indieweb-in-block-themes' ),
	// Sad
	'😢': __( 'crying face', 'post-kinds-for-indieweb-in-block-themes' ),
	'😭': __( 'loudly crying face', 'post-kinds-for-indieweb-in-block-themes' ),
	'😔': __( 'pensive face', 'post-kinds-for-indieweb-in-block-themes' ),
	'😞': __( 'disappointed face', 'post-kinds-for-indieweb-in-block-themes' ),
	'🥺': __( 'pleading face', 'post-kinds-for-indieweb-in-block-themes' ),
	'😿': __( 'crying cat', 'post-kinds-for-indieweb-in-block-themes' ),
	// Angry
	'😡': __( 'enraged face', 'post-kinds-for-indieweb-in-block-themes' ),
	'😤': __(
		'face with steam from nose',
		'post-kinds-for-indieweb-in-block-themes'
	),
	'🤬': __(
		'face with symbols on mouth',
		'post-kinds-for-indieweb-in-block-themes'
	),
	'💢': __( 'anger symbol', 'post-kinds-for-indieweb-in-block-themes' ),
	'😠': __( 'angry face', 'post-kinds-for-indieweb-in-block-themes' ),
	// Tired
	'😴': __( 'sleeping face', 'post-kinds-for-indieweb-in-block-themes' ),
	'🥱': __( 'yawning face', 'post-kinds-for-indieweb-in-block-themes' ),
	'😪': __( 'sleepy face', 'post-kinds-for-indieweb-in-block-themes' ),
	'😩': __( 'weary face', 'post-kinds-for-indieweb-in-block-themes' ),
	'🤒': __(
		'face with thermometer',
		'post-kinds-for-indieweb-in-block-themes'
	),
	// Anxious
	'😰': __(
		'anxious face with sweat',
		'post-kinds-for-indieweb-in-block-themes'
	),
	'😨': __( 'fearful face', 'post-kinds-for-indieweb-in-block-themes' ),
	'😱': __(
		'face screaming in fear',
		'post-kinds-for-indieweb-in-block-themes'
	),
	'🫣': __(
		'face with peeking eye',
		'post-kinds-for-indieweb-in-block-themes'
	),
	'😬': __( 'grimacing face', 'post-kinds-for-indieweb-in-block-themes' ),
};

/**
 * Mood emojis organized by category for the inline picker.
 */
const MOOD_CATEGORIES = [
	{
		name: __( 'Happy', 'post-kinds-for-indieweb-in-block-themes' ),
		emojis: [ '😊', '😃', '😄', '🥳', '😎', '🤗', '😌', '🥰' ],
	},
	{
		name: __( 'Neutral', 'post-kinds-for-indieweb-in-block-themes' ),
		emojis: [ '😐', '🤔', '😑', '🙄', '😶', '😏' ],
	},
	{
		name: __( 'Sad', 'post-kinds-for-indieweb-in-block-themes' ),
		emojis: [ '😢', '😭', '😔', '😞', '🥺', '😿' ],
	},
	{
		name: __( 'Angry', 'post-kinds-for-indieweb-in-block-themes' ),
		emojis: [ '😡', '😤', '🤬', '💢', '😠' ],
	},
	{
		name: __( 'Tired', 'post-kinds-for-indieweb-in-block-themes' ),
		emojis: [ '😴', '🥱', '😪', '😩', '🤒' ],
	},
	{
		name: __( 'Anxious', 'post-kinds-for-indieweb-in-block-themes' ),
		emojis: [ '😰', '😨', '😱', '🫣', '😬' ],
	},
];

// Build emoji options with labels for dropdown
const EMOJI_OPTIONS = Object.entries( MOOD_EMOJIS ).map(
	( [ emojiChar, label ] ) => ( {
		label: `${ emojiChar } ${ label }`,
		value: emojiChar,
	} )
);

export default function Edit( { attributes, setAttributes } ) {
	const { mood, emoji, note, intensity } = attributes;

	const blockProps = useBlockProps( {
		className: 'mood-card-block pk-card k-mood',
	} );

	const { editPost } = useDispatch( 'core/editor' );

	// Get post meta and kind - meta is the source of truth for sidebar sync
	const { currentKind, postMeta } = useSelect( ( select ) => {
		const terms =
			select( 'core/editor' ).getEditedPostAttribute(
				'indieblocks_kind'
			);
		const meta =
			select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		return {
			currentKind: terms && terms.length > 0 ? terms[ 0 ] : null,
			postMeta: meta,
		};
	}, [] );

	// Set post kind to "mood" when block is inserted
	useEffect( () => {
		if ( ! currentKind ) {
			wp.apiFetch( { path: '/wp/v2/kind?slug=mood' } )
				.then( ( terms ) => {
					if ( terms && terms.length > 0 ) {
						editPost( { indieblocks_kind: [ terms[ 0 ].id ] } );
					}
				} )
				.catch( () => {} );
		}
	}, [] );

	// Sync FROM post meta TO block attributes when meta changes from sidebar.
	// _pkiw_mood_* isn't a registered post meta key (no REST schema
	// entry, so WordPress core silently drops writes to it and the key is
	// never actually persisted server-side) — only ever apply a non-empty
	// meta value here. Without this guard, a fresh/just-inserted block's
	// attribute gets raced back to blank: the "sync attrs -> meta" effect
	// below writes meta asynchronously via editPost(), and depending on
	// render timing this effect can still observe the pre-write ''/undefined
	// meta value and stomp a real attribute with it.
	useEffect( () => {
		const updates = {};

		const metaMood = postMeta._pkiw_mood_label;
		if ( metaMood && metaMood !== ( mood || '' ) ) {
			updates.mood = metaMood;
		}
		const metaEmoji = postMeta._pkiw_mood_emoji;
		if ( metaEmoji && metaEmoji !== ( emoji || '' ) ) {
			updates.emoji = metaEmoji;
		}
		const metaIntensity = postMeta._pkiw_mood_rating;
		if ( metaIntensity && metaIntensity !== ( intensity || 3 ) ) {
			updates.intensity = metaIntensity;
		}

		if ( Object.keys( updates ).length > 0 ) {
			setAttributes( updates );
		}
	}, [
		postMeta._pkiw_mood_label,
		postMeta._pkiw_mood_emoji,
		postMeta._pkiw_mood_rating,
	] );

	// Sync FROM block attributes TO post meta when attributes change
	useEffect( () => {
		const metaUpdates = {};

		if ( ( mood || '' ) !== ( postMeta._pkiw_mood_label ?? '' ) ) {
			metaUpdates._pkiw_mood_label = mood || '';
		}
		if ( ( emoji || '' ) !== ( postMeta._pkiw_mood_emoji ?? '' ) ) {
			metaUpdates._pkiw_mood_emoji = emoji || '';
		}
		if ( ( intensity || 3 ) !== ( postMeta._pkiw_mood_rating ?? 3 ) ) {
			metaUpdates._pkiw_mood_rating = intensity || 3;
		}

		if ( Object.keys( metaUpdates ).length > 0 ) {
			editPost( { meta: metaUpdates } );
		}
	}, [ mood, emoji, intensity ] );

	const handleEmojiSelect = ( selectedEmoji ) => {
		setAttributes( { emoji: selectedEmoji } );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Mood Details',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ true }
				>
					<TextControl
						label={ __(
							'Mood',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ mood || '' }
						onChange={ ( value ) =>
							setAttributes( { mood: value } )
						}
						placeholder={ __(
							'How are you feeling?',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					<SelectControl
						label={ __(
							'Emoji',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ emoji || '😊' }
						options={ EMOJI_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { emoji: value } )
						}
					/>
					<RangeControl
						label={ __(
							'Intensity',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ intensity || 3 }
						onChange={ ( value ) =>
							setAttributes( { intensity: value } )
						}
						min={ 1 }
						max={ 5 }
						marks={ [
							{ value: 1, label: '1' },
							{ value: 3, label: '3' },
							{ value: 5, label: '5' },
						] }
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'Note',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ false }
				>
					<TextControl
						label={ __(
							'Note',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ note || '' }
						onChange={ ( value ) =>
							setAttributes( { note: value } )
						}
						placeholder={ __(
							"What's on your mind?",
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="post-kinds-card post-kinds-card--mood">
					<div className="post-kinds-card__emoji-section">
						<div className="post-kinds-card__emoji-display">
							<span className="post-kinds-card__emoji-large">
								{ emoji || '😊' }
							</span>
						</div>

						{ /* Intensity Dots */ }
						<div className="post-kinds-card__intensity-dots">
							{ Array.from( { length: 5 }, ( _, i ) => (
								<button
									key={ i }
									type="button"
									className={ `post-kinds-card__intensity-dot ${
										i < ( intensity || 3 ) ? 'filled' : ''
									}` }
									onClick={ () =>
										setAttributes( { intensity: i + 1 } )
									}
									aria-label={ `${ __(
										'Set intensity to',
										'post-kinds-for-indieweb-in-block-themes'
									) } ${ i + 1 }` }
								/>
							) ) }
						</div>

						{ /* Emoji Picker */ }
						<div className="post-kinds-card__emoji-picker">
							{ MOOD_CATEGORIES.map( ( category ) => (
								<div
									key={ category.name }
									className="post-kinds-card__emoji-category"
								>
									{ category.emojis.map( ( e ) => (
										<button
											key={ e }
											type="button"
											className={ `post-kinds-card__emoji-btn ${
												emoji === e ? 'selected' : ''
											}` }
											onClick={ () =>
												handleEmojiSelect( e )
											}
										>
											{ e }
										</button>
									) ) }
								</div>
							) ) }
						</div>
					</div>

					<div className="post-kinds-card__content">
						<span className="post-kinds-card__badge">
							😊{ ' ' }
							{ __(
								'Feeling',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						</span>

						<RichText
							tagName="h3"
							className="post-kinds-card__title"
							value={ mood }
							onChange={ ( value ) =>
								setAttributes( { mood: value } )
							}
							placeholder={ __(
								'How are you feeling?',
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>

						<RichText
							tagName="p"
							className="post-kinds-card__notes"
							value={ note }
							onChange={ ( value ) =>
								setAttributes( { note: value } )
							}
							placeholder={ __(
								"What's on your mind?",
								'post-kinds-for-indieweb-in-block-themes'
							) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
