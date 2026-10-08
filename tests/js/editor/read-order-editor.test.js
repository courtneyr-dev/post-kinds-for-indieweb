/**
 * The Read order block's editor registration (assets/js/read-order-editor.js).
 *
 * The script is a plain file that reads WordPress globals, so the test hands
 * it a stand-in for `window.wp` and renders its edit function by hand.
 */

const SCRIPT = '../../../assets/js/read-order-editor.js';
const NAME = 'post-kinds-indieweb/read-order';

/**
 * Load the script with a stand-in `window.wp`.
 *
 * @param {Object} overrides Properties replacing parts of the stand-in.
 * @return {jest.Mock} The registerBlockType mock.
 */
function load( overrides = {} ) {
	const registerBlockType = jest.fn();
	window.wp = Object.assign(
		{
			blocks: { registerBlockType },
			serverSideRender: 'ServerSideRender',
			element: {
				createElement: ( type, props, ...children ) => ( {
					type,
					props,
					children,
				} ),
			},
			i18n: { __: ( text ) => text },
			blockEditor: {
				useBlockProps: ( props ) => ( { ...props, blockProps: true } ),
			},
			components: { Disabled: 'Disabled' },
		},
		overrides
	);
	jest.isolateModules( () => {
		require( SCRIPT );
	} );
	return registerBlockType;
}

/**
 * Load the script and return the name and settings it registers.
 *
 * @return {Array} [ name, settings ].
 */
function register() {
	const registerBlockType = load();
	expect( registerBlockType ).toHaveBeenCalledTimes( 1 );
	return registerBlockType.mock.calls[ 0 ];
}

describe( 'read order editor', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'registers the server-rendered block under its PHP name', () => {
		const [ name, settings ] = register();

		expect( name ).toBe( NAME );
		expect( settings.apiVersion ).toBe( 3 );
		expect( settings.title ).toBe( 'Read order' );
		expect( settings.category ).toBe( 'post-kinds-indieweb' );
		expect( settings.supports ).toEqual( { html: false, reusable: false } );
		expect( settings.save() ).toBeNull();
	} );

	it( 'previews the links the archive prints, disabled so a click selects the block', () => {
		const [ , settings ] = register();
		const attributes = { className: 'is-style-pills' };

		const element = settings.edit( { attributes } );

		expect( element.type ).toBe( 'div' );
		expect( element.props.blockProps ).toBe( true );
		const disabled = element.children[ 0 ];
		expect( disabled.type ).toBe( 'Disabled' );
		const render = disabled.children[ 0 ];
		expect( render.type ).toBe( 'ServerSideRender' );
		expect( render.props ).toEqual( { block: NAME, attributes } );
	} );

	it.each( [
		[ 'blocks', { blocks: undefined } ],
		[ 'serverSideRender', { serverSideRender: undefined } ],
		[ 'components', { components: undefined } ],
	] )( 'registers nothing without wp.%s', ( label, overrides ) => {
		expect( load( overrides ) ).not.toHaveBeenCalled();
	} );
} );
