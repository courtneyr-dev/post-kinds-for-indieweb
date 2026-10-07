/**
 * The menu entry's editor registration (assets/js/menu-entry-editor.js).
 *
 * The script is a plain file that reads WordPress globals, so the test hands
 * it a stand-in for `window.wp` and renders its edit function by hand.
 */

const SCRIPT = '../../../assets/js/menu-entry-editor.js';

/**
 * Load the script and return the settings it registers the block with.
 *
 * @return {Object} Block settings.
 */
function register() {
	const registerBlockType = jest.fn();
	const component = ( name ) => name;
	window.wp = {
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
			useBlockProps: ( props ) => props,
			InspectorControls: component( 'InspectorControls' ),
		},
		components: {
			PanelBody: component( 'PanelBody' ),
			ToggleControl: component( 'ToggleControl' ),
			RangeControl: component( 'RangeControl' ),
			SelectControl: component( 'SelectControl' ),
			TextControl: component( 'TextControl' ),
		},
	};
	jest.isolateModules( () => {
		require( SCRIPT );
	} );
	expect( registerBlockType ).toHaveBeenCalledTimes( 1 );
	return registerBlockType.mock.calls[ 0 ][ 1 ];
}

// The ServerSideRender element an edit render returns.
function serverSideRender( settings, context ) {
	const element = settings.edit( {
		attributes: { showSections: true },
		context,
		setAttributes: () => {},
	} );
	return element.children.find(
		( child ) => child && 'ServerSideRender' === child.type
	);
}

describe( 'menu entry editor', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'reads the template slug from block context', () => {
		expect( register().usesContext ).toContain( 'templateSlug' );
	} );

	it( 'previews a line as the kind whose archive template holds it', () => {
		const render = serverSideRender( register(), {
			postId: 12,
			templateSlug: 'taxonomy-kind-eat',
		} );

		expect( render.props.urlQueryArgs ).toEqual( {
			post_id: 12,
			pkiw_kind: 'eat',
		} );
	} );

	it.each( [
		[ 'a post is being edited', undefined ],
		[ 'the template is the general kind archive', 'taxonomy-kind' ],
		[ 'the template belongs to another taxonomy', 'taxonomy-venue-cafe' ],
	] )( 'names no kind when %s', ( label, templateSlug ) => {
		const render = serverSideRender( register(), {
			postId: 12,
			templateSlug,
		} );

		expect( render.props.urlQueryArgs ).toEqual( { post_id: 12 } );
	} );
} );
