/**
 * Matrix-driven serialize coverage for static (JS-rendered) card blocks.
 *
 * For each block marked `render: "static"` in the field matrix, creates the
 * block with sample attribute values and asserts every attribute value
 * appears in the save() markup — since these blocks have no PHP render
 * callback, save.js output IS the front-end markup.
 *
 * Assertions run against getBlockContent() (the save markup only), NOT
 * serialize(): serialize() embeds every non-default attribute as JSON in the
 * block comment delimiter, which would make each toContain() assertion pass
 * trivially and hide real save.js display gaps.
 */

// tests/js/setup.js globally mocks @wordpress/data and @wordpress/block-editor
// with stubs too thin for real block registration + serialization:
// registerBlockType/createBlock need the actual @wordpress/blocks store
// (real @wordpress/data), and save.js calls useBlockProps.save(), which the
// global mock lacks. Restore the real data package and provide a
// block-editor mock whose useBlockProps.save() passes props through — for
// these blocks that matches the real one (the generated wp-block class is
// added by @wordpress/blocks itself). Keeping block-editor mocked avoids
// dragging the full editor dependency chain into jsdom.
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
	setCategories,
} from '@wordpress/blocks';
import matrix from '../phpunit/fixtures/field-matrix.json';
import { sampleFor } from './sample-values';

// src/blocks/index.js registers the custom block category before the block
// imports; mirror that here so registerBlockType doesn't warn.
setCategories( [
	{
		slug: 'post-kinds-indieweb',
		title: 'Post Kinds for IndieWeb in Block Themes',
	},
	...getCategories(),
] );

// Importing the barrel file ('../../src/blocks') would register all 22
// blocks, including dynamic ones whose edit.js drags in editor dependency
// chains that jsdom can't run and that have nothing to do with save.js
// serialization. Require each static block's own index.js directly — the
// same registerBlockType() call, none of the unrelated dependencies.
// (require, not import, so the category registration above runs first.)
require( '../../src/blocks/acquisition-card' );
require( '../../src/blocks/favorite-card' );
require( '../../src/blocks/media-lookup' );
require( '../../src/blocks/star-rating' );
require( '../../src/blocks/wish-card' );

// registerCoreBlocks() from @wordpress/block-library is intentionally NOT
// used: none of the 5 static blocks' save.js nest a core block (no
// InnerBlocks, no core/* references) — verified by inspecting each save.js.

const staticBlocks = Object.entries( matrix ).filter(
	( [ , def ] ) => def.render === 'static'
);

// Attributes that legitimately never appear in the save markup. Keyed by
// block name; value maps attribute -> written reason. This is the only
// sanctioned escape hatch — do not skip attributes without an entry here.
const EXCEPTIONS = {
	'post-kinds-indieweb/media-lookup': {
		searchQuery:
			'Transient editor state (the search box text); save.js intentionally renders only the selected item, never the query.',
		selectedItem:
			'Typed "object"; save.js renders derived subfields (title, author, cover…), so the sampler\'s flat string (locked rule order has no object rule) cannot appear verbatim. The object rendering path is covered by the dedicated test below.',
	},
};

// Note on star-rating: the numeric sampler yields rating=4 AND maxRating=4.
// Verified against save.js that a maxed-out rating trips no clamping or
// conditional-rendering edge case: renderIcons() marks all 4 icons filled,
// the "4 / 4" text value still renders (style "Sample style value" !==
// 'numeric'), and both <data>/<meta> microformat values serialize as "4".

describe.each( staticBlocks )( '%s static save output', ( name, def ) => {
	const attrs = Object.fromEntries(
		Object.entries( def.attributes ).map( ( [ a, d ] ) => [
			a,
			sampleFor( a, d ),
		] )
	);

	test( 'every attribute value appears in save markup', () => {
		const html = getBlockContent( createBlock( name, attrs ) );
		const exceptions = EXCEPTIONS[ name ] || {};
		for ( const [ attr, d ] of Object.entries( def.attributes ) ) {
			if ( d.type === 'boolean' || attr === 'layout' ) {
				continue;
			}
			if ( attr in exceptions ) {
				continue;
			}
			expect( html ).toContain( String( sampleFor( attr, d ) ) );
		}
	} );

	test( 'empty block serializes without leakage', () => {
		const html = getBlockContent( createBlock( name, {} ) );
		expect( html ).not.toContain( 'undefined' );
		expect( html ).not.toContain( 'Sample' );
	} );
} );

// selectedItem is excepted from the matrix loop above (object attribute, flat
// string sample), so cover its real rendering path here: a representative
// object must surface its subfields in the save markup.
describe( 'post-kinds-indieweb/media-lookup selectedItem rendering', () => {
	test( 'selected item subfields appear in save markup', () => {
		const selectedItem = {
			title: 'Sample item title',
			author: 'Sample item author',
			cover: 'https://example.com/sample-item-cover',
			description: 'Sample item description',
			year: 1999,
			url: 'https://example.com/sample-item-url',
			id: 'sample-item-id',
		};
		const html = getBlockContent(
			createBlock( 'post-kinds-indieweb/media-lookup', {
				mediaType: 'book',
				displayStyle: 'card',
				showImage: true,
				showDescription: true,
				linkToSource: true,
				selectedItem,
			} )
		);
		expect( html ).toContain( selectedItem.title );
		expect( html ).toContain( selectedItem.author );
		expect( html ).toContain( selectedItem.cover );
		expect( html ).toContain( selectedItem.description );
		expect( html ).toContain( String( selectedItem.year ) );
		expect( html ).toContain( selectedItem.url );
		expect( html ).toContain( selectedItem.id );
	} );
} );
