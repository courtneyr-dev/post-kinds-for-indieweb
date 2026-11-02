/** Presentation controls in the kind selector. */

import { fireEvent, render, screen } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import KindFields from '../../../src/editor/kind-selector/components/KindFields';

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );
jest.mock( '@wordpress/core-data', () => ( { useEntityProp: jest.fn() } ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/icons', () => ( { search: 'search', link: 'link' } ) );
jest.mock( '../../../src/editor/stores/post-kinds', () => ( {
	STORE_NAME: 'post-kinds',
} ) );
jest.mock(
	'../../../src/editor/kind-selector/components/SyndicationControls',
	() => () => null
);
jest.mock( '../../../src/blocks/shared/mood-vocabulary', () => ( {
	MoodSuggestions: () => null,
	moodDisplayLabel: ( value ) => value,
	moodKeyForLabel: () => '',
	useMoodVocabulary: () => [],
} ) );
jest.mock( '@wordpress/components', () => ( {
	Flex: ( { children } ) => <div>{ children }</div>,
	TextControl: ( { label, value, onChange, type } ) => (
		<input
			aria-label={ label }
			value={ value }
			type={ type || 'text' }
			onChange={ ( event ) => onChange( event.target.value ) }
		/>
	),
	SelectControl: ( { label, value, options, onChange } ) => (
		<select
			aria-label={ label }
			value={ value }
			onChange={ ( event ) => onChange( event.target.value ) }
		>
			{ options.map( ( option ) => (
				<option key={ option.value } value={ option.value }>
					{ option.label }
				</option>
			) ) }
		</select>
	),
	TextareaControl: () => null,
	ToggleControl: () => null,
	RangeControl: () => null,
	Button: () => null,
	Spinner: () => null,
	BaseControl: ( { children } ) => <div>{ children }</div>,
	ExternalLink: ( { children } ) => <span>{ children }</span>,
} ) );

const setMeta = jest.fn();

beforeEach( () => {
	window.pkiwPresentationEventTypes = [
		{ slug: 'talk', label: 'Talk' },
		{ slug: 'keynote', label: 'Keynote' },
	];
	useSelect.mockImplementation( ( callback ) =>
		callback( () => ( { getCurrentPostType: () => 'post' } ) )
	);
	useDispatch.mockReturnValue( { updateKindMeta: jest.fn() } );
	useEntityProp.mockReturnValue( [
		{
			_pkiw_presentation_event_type: 'talk',
			_pkiw_presentation_event_name: 'Event',
			_pkiw_presentation_event_url: '',
			_pkiw_presentation_slides_url: '',
			_pkiw_presentation_recording_url: '',
			_pkiw_presentation_calendar_source: '',
			_pkiw_presentation_calendar_event_id: 0,
		},
		setMeta,
	] );
} );

it( 'shows the presentation fields and hides the event id without a calendar', () => {
	render( <KindFields kind="presentation" /> );
	expect( screen.getByLabelText( 'Event type' ) ).toHaveTextContent( 'None' );
	for ( const label of [
		'Event name',
		'Event link',
		'Slides link',
		'Recording link',
		'Calendar',
	] ) {
		expect( screen.getByLabelText( label ) ).toBeInTheDocument();
	}
	expect(
		screen.queryByLabelText( 'Calendar event ID' )
	).not.toBeInTheDocument();
} );

it( 'shows the event id after a calendar is selected', () => {
	useEntityProp.mockReturnValue( [
		{
			_pkiw_presentation_calendar_source: 'my-calendar',
			_pkiw_presentation_calendar_event_id: 9,
		},
		setMeta,
	] );
	render( <KindFields kind="presentation" /> );
	expect( screen.getByLabelText( 'Calendar event ID' ) ).toBeInTheDocument();
	fireEvent.change( screen.getByLabelText( 'Event name' ), {
		target: { value: 'Updated' },
	} );
	expect( setMeta ).toHaveBeenCalledWith(
		expect.objectContaining( { _pkiw_presentation_event_name: 'Updated' } )
	);
} );
