import { createBlock } from '@wordpress/blocks';
import { expect, userEvent, within } from 'storybook/test';
import './blocks.js';
import { mockApiFetch, never } from './api-fetch-mock.js';
import { draftPost, EditorContext, EditorFrame } from './editor-context.js';
import { BOOK_RESULTS } from './fixtures.js';

const BOOK = '/post-kinds-indieweb/v1/lookup/book';

export default {
	title: 'Blocks/Media Lookup',
	parameters: {
		layout: 'fullscreen',
		docs: {
			description: {
				component:
					'Source: `src/blocks/media-lookup/edit.js`, registered by `src/blocks/media-lookup/index.js`. ' +
					'Its search box is `MediaSearch` from `src/blocks/shared/components.js`, which calls `wp.apiFetch` on `/post-kinds-indieweb/v1/lookup/{book,video,music}`. ' +
					'These stories answer the book route from a fixture shaped like `includes/apis/class-openlibrary.php` results.',
			},
		},
	},
	args: { attributes: { mediaType: 'book' }, routes: {} },
	beforeEach: ( { args } ) => mockApiFetch( args.routes ),
	render: ( { attributes }, { id } ) => (
		<EditorContext key={ id } post={ draftPost() }>
			<EditorFrame
				initialBlocks={ [
					createBlock(
						'post-kinds-indieweb/media-lookup',
						attributes
					),
				] }
			/>
		</EditorContext>
	),
};

const search = async ( canvasElement, query ) => {
	const canvas = within(
		canvasElement.querySelector( '.post-kinds-media-search' )
	);
	await userEvent.type(
		canvas.getByPlaceholderText( 'Search by title, author, or ISBN…' ),
		query
	);
	await userEvent.click( canvas.getByRole( 'button', { name: 'Search' } ) );
	return canvas;
};

export const EmptyBookSearch = {};

export const BookResults = {
	args: { routes: { [ BOOK ]: () => BOOK_RESULTS } },
	play: async ( { canvasElement } ) => {
		const canvas = await search( canvasElement, 'Le Guin' );
		await canvas.findByRole( 'button', {
			name: 'The Dispossessed Ursula K. Le Guin (1974)',
			exact: true,
		} );
		await expect(
			canvas.getByRole( 'button', {
				name: 'The Left Hand of Darkness Ursula K. Le Guin (1969)',
				exact: true,
			} )
		).toBeVisible();
		await expect( canvas.getByRole( 'status' ) ).toHaveTextContent(
			'Search results available.'
		);
	},
};

export const Searching = {
	args: { routes: { [ BOOK ]: never } },
	play: async ( { canvasElement } ) => {
		const canvas = await search( canvasElement, 'Le Guin' );
		await expect(
			canvas.getByPlaceholderText( 'Search by title, author, or ISBN…' )
		).toBeDisabled();
		await expect(
			canvas.getByRole( 'button', { name: 'Search', exact: true } )
		).toBeDisabled();
		await expect( canvas.getByRole( 'status' ) ).toHaveTextContent(
			'Searching…'
		);
	},
};

export const NoResults = {
	args: { routes: { [ BOOK ]: () => [] } },
	play: async ( { canvasElement } ) => {
		const canvas = await search( canvasElement, 'zzzz' );
		await canvas.findByText( 'No results found.' );
		await expect( canvas.getByRole( 'status' ) ).toHaveTextContent(
			'No results found.'
		);
	},
};

export const SelectedBook = {
	args: {
		attributes: { mediaType: 'book', selectedItem: BOOK_RESULTS[ 0 ] },
	},
};
