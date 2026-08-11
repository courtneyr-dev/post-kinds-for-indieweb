<?php
/**
 * Standard.site reader
 *
 * Resolves a web page to the standard.site document record behind it, so a
 * bookmark, like, reply, or read kind can carry the author's own structured
 * metadata instead of whatever could be scraped from the page.
 *
 * Read-only and unauthenticated. Public AT Protocol repositories serve
 * com.atproto.repo.getRecord without credentials, so none of this needs an
 * account, an OAuth connection, or any third-party service.
 *
 * @package PKIW
 * @since   1.3.0
 * @link    https://standard.site/docs/verification/
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Standard.site reader class.
 *
 * @since 1.3.0
 */
class Standard_Site {

	/**
	 * Post meta holding a resolved document's AT-URI.
	 *
	 * @since 1.3.0
	 *
	 * @var string
	 */
	public const META_DOCUMENT_URI = '_pkiw_standard_site_uri';

	/**
	 * Transient prefix.
	 *
	 * @since 1.3.0
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'pkiw_ss_';

	/**
	 * How long a resolution is cached, hit or miss.
	 *
	 * Misses are cached too. Most of the web is not on AT Protocol, and
	 * without a negative cache every save of an unresolvable bookmark would
	 * re-fetch the target page.
	 *
	 * @since 1.3.0
	 *
	 * @var int
	 */
	private const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Largest page body to search for the link tag.
	 *
	 * The tag belongs in <head>, so there is no reason to read further into
	 * an arbitrary remote document.
	 *
	 * @since 1.3.0
	 *
	 * @var int
	 */
	private const MAX_HEAD_BYTES = 262144;

	/**
	 * Cron hook that resolves one post's cited URL.
	 *
	 * @since 1.3.0
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'pkiw_resolve_standard_site';

	/**
	 * Register hooks.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'save_post', [ __CLASS__, 'schedule_resolution' ], 20, 2 );
		add_action( self::CRON_HOOK, [ __CLASS__, 'resolve_post' ] );
	}

	/**
	 * Queue a post's cited URL for resolution.
	 *
	 * Resolution costs up to three HTTP requests, so it never runs during the
	 * save itself. The editor should not wait on someone else's PDS.
	 *
	 * @since 1.3.0
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    The post.
	 * @return void
	 */
	public static function schedule_resolution( $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$cite_url = get_post_meta( $post_id, '_pkiw_cite_url', true );
		if ( ! is_string( $cite_url ) || '' === $cite_url ) {
			return;
		}

		if ( wp_next_scheduled( self::CRON_HOOK, [ $post_id ] ) ) {
			return;
		}

		wp_schedule_single_event( time() + 10, self::CRON_HOOK, [ $post_id ] );
	}

