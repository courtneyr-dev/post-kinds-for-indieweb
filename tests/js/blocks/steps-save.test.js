/**
 * Craft steps and step markup (issue 249).
 *
 * patterns/craft-project.php ships the steps group as saved markup, so the
 * two save functions have to produce exactly that markup or the editor marks
 * the pattern's blocks invalid. Steps save an ol, a step saves an li, both
 * with the generated class first and the plugin class second.
 */

// The real data store, so the blocks register. The block-editor stub mirrors
// the parts save() uses, built from the same functions @wordpress/blocks hands
// the real package: useBlockProps.save is its getBlockProps and
// InnerBlocks.Content prints getInnerBlocksProps' children.
jest.mock( '@wordpress/data', () => jest.requireActual( '@wordpress/data' ) );
jest.mock( '@wordpress/block-editor', () => {
	const blocks = jest.requireActual( '@wordpress/blocks' );
	const useBlockProps = () => ( {} );
	useBlockProps.save = blocks.__unstableGetBlockProps;
	const useInnerBlocksProps = () => ( {} );
	useInnerBlocksProps.save = blocks.__unstableGetInnerBlocksProps;
	const InnerBlocks = () => null;
	InnerBlocks.Content = () => useInnerBlocksProps.save().children;
	return {
		useBlockProps,
		useInnerBlocksProps,
		InnerBlocks,
		Warning: () => null,
		store: 'core/block-editor',
	};
} );

import fs from 'fs';
import path from 'path';
import { addFilter } from '@wordpress/hooks';
import {
	createBlock,
	getBlockContent,
	getBlockDefaultClassName,
	getCategories,
	parse,
	registerBlockType,
	serialize,
	setCategories,
} from '@wordpress/blocks';
import metadata from '../../../src/blocks/steps/block.json';
import stepMetadata from '../../../src/blocks/step/block.json';

// What @wordpress/block-editor's generated-class-name hook does on save
// (src/hooks/generated-class-name.js): the block's wp-block class goes first.
addFilter(
	'blocks.getSaveContent.extraProps',
	'test/generated-class-name',
	( extraProps, blockType ) => ( {
		...extraProps,
		className: [
			...new Set( [
				getBlockDefaultClassName( blockType.name ),
				...String( extraProps.className || '' )
					.split( ' ' )
					.filter( Boolean ),
			] ),
		].join( ' ' ),
	} )
);

setCategories( [
	{
		slug: 'post-kinds-indieweb',
		title: 'Post Kinds for IndieWeb in Block Themes',
	},
	...getCategories(),
] );

// Stand-ins for the two core blocks a step holds; the point is their order.
registerBlockType( 'core/image', {
	apiVersion: 3,
	title: 'Image',
	category: 'media',
	attributes: { url: { type: 'string' }, alt: { type: 'string' } },
	save: ( { attributes } ) => (
		<figure className="wp-block-image">
			<img src={ attributes.url } alt={ attributes.alt || '' } />
		</figure>
	),
} );
registerBlockType( 'core/paragraph', {
	apiVersion: 3,
	title: 'Paragraph',
	category: 'text',
	attributes: { content: { type: 'string' } },
	save: ( { attributes } ) => <p>{ attributes.content }</p>,
} );

require( '../../../src/blocks/steps' );
require( '../../../src/blocks/step' );

const STEPS = 'post-kinds-indieweb/steps';
const STEP = 'post-kinds-indieweb/step';

describe( 'Craft steps save', () => {
	it( 'prints an ol with the generated class first and pkiw-craft-steps second', () => {
		expect( getBlockContent( createBlock( STEPS ) ) ).toBe(
			'<ol class="wp-block-post-kinds-indieweb-steps pkiw-craft-steps"></ol>'
		);
	} );

	it( 'holds its steps in order', () => {
		const html = getBlockContent(
			createBlock( STEPS, {}, [
				createBlock( STEP, {}, [
					createBlock( 'core/paragraph', { content: 'First' } ),
				] ),
				createBlock( STEP, {}, [
					createBlock( 'core/paragraph', { content: 'Second' } ),
				] ),
			] )
		);

		expect( html.indexOf( 'First' ) ).toBeGreaterThan( -1 );
		expect( html.indexOf( 'Second' ) ).toBeGreaterThan(
			html.indexOf( 'First' )
		);
		expect( html.match( /<li /g ) ).toHaveLength( 2 );
	} );

	it( 'declares no attributes and allows only the step inside it', () => {
		expect( metadata.attributes ).toBeUndefined();
		expect( stepMetadata.parent ).toEqual( [ STEPS ] );
	} );
} );

describe( 'Craft step save', () => {
	it( 'prints an li with the generated class first and pkiw-craft-step second', () => {
		expect( getBlockContent( createBlock( STEP ) ) ).toBe(
			'<li class="wp-block-post-kinds-indieweb-step pkiw-craft-step"></li>'
		);
	} );

	it( 'holds the image and the paragraph in template order', () => {
		const html = getBlockContent(
			createBlock( STEP, {}, [
				createBlock( 'core/image', {
					url: 'https://example.test/cast-on.jpg',
					alt: 'Cast-on row',
				} ),
				createBlock( 'core/paragraph', { content: 'Cast on 40.' } ),
			] )
		);

		// The li wraps the two inner blocks as saved, image first.
		expect(
			html.startsWith(
				'<li class="wp-block-post-kinds-indieweb-step pkiw-craft-step"><!-- wp:image '
			)
		).toBe( true );
		expect( html.endsWith( '<!-- /wp:paragraph --></li>' ) ).toBe( true );
		expect( html.indexOf( 'wp:image' ) ).toBeLessThan(
			html.indexOf( 'wp:paragraph' )
		);
		expect( html ).toContain( 'alt="Cast-on row"' );
		expect( html ).toContain( 'Cast on 40.' );
	} );
} );

describe( 'patterns/craft-project.php', () => {
	const pattern = fs.readFileSync(
		path.resolve( __dirname, '../../../patterns/craft-project.php' ),
		'utf8'
	);
	const markup = pattern.slice(
		pattern.indexOf( '<!-- wp:post-kinds-indieweb/steps -->' ),
		pattern.indexOf( '<!-- /wp:post-kinds-indieweb/steps -->' ) +
			'<!-- /wp:post-kinds-indieweb/steps -->'.length
	);

	it( 'finds the steps group the pattern ships', () => {
		expect( markup ).toContain(
			'<ol class="wp-block-post-kinds-indieweb-steps pkiw-craft-steps">'
		);
		expect( markup ).toContain(
			'<li class="wp-block-post-kinds-indieweb-step pkiw-craft-step"></li>'
		);
	} );

	it( 'parses as valid blocks through the save functions', () => {
		const blocks = parse( markup );

		expect( blocks ).toHaveLength( 1 );
		expect( blocks[ 0 ].name ).toBe( STEPS );
		expect( blocks[ 0 ].isValid ).toBe( true );
		expect( blocks[ 0 ].innerBlocks ).toHaveLength( 1 );
		expect( blocks[ 0 ].innerBlocks[ 0 ].name ).toBe( STEP );
		expect( blocks[ 0 ].innerBlocks[ 0 ].isValid ).toBe( true );
	} );

	it( 'serializes back to the same markup', () => {
		expect( serialize( parse( markup ) ) ).toBe( markup );
	} );

	it( 'is what a new steps block with one empty step saves', () => {
		expect(
			serialize( createBlock( STEPS, {}, [ createBlock( STEP ) ] ) )
		).toBe( markup );
	} );
} );
