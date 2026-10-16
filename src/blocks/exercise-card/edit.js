/**
 * Exercise Card Block - Edit Component
 *
 * The canvas shows the server's public view of the card. The sidebar holds
 * the fields: activity, distance, time (typed as h:mm:ss, stored as an ISO
 * 8601 duration), splits, a personal best and the switch that lets the
 * details print. Nothing here names heart rate, calories or a clock time,
 * and no control writes the card's `origin`.
 *
 * @package
 */

import { __, sprintf } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	SelectControl,
	ToggleControl,
	Button,
	Disabled,
	Placeholder,
} from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import ServerSideRender from '@wordpress/server-side-render';

const DURATION_PATTERN =
	/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i;

/**
 * Personal best categories used when window.pkiwExercise is missing.
 *
 * @type {Array<{value: string, label: string}>}
 */
export const FALLBACK_PB_CATEGORIES = [
	{
		value: '1mi',
		label: __( '1 mile', 'post-kinds-for-indieweb-in-block-themes' ),
	},
	{
		value: '5k',
		label: __( '5K', 'post-kinds-for-indieweb-in-block-themes' ),
	},
	{
		value: '10k',
		label: __( '10K', 'post-kinds-for-indieweb-in-block-themes' ),
	},
	{
		value: 'half',
		label: __( 'Half marathon', 'post-kinds-for-indieweb-in-block-themes' ),
	},
	{
		value: 'marathon',
		label: __( 'Marathon', 'post-kinds-for-indieweb-in-block-themes' ),
	},
];

/**
 * Seconds in an ISO 8601 duration, the way PHP's card_duration_seconds() reads it.
 *
 * @param {string} iso Duration such as PT29M12S.
 * @return {number} Seconds, 0 when the text is not a duration.
 */
function isoSeconds( iso ) {
	const parts = DURATION_PATTERN.exec( String( iso || '' ).trim() );
	if ( ! parts ) {
		return 0;
	}
	return (
		Number( parts[ 1 ] || 0 ) * 86400 +
		Number( parts[ 2 ] || 0 ) * 3600 +
		Number( parts[ 3 ] || 0 ) * 60 +
		Number( parts[ 4 ] || 0 )
	);
}

/**
 * An ISO 8601 duration as the clock text the author types: PT29M12S is 29:12.
 *
 * @param {string} iso Duration.
 * @return {string} 'm:ss' or 'h:mm:ss', '' for no time.
 */
export function isoToClock( iso ) {
	const seconds = isoSeconds( iso );
	if ( seconds <= 0 ) {
		return '';
	}
	const hours = Math.floor( seconds / 3600 );
	const minutes = Math.floor( ( seconds % 3600 ) / 60 );
	const rest = String( seconds % 60 ).padStart( 2, '0' );
	return hours > 0
		? `${ hours }:${ String( minutes ).padStart( 2, '0' ) }:${ rest }`
		: `${ minutes }:${ rest }`;
}

/**
 * Clock text as an ISO 8601 duration: 29:12 is PT29M12S, 1:02:03 is PT1H2M3S.
 *
 * Zero parts drop out, as PHP's card_duration_iso() does, so 1:00:00 is PT1H.
 *
 * @param {string} text 'm:ss' or 'h:mm:ss'.
 * @return {string|null} The duration, '' for empty text, null when the text is not a time above 0 seconds.
 */
export function clockToIso( text ) {
	const value = String( text ?? '' ).trim();
	if ( '' === value ) {
		return '';
	}
	const parts = value.split( ':' );
	if (
		parts.length < 2 ||
		parts.length > 3 ||
		! parts.every( ( part ) => /^\d+$/.test( part ) )
	) {
		return null;
	}
	const numbers = parts.map( Number );
	const [ hours, minutes, seconds ] =
		3 === numbers.length ? numbers : [ 0, ...numbers ];
	if ( seconds > 59 || ( 3 === numbers.length && minutes > 59 ) ) {
		return null;
	}
	const total = hours * 3600 + minutes * 60 + seconds;
	if ( total <= 0 ) {
		return null;
	}
	const h = Math.floor( total / 3600 );
	const m = Math.floor( ( total % 3600 ) / 60 );
	const s = total % 60;
	return `PT${ h > 0 ? `${ h }H` : '' }${ m > 0 ? `${ m }M` : '' }${
		s > 0 ? `${ s }S` : ''
	}`;
}

