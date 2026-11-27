/**
 * Read Card spacing per viewport (src/blocks/shared/viewport-spacing.js).
 *
 * The helpers take core's style-state API as an argument. The stand-in
 * below mirrors WordPress 7.2's getStyleForState/setStyleForState:
 * the default state is the whole style; any other state is the object
 * at style[viewport].
 */

jest.mock( '@wordpress/block-editor', () => ( {} ) );

import {
	getStyleStateApi,
	spacingOptions,
	getCardPadding,
	setCardPadding,
} from '../../../src/blocks/shared/viewport-spacing';

const path = ( state ) =>
	[ state.viewport, state.pseudo ].filter( ( s ) => s && s !== 'default' );

const api72 = {
	getStyleForState: ( style, state ) =>
		path( state ).reduce( ( obj, key ) => obj?.[ key ], style ),
	setStyleForState: ( style, state, next ) => {
		const keys = path( state );
		if ( ! keys.length ) {
			return next;
		}
		return { ...style, [ keys[ 0 ] ]: next };
	},
};

const BASE = { viewport: 'default', pseudo: 'default' };
const MOBILE = { viewport: '@mobile', pseudo: 'default' };
const SM = 'var:preset|spacing|20';
const LG = 'var:preset|spacing|50';

describe( 'getStyleStateApi', () => {
	it( 'finds the 7.2 exports', () => {
		expect( getStyleStateApi( api72 ) ).toEqual( api72 );
	} );

	it( 'returns null on a 7.1 package that keeps them private', () => {
		expect( getStyleStateApi( { useBlockProps() {} } ) ).toBeNull();
		expect( getStyleStateApi( { getStyleForState() {} } ) ).toBeNull();
		expect( getStyleStateApi( {} ) ).toBeNull();
	} );
} );

describe( 'spacingOptions', () => {
	it( 'lists theme tokens as preset references, never raw sizes', () => {
		expect(
			spacingOptions(
				undefined,
				[ { name: 'Small', slug: '20', size: '0.5rem' } ],
				[ { name: 'Large', slug: '50', size: '2rem' } ]
			)
		).toEqual( [
			{ label: 'Small', value: SM },
			{ label: 'Large', value: LG },
		] );
	} );
} );

describe( 'setCardPadding', () => {
	const style = {
		spacing: { padding: { top: LG, right: LG, bottom: LG, left: LG } },
		color: { background: 'var:preset|color|base' },
	};

	it( 'writes the mobile value without touching the base style', () => {
		const next = setCardPadding( style, MOBILE, SM, api72 );
		expect( next[ '@mobile' ] ).toEqual( {
			spacing: { padding: { top: SM, right: SM, bottom: SM, left: SM } },
		} );
		expect( next.spacing ).toBe( style.spacing );
		expect( next.color ).toBe( style.color );
		expect( getCardPadding( next, MOBILE, api72 ) ).toBe( SM );
		expect( getCardPadding( next, BASE, api72 ) ).toBe( LG );
	} );

	it( 'keeps the mobile value when the base changes', () => {
		const mobile = setCardPadding( style, MOBILE, SM, api72 );
		const next = setCardPadding( mobile, BASE, '', api72 );
		expect( next.spacing.padding ).toBeUndefined();
		expect( next[ '@mobile' ] ).toEqual( mobile[ '@mobile' ] );
		expect( next.color ).toEqual( style.color );
	} );

	it( 'reads mixed sides as no card value', () => {
		expect(
			getCardPadding(
				{ spacing: { padding: { top: SM, right: LG } } },
				BASE,
				api72
			)
		).toBe( '' );
		expect( getCardPadding( undefined, MOBILE, api72 ) ).toBe( '' );
	} );
} );
