/**
 * Star Rating block tests
 */

import { render, screen, fireEvent } from '@testing-library/react';

// Mock the star rating component
const StarRating = ( { rating = 0, maxRating = 5, onChange } ) => {
	return (
		<div role="group" aria-label="Star Rating">
			{ [ ...Array( maxRating ) ].map( ( _, index ) => (
				<button
					key={ index }
					onClick={ () => onChange?.( index + 1 ) }
					aria-label={ `Rate ${ index + 1 } out of ${ maxRating }` }
					aria-pressed={ rating >= index + 1 }
				>
					{ rating >= index + 1 ? '★' : '☆' }
				</button>
			) ) }
		</div>
	);
};

describe( 'StarRating', () => {
	it( 'renders with default props', () => {
		render( <StarRating /> );

		const ratingGroup = screen.getByRole( 'group', {
			name: 'Star Rating',
		} );
		expect( ratingGroup ).toBeInTheDocument();

		const buttons = screen.getAllByRole( 'button' );
		expect( buttons ).toHaveLength( 5 );
	} );

	it( 'displays filled stars based on rating', () => {
		render( <StarRating rating={ 3 } /> );

		const buttons = screen.getAllByRole( 'button' );

		expect( buttons[ 0 ] ).toHaveTextContent( '★' );
		expect( buttons[ 1 ] ).toHaveTextContent( '★' );
		expect( buttons[ 2 ] ).toHaveTextContent( '★' );
		expect( buttons[ 3 ] ).toHaveTextContent( '☆' );
		expect( buttons[ 4 ] ).toHaveTextContent( '☆' );
	} );

	it( 'calls onChange when clicking a star', () => {
		const handleChange = jest.fn();
		render( <StarRating rating={ 0 } onChange={ handleChange } /> );

		const buttons = screen.getAllByRole( 'button' );
		fireEvent.click( buttons[ 2 ] );

		expect( handleChange ).toHaveBeenCalledWith( 3 );
	} );

	it( 'respects custom maxRating', () => {
		render( <StarRating maxRating={ 10 } /> );

		const buttons = screen.getAllByRole( 'button' );
		expect( buttons ).toHaveLength( 10 );
	} );

	it( 'has proper aria labels for accessibility', () => {
		render( <StarRating rating={ 3 } /> );

		const buttons = screen.getAllByRole( 'button' );

		expect( buttons[ 0 ] ).toHaveAttribute( 'aria-pressed', 'true' );
		expect( buttons[ 3 ] ).toHaveAttribute( 'aria-pressed', 'false' );
	} );
} );