/**
 * Splits renumbered from 1 in the order held, the shape Card_Meta_Sync keeps.
 *
 * @param {Array<{t: string}>} list Splits in order.
 * @return {Array<{i: number, t: string}>} Splits.
 */
function numbered( list ) {
	return list.map( ( split, index ) => ( {
		i: index + 1,
		t: split.t || '',
	} ) );
}

/**
 * A text field for a time typed as h:mm:ss or m:ss and stored as ISO 8601.
 *
 * The typed text stays as typed until it's a time; only a time reaches the
 * attribute, and clearing the field clears it.
 *
 * @param {Object}                          props          Component props.
 * @param {string}                          props.label    Field label.
 * @param {string}                          props.value    Stored ISO 8601 duration.
 * @param {(iso: string|undefined) => void} props.onChange Called with a duration, or undefined when cleared.
 * @param {string}                          props.help     Help text.
 * @return {JSX.Element} The field.
 */
function ClockField( { label, value, onChange, help } ) {
	const [ draft, setDraft ] = useState( isoToClock( value ) );

	// Follow a stored value this field didn't just write.
	useEffect( () => {
		if ( clockToIso( draft ) !== ( value || '' ) ) {
			setDraft( isoToClock( value ) );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- Only an outside change to the stored value resets the text.
	}, [ value ] );

	const invalid = null === clockToIso( draft );

	return (
		<TextControl
			label={ label }
			value={ draft }
			placeholder="29:12"
			onChange={ ( text ) => {
				setDraft( text );
				const iso = clockToIso( text );
				if ( '' === iso ) {
					onChange( undefined );
				} else if ( null !== iso ) {
					onChange( iso );
				}
			} }
			help={
				invalid
					? __(
							'Use h:mm:ss or m:ss, such as 29:12.',
							'post-kinds-for-indieweb-in-block-themes'
						)
					: help
			}
		/>
	);
}

export default function Edit( { attributes, setAttributes } ) {
	const {
		activity,
		distance,
		distanceUnit,
		duration,
		personalBest,
		pbCategory,
		showDetailsPublicly,
	} = attributes;
	const splits = Array.isArray( attributes.splits ) ? attributes.splits : [];
	const blockProps = useBlockProps( {
		className: 'exercise-card-block pk-card k-exercise',
	} );
	const listId = useInstanceId( Edit, 'pkiw-exercise-activities' );

	const data =
		'undefined' !== typeof window && window.pkiwExercise
			? window.pkiwExercise
			: {};
	const activities = Array.isArray( data.activities ) ? data.activities : [];
	const categories =
		data.pbCategories && 'object' === typeof data.pbCategories
			? Object.entries( data.pbCategories ).map(
					( [ value, label ] ) => ( {
						value,
						label,
					} )
				)
			: FALLBACK_PB_CATEGORIES;

	const setSplits = ( list ) =>
		setAttributes( {
			splits: list.length > 0 ? numbered( list ) : undefined,
		} );

	const hasContent =
		activity || distance || duration || splits.length > 0 || personalBest;

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Workout',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ true }
				>
					<TextControl
						label={ __(
							'Activity',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ activity || '' }
						onChange={ ( value ) =>
							setAttributes( {
								activity: value || undefined,
							} )
						}
						list={ listId }
						help={ __(
							'Pick a suggestion or type your own.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
					<datalist id={ listId }>
						{ activities.map( ( name ) => (
							<option key={ name } value={ name } />
						) ) }
					</datalist>
					<TextControl
						label={ __(
							'Distance',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						type="number"
						min={ 0 }
						step="any"
						value={ undefined === distance ? '' : distance }
						onChange={ ( value ) => {
							const number = Number( value );
							setAttributes( {
								distance:
									'' !== value && number > 0
										? number
										: undefined,
							} );
						} }
					/>
					<SelectControl
						label={ __(
							'Distance unit',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ 'mi' === distanceUnit ? 'mi' : 'km' }
						options={ [
							{
								value: 'km',
								label: __(
									'Kilometers',
									'post-kinds-for-indieweb-in-block-themes'
								),
							},
							{
								value: 'mi',
								label: __(
									'Miles',
									'post-kinds-for-indieweb-in-block-themes'
								),
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { distanceUnit: value } )
						}
					/>
					<ClockField
						label={ __(
							'Time',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						value={ duration }
						onChange={ ( iso ) =>
							setAttributes( { duration: iso } )
						}
						help={ __(
							'Hours, minutes and seconds.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				</PanelBody>
				<PanelBody
					title={ __(
						'Splits',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ false }
				>
					{ splits.map( ( split, index ) => (
						<div key={ index } className="pkiw-exercise-split-row">
							<ClockField
								label={ sprintf(
									/* translators: %d: split number. */
									__(
										'Split %d',
										'post-kinds-for-indieweb-in-block-themes'
									),
									index + 1
								) }
								value={ split.t }
								onChange={ ( iso ) =>
									setSplits(
										splits.map( ( item, at ) =>
											at === index
												? { t: iso || '' }
												: item
										)
									)
								}
							/>
							<Button
								variant="link"
								isDestructive
								onClick={ () =>
									setSplits(
										splits.filter(
											( item, at ) => at !== index
										)
									)
								}
							>
								{ sprintf(
									/* translators: %d: split number. */
									__(
										'Remove split %d',
										'post-kinds-for-indieweb-in-block-themes'
									),
									index + 1
								) }
							</Button>
						</div>
					) ) }
					<Button
						variant="secondary"
						onClick={ () => setSplits( [ ...splits, { t: '' } ] ) }
					>
						{ __(
							'Add split',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					</Button>
				</PanelBody>
				<PanelBody
					title={ __(
						'Personal best',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __(
							'This is a personal best',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ !! personalBest }
						onChange={ ( value ) =>
							setAttributes( { personalBest: value } )
						}
					/>
					{ personalBest && (
						<SelectControl
							label={ __(
								'Personal best for',
								'post-kinds-for-indieweb-in-block-themes'
							) }
							value={ pbCategory || '' }
							options={ [
								{
									value: '',
									label: __(
										'Choose a distance',
										'post-kinds-for-indieweb-in-block-themes'
									),
								},
								...categories,
							] }
							onChange={ ( value ) =>
								setAttributes( {
									pbCategory: value || undefined,
								} )
							}
						/>
					) }
				</PanelBody>
				<PanelBody
					title={ __(
						'Privacy',
						'post-kinds-for-indieweb-in-block-themes'
					) }
					initialOpen={ true }
				>
					<ToggleControl
						label={ __(
							'Show workout details publicly',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						checked={ !! showDetailsPublicly }
						onChange={ ( value ) =>
							setAttributes( { showDetailsPublicly: value } )
						}
						help={ __(
							'Distance, duration, splits and personal best print only when this is on.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ hasContent ? (
					<Disabled>
						<ServerSideRender
							block="post-kinds-indieweb/exercise-card"
							attributes={ attributes }
						/>
					</Disabled>
				) : (
					<Placeholder
						label={ __(
							'Exercise card',
							'post-kinds-for-indieweb-in-block-themes'
						) }
						instructions={ __(
							'Add the activity in the block settings. The card shows what visitors see.',
							'post-kinds-for-indieweb-in-block-themes'
						) }
					/>
				) }
			</div>
		</>
	);
}
