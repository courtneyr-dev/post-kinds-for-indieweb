import { render } from '@testing-library/react';
import Edit from '../../../src/blocks/wrapped/edit';

const mockDate = jest.fn( () => '2026' );
jest.mock( '@wordpress/date', () => ( {
	date: ( ...args ) => mockDate( ...args ),
} ) );
jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: ( { children } ) => children,
	useBlockProps: () => ( {} ),
} ) );
const mockSelects = [];
jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { children } ) => children,
	TextControl: () => null,
	RangeControl: () => null,
	SelectControl: ( props ) => {
		mockSelects.push( props );
		return null;
	},
} ) );
jest.mock( '@wordpress/data', () => ( {
	useSelect: ( callback ) =>
		callback( () => ( {
			getEntityRecords: () => [
				{ name: 'Read', slug: 'read' },
				{ name: 'Listen', slug: 'listen' },
			],
		} ) ),
} ) );
jest.mock( '@wordpress/core-data', () => ( { store: 'core' } ) );
jest.mock( '@wordpress/server-side-render', () => () => null );

describe( 'Wrapped editor', () => {
	it( 'sets a missing year from the site-local WordPress date helper', () => {
		const setAttributes = jest.fn();
		render(
			<Edit
				attributes={ { metrics: [] } }
				setAttributes={ setAttributes }
			/>
		);
		expect( mockDate ).toHaveBeenCalledWith( 'Y' );
		expect( setAttributes ).toHaveBeenCalledWith( { year: 2026 } );
	} );

	it( 'tolerates a string in the metrics array attribute', () => {
		expect( () =>
			render(
				<Edit
					attributes={ { year: 2026, metrics: 'sample' } }
					setAttributes={ jest.fn() }
				/>
			)
		).not.toThrow();
	} );

	it( 'offers the kind terms, years 2000 to the current year and heading levels 2-4 as selects', () => {
		mockSelects.length = 0;
		render(
			<Edit
				attributes={ { year: 2026, metrics: [] } }
				setAttributes={ jest.fn() }
			/>
		);
		const [ kind, year, level ] = mockSelects;
		expect( kind.options.map( ( option ) => option.value ) ).toEqual( [
			'',
			'read',
			'listen',
		] );
		expect( year.options[ 0 ].value ).toBe( '2026' );
		expect( year.options[ year.options.length - 1 ].value ).toBe( '2000' );
		expect( year.options ).toHaveLength( 27 );
		expect( level.options.map( ( option ) => option.value ) ).toEqual( [
			'2',
			'3',
			'4',
		] );
	} );
} );
