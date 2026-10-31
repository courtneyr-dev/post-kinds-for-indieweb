/**
 * RSVP status controls in the kind selector.
 */

import { fireEvent, render, screen } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import KindFields from '../../../src/editor/kind-selector/components/KindFields';

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/core-data', () => ( { useEntityProp: jest.fn() } ) );
jest.mock( '@wordpress/icons', () => ( { search: 'search', link: 'link' } ) );
jest.mock( '../../../src/editor/stores/post-kinds', () => ( { STORE_NAME: 'post-kinds' } ) );
jest.mock( '../../../src/editor/kind-selector/components/SyndicationControls', () => () => null );
jest.mock( '../../../src/blocks/shared/mood-vocabulary', () => ( {
	MoodSuggestions: () => null,
	moodDisplayLabel: ( value ) => value,
	moodKeyForLabel: () => '',
	useMoodVocabulary: () => [],
} ) );
jest.mock( '@wordpress/components', () => ( {
	Flex: ( { children } ) => <div>{ children }</div>,
	TextControl: ( { label, value } ) => <input aria-label={ label } value={ value } readOnly />,
	SelectControl: ( { label, value, options, onChange } ) => (
		<select aria-label={ label } value={ value } onChange={ ( event ) => onChange( event.target.value ) }>
			{ options.map( ( option ) => <option key={ option.value } value={ option.value }>{ option.label }</option> ) }
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

const updateKindMeta = jest.fn();

beforeEach( () => {
	jest.clearAllMocks();
	useSelect.mockImplementation( ( callback ) => callback( () => ( {
		getKindMeta: ( key ) => ( { rsvp_status: 'remote', cite_url: '', cite_name: '' } )[ key ] || '',
	} ) ) );
	useDispatch.mockReturnValue( { updateKindMeta } );
} );

it( 'offers all five RSVP statuses including remote', () => {
	render( <KindFields kind="rsvp" /> );
	const select = screen.getByLabelText( 'RSVP Status' );
	const options = Array.from( select.options ).map( ( option ) => ( { value: option.value, text: option.textContent } ) );

	expect( options.map( ( option ) => option.value ) ).toEqual( [ '', 'yes', 'no', 'maybe', 'interested', 'remote' ] );
	expect( options.at( -1 ) ).toEqual( { value: 'remote', text: 'Attending remotely' } );
	expect( select ).toHaveValue( 'remote' );
} );

it( 'stores the remote value through the kind-meta action', () => {
	render( <KindFields kind="rsvp" /> );
	fireEvent.change( screen.getByLabelText( 'RSVP Status' ), { target: { value: 'remote' } } );

	expect( updateKindMeta ).toHaveBeenCalledWith( 'rsvp_status', 'remote' );
} );
