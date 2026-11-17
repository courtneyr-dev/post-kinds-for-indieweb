import hasWrappedBlock, {
	WRAPPED_BLOCK_NAME,
} from '../../../src/editor/kind-selector/has-wrapped-block';

const block = ( name, innerBlocks = [] ) => ( { name, innerBlocks } );

describe( 'Wrapped kind-panel gate', () => {
	it( 'finds the Wrapped block at any depth', () => {
		expect( hasWrappedBlock( [ block( WRAPPED_BLOCK_NAME ) ] ) ).toBe(
			true
		);
		expect(
			hasWrappedBlock( [
				block( 'core/group', [ block( WRAPPED_BLOCK_NAME ) ] ),
			] )
		).toBe( true );
	} );

	it( 'leaves ordinary posts eligible for kind detection', () => {
		expect( hasWrappedBlock( [ block( 'core/paragraph' ) ] ) ).toBe(
			false
		);
		expect( hasWrappedBlock( undefined ) ).toBe( false );
	} );
} );
