/**
 * Exercise Card Block - Save Component
 *
 * Dynamic block: server-side rendering lives in `render.php`. post_content
 * holds only the block comment, never static HTML with metrics, so a private
 * distance or split can't reach ActivityPub, search or a feed before the
 * health rule runs (issue 245).
 *
 * @package
 */

/**
 * @return {null} Null because this block uses server-side rendering.
 */
export default function Save() {
	return null;
}
