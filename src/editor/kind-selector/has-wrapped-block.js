export const WRAPPED_BLOCK_NAME = 'post-kinds-indieweb/wrapped';

export default function hasWrappedBlock( blocks ) {
	return (
		Array.isArray( blocks ) &&
		blocks.some(
			( block ) =>
				block.name === WRAPPED_BLOCK_NAME ||
				hasWrappedBlock( block.innerBlocks )
		)
	);
}
