import { fireEvent, render, screen } from '@testing-library/react';
import { useDispatch, useSelect } from '@wordpress/data';
import KindFields, {
	FollowFields,
} from '../../../src/editor/kind-selector/components/KindFields';

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/core-data', () => ( { useEntityProp: jest.fn() } ) );
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
	BaseControl: ( { help } ) => <p>{ help }</p>,
	Flex: ( { children } ) => <div>{ children }</div>,
	Notice: ( { children } ) => <div role="status">{ children }</div>,
	TextControl: ( { label, value, onChange, type } ) => (
		<label htmlFor={ `mock-${ label }` }>
			{ label }
			<input
				id={ `mock-${ label }` }
				type={ type }
				value={ value }
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
		</label>
	),
	TextareaControl: ( { label } ) => <textarea aria-label={ label } />,
} ) );

const selectWith = ( values ) => ( selector ) =>
	selector( () => ( {
		getKindMeta: ( key ) => values[ key ] ?? '',
	} ) );

describe( 'follow kind fields', () => {
	const updateKindMeta = jest.fn();

	beforeEach( () => {
		updateKindMeta.mockReset();
		useSelect.mockReset();
		useSelect.mockImplementation(
			selectWith( {
				cite_name: 'Ada',
				cite_url: 'https://ada.example/',
			} )
		);
		useDispatch.mockReturnValue( { updateKindMeta } );
	} );

	it( 'routes the follow kind to name, site URL, and local photo guidance', () => {
		render( <KindFields kind="follow" /> );
		expect( screen.getByLabelText( 'Name' ) ).toHaveValue( 'Ada' );
		expect( screen.getByLabelText( 'Site URL' ) ).toHaveValue(
			'https://ada.example/'
		);
		expect(
			screen.getByText(
				/set the featured image from your media library/i
			)
		).toBeInTheDocument();
		expect(
			screen.queryByLabelText( 'Photo URL' )
		).not.toBeInTheDocument();
	} );

	it( 'writes both follow fields through the kind metadata store', () => {
		render( <FollowFields /> );
		fireEvent.change( screen.getByLabelText( 'Name' ), {
			target: { value: 'Grace' },
		} );
		fireEvent.change( screen.getByLabelText( 'Site URL' ), {
			target: { value: 'https://grace.example/' },
		} );
		expect( updateKindMeta ).toHaveBeenNthCalledWith(
			1,
			'cite_name',
			'Grace'
		);
		expect( updateKindMeta ).toHaveBeenNthCalledWith(
			2,
			'cite_url',
			'https://grace.example/'
		);
	} );
} );
