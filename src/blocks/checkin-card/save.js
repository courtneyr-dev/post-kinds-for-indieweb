/**
 * Checkin Card Block - Save Component
 *
 * Dynamic block — server-side rendering lives in `render.php`.
 * Returning null tells WordPress to call the PHP renderer at output time
 * instead of saving HTML at edit time. The Micropub bridge writes
 * self-closing block markup (`<!-- wp:post-kinds-indieweb/checkin-card /-->`)
 * which produces visible output only when the renderer is server-side.
 *
 * @package
 */

/**
 * Save component for the Checkin Card block.
 *
 * @return {null} Null to defer rendering to render.php.
 */
export default function Save() {
	return null;
}
