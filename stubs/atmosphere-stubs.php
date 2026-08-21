<?php
/**
 * PHPStan discovery stubs for the ATmosphere plugin.
 *
 * Signatures only, mirroring ATmosphere 2.1.0's public surface that the
 * integration references (see includes/integrations/class-atmosphere*.php).
 * Never loaded at runtime and never shipped — stubs/ is excluded from the
 * distribution; the real symbols come from the ATmosphere plugin.
 *
 * @package PKIW
 */

// phpcs:ignoreFile -- Analyzer stubs, not runtime code.

namespace {
	define( 'ATMOSPHERE_VERSION', '2.1.0' );
	define( 'ATMOSPHERE_META_DISABLED', 'atmosphere_disabled' );
}

namespace Atmosphere {
	/**
	 * Whether an AT Protocol account is connected and usable for writes.
	 *
	 * @return bool
	 */
	function is_connected(): bool {
		return false;
	}

	/**
	 * Whether the connection requires reauthorization.
	 *
	 * @return bool
	 */
	function needs_reauth(): bool {
		return false;
	}

	/**
	 * URL of ATmosphere's settings screen.
	 *
	 * @return string
	 */
	function settings_url(): string {
		return '';
	}
}

namespace Atmosphere\Transformer {
	/**
	 * Standard.site document transformer (stub).
	 */
	class Document {
		public const META_TID = '_atmosphere_doc_tid';
		public const META_URI = '_atmosphere_doc_uri';
		public const META_CID = '_atmosphere_doc_cid';
	}

	/**
	 * Bluesky post transformer (stub).
	 */
	class Post {
		public const META_TID = '_atmosphere_bsky_tid';
		public const META_URI = '_atmosphere_bsky_uri';
	}
}
