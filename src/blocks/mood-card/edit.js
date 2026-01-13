/**
 * Mood Card Block - Edit Component
 *
 * Full inline editing with theme-aware styling and full sidebar controls.
 *
 * @package Reactions_For_IndieWeb
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
	'😊': __( 'smiling face with smiling eyes', 'reactions-for-indieweb' ),
	'😃': __( 'grinning face with big eyes', 'reactions-for-indieweb' ),
	'😄': __( 'grinning face with smiling eyes', 'reactions-for-indieweb' ),
	'🥳': __( 'partying face', 'reactions-for-indieweb' ),
	'😎': __( 'smiling face with sunglasses', 'reactions-for-indieweb' ),
	'🤗': __( 'smiling face with open hands', 'reactions-for-indieweb' ),
	'😌': __( 'relieved face', 'reactions-for-indieweb' ),
	'🥰': __( 'smiling face with hearts', 'reactions-for-indieweb' ),
	// Neutral
	'😐': __( 'neutral face', 'reactions-for-indieweb' ),
	'🤔': __( 'thinking face', 'reactions-for-indieweb' ),
	'😑': __( 'expressionless face', 'reactions-for-indieweb' ),
	'🙄': __( 'face with rolling eyes', 'reactions-for-indieweb' ),
	'😶': __( 'face without mouth', 'reactions-for-indieweb' ),
	'😏': __( 'smirking face', 'reactions-for-indieweb' ),
	// Sad
	'😢': __( 'crying face', 'reactions-for-indieweb' ),
	'😭': __( 'loudly crying face', 'reactions-for-indieweb' ),
	'😔': __( 'pensive face', 'reactions-for-indieweb' ),
	'😞': __( 'disappointed face', 'reactions-for-indieweb' ),
	'🥺': __( 'pleading face', 'reactions-for-indieweb' ),
	'😿': __( 'crying cat', 'reactions-for-indieweb' ),
	// Angry
	'😡': __( 'enraged face', 'reactions-for-indieweb' ),
	'😤': __( 'face with steam from nose', 'reactions-for-indieweb' ),
	'🤬': __( 'face with symbols on mouth', 'reactions-for-indieweb' ),
	'💢': __( 'anger symbol', 'reactions-for-indieweb' ),
	'😠': __( 'angry face', 'reactions-for-indieweb' ),
	// Tired
	'😴': __( 'sleeping face', 'reactions-for-indieweb' ),
	'🥱': __( 'yawning face', 'reactions-for-indieweb' ),
	'😪': __( 'sleepy face', 'reactions-for-indieweb' ),
	'😩': __( 'weary face', 'reactions-for-indieweb' ),
	'🤒': __( 'face with thermometer', 'reactions-for-indieweb' ),
	// Anxious
	'😰': __( 'anxious face with sweat', 'reactions-for-indieweb' ),
	'😨': __( 'fearful face', 'reactions-for-indieweb' ),
	'😱': __( 'face screaming in fear', 'reactions-for-indieweb' ),
	'🫣': __( 'face with peeking eye', 'reactions-for-indieweb' ),
	'😬': __( 'grimacing face', 'reactions-for-indieweb' ),
};

/**
 * Mood emojis organized by category for the inline picker.
 */
const MOOD_CATEGORIES = [
	{
		name: __( 'Happy', 'reactions-for-indieweb' ),
		emojis: [ '😊', '😃', '😄', '🥳', '😎', '🤗', '😌', '🥰' ],
	},
	{
		name: __( 'Neutral', 'reactions-for-indieweb' ),
		emojis: [ '😐', '🤔', '😑', '🙄', '😶', '😏' ],
	},
	{
		name: __( 'Sad', 'reactions-for-indieweb' ),
		emojis: [ '😢', '😭', '😔', '😞', '🥺', '😿' ],
	},
	{
		name: __( 'Angry', 'reactions-for-indieweb' ),
		emojis: [ '😡', '😤', '🤬', '💢', '😠' ],
	},
	{
		name: __( 'Tired', 'reactions-for-indieweb' ),
		emojis: [ '😴', '🥱', '😪', '😩', '🤒' ],
	},
	{
		name: __( 'Anxious', 'reactions-for-indieweb' ),
		emojis: [ '😰', '😨', '😱', '🫣', '😬' ],
	},
];

// Build emoji options with labels for dropdown
const EMOJI_OPTIONS = Object.entries( MOOD_EMOJIS ).map( ( [ emojiChar, label ] ) => ( {
	label: `${ emojiChar } ${ label }`,
	value: emojiChar,
} ) );

