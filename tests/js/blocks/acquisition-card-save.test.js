/**
 * The Acquisition Card's static markup keeps a private cost out of
 * post_content (issue 239). ActivityPub summaries, ATmosphere titles and
 * search read that markup raw, before render.php runs. Cards stored with
 * their cost before the change still parse as valid blocks.
 *
 * The same markup also matches render.php's labels and microformats: an
 * 'inherited' card says "Inherited", the h-cite is the u-acquisition-of
 * property with no u-acquired, and an acquired date prints as the stored
 * Y-m-d so every browser serializes the same bytes. Cards saved by the
 * issue 327 markup (private cost toggle) and the markup before it still
 * validate through the deprecations.
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

// A card as save.js stored it for issue 327: a public cost, the u-acquired
// data element, and no acquisition-of class on the h-cite.
const STORED_327 =
	'<!-- wp:post-kinds-indieweb/acquisition-card {"title":"Walnut Desk Lamp Zq9","cost":"$149.99","showCostPublicly":true,"where":"Corner Hardware Zq5"} -->\n' +
	'<div class="acquisition-card layout-horizontal"><div class="post-kinds-card h-cite"><div class="post-kinds-card__content">' +
	'<span class="post-kinds-card__badge">Purchase</span><h3 class="post-kinds-card__title p-name">Walnut Desk Lamp Zq9</h3>' +
	'<p class="post-kinds-card__subtitle">$149.99</p><p class="post-kinds-card__meta p-location">from Corner Hardware Zq5</p></div>' +
	'<data class="u-acquired" value="Walnut Desk Lamp Zq9" hidden></data></div></div>\n' +
	'<!-- /wp:post-kinds-indieweb/acquisition-card -->';

// The issue 327 markup for a card with a photo, whose alt text fell back to
// the title.
const STORED_327_PHOTO =
	'<!-- wp:post-kinds-indieweb/acquisition-card {"title":"Walnut Desk Lamp Zq9","photo":"https://example.test/lamp.jpg"} -->\n' +
	'<div class="acquisition-card layout-horizontal"><div class="post-kinds-card h-cite"><div class="post-kinds-card__media">' +
	'<img src="https://example.test/lamp.jpg" alt="Walnut Desk Lamp Zq9" class="post-kinds-card__image u-photo" loading="lazy"/></div>' +
	'<div class="post-kinds-card__content"><span class="post-kinds-card__badge">Purchase</span>' +
	'<h3 class="post-kinds-card__title p-name">Walnut Desk Lamp Zq9</h3></div>' +
	'<data class="u-acquired" value="Walnut Desk Lamp Zq9" hidden></data></div></div>\n' +
	'<!-- /wp:post-kinds-indieweb/acquisition-card -->';

const ORIGINAL_TZ = process.env.TZ;

afterEach( () => {
	if ( undefined === ORIGINAL_TZ ) {
		delete process.env.TZ;
	} else {
		process.env.TZ = ORIGINAL_TZ;
	}
} );

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
	test( 'saves the same bytes in every time zone', () => {
		const attrs = { ...ATTRS, acquiredAt: '2026-08-01' };

		process.env.TZ = 'UTC';
		const utc = getBlockContent( createBlock( NAME, attrs ) );
		process.env.TZ = 'America/New_York';
		const newYork = getBlockContent( createBlock( NAME, attrs ) );
		process.env.TZ = 'Pacific/Honolulu';
		const honolulu = getBlockContent( createBlock( NAME, attrs ) );

		expect( newYork ).toBe( utc );
		expect( honolulu ).toBe( utc );
		expect( utc ).toContain(
			'<time class="post-kinds-card__timestamp dt-published" datetime="2026-08-01">2026-08-01</time>'
		);
	} );

	test.each( [
		[ 'an empty date', '' ],
		[ 'a date that is not Y-m-d', 'August first' ],
		[ 'a day that does not exist', '2026-02-31' ],
	] )( 'prints no time element for %s', ( label, acquiredAt ) => {
		const html = getBlockContent(
			createBlock( NAME, { ...ATTRS, acquiredAt } )
		);

		expect( html ).not.toContain( '<time' );
	} );

	test( 'reads the day from a date that carries a time, as render.php does', () => {
		const html = getBlockContent(
			createBlock( NAME, {
				...ATTRS,
				acquiredAt: '2026-08-01T23:30:00',
			} )
		);

		expect( html ).toContain( 'datetime="2026-08-01">2026-08-01</time>' );
	} );

	test( 'prints Inherited for an inherited acquisition', () => {
		const html = getBlockContent(
			createBlock( NAME, { ...ATTRS, acquisitionType: 'inherited' } )
		);

		expect( html ).toContain(
			'<span class="post-kinds-card__badge">Inherited</span>'
		);
	} );

	test( 'prints Acquired for a card with no type, as render.php does', () => {
		const html = getBlockContent(
			createBlock( NAME, { ...ATTRS, acquisitionType: '' } )
		);

		expect( html ).toContain(
			'<span class="post-kinds-card__badge">Acquired</span>'
		);
	} );

	test( 'marks the item with u-acquisition-of and prints no u-acquired', () => {
		const html = getBlockContent(
			createBlock( NAME, { ...ATTRS, acquiredAt: '2026-08-01' } )
		);

		expect( html ).toContain(
			'class="post-kinds-card h-cite u-acquisition-of"'
		);
		expect( html ).not.toContain( 'u-acquired' );
		expect( html ).not.toContain( '<data' );
	} );

	test( 'saves an empty alt for a photo with no alt text', () => {
		const html = getBlockContent(
			createBlock( NAME, {
				...ATTRS,
				photo: 'https://example.test/lamp.jpg',
				photoAlt: '',
			} )
		);

		expect( html ).toContain( 'alt=""' );
		expect( html ).not.toContain( 'alt="Walnut Desk Lamp Zq9"' );
	} );

	test( 'saves the alt text a photo has', () => {
		const html = getBlockContent(
			createBlock( NAME, {
				...ATTRS,
				photo: 'https://example.test/lamp.jpg',
				photoAlt: 'A walnut lamp on a bench',
			} )
		);

		expect( html ).toContain( 'alt="A walnut lamp on a bench"' );
	} );

	test( 'parses a card stored by the issue 327 markup through its deprecation', () => {
		const [ block ] = parse( STORED_327 );

		expect( console ).toHaveInformed();
		expect( block.name ).toBe( NAME );
		expect( block.isValid ).toBe( true );
		expect( block.attributes.cost ).toBe( '$149.99' );
		expect( block.attributes.showCostPublicly ).toBe( true );

		// The next save moves it to the current markup.
		const html = getBlockContent( block );
		expect( html ).toContain( 'h-cite u-acquisition-of' );
		expect( html ).not.toContain( 'u-acquired' );
		expect( html ).toContain( '149.99' );
	} );

	test( 'parses an issue 327 card with a photo whose alt was the title', () => {
		const [ block ] = parse( STORED_327_PHOTO );

		expect( console ).toHaveInformed();
		expect( block.isValid ).toBe( true );
		expect( block.attributes.photo ).toBe(
			'https://example.test/lamp.jpg'
		);
	} );

	test( 'parses a card stored by the issue 327 markup with a date, in the zone that saved it', () => {
		// The old markup printed the browser's own rendering of the date, so
		// the test builds it the way the browser that saved it did.
		process.env.TZ = 'UTC';
		const when = new Date( '2026-08-01' );
		const stored =
			'<!-- wp:post-kinds-indieweb/acquisition-card {"title":"Walnut Desk Lamp Zq9","acquiredAt":"2026-08-01"} -->\n' +
			'<div class="acquisition-card layout-horizontal"><div class="post-kinds-card h-cite"><div class="post-kinds-card__content">' +
			'<span class="post-kinds-card__badge">Purchase</span><h3 class="post-kinds-card__title p-name">Walnut Desk Lamp Zq9</h3>' +
			`<time class="post-kinds-card__timestamp dt-published" datetime="${ when.toISOString() }">${ when.toLocaleString() }</time></div>` +
			'<data class="u-acquired" value="Walnut Desk Lamp Zq9" hidden></data></div></div>\n' +
			'<!-- /wp:post-kinds-indieweb/acquisition-card -->';

		const [ block ] = parse( stored );

		expect( console ).toHaveInformed();
		expect( block.isValid ).toBe( true );
		expect( getBlockContent( block ) ).toContain(
			'datetime="2026-08-01">2026-08-01</time>'
		);
	} );
} );
