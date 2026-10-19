/**
 * Transcript and audio-player detection.
 *
 * @package
 * @since   1.9.0
 */

export const TRANSCRIPT_CLASS = 'pkiw-transcript';

// The pkiw_audio_embed_providers filter is PHP-side only.
export const AUDIO_EMBED_PROVIDERS = [
	'soundcloud',
	'spotify',
	'mixcloud',
	'pocket-casts',
	'reverbnation',
];

const TEXT_ATTRIBUTES = [ 'content', 'value', 'values', 'citation', 'text' ];

/**
 * Whether paragraph markup contains an absolute u-audio link.
 *
 * @param {*} content Paragraph content.
 * @return {boolean} True when the markup contains an audio link.
 */
function hasAudioLink( content ) {
	const anchors = String( content ?? '' ).match( /<a\b[^>]*>/gi ) || [];

	return anchors.some( ( anchor ) => {
		const classMatch = /\sclass\s*=\s*(["'])([^"']*)\1/i.exec( anchor );
		const hrefMatch = /\shref\s*=\s*(["'])https?:\/\/[^"']*\1/i.exec(
			anchor
		);
		const classes = classMatch ? classMatch[ 2 ].split( /\s+/ ) : [];

		return classes.includes( 'u-audio' ) && Boolean( hrefMatch );
	} );
}

/**
 * Whether a block list contains an audio player.
 *
 * @param {Array<Object>} blocks Blocks to search.
 * @return {boolean} True when an audio player is present.
 */
export function hasAudioPlayer( blocks ) {
	if ( ! Array.isArray( blocks ) ) {
		return false;
	}

	for ( const block of blocks ) {
		if ( 'core/audio' === block?.name ) {
			return true;
		}

		if (
			'core/embed' === block?.name &&
			AUDIO_EMBED_PROVIDERS.includes(
				block?.attributes?.providerNameSlug
			)
		) {
			return true;
		}

		if (
			'core/paragraph' === block?.name &&
			hasAudioLink( block?.attributes?.content )
		) {
			return true;
		}

		if (
			block?.innerBlocks?.length &&
			hasAudioPlayer( block.innerBlocks )
		) {
			return true;
		}
	}

	return false;
}

/**
 * Decode entities that commonly appear in block text.
 *
 * @param {string} text Text to decode.
 * @return {string} Decoded text.
 */
function decodeEntities( text ) {
	const named = {
		amp: '&',
		apos: "'",
		gt: '>',
		lt: '<',
		nbsp: '\u00a0',
		quot: '"',
	};

	return text
		.replace(
			/&(amp|apos|gt|lt|nbsp|quot);/gi,
			( entity, name ) => named[ name.toLowerCase() ]
		)
		.replace( /&#(\d+);/g, ( entity, value ) =>
			String.fromCodePoint( Number( value ) )
		)
		.replace( /&#x([\da-f]+);/gi, ( entity, value ) =>
			String.fromCodePoint( Number.parseInt( value, 16 ) )
		);
}

/**
 * Concatenate text attributes from every descendant block.
 *
 * @param {Array<Object>} blocks Descendant blocks.
 * @return {string} Concatenated text.
 */
function descendantText( blocks ) {
	if ( ! Array.isArray( blocks ) ) {
		return '';
	}

	let text = '';
	for ( const block of blocks ) {
		for ( const attribute of TEXT_ATTRIBUTES ) {
			if ( block?.attributes?.[ attribute ] !== undefined ) {
				text += String( block.attributes[ attribute ] );
			}
		}

		if ( block?.innerBlocks?.length ) {
			text += descendantText( block.innerBlocks );
		}
	}

	return text;
}

/**
 * Whether descendants contain readable text.
 *
 * @param {Array<Object>} blocks Descendant blocks.
 * @return {boolean} True when text remains after markup and whitespace removal.
 */
function descendantsHaveText( blocks ) {
	const text = descendantText( blocks );

	return Boolean(
		decodeEntities( text.replace( /<[^>]*>/g, '' ) ).replace(
			/[\s\u00a0]+/g,
			''
		)
	);
}

/**
 * Whether a block list contains a transcript group with readable text.
 *
 * @param {Array<Object>} blocks Blocks to search.
 * @return {boolean} True when a filled transcript is present.
 */
export function hasFilledTranscript( blocks ) {
	if ( ! Array.isArray( blocks ) ) {
		return false;
	}

	for ( const block of blocks ) {
		const classes = String( block?.attributes?.className ?? '' ).split(
			/\s+/
		);
		if (
			'core/group' === block?.name &&
			classes.includes( TRANSCRIPT_CLASS ) &&
			descendantsHaveText( block.innerBlocks )
		) {
			return true;
		}

		if (
			block?.innerBlocks?.length &&
			hasFilledTranscript( block.innerBlocks )
		) {
			return true;
		}
	}

	return false;
}
