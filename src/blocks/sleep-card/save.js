/**
 * Sleep Card Block - Save Component
 *
 * Dynamic block: server-side rendering lives in `render.php`. post_content
 * holds only the block comment, so a private bedtime or wake time never
 * reaches a feed, search or ActivityPub as static markup (issue 247).
 *
 * @package
 */

/**
 * @return {null} Null because this block uses server-side rendering.
 */
export default function Save() {
	return null;
}