	/**
	 * Resolve a post's cited URL and store the AT-URI it points at.
	 *
	 * Stores nothing when the target has no record, or when the record fails
	 * the backlink check: an unverified claim is worse than no claim.
	 *
	 * @since 1.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function resolve_post( $post_id ): void {
		$post_id  = (int) $post_id;
		$cite_url = get_post_meta( $post_id, '_pkiw_cite_url', true );

		if ( ! is_string( $cite_url ) || '' === $cite_url ) {
			return;
		}

		$result = self::resolve_url( $cite_url );

		if ( null === $result || empty( $result['verified'] ) ) {
			delete_post_meta( $post_id, self::META_DOCUMENT_URI );
			return;
		}

		update_post_meta( $post_id, self::META_DOCUMENT_URI, $result['uri'] );

		/**
		 * Fires after a cited URL resolves to a verified document record.
		 *
		 * @since 1.3.0
		 *
		 * @param int   $post_id Post ID.
		 * @param array $result  The resolution result.
		 */
		do_action( 'pkiw_standard_site_resolved', $post_id, $result );
	}

	/**
	 * Resolve a URL to its standard.site document record.
	 *
	 * @since 1.3.0
	 *
	 * @param string $url The page to resolve.
	 * @return array|null {
	 *     Resolution result, or null when the page has no document record.
	 *
	 *     @type string $uri      The document's AT-URI.
	 *     @type string $did      The authoring DID.
	 *     @type array  $record   The site.standard.document record value.
	 *     @type bool   $verified Whether the record points back at this URL.
	 * }
	 */
	public static function resolve_url( string $url ): ?array {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return null;
		}

		$cache_key = self::CACHE_PREFIX . md5( $url );
		$cached    = get_transient( $cache_key );

		// A cached miss is stored as the string 'none' so it can be told
		// apart from "nothing cached".
		if ( 'none' === $cached ) {
			return null;
		}
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = self::resolve_url_uncached( $url );

		set_transient( $cache_key, null === $result ? 'none' : $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * The AT-URI a post's cited URL resolved to, if any.
	 *
	 * @since 1.3.0
	 *
	 * @param int $post_id Post ID.
	 * @return string|null The AT-URI, or null.
	 */
	public static function get_post_document_uri( int $post_id ): ?string {
		$uri = get_post_meta( $post_id, self::META_DOCUMENT_URI, true );

		return is_string( $uri ) && '' !== $uri ? $uri : null;
	}

	/**
	 * Do the work behind resolve_url().
	 *
	 * @since 1.3.0
	 *
	 * @param string $url The page to resolve.
	 * @return array|null Resolution result, or null.
	 */
	private static function resolve_url_uncached( string $url ): ?array {
		$at_uri = self::discover_at_uri( $url );
		if ( null === $at_uri ) {
			return null;
		}

		$parts = self::parse_at_uri( $at_uri );
		if ( null === $parts || 'site.standard.document' !== $parts['collection'] ) {
			return null;
		}

		$pds = self::resolve_pds( $parts['did'] );
		if ( null === $pds ) {
			return null;
		}

		$record = self::fetch_record( $pds, $parts['did'], $parts['collection'], $parts['rkey'] );
		if ( null === $record ) {
			return null;
		}

		$backlink = self::check_backlink( $record, $url, $pds );

		return [
			'uri'         => $at_uri,
			'did'         => $parts['did'],
			'record'      => $record,
			'publication' => $backlink['publication'],
			'verified'    => $backlink['verified'],
		];
	}

	/**
	 * Resolve a site's publication record from its domain.
	 *
	 * Uses the `.well-known` endpoint, which is the authoritative half of
	 * standard.site verification. The `<link rel="site.standard.publication">`
	 * tag a page may also carry is documented as a discovery hint only, so it
	 * is deliberately not consulted here.
	 *
	 * @since 1.3.0
	 *
	 * @param string $url Any URL on the site, or the site root.
	 * @return array|null {
	 *     Publication, or null when the site publishes none.
	 *
	 *     @type string $uri    The publication's AT-URI.
	 *     @type string $did    The owning DID.
	 *     @type array  $record The site.standard.publication record value.
	 * }
	 */
	public static function resolve_publication( string $url ): ?array {
		$parts = wp_parse_url( esc_url_raw( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$origin    = 'https://' . $parts['host'];
		$cache_key = self::CACHE_PREFIX . 'pub_' . md5( $origin );
		$cached    = get_transient( $cache_key );

		if ( 'none' === $cached ) {
			return null;
		}
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = self::resolve_publication_uncached( $origin );

		set_transient( $cache_key, null === $result ? 'none' : $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Do the work behind resolve_publication().
	 *
	 * @since 1.3.0
	 *
	 * @param string $origin Scheme and host, no trailing slash.
	 * @return array|null Publication, or null.
	 */
	private static function resolve_publication_uncached( string $origin ): ?array {
		$body = self::remote_get_body( $origin . '/.well-known/site.standard.publication', 2048 );
		if ( null === $body ) {
			return null;
		}

		$at_uri = trim( $body );
		$parts  = self::parse_at_uri( $at_uri );

		if ( null === $parts || 'site.standard.publication' !== $parts['collection'] ) {
			return null;
		}

		$pds = self::resolve_pds( $parts['did'] );
		if ( null === $pds ) {
			return null;
		}

		$record = self::fetch_record( $pds, $parts['did'], $parts['collection'], $parts['rkey'] );
		if ( null === $record ) {
			return null;
		}

		return [
			'uri'    => $at_uri,
			'did'    => $parts['did'],
			'record' => $record,
		];
	}

	/**
	 * Read a page's document AT-URI from its verification link tag.
	 *
	 * @since 1.3.0
	 *
	 * @param string $url The page to read.
	 * @return string|null The AT-URI, or null when the tag is absent.
	 */
	private static function discover_at_uri( string $url ): ?string {
		$body = self::remote_get_body( $url, self::MAX_HEAD_BYTES );
		if ( null === $body ) {
			return null;
		}

		// Stop at </head>; the tag is not valid outside it and the rest of a
		// page can quote an at:// URI in ordinary prose.
		$head_end = stripos( $body, '</head>' );
		if ( false !== $head_end ) {
			$body = substr( $body, 0, $head_end );
		}

		if ( ! preg_match_all( '/<link\b[^>]*>/i', $body, $tags ) ) {
			return null;
		}

		foreach ( $tags[0] as $tag ) {
			if ( ! preg_match( '/\brel\s*=\s*["\']?site\.standard\.document["\']?/i', $tag ) ) {
				continue;
			}
			if ( preg_match( '/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $href ) ) {
				return trim( html_entity_decode( $href[1], ENT_QUOTES, 'UTF-8' ) );
			}
		}

		return null;
	}

	/**
	 * Split an AT-URI into its DID, collection, and record key.
	 *
	 * Deliberately strict. These values are interpolated into request URLs,
	 * and they arrive from a third-party web page.
	 *
	 * @since 1.3.0
	 *
	 * @param string $uri The AT-URI.
	 * @return array{did: string, collection: string, rkey: string}|null Parts, or null when malformed.
	 */
	private static function parse_at_uri( string $uri ): ?array {
		$pattern = '#^at://(did:(?:plc:[a-z2-7]{24}|web:[a-z0-9._%-]+))/([a-zA-Z0-9.]+)/([a-zA-Z0-9._~-]{1,512})$#';

		if ( ! preg_match( $pattern, $uri, $m ) ) {
			return null;
		}

		return [
			'did'        => $m[1],
			'collection' => $m[2],
			'rkey'       => $m[3],
		];
	}

	/**
	 * Find the PDS endpoint serving a DID's repository.
	 *
	 * @since 1.3.0
	 *
	 * @param string $did The DID.
	 * @return string|null The PDS base URL, or null.
	 */
	private static function resolve_pds( string $did ): ?string {
		$cache_key = self::CACHE_PREFIX . 'pds_' . md5( $did );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return 'none' === $cached ? null : $cached;
		}

		if ( str_starts_with( $did, 'did:plc:' ) ) {
			$doc_url = 'https://plc.directory/' . rawurlencode( $did );
		} elseif ( str_starts_with( $did, 'did:web:' ) ) {
			$host = substr( $did, strlen( 'did:web:' ) );
			// did:web percent-encodes the host; ports use %3A.
			$host    = rawurldecode( $host );
			$doc_url = 'https://' . $host . '/.well-known/did.json';
		} else {
			return null;
		}

		$doc = self::remote_get_json( $doc_url );

		$endpoint = null;
		if ( is_array( $doc ) && isset( $doc['service'] ) && is_array( $doc['service'] ) ) {
			foreach ( $doc['service'] as $service ) {
				if ( ! is_array( $service ) ) {
					continue;
				}
				if ( ( $service['type'] ?? '' ) === 'AtprotoPersonalDataServer'
					&& ! empty( $service['serviceEndpoint'] ) ) {
					$candidate = esc_url_raw( (string) $service['serviceEndpoint'] );
					if ( '' !== $candidate && str_starts_with( $candidate, 'https://' ) ) {
						$endpoint = untrailingslashit( $candidate );
					}
					break;
				}
			}
		}

		set_transient( $cache_key, null === $endpoint ? 'none' : $endpoint, self::CACHE_TTL );

		return $endpoint;
	}

	/**
	 * Fetch a record from a repository.
	 *
	 * @since 1.3.0
	 *
	 * @param string $pds        PDS base URL.
	 * @param string $did        Repository DID.
	 * @param string $collection Collection NSID.
	 * @param string $rkey       Record key.
	 * @return array|null The record value, or null.
	 */
	private static function fetch_record( string $pds, string $did, string $collection, string $rkey ): ?array {
		// Built directly rather than with add_query_arg(), which does not
		// encode its values. Pre-encoding and then handing it to a function
		// that may or may not encode again is how you get did%253Aplc%253A.
		$query = http_build_query(
			[
				'repo'       => $did,
				'collection' => $collection,
				'rkey'       => $rkey,
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		$url = $pds . '/xrpc/com.atproto.repo.getRecord?' . $query;

		$data = self::remote_get_json( $url );

		if ( ! is_array( $data ) || ! isset( $data['value'] ) || ! is_array( $data['value'] ) ) {
			return null;
		}

		return $data['value'];
	}

	/**
	 * Check that a document record points back at the URL it was found on.
	 *
	 * The link tag is the page asserting "I am this record." Nothing stops a
	 * page asserting somebody else's record, so the claim is only trustworthy
	 * once the record agrees: its site plus path has to reconstruct to the
	 * URL we started from.
	 *
	 * A record whose site is an at:// publication needs that publication
	 * fetched to learn its base url, which is one more request and is why the
	 * result is cached with the resolution.
	 *
	 * @since 1.3.0
	 *
	 * @param array  $record The document record.
	 * @param string $url    The URL the record was discovered on.
	 * @param string $pds    PDS serving the author's repository.
	 * @return array{verified: bool, publication: array|null} The verdict, and the
	 *                                                        publication if one was fetched.
	 */
	private static function check_backlink( array $record, string $url, string $pds ): array {
		$fail = [
			'verified'    => false,
			'publication' => null,
		];

		$site = isset( $record['site'] ) ? (string) $record['site'] : '';
		$path = isset( $record['path'] ) ? (string) $record['path'] : '';

		if ( '' === $site ) {
			return $fail;
		}

		$base        = '';
		$publication = null;

		if ( str_starts_with( $site, 'at://' ) ) {
			$pub_parts = self::parse_at_uri( $site );
			if ( null === $pub_parts || 'site.standard.publication' !== $pub_parts['collection'] ) {
				return $fail;
			}

			$record_value = self::fetch_record(
				$pds,
				$pub_parts['did'],
				$pub_parts['collection'],
				$pub_parts['rkey']
			);

			if ( null === $record_value || empty( $record_value['url'] ) ) {
				return $fail;
			}

			$publication = [
				'uri'    => $site,
				'did'    => $pub_parts['did'],
				'record' => $record_value,
			];

			$base = (string) $record_value['url'];
		} elseif ( str_starts_with( $site, 'https://' ) ) {
			// A loose document names its site directly.
			$base = $site;
		} else {
			return $fail;
		}

		$reconstructed = untrailingslashit( $base ) . $path;

		return [
			'verified'    => self::same_url( $reconstructed, $url ),
			'publication' => $publication,
		];
	}

	/**
	 * Compare two URLs, ignoring differences that do not change the resource.
	 *
	 * @since 1.3.0
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 * @return bool True when they address the same resource.
	 */
	private static function same_url( string $a, string $b ): bool {
		$normalize = static function ( string $url ): string {
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				return '';
			}

			$host = strtolower( $parts['host'] );
			$host = preg_replace( '/^www\./', '', $host );
			$path = untrailingslashit( $parts['path'] ?? '' );

			// Scheme is deliberately excluded: a record may say https while
			// the citation was captured as http, and that is the same page.
			return $host . $path;
		};

		$left  = $normalize( $a );
		$right = $normalize( $b );

		return '' !== $left && $left === $right;
	}

	/**
	 * GET a URL and return its body.
	 *
	 * Uses wp_safe_remote_get so the request cannot be pointed at the host's
	 * own network. These URLs come from post meta a user typed, and from a
	 * DID document served by a third party.
	 *
	 * @since 1.3.0
	 *
	 * @param string $url       URL to fetch.
	 * @param int    $max_bytes Largest body to return.
	 * @return string|null The body, or null on failure.
	 */
	private static function remote_get_body( string $url, int $max_bytes ): ?string {
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'     => 10,
				'redirection' => 3,
				'user-agent'  => 'PostKindsForIndieWeb/' . ( defined( 'PKIW_VERSION' ) ? PKIW_VERSION : '1.0' ) . '; ' . home_url(),
				'headers'     => [ 'Accept' => 'text/html,application/json;q=0.9,*/*;q=0.8' ],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			return null;
		}

		return strlen( $body ) > $max_bytes ? substr( $body, 0, $max_bytes ) : $body;
	}

	/**
	 * GET a URL and decode its JSON body.
	 *
	 * @since 1.3.0
	 *
	 * @param string $url URL to fetch.
	 * @return array|null Decoded JSON, or null.
	 */
	private static function remote_get_json( string $url ): ?array {
		$body = self::remote_get_body( $url, self::MAX_HEAD_BYTES );
		if ( null === $body ) {
			return null;
		}

		$data = json_decode( $body, true );

		return is_array( $data ) ? $data : null;
	}
}
