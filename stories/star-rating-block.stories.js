import { createBlock } from '@wordpress/blocks';
import { expect, userEvent, waitFor, within } from 'storybook/test';
import './blocks.js';
import { draftPost, EditorContext, EditorFrame } from './editor-context.js';

export default {
	title: 'Blocks/Star Rating',
	parameters: {
		layout: 'fullscreen',
		docs: {
			description: {
				component:
					'Source: `src/blocks/star-rating/edit.js`, registered by `src/blocks/star-rating/index.js` from `block.json`. ' +
					'The canvas shows the rating as a radio group, or a range control in the Numeric style. ' +
					'The inspector holds Rating Settings and Item Being Rated panels.',
			},
		},
	},
	render: ( attributes, { id } ) => (
		<EditorContext key={ id } post={ draftPost() }>
			<EditorFrame
				initialBlocks={ [
					createBlock(
						'post-kinds-indieweb/star-rating',
						attributes
					),
				] }
			/>
		</EditorContext>
	),
};

export const Unrated = {};

export const FourOfFive = {
	args: { rating: 4 },
	play: async ( { canvasElement } ) => {
		const group = within( canvasElement ).getByRole( 'radiogroup' );
		const stars = within( group ).getAllByRole( 'radio' );
		await userEvent.click( stars[ 2 ] );
		await waitFor( () =>
			expect( stars[ 3 ] ).toHaveAttribute( 'aria-checked', 'false' )
		);
		expect( stars[ 2 ] ).toHaveAttribute( 'aria-checked', 'true' );
	},
};

export const HalfStarsOutOfTen = {
	args: { rating: 7.5, maxRating: 10, allowHalf: true },
};

export const Hearts = { args: { rating: 3, style: 'hearts' } };

export const Numeric = { args: { rating: 8, maxRating: 10, style: 'numeric' } };

export const RatedItemLarge = {
	args: {
		rating: 5,
		size: 'large',
		label: 'My rating',
		itemName: 'Hounds of Love',
		itemUrl: 'https://example.com/hounds-of-love',
	},
};
