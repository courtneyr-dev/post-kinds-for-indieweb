describe( 'On This Day editor blocks', () => {
	const registered = {};

	beforeAll( () => {
		global.wp = {
			blocks: {
				registerBlockType: ( name, settings ) => {
					registered[ name ] = settings;
				},
			},
			element: {
				createElement: ( type, props, ...children ) => ( {
					type,
					props: props || {},
					children,
				} ),
			},
			i18n: { __: ( text ) => text },
			blockEditor: {
				InspectorControls: 'InspectorControls',
				useBlockProps: () => ( {} ),
			},
			components: {
				PanelBody: 'PanelBody',
				SelectControl: 'SelectControl',
				RangeControl: 'RangeControl',
			},
		};
		window.wp = global.wp;
		require( '../../assets/js/on-this-day-editor.js' );
	} );

	test( 'registers both panels with PHP-matching defaults', () => {
		const card = registered[ 'post-kinds-indieweb/on-this-day' ];
		const entry = registered[ 'post-kinds-indieweb/on-this-day-entry' ];
		expect( card ).toBeDefined();
		expect( entry ).toBeDefined();
		expect( card.attributes.context.default ).toBe( 'stream' );
		expect( card.attributes.headingLevel.default ).toBe( 3 );
		expect( entry.attributes.headingLevel.default ).toBe( 3 );
		expect(
			card.edit( {
				attributes: { context: 'stream', headingLevel: 3 },
				setAttributes: jest.fn(),
			} )
		).toBeTruthy();
		expect(
			entry.edit( {
				attributes: { headingLevel: 3 },
				setAttributes: jest.fn(),
			} )
		).toBeTruthy();
	} );
} );
