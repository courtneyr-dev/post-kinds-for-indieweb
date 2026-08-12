<?php
/**
 * Manual end-to-end check for the Standard_Site reader.
 *
 * Runs the resolver against live URLs outside WordPress, stubbing only the
 * WordPress functions it calls. Unit tests mock the network; this one does
 * not, which is the point: it proves the resolver works against records real
 * publishers actually wrote.
 *
 * Usage: php tests/manual/standard-site-resolve.php [url]
 *
 * @package PKIW
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'PKIW_VERSION', 'manual-test' );
define( 'ENT_QUOTES_COMPAT', ENT_QUOTES );

$GLOBALS['pkiw_transients'] = [];

function esc_url_raw( string $url ): string {
	$url = trim( $url );
	return preg_match( '#^https?://#i', $url ) ? $url : '';
}
function get_transient( string $k ) {
	return $GLOBALS['pkiw_transients'][ $k ] ?? false;
}
function set_transient( string $k, $v, int $t ): bool {
	$GLOBALS['pkiw_transients'][ $k ] = $v;
	return true;
}
function untrailingslashit( string $s ): string {
	return rtrim( $s, '/' );
}
function home_url(): string {
	return 'https://example.test';
}
function wp_parse_url( string $url ) {
	return parse_url( $url );
}
function is_wp_error( $t ): bool {
	return $t instanceof WP_Error;
}
class WP_Error {
	public string $msg;
	public function __construct( string $m = '' ) {
		$this->msg = $m;
	}
}
function wp_remote_retrieve_response_code( array $r ): int {
	return $r['code'];
}
function wp_remote_retrieve_body( array $r ): string {
	return $r['body'];
}
function wp_safe_remote_get( string $url, array $args = [] ) {
	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		[
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => (int) ( $args['redirection'] ?? 3 ),
			CURLOPT_TIMEOUT        => (int) ( $args['timeout'] ?? 10 ),
			CURLOPT_USERAGENT      => (string) ( $args['user-agent'] ?? 'pkiw-test' ),
		]
	);
	$body = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );

	if ( false === $body ) {
		return new WP_Error( 'http_failed' );
	}

	return [
		'code' => $code,
		'body' => $body,
	];
}

require_once __DIR__ . '/../../includes/class-standard-site.php';

use PKIW\Standard_Site;

$targets = $argv[1] ?? null
	? [ $argv[1] ]
	: [
		// A real standard.site document.
		'https://notes.pckt.blog/blogging-its-more-social-than-ever-p9q1fky',
		// A page with no document record: should resolve to null, not error.
		'https://standard.site/docs/verification',
	];

foreach ( $targets as $url ) {
	echo "\n== $url\n";
	$start  = microtime( true );
	$result = Standard_Site::resolve_url( $url );
	$ms     = (int) ( ( microtime( true ) - $start ) * 1000 );

	if ( null === $result ) {
		echo "   no document record ({$ms}ms)\n";
		continue;
	}

	printf(
		"   uri      : %s\n   did      : %s\n   title    : %s\n   published: %s\n   tags     : %s\n   from     : %s\n   verified : %s  (%dms)\n",
		$result['uri'],
		$result['did'],
		$result['record']['title'] ?? '(none)',
		$result['record']['publishedAt'] ?? '(none)',
		implode( ', ', $result['record']['tags'] ?? [] ) ?: '(none)',
		$result['publication']['record']['name'] ?? '(loose document)',
		$result['verified'] ? 'YES' : 'no',
		$ms
	);
}

echo "\n== publication resolution from a bare domain\n";
foreach ( [ 'https://notes.pckt.blog', 'https://standard.site' ] as $site ) {
	$pub = Standard_Site::resolve_publication( $site );
	printf(
		"   %-28s -> %s\n",
		$site,
		null === $pub ? '(no publication)' : ( $pub['record']['name'] ?? '(unnamed)' ) . '  ' . $pub['uri']
	);
}

echo "\n== cache check (second call should be instant)\n";
$start = microtime( true );
Standard_Site::resolve_url( $targets[0] );
printf( "   %dms\n", (int) ( ( microtime( true ) - $start ) * 1000 ) );
