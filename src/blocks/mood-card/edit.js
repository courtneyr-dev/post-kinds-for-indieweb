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
	'😊': __( 'smiling face with smiling eyes', 'post-kinds-for-indieweb' ),
	'😃': __( 'grinning face with big eyes', 'post-kinds-for-indieweb' ),
	'😄': __( 'grinning face with smiling eyes', 'post-kinds-for-indieweb' ),
	'🥳': __( 'partying face', 'post-kinds-for-indieweb' ),
	'😎': __( 'smiling face with sunglasses', 'post-kinds-for-indieweb' ),
	'🤗': __( 'smiling face with open hands', 'post-kinds-for-indieweb' ),
	'😌': __( 'relieved face', 'post-kinds-for-indieweb' ),
	'🥰': __( 'smiling face with hearts', 'post-kinds-for-indieweb' ),
	// Neutral
	'😐': __( 'neutral face', 'post-kinds-for-indieweb' ),
	'🤔': __( 'thinking face', 'post-kinds-for-indieweb' ),
	'😑': __( 'expressionless face', 'post-kinds-for-indieweb' ),
	'🙄': __( 'face with rolling eyes', 'post-kinds-for-indieweb' ),
	'😶': __( 'face without mouth', 'post-kinds-for-indieweb' ),
	'😏': __( 'smirking face', 'post-kinds-for-indieweb' ),
	// Sad
	'😢': __( 'crying face', 'post-kinds-for-indieweb' ),
	'😭': __( 'loudly crying face', 'post-kinds-for-indieweb' ),
	'😔': __( 'pensive face', 'post-kinds-for-indieweb' ),
	'😞': __( 'disappointed face', 'post-kinds-for-indieweb' ),
	'🥺': __( 'pleading face', 'post-kinds-for-indieweb' ),
	'😿': __( 'crying cat', 'post-kinds-for-indieweb' ),
	// Angry
	'😡': __( 'enraged face', 'post-kinds-for-indieweb' ),
	'😤': __( 'face with steam from nose', 'post-kinds-for-indieweb' ),
	'🤬': __( 'face with symbols on mouth', 'post-kinds-for-indieweb' ),
	'💢': __( 'anger symbol', 'post-kinds-for-indieweb' ),
	'😠': __( 'angry face', 'post-kinds-for-indieweb' ),
	// Tired
	'😴': __( 'sleeping face', 'post-kinds-for-indieweb' ),
	'🥱': __( 'yawning face', 'post-kinds-for-indieweb' ),
	'😪': __( 'sleepy face', 'post-kinds-for-indieweb' ),
	'😩': __( 'weary face', 'post-kinds-for-indieweb' ),
	'🤒': __( 'face with thermometer', 'post-kinds-for-indieweb' ),
	// Anxious
	'😰': __( 'anxious face with sweat', 'post-kinds-for-indieweb' ),
	'😨': __( 'fearful face', 'post-kinds-for-indieweb' ),
	'😱': __( 'face screaming in fear', 'post-kinds-for-indieweb' ),
	'🫣': __( 'face with peeking eye', 'post-kinds-for-indieweb' ),
	'😬': __( 'grimacing face', 'post-kinds-for-indieweb' ),
};

/**
 * Mood emojis organized by category for the inline picker.
 */
const MOOD_CATEGORIES = [
	{
		name: __( 'Happy', 'post-kinds-for-indieweb' ),
		emojis: [ '😊', '😃', '😄', '🥳', '😎', '🤗', '😌', '🥰' ],
	},
	{
		name: __( 'Neutral', 'post-kinds-for-indieweb' ),
		emojis: [ '😐', '🤔', '😑', '🙄', '😶', '😏' ],
	},
	{
		name: __( 'Sad', 'post-kinds-for-indieweb' ),
		emojis: [ '😢', '😭', '😔', '😞', '🥺', '😿' ],
	},
	{
		name: __( 'Angry', 'post-kinds-for-indieweb' ),
		emojis: [ '😡', '😤', '🤬', '💢', '😠' ],
	},
	{
		name: __( 'Tired', 'post-kinds-for-indieweb' ),
		emojis: [ '😴', '🥱', '😪', '😩', '🤒' ],
	},
	{
		name: __( 'Anxious', 'post-kinds-for-indieweb' ),
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
		className: 'mood-card-block',
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

	// Sync FROM post meta TO block attributes when meta changes from sidebar
	useEffect( () => {
		const updates = {};

		const metaMood = postMeta._postkind_mood_label ?? '';
		const metaEmoji = postMeta._postkind_mood_emoji ?? '';
		const metaIntensity = postMeta._postkind_mood_rating ?? 3;

		if ( metaMood !== ( mood || '' ) ) {
			updates.mood = metaMood;
		}
		if ( metaEmoji !== ( emoji || '' ) ) {
			updates.emoji = metaEmoji;
		}
		if ( metaIntensity !== ( intensity || 3 ) ) {
			updates.intensity = metaIntensity;
		}

		if ( Object.keys( updates ).length > 0 ) {
			setAttributes( updates );
		}
	}, [
		postMeta._postkind_mood_label,
		postMeta._postkind_mood_emoji,
		postMeta._postkind_mood_rating,
	] );

	// Sync FROM block attributes TO post meta when attributes change
	useEffect( () => {
		const metaUpdates = {};

		if ( ( mood || '' ) !== ( postMeta._postkind_mood_label ?? '' ) ) {
			metaUpdates._postkind_mood_label = mood || '';
		}
		if ( ( emoji || '' ) !== ( postMeta._postkind_mood_emoji ?? '' ) ) {
			metaUpdates._postkind_mood_emoji = emoji || '';
		}
		if ( ( intensity || 3 ) !== ( postMeta._postkind_mood_rating ?? 3 ) ) {
			metaUpdates._postkind_mood_rating = intensity || 3;
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
					title={ __( 'Mood Details', 'post-kinds-for-indieweb' ) }
					initialOpen={ true }
				>
					<TextControl
						label={ __( 'Mood', 'post-kinds-for-indieweb' ) }
						value={ mood || '' }
						onChange={ ( value ) =>
							setAttributes( { mood: value } )
						}
						placeholder={ __(
							'How are you feeling?',
							'post-kinds-for-indieweb'
						) }
					/>
					<SelectControl
						label={ __( 'Emoji', 'post-kinds-for-indieweb' ) }
						value={ emoji || '😊' }
						options={ EMOJI_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { emoji: value } )
						}
					/>
					<RangeControl
						label={ __( 'Intensity', 'post-kinds-for-indieweb' ) }
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
					title={ __( 'Note', 'post-kinds-for-indieweb' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Note', 'post-kinds-for-indieweb' ) }
						value={ note || '' }
						onChange={ ( value ) =>
							setAttributes( { note: value } )
						}
						placeholder={ __(
							"What's on your mind?",
							'post-kinds-for-indieweb'
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
										'post-kinds-for-indieweb'
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
							😊 { __( 'Feeling', 'post-kinds-for-indieweb' ) }
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
								'post-kinds-for-indieweb'
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
								'post-kinds-for-indieweb'
							) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
