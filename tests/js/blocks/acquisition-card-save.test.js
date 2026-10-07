/**
 * The Acquisition Card's static markup keeps a private cost out of
 * post_content (issue 239). ActivityPub summaries, ATmosphere titles and
 * search read that markup raw, before render.php runs. Cards stored with
 * their cost before the change still parse as valid blocks.
 */

// Same mocks as tests/js/field-matrix-static.test.js: the real data store
// for registration, and a block-editor stub whose useBlockProps.save()
// passes props through. The stub leaves out the generated
// wp-block-post-kinds-indieweb-acquisition-card class, so STORED does too.
jest.mock( '@wordpress/data', () => jest.requireActual( '@wordpress/data' ) );
jest.mock( '@wordpress/block-editor', () => {
	const useBlockProps = () => ( {} );
	useBlockProps.save = ( props = {} ) => props;
	return {
		useBlockProps,
		InspectorControls: ( { children } ) => children,
		BlockControls: ( { children } ) => children,
		RichText: () => null,
		MediaUpload: () => null,
		MediaUploadCheck: ( { children } ) => children,
	};
} );

import {
	createBlock,
	getBlockContent,
	getCategories,
	parse,
	setCategories,
} from '@wordpress/blocks';

setCategories( [
	{
		slug: 'post-kinds-indieweb',
		title: 'Post Kinds for IndieWeb in Block Themes',
	},
	...getCategories(),
] );

require( '../../../src/blocks/acquisition-card' );

const NAME = 'post-kinds-indieweb/acquisition-card';
const ATTRS = {
	title: 'Walnut Desk Lamp Zq9',
	cost: '$149.99',
	where: 'Corner Hardware Zq5',
};

// A card as save.js stored it before issue 239, cost in the subtitle.
const STORED =
	'<!-- wp:post-kinds-indieweb/acquisition-card {"title":"Walnut Desk Lamp Zq9","cost":"$149.99","where":"Corner Hardware Zq5"} -->\n' +
	'<div class="acquisition-card layout-horizontal"><div class="post-kinds-card h-cite"><div class="post-kinds-card__content">' +
	'<span class="post-kinds-card__badge">Purchase</span><h3 class="post-kinds-card__title p-name">Walnut Desk Lamp Zq9</h3>' +
	'<p class="post-kinds-card__subtitle">$149.99</p><p class="post-kinds-card__meta p-location">from Corner Hardware Zq5</p></div>' +
	'<data class="u-acquired" value="Walnut Desk Lamp Zq9" hidden></data></div></div>\n' +
	'<!-- /wp:post-kinds-indieweb/acquisition-card -->';

describe( 'acquisition card save markup', () => {
	test( 'leaves a private cost out', () => {
		const html = getBlockContent( createBlock( NAME, ATTRS ) );

		expect( html ).toContain( 'Walnut Desk Lamp Zq9' );
		expect( html ).toContain( 'from Corner Hardware Zq5' );
		expect( html ).not.toContain( '149.99' );
	} );

	test( 'keeps a public cost', () => {
		const html = getBlockContent(
			createBlock( NAME, { ...ATTRS, showCostPublicly: true } )
		);

		expect( html ).toContain(
			'<p class="post-kinds-card__subtitle">$149.99</p>'
		);
	} );

	test( 'parses a card stored with its cost and drops the cost on the next save', () => {
		const [ block ] = parse( STORED );

		// The parser reports a deprecation match with console.info().
		expect( console ).toHaveInformed();
		expect( block.name ).toBe( NAME );
		expect( block.isValid ).toBe( true );
		expect( block.attributes.cost ).toBe( '$149.99' );
		expect( block.attributes.showCostPublicly ).toBe( false );
		expect( getBlockContent( block ) ).not.toContain( '149.99' );
	} );
} );
