<?php
/**
 * Reactions → Webhooks shows Plex and Jellyfin setup that matches the routes.
 *
 * @package PKIW\Tests
 */

namespace PKIW\Tests\Integration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PKIW\Admin\Admin;
use PKIW\Admin\Webhooks_Page;
use PKIW\Plugin;
use PKIW\REST_API;
use WP_REST_Request;
use WPDieException;
use WP_UnitTestCase;

/**
 * Token setup and rotation on the Webhooks admin page.
 */
final class WebhooksPageTokenSetupTest extends WP_UnitTestCase {

	private const PLEX_TOKEN     = 'fake-plex-token-0123456789abcdef';
	private const JELLYFIN_TOKEN = 'fake-jellyfin-token-0123456789ab';

	/**
	 * Page under test.
	 *
	 * @var Webhooks_Page
	 */
	private Webhooks_Page $page;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		update_option( 'pkiw_webhook_token_plex', self::PLEX_TOKEN );
		update_option( 'pkiw_webhook_token_jellyfin', self::JELLYFIN_TOKEN );
		$this->page = new Webhooks_Page( new Admin( Plugin::get_instance() ) );
	}

	public function tear_down(): void {
		unset( $_POST['service'], $_POST['_wpnonce'], $_REQUEST['service'], $_REQUEST['_wpnonce'] );
		remove_all_filters( 'wp_redirect' );
		parent::tear_down();
	}

	public function test_plex_url_carries_token_and_is_masked(): void {
		$xpath = $this->render_page();
		$input = $this->element( $xpath, '//input[@id="pkiw-webhook-url-plex"]' );

		$this->assertSame( 'password', $input->getAttribute( 'type' ) );
		$this->assertSame( 'off', $input->getAttribute( 'autocomplete' ) );
		$this->assertTrue( $input->hasAttribute( 'readonly' ) );
		$this->assertSame( add_query_arg( 'token', rawurlencode( self::PLEX_TOKEN ), rest_url( 'post-kinds-indieweb/v1/webhook/plex' ) ), $input->getAttribute( 'value' ) );
		$this->assertStringContainsString( 'access logs', $this->text( $xpath, '//div[@data-webhook="plex"]' ) );
	}

	public function test_plex_url_shown_on_the_page_authorizes_a_delivery(): void {
		$xpath = $this->render_page();
		$url   = $this->element( $xpath, '//input[@id="pkiw-webhook-url-plex"]' )->getAttribute( 'value' );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		unset( $query['rest_route'] );

		$request = new WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/webhook/plex' );
		$request->set_query_params( $query );
		$request->set_body_params( [ 'payload' => '{"event":"media.pause"}' ] );

		$this->assertSame( 200, $this->dispatch_as_guest( $request ) );
	}

	public function test_plex_url_shown_on_the_page_encodes_reserved_characters_once(): void {
		$token = 'plex+token/with=reserved?chars';
		update_option( 'pkiw_webhook_token_plex', $token );

		$xpath = $this->render_page();
		$url   = $this->element( $xpath, '//input[@id="pkiw-webhook-url-plex"]' )->getAttribute( 'value' );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		unset( $query['rest_route'] );

		// add_query_arg() doesn't encode values, so the token must be encoded exactly once by the caller.
		$this->assertSame( add_query_arg( 'token', rawurlencode( $token ), rest_url( 'post-kinds-indieweb/v1/webhook/plex' ) ), $url );
		$this->assertSame( $token, $query['token'] );

		$request = new WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/webhook/plex' );
		$request->set_query_params( $query );
		$request->set_body_params( [ 'payload' => '{"event":"media.pause"}' ] );

		$this->assertSame( 200, $this->dispatch_as_guest( $request ) );
	}

	public function test_jellyfin_shows_plain_url_header_name_and_masked_token(): void {
		$xpath = $this->render_page();
		$url   = $this->element( $xpath, '//input[@id="pkiw-webhook-url-jellyfin"]' );
		$token = $this->element( $xpath, '//input[@id="pkiw-webhook-token-jellyfin"]' );

		$this->assertSame( 'text', $url->getAttribute( 'type' ) );
		$this->assertSame( rest_url( 'post-kinds-indieweb/v1/webhook/jellyfin' ), $url->getAttribute( 'value' ) );
		$this->assertSame( 'password', $token->getAttribute( 'type' ) );
		$this->assertSame( 'off', $token->getAttribute( 'autocomplete' ) );
		$this->assertSame( self::JELLYFIN_TOKEN, $token->getAttribute( 'value' ) );
		$this->assertStringContainsString( 'X-Webhook-Token', $this->text( $xpath, '//div[@data-webhook="jellyfin"]' ) );
		$this->assertSame( 1, $xpath->query( '//div[@data-webhook="jellyfin"]//p[contains(@class,"description")]//code[text()="X-Webhook-Token"]' )->length );
	}

	public function test_page_links_no_nonexistent_webhooks_route_and_no_token_leaks_outside_its_field(): void {
		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'post-kinds-indieweb/v1/webhooks/', $html );
		$this->assertStringContainsString( 'post-kinds-indieweb/v1/webhook/listenbrainz', $html );
		$this->assertSame( 1, substr_count( $html, self::JELLYFIN_TOKEN ) );
		$this->assertSame( 1, substr_count( $html, self::PLEX_TOKEN ) );
	}

	public function test_rotate_buttons_submit_nonce_protected_forms(): void {
		$xpath = $this->render_page();

		foreach ( [ 'plex', 'jellyfin' ] as $service ) {
			$form = $this->element( $xpath, '//form[@id="pkiw-rotate-token-' . $service . '"]' );
			$this->assertStringEndsWith( 'admin-post.php', $form->getAttribute( 'action' ) );
			$this->assertSame( 'pkiw_rotate_webhook_token', $this->element( $xpath, '//form[@id="pkiw-rotate-token-' . $service . '"]/input[@name="action"]' )->getAttribute( 'value' ) );
			$nonce = $this->element( $xpath, '//form[@id="pkiw-rotate-token-' . $service . '"]/input[@name="_wpnonce"]' )->getAttribute( 'value' );
			$this->assertSame( 1, wp_verify_nonce( $nonce, 'pkiw_rotate_webhook_token' ) );
			$this->assertSame( 'Rotate token', trim( $this->element( $xpath, '//button[@form="pkiw-rotate-token-' . $service . '"]' )->textContent ) );
		}
	}

	public function test_missing_token_offers_generate_without_creating_one(): void {
		delete_option( 'pkiw_webhook_token_plex' );

		$xpath = $this->render_page();

		$this->assertSame( 0, $xpath->query( '//input[@id="pkiw-webhook-url-plex"]' )->length );
		$this->assertSame( 'Generate token', trim( $this->element( $xpath, '//button[@form="pkiw-rotate-token-plex"]' )->textContent ) );
		$this->assertFalse( get_option( 'pkiw_webhook_token_plex' ) );
	}

	public function test_rotation_replaces_token_and_old_token_stops_authorizing(): void {
		$location = $this->rotate( 'plex', wp_create_nonce( 'pkiw_rotate_webhook_token' ) );

		$new = get_option( 'pkiw_webhook_token_plex' );
		$this->assertIsString( $new );
		$this->assertSame( 32, strlen( $new ) );
		$this->assertNotSame( self::PLEX_TOKEN, $new );
		$this->assertStringNotContainsString( $new, $location );
		$this->assertStringContainsString( 'page=post-kinds-indieweb-webhooks', $location );
		$this->assertSame( self::JELLYFIN_TOKEN, get_option( 'pkiw_webhook_token_jellyfin' ) );

		$old_request = new WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/webhook/plex' );
		$old_request->set_query_params( [ 'token' => self::PLEX_TOKEN ] );
		$old_request->set_body_params( [ 'payload' => '{"event":"media.pause"}' ] );
		$this->assertSame( 401, $this->dispatch_as_guest( $old_request ) );

		$new_request = new WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/webhook/plex' );
		$new_request->set_query_params( [ 'token' => $new ] );
		$new_request->set_body_params( [ 'payload' => '{"event":"media.pause"}' ] );
		$this->assertSame( 200, $this->dispatch_as_guest( $new_request ) );
	}

	public function test_rotation_notice_shows_once(): void {
		$this->rotate( 'jellyfin', wp_create_nonce( 'pkiw_rotate_webhook_token' ) );

		ob_start();
		$this->page->render();
		$first = (string) ob_get_clean();
		ob_start();
		$this->page->render();
		$second = (string) ob_get_clean();

		$this->assertStringContainsString( 'New Jellyfin webhook token saved.', $first );
		$this->assertStringNotContainsString( 'webhook token saved.', $second );
	}

	public function test_rotation_rejects_bad_nonce(): void {
		$this->expect_die_without_change( 'plex', 'not-a-valid-nonce' );
	}

	public function test_rotation_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->expect_die_without_change( 'plex', wp_create_nonce( 'pkiw_rotate_webhook_token' ) );
	}

	public function test_rotation_rejects_services_without_tokens(): void {
		$this->expect_die_without_change( 'listenbrainz', wp_create_nonce( 'pkiw_rotate_webhook_token' ) );
		$this->assertFalse( get_option( 'pkiw_webhook_token_listenbrainz' ) );
	}

	/**
	 * Run the admin-post handler and return the redirect location.
	 *
	 * @param string $service Service slug.
	 * @param string $nonce   Nonce value.
	 * @return string
	 */
	private function rotate( string $service, string $nonce ): string {
		$_POST['service']     = $service;
		$_REQUEST['service']  = $service;
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( (string) $location );
			}
		);

		try {
			$this->page->handle_rotate_token();
		} catch ( \RuntimeException $redirect ) {
			return $redirect->getMessage();
		}

		$this->fail( 'handle_rotate_token() did not redirect.' );
	}

	/**
	 * Assert the handler dies and leaves both tokens alone.
	 *
	 * @param string $service Service slug.
	 * @param string $nonce   Nonce value.
	 */
	private function expect_die_without_change( string $service, string $nonce ): void {
		try {
			$this->rotate( $service, $nonce );
			$this->fail( 'handle_rotate_token() should have died.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( self::PLEX_TOKEN, get_option( 'pkiw_webhook_token_plex' ) );
			$this->assertSame( self::JELLYFIN_TOKEN, get_option( 'pkiw_webhook_token_jellyfin' ) );
		}
	}

	/**
	 * Dispatch a request as a logged-out client and return the status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int
	 */
	private function dispatch_as_guest( WP_REST_Request $request ): int {
		$user = get_current_user_id();
		wp_set_current_user( 0 );

		$GLOBALS['wp_rest_server'] = null;
		$rest                      = new REST_API();
		add_action( 'rest_api_init', [ $rest, 'register_routes' ] );
		$status = rest_get_server()->dispatch( $request )->get_status();
		remove_action( 'rest_api_init', [ $rest, 'register_routes' ] );
		$GLOBALS['wp_rest_server'] = null;

		wp_set_current_user( $user );

		return $status;
	}

	/**
	 * Render the page and return an XPath over it.
	 *
	 * @return DOMXPath
	 */
	private function render_page(): DOMXPath {
		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();

		return new DOMXPath( $doc );
	}

	/**
	 * Return the single element matching a query.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @param string   $query Query.
	 * @return DOMElement
	 */
	private function element( DOMXPath $xpath, string $query ): DOMElement {
		$nodes = $xpath->query( $query );
		$this->assertSame( 1, $nodes->length, $query );
		$node = $nodes->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $node );

		return $node;
	}

	/**
	 * Text content of the single element matching a query.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @param string   $query Query.
	 * @return string
	 */
	private function text( DOMXPath $xpath, string $query ): string {
		return $this->element( $xpath, $query )->textContent;
	}
}
