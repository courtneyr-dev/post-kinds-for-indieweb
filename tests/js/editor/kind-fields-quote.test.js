/**
 * Quote citation controls in the kind selector.
 */

import { render, screen } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import KindFields from '../../../src/editor/kind-selector/components/KindFields';

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );
// KindFields imports useEntityProp for the comics fields; loading the real
// core-data store needs TextEncoder, which jsdom lacks.
jest.mock( '@wordpress/core-data', () => ( {
	useEntityProp: jest.fn(),
} ) );
jest.mock( '@wordpress/icons', () => ( { search: 'search', link: 'link' } ) );
jest.mock( '../../../src/editor/stores/post-kinds', () => ( {
	STORE_NAME: 'post-kinds',
} ) );
jest.mock( '@wordpress/components', () => {
	return {
		Flex: ( { children } ) => {
			const { createElement } =
				jest.requireActual( '@wordpress/element' );
			return createElement( 'div', null, children );
		},
		TextControl: ( { label, value, onChange, type = 'text' } ) => {
			const { createElement } =
				jest.requireActual( '@wordpress/element' );
			const id = 'field-' + label.toLowerCase();
			return createElement(
				'label',
				{ htmlFor: id },
				label,
				createElement( 'input', { id, type, value, onChange } )
			);
		},
		TextareaControl: ( { label, value, onChange } ) => {
			const { createElement } =
				jest.requireActual( '@wordpress/element' );
			const id = 'field-' + label.toLowerCase();
			return createElement(
				'label',
				{ htmlFor: id },
				label,
				createElement( 'textarea', { id, value, onChange } )
			);
		},
		SelectControl: () => null,
		ToggleControl: () => null,
		RangeControl: () => null,
		Button: () => null,
		Spinner: () => null,
		BaseControl: ( { children } ) => <div>{ children }</div>,
		ExternalLink: ( { children } ) => <span>{ children }</span>,
	};
} );
jest.mock(
	'../../../src/editor/kind-selector/components/SyndicationControls',
	() => () => null
);
jest.mock( '../../../src/blocks/shared/mood-vocabulary', () => ( {
	MoodSuggestions: () => null,
	moodDisplayLabel: ( label ) => label,
	moodKeyForLabel: () => '',
	useMoodVocabulary: () => [],
} ) );

beforeEach( () => {
	useSelect.mockImplementation( ( callback ) =>
		callback( () => ( {
			getKindMeta: ( key ) =>
				( {
					cite_url: 'https://example.com/',
					cite_name: 'Collected Essays',
					cite_author: 'Avery Quill',
					cite_summary: 'Summary that must stay hidden.',
				} )[ key ] || '',
		} ) )
	);
	useDispatch.mockReturnValue( { updateKindMeta: jest.fn() } );
} );

it( 'shows URL, title, and author for quote without summary', () => {
	render( <KindFields kind="quote" /> );

	expect( screen.getByLabelText( 'URL' ) ).toHaveValue(
		'https://example.com/'
	);
	expect( screen.getByLabelText( 'Title' ) ).toHaveValue(
		'Collected Essays'
	);
	expect( screen.getByLabelText( 'Author' ) ).toHaveValue( 'Avery Quill' );
	expect( screen.queryByLabelText( 'Summary' ) ).not.toBeInTheDocument();
} );

it( 'keeps summary for the existing citation kinds', () => {
	render( <KindFields kind="reply" /> );

	expect( screen.getByLabelText( 'Summary' ) ).toHaveValue(
		'Summary that must stay hidden.'
	);
} );
