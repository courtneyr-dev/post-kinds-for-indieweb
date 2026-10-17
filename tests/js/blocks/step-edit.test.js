/**
 * Craft step in the editor (issue 249).
 *
 * A step is a photo and a note in a locked pair. While its photo has a url and
 * no alt text it shows a warning. The warning only reads the image: it never
 * writes alt text, so the author decides what the picture says.
 */

import { render, screen } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import StepEdit from '../../../src/blocks/step/edit';
import StepsEdit from '../../../src/blocks/steps/edit';

let mockInnerOptions = null;

jest.mock( '@wordpress/block-editor', () => ( {
	useBlockProps: ( props = {} ) => props,
	useInnerBlocksProps: ( props, options ) => {
		mockInnerOptions = options;
		return { ...props, children: <span data-testid="inner-blocks" /> };
	},
	Warning: ( { children } ) => <div role="alert">{ children }</div>,
	store: 'core/block-editor',
} ) );

const image = ( attributes ) => ( {
	clientId: 'img',
	name: 'core/image',
	attributes,
	innerBlocks: [],
} );
const paragraph = {
	clientId: 'p',
	name: 'core/paragraph',
	attributes: { content: 'Cast on 40.' },
	innerBlocks: [],
};

const dispatchers = {
	updateBlockAttributes: jest.fn(),
	replaceBlock: jest.fn(),
	replaceBlocks: jest.fn(),
	insertBlocks: jest.fn(),
	removeBlock: jest.fn(),
};

let mockInner = [];
let mockedClientId = null;

beforeEach( () => {
	jest.clearAllMocks();
	mockInner = [];
	mockedClientId = null;
	mockInnerOptions = null;
	useDispatch.mockImplementation( () => dispatchers );
	useSelect.mockImplementation( ( callback ) =>
		callback( () => ( {
			getBlocks: ( clientId ) => {
				mockedClientId = clientId;
				return mockInner;
			},
		} ) )
	);
} );

const expectNoBlockWrites = () => {
	for ( const fn of Object.values( dispatchers ) ) {
		expect( fn ).not.toHaveBeenCalled();
	}
};

const WARNING = 'This step’s image has no alt text.';

describe( 'Craft step editor', () => {
	it( 'warns while the image has a url and an empty alt', () => {
		mockInner = [
			image( { url: 'https://example.test/a.jpg', alt: '' } ),
			paragraph,
		];

		render( <StepEdit clientId="step-1" /> );

		expect( screen.getByRole( 'alert' ).textContent ).toBe( WARNING );
		expect( mockedClientId ).toBe( 'step-1' );
		expectNoBlockWrites();
	} );

	it( 'warns for an alt that was never set and one of only spaces', () => {
		mockInner = [ image( { url: 'https://example.test/a.jpg' } ) ];
		const { unmount } = render( <StepEdit clientId="step-1" /> );
		expect( screen.getByRole( 'alert' ) ).toBeTruthy();
		unmount();

		mockInner = [
			image( { url: 'https://example.test/a.jpg', alt: '   ' } ),
		];
		render( <StepEdit clientId="step-1" /> );
		expect( screen.getByRole( 'alert' ) ).toBeTruthy();
	} );

	it( 'hides the warning once alt text is typed', () => {
		mockInner = [ image( { url: 'https://example.test/a.jpg', alt: '' } ) ];
		const { rerender } = render( <StepEdit clientId="step-1" /> );
		expect( screen.getByRole( 'alert' ) ).toBeTruthy();

		mockInner = [
			image( { url: 'https://example.test/a.jpg', alt: 'Cast-on row' } ),
		];
		rerender( <StepEdit clientId="step-1" /> );

		expect( screen.queryByRole( 'alert' ) ).toBeNull();
		expectNoBlockWrites();
	} );

	it( 'stays quiet while the image has no picture yet', () => {
		mockInner = [ image( { url: undefined, alt: '' } ), paragraph ];

		render( <StepEdit clientId="step-1" /> );

		expect( screen.queryByRole( 'alert' ) ).toBeNull();
	} );

	it( 'stays quiet when the step holds no image block', () => {
		mockInner = [ paragraph ];

		render( <StepEdit clientId="step-1" /> );

		expect( screen.queryByRole( 'alert' ) ).toBeNull();
	} );

	it( 'asks for a locked photo and note, with the note prompting for a description', () => {
		render( <StepEdit clientId="step-1" /> );

		expect( mockInnerOptions.templateLock ).toBe( 'all' );
		expect( mockInnerOptions.template ).toEqual( [
			[ 'core/image' ],
			[ 'core/paragraph', { placeholder: 'Describe this step' } ],
		] );
		expect( screen.getByTestId( 'inner-blocks' ) ).toBeTruthy();
	} );
} );

describe( 'Craft steps editor', () => {
	it( 'allows only steps and starts with one', () => {
		render( <StepsEdit /> );

		expect( mockInnerOptions.allowedBlocks ).toEqual( [
			'post-kinds-indieweb/step',
		] );
		expect( mockInnerOptions.template ).toEqual( [
			[ 'post-kinds-indieweb/step' ],
		] );
	} );
} );