export default function Edit( { attributes, setAttributes } ) {
	const {
		mood,
		emoji,
		note,
		intensity,
	} = attributes;

	const blockProps = useBlockProps( {
		className: 'mood-card-block',
	} );

	const { editPost } = useDispatch( 'core/editor' );

	// Get post meta and kind - meta is the source of truth for sidebar sync
	const { currentKind, postMeta } = useSelect(
		( select ) => {
			const terms = select( 'core/editor' ).getEditedPostAttribute( 'indieblocks_kind' );
			const meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
			return {
				currentKind: terms && terms.length > 0 ? terms[ 0 ] : null,
				postMeta: meta,
			};
		},
		[]
	);

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

		const metaMood = postMeta._reactions_mood_label ?? '';
		const metaEmoji = postMeta._reactions_mood_emoji ?? '';
		const metaIntensity = postMeta._reactions_mood_rating ?? 3;

		if ( metaMood !== ( mood || '' ) ) updates.mood = metaMood;
		if ( metaEmoji !== ( emoji || '' ) ) updates.emoji = metaEmoji;
		if ( metaIntensity !== ( intensity || 3 ) ) updates.intensity = metaIntensity;

		if ( Object.keys( updates ).length > 0 ) {
			setAttributes( updates );
		}
	}, [
		postMeta._reactions_mood_label,
		postMeta._reactions_mood_emoji,
		postMeta._reactions_mood_rating,
	] );

	// Sync FROM block attributes TO post meta when attributes change
	useEffect( () => {
		const metaUpdates = {};

		if ( ( mood || '' ) !== ( postMeta._reactions_mood_label ?? '' ) ) {
			metaUpdates._reactions_mood_label = mood || '';
		}
		if ( ( emoji || '' ) !== ( postMeta._reactions_mood_emoji ?? '' ) ) {
			metaUpdates._reactions_mood_emoji = emoji || '';
		}
		if ( ( intensity || 3 ) !== ( postMeta._reactions_mood_rating ?? 3 ) ) {
			metaUpdates._reactions_mood_rating = intensity || 3;
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
				<PanelBody title={ __( 'Mood Details', 'reactions-for-indieweb' ) } initialOpen={ true }>
					<TextControl
						label={ __( 'Mood', 'reactions-for-indieweb' ) }
						value={ mood || '' }
						onChange={ ( value ) => setAttributes( { mood: value } ) }
						placeholder={ __( 'How are you feeling?', 'reactions-for-indieweb' ) }
					/>
					<SelectControl
						label={ __( 'Emoji', 'reactions-for-indieweb' ) }
						value={ emoji || '😊' }
						options={ EMOJI_OPTIONS }
						onChange={ ( value ) => setAttributes( { emoji: value } ) }
					/>
					<RangeControl
						label={ __( 'Intensity', 'reactions-for-indieweb' ) }
						value={ intensity || 3 }
						onChange={ ( value ) => setAttributes( { intensity: value } ) }
						min={ 1 }
						max={ 5 }
						marks={ [
							{ value: 1, label: '1' },
							{ value: 3, label: '3' },
							{ value: 5, label: '5' },
						] }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Note', 'reactions-for-indieweb' ) } initialOpen={ false }>
					<TextControl
						label={ __( 'Note', 'reactions-for-indieweb' ) }
						value={ note || '' }
						onChange={ ( value ) => setAttributes( { note: value } ) }
						placeholder={ __( "What's on your mind?", 'reactions-for-indieweb' ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="reactions-card reactions-card--mood">
					<div className="reactions-card__emoji-section">
						<div className="reactions-card__emoji-display">
							<span className="reactions-card__emoji-large">{ emoji || '😊' }</span>
						</div>

						{ /* Intensity Dots */ }
						<div className="reactions-card__intensity-dots">
							{ Array.from( { length: 5 }, ( _, i ) => (
								<button
									key={ i }
									type="button"
									className={ `reactions-card__intensity-dot ${ i < ( intensity || 3 ) ? 'filled' : '' }` }
									onClick={ () => setAttributes( { intensity: i + 1 } ) }
									aria-label={ `${ __( 'Set intensity to', 'reactions-for-indieweb' ) } ${ i + 1 }` }
								/>
							) ) }
						</div>

						{ /* Emoji Picker */ }
						<div className="reactions-card__emoji-picker">
							{ MOOD_CATEGORIES.map( ( category ) => (
								<div key={ category.name } className="reactions-card__emoji-category">
									{ category.emojis.map( ( e ) => (
										<button
											key={ e }
											type="button"
											className={ `reactions-card__emoji-btn ${ emoji === e ? 'selected' : '' }` }
											onClick={ () => handleEmojiSelect( e ) }
										>
											{ e }
										</button>
									) ) }
								</div>
							) ) }
						</div>
					</div>

					<div className="reactions-card__content">
						<span className="reactions-card__badge">😊 { __( 'Feeling', 'reactions-for-indieweb' ) }</span>

						<RichText
							tagName="h3"
							className="reactions-card__title"
							value={ mood }
							onChange={ ( value ) => setAttributes( { mood: value } ) }
							placeholder={ __( 'How are you feeling?', 'reactions-for-indieweb' ) }
						/>

						<RichText
							tagName="p"
							className="reactions-card__notes"
							value={ note }
							onChange={ ( value ) => setAttributes( { note: value } ) }
							placeholder={ __( "What's on your mind?", 'reactions-for-indieweb' ) }
						/>
					</div>
				</div>
			</div>
		</>
	);
}
