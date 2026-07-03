/**
 * Jest test setup file
 *
 * Runs before each test file. Sets up global mocks and configurations.
 */

/* eslint-disable no-console -- This file's job is mocking console methods. */

import '@testing-library/jest-dom';

// Mock WordPress packages that cause issues
jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: ( { children } ) => children,
	useBlockProps: () => ( {} ),
	RichText: () => null,
	MediaUpload: () => null,
	MediaUploadCheck: ( { children } ) => children,
} ) );
jest.mock( '@wordpress/components', () => ( {
	PanelBody: ( { children } ) => children,
	TextControl: () => null,
	SelectControl: () => null,
	Button: ( { children, onClick } ) => (
		<button onClick={ onClick }>{ children }</button>
	),
	Spinner: () => null,
	BaseControl: ( { children } ) => children,
} ) );
jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn( () => ( {} ) ),
	useDispatch: jest.fn( () => ( {} ) ),
	select: jest.fn( () => ( {} ) ),
	dispatch: jest.fn( () => ( {} ) ),
} ) );

// Mock WordPress globals
global.wp = {
	i18n: {
		__: ( str ) => str,
		_x: ( str ) => str,
		_n: ( single, plural, number ) => ( number === 1 ? single : plural ),
		sprintf: ( format, ...args ) => {
			let i = 0;
			return format.replace( /%s/g, () => args[ i++ ] || '' );
		},
	},
	apiFetch: jest.fn(),
};

// Mock ajaxurl
global.ajaxurl = '/wp-admin/admin-ajax.php';

// Mock console methods to reduce noise in tests
const originalError = console.error;
const originalWarn = console.warn;

beforeAll( () => {
	console.error = jest.fn( ( ...args ) => {
		if (
			typeof args[ 0 ] === 'string' &&
			( args[ 0 ].includes( 'Warning: ReactDOM.render' ) ||
				args[ 0 ].includes( 'act(' ) )
		) {
			return;
		}
		originalError.apply( console, args );
	} );

	console.warn = jest.fn( ( ...args ) => {
		if (
			typeof args[ 0 ] === 'string' &&
			args[ 0 ].includes( 'componentWillReceiveProps' )
		) {
			return;
		}
		originalWarn.apply( console, args );
	} );
} );

afterAll( () => {
	console.error = originalError;
	console.warn = originalWarn;
} );
