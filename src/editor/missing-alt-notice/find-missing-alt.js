/**
 * Find images with no alt text.
 *
 * Walks a block tree, gallery children and nested blocks included, and
 * returns the core/image blocks that have a picture and no alt text. The
 * walk only reads; nothing here changes a block.
 *
 * @package
 * @since   1.9.0
 */

/**
 * Kinds whose posts get the missing-alt notice.
 *
 * Another kind joins by adding its slug here. Craft joins for its steps:
 * each step holds a photo, and the walk below reaches it through the
 * steps and step blocks (issue 249).
 *
 * @type {Array<string>}
 */
export const MISSING_ALT_KINDS = [ 'photo', 'craft' ];

/**
 * Whether a value holds no alt text.
 *
 * @param {unknown} alt Alt attribute value.
 * @return {boolean} True for undefined, null, '' and whitespace.
 */
const isBlank = ( alt ) => 'string' !== typeof alt || '' === alt.trim();

/**
 * Last path segment of a URL, for naming an image in a list.
 *
 * @param {string} url Image URL.
 * @return {string} File name, or '' when the URL has none.
 */
const fileName = ( url ) => {
	if ( 'string' !== typeof url ) {
		return '';
	}

	const path = url.split( /[?#]/ )[ 0 ];
	return path.slice( path.lastIndexOf( '/' ) + 1 );
};

/**
 * Images in a block list that have a picture and no alt text.
 *
 * Galleries saved before WordPress 5.9 keep their images in an attribute
 * rather than in inner blocks; each of those is reported against the gallery.
 *
 * @param {Array<Object>} blocks Block list from the block editor.
 * @return {Array<{clientId: string, key: string, name: string}>} One entry per image, in document order. `key` is unique within the list.
 */
export function findImagesMissingAlt( blocks ) {
	const found = [];

	const walk = ( list ) => {
		if ( ! Array.isArray( list ) ) {
			return;
		}

		for ( const block of list ) {
			if ( 'core/image' === block?.name ) {
				const { url, alt } = block.attributes || {};

				if ( url && isBlank( alt ) ) {
					found.push( {
						clientId: block.clientId,
						key: block.clientId,
						name: fileName( url ),
					} );
				}
			}

			if (
				'core/gallery' === block?.name &&
				Array.isArray( block.attributes?.images )
			) {
				block.attributes.images.forEach( ( item, index ) => {
					if ( item?.url && isBlank( item.alt ) ) {
						found.push( {
							clientId: block.clientId,
							key: `${ block.clientId }:${ index }`,
							name: fileName( item.url ),
						} );
					}
				} );
			}

			walk( block?.innerBlocks );
		}
	};

	walk( blocks );

	return found;
}
