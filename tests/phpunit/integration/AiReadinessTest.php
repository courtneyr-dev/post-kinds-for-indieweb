<?php
/**
 * AI readiness and typed suggestion outcomes.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ProviderRegistry;

/**
 * Every provider outcome (disabled, missing, blocked, malformed, success)
 * reports a readiness state, returns either typed suggestions or a fixed
 * error, and leaves the post's citation metadata and manual edits alone.
 *
 * The provider is PKIW_Fake_Text_Provider in a registry swapped in for the
 * test; a pre_http_request tripwire proves no request leaves the process.
 *
 * @group integration
 * @group ai
 */
final class AiReadinessTest extends WP_UnitTestCase {

	private const STATUS  = '/post-kinds/v1/ai/status';
	private const PREFILL = '/post-kinds/v1/ai/auto-populate';
	private const TAGS    = '/post-kinds/v1/ai/suggest-tags';
	private const SECRET  = 'sk-test-PKIW-DO-NOT-ECHO-1234';

	/**
	 * Registry the test swapped out.
	 *
	 * @var ProviderRegistry|null
	 */
	private ?ProviderRegistry $saved_registry = null;

	/**
	 * Registry under test.
	 *
	 * @var ProviderRegistry
	 */
	private ProviderRegistry $registry;

	/**
	 * Outbound HTTP attempts.
	 *
	 * @var int
	 */
	private int $http = 0;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private int $admin = 0;

	/**
	 * Post carrying citation metadata and manual edits.
	 *
	 * @var int
	 */
	private int $post_id = 0;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once dirname( __DIR__ ) . '/fixtures/ai/fake-text-provider.php';
	}

	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			$this->markTestSkipped( 'This WordPress has no AI Client.' );
		}

		PKIW_Fake_Text_Provider::reset();
		$this->http = 0;
		add_filter( 'pre_http_request', [ $this, 'block_http' ], PHP_INT_MAX );

		$property = new ReflectionProperty( AiClient::class, 'defaultRegistry' );
		$property->setAccessible( true );
		$this->saved_registry = $property->getValue();
		$this->registry       = new ProviderRegistry();
		$property->setValue( null, $this->registry );

		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );

		$this->post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_author'  => $this->admin,
				'post_title'   => 'My own title',
				'post_content' => '<!-- wp:paragraph --><p>Words I typed myself.</p><!-- /wp:paragraph -->',
			]
		);
		wp_set_object_terms( $this->post_id, 'bookmark', 'kind' );
		update_post_meta( $this->post_id, Meta_Fields::PREFIX . 'cite_url', 'https://example.org/article' );
		update_post_meta( $this->post_id, Meta_Fields::PREFIX . 'cite_name', 'Title I corrected by hand' );
		update_post_meta( $this->post_id, Meta_Fields::PREFIX . 'cite_published', '2026-11-01' );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'block_http' ], PHP_INT_MAX );
		remove_all_filters( 'wp_supports_ai' );
		remove_all_filters( 'wp_ai_client_prevent_prompt' );
		if ( null !== $this->saved_registry ) {
			$property = new ReflectionProperty( AiClient::class, 'defaultRegistry' );
			$property->setAccessible( true );
			$property->setValue( null, $this->saved_registry );
			$this->saved_registry = null;
		}
		delete_option( 'pkiw_enable_ai' );
		foreach ( [ 'auto_populate', 'suggest_tags', 'content_summary' ] as $action ) {
			delete_transient( 'pkiw_ai_rate_' . $action . '_' . $this->admin );
		}
		if ( class_exists( 'PKIW_Fake_Text_Provider' ) ) {
			PKIW_Fake_Text_Provider::reset();
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Count and refuse every outbound request.
	 *
	 * @return WP_Error
	 */
	public function block_http(): WP_Error {
		++$this->http;
		return new WP_Error( 'pkiw_test_offline', 'Network is off in this test.' );
	}

	/**
	 * Everything about the post a suggestion must never change.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot(): array {
		clean_post_cache( $this->post_id );
		wp_cache_delete( $this->post_id, 'post_meta' );

		return [
			'title'   => get_post_field( 'post_title', $this->post_id ),
			'content' => get_post_field( 'post_content', $this->post_id ),
			'meta'    => get_post_meta( $this->post_id ),
			'tags'    => wp_get_post_tags( $this->post_id, [ 'fields' => 'names' ] ),
		];
	}

	/**
	 * Readiness as the editor sees it.
	 *
	 * @return array<string, mixed>
	 */
	private function status(): array {
		$response = rest_do_request( new WP_REST_Request( 'GET', self::STATUS ) );
		$this->assertSame( 200, $response->get_status(), 'The status route answers in every state.' );

		return (array) $response->get_data();
	}

	/**
	 * Ask for citation suggestions.
	 */
	private function prefill(): WP_REST_Response {
		delete_transient( 'pkiw_ai_rate_auto_populate_' . $this->admin );
		$request = new WP_REST_Request( 'POST', self::PREFILL );
		$request->set_param( 'url', 'https://example.org/article' );

		return rest_do_request( $request );
	}

	/**
	 * Run one outcome and check the post survived it.
	 *
	 * @param string      $state       Expected readiness state.
	 * @param int         $http_status Expected suggestion status code.
	 * @param string      $code        Expected error code, or '' for success.
	 * @param string|null $request     Expected request outcome in the error, or null for the readiness state.
	 * @return WP_REST_Response Suggestion response.
	 */
	private function outcome( string $state, int $http_status, string $code, ?string $request = null ): WP_REST_Response {
		$before = $this->snapshot();

		$status = $this->status();
		$this->assertSame( $state, $status['state'] );
		$this->assertIsString( $status['message'] );
		$this->assertNotSame( '', $status['message'] );

		$response = $this->prefill();
		$this->assertSame( $http_status, $response->get_status() );
		$data = $response->get_data();
		if ( '' !== $code ) {
			$this->assertSame( $code, $data['code'] ?? null );
			$this->assertSame( $request ?? $state, $data['data']['state'] ?? null, 'The error names the request outcome.' );
			$this->assertArrayNotHasKey( 'suggestions', $data );
		}

		$this->assertSame( $before, $this->snapshot(), 'Citation metadata and manual edits survive the outcome.' );
		$this->assertSame( 0, $this->http, 'No request leaves the process.' );

		return $response;
	}

	public function test_disabled_reports_disabled_and_never_reaches_a_provider(): void {
		$this->registry->registerProvider( PKIW_Fake_Text_Provider::class );

		$status = $this->status();
		$this->assertSame( '', $status['connectorsUrl'], 'A disabled site points nowhere.' );
		$this->outcome( 'disabled', 503, 'pkiw_ai_disabled' );
		$this->assertSame( [], PKIW_Fake_Text_Provider::$calls );
	}

	public function test_missing_provider_links_admins_to_core_connectors(): void {
		update_option( 'pkiw_enable_ai', true );

		$this->assertSame( admin_url( 'options-connectors.php' ), $this->status()['connectorsUrl'] );
		$this->outcome( 'missing', 503, 'pkiw_ai_missing' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( '', $this->status()['connectorsUrl'], 'Only users who can open Connectors get the link.' );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function blockers(): array {
		return [
			'environment turns AI off' => [ 'wp_supports_ai' ],
			'a filter prevents prompts' => [ 'wp_ai_client_prevent_prompt' ],
		];
	}

	/**
	 * @dataProvider blockers
	 *
	 * @param string $filter Core filter that blocks AI.
	 */
	public function test_blocked_reports_blocked_without_generating( string $filter ): void {
		update_option( 'pkiw_enable_ai', true );
		$this->registry->registerProvider( PKIW_Fake_Text_Provider::class );
		add_filter( $filter, 'wp_supports_ai' === $filter ? '__return_false' : '__return_true' );

		$this->assertSame( '', $this->status()['connectorsUrl'] );
		$this->outcome( 'blocked', 503, 'pkiw_ai_blocked' );
		$this->assertSame( [], PKIW_Fake_Text_Provider::$calls );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function malformed_responses(): array {
		return [
			'not JSON'          => [ 'Here is your answer: title is Example' ],
			'wrong field type'  => [ '{"title":["Example"],"summary":"One","author":"Two"}' ],
			'missing field'     => [ '{"title":"Example","summary":"One"}' ],
			'unexpected field'  => [ '{"title":"Example","summary":"One","author":"Two","cite_url":"https://evil.example"}' ],
			'JSON list'         => [ '["Example","One","Two"]' ],
			'numeric-key object' => [ '{"0":"Example","1":"One","2":"Two"}' ],
			'oversized title'   => [ '{"title":"' . str_repeat( 'x', 400 ) . '","summary":"One","author":"Two"}' ],
		];
	}

	/**
	 * @dataProvider malformed_responses
	 *
	 * @param string $body Provider text.
	 */
	public function test_malformed_provider_response_suggests_nothing( string $body ): void {
		update_option( 'pkiw_enable_ai', true );
		$this->registry->registerProvider( PKIW_Fake_Text_Provider::class );
		PKIW_Fake_Text_Provider::$response = $body;

		$this->outcome( 'ready', 502, 'pkiw_ai_malformed', 'malformed' );
		$this->assertCount( 1, PKIW_Fake_Text_Provider::$calls );
	}

	public function test_success_returns_typed_deterministic_suggestions(): void {
		update_option( 'pkiw_enable_ai', true );
		$this->registry->registerProvider( PKIW_Fake_Text_Provider::class );
		PKIW_Fake_Text_Provider::$response = '{"author":"Ada Example","summary":"A short summary.","title":"Suggested title"}';

		$this->assertSame( '', $this->status()['connectorsUrl'] );
		$first  = $this->outcome( 'ready', 200, '' )->get_data();
		$second = $this->outcome( 'ready', 200, '' )->get_data();

		$expected = [
			'state'       => 'ready',
			'title'       => 'Suggested title',
			'summary'     => 'A short summary.',
			'author'      => 'Ada Example',
			'suggestions' => [
				[
					'field'  => 'cite_name',
					'value'  => 'Suggested title',
					'source' => 'ai',
				],
				[
					'field'  => 'cite_author',
					'value'  => 'Ada Example',
					'source' => 'ai',
				],
				[
					'field'  => 'cite_summary',
					'value'  => 'A short summary.',
					'source' => 'ai',
				],
			],
		];
		$this->assertSame( $expected, $first );
		$this->assertSame( $first, $second, 'The same answer gives the same suggestions in the same order.' );

		$schema = PKIW_Fake_Text_Provider::$calls[0];
		$this->assertIsArray( $schema, 'The provider is asked for a structured response.' );
		$this->assertSame( [ 'title', 'summary', 'author' ], $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}

	public function test_empty_suggested_values_are_left_out(): void {
		update_option( 'pkiw_enable_ai', true );
		$this->registry->registerProvider( PKIW_Fake_Text_Provider::class );
		PKIW_Fake_Text_Provider::$response = '{"title":"Suggested title","summary":"  ","author":""}';

		$data = $this->outcome( 'ready', 200, '' )->get_data();

		$this->assertSame( [ 'cite_name' ], array_column( $data['suggestions'], 'field' ) );
		$this->assertSame( [ 'Suggested title', '', '' ], [ $data['title'], $data['summary'], $data['author'] ], 'The legacy keys stay, empty when nothing came back.' );
	}

	public function test_provider_failure_never_echoes_provider_text(): void {
		update_option( 'pkiw_enable_ai', true );
		$this->registry->registerProvider( PKIW_Fake_Text_Provider::class );
		PKIW_Fake_Text_Provider::$throw = 'Upstream rejected key ' . self::SECRET;

		$response = $this->outcome( 'ready', 502, 'pkiw_ai_request_failed' );

		$this->assertStringNotContainsString( self::SECRET, (string) wp_json_encode( $response->get_data() ) );
		$this->assertStringNotContainsString( 'Upstream', (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_tag_suggestions_are_typed_and_validated(): void {
		update_option( 'pkiw_enable_ai', true );
		$this->registry->registerProvider( PKIW_Fake_Text_Provider::class );
		$before = $this->snapshot();

		PKIW_Fake_Text_Provider::$response = '{"tags":["indieweb","reading"]}';
		$request                           = new WP_REST_Request( 'POST', self::TAGS );
		$request->set_param( 'post_id', $this->post_id );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'state' => 'ready',
				'tags'  => [ 'indieweb', 'reading' ],
			],
			$response->get_data()
		);

		delete_transient( 'pkiw_ai_rate_suggest_tags_' . $this->admin );
		PKIW_Fake_Text_Provider::$response = '{"tags":"indieweb, reading"}';
		$response                          = rest_do_request( $request );
		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'pkiw_ai_malformed', $response->get_data()['code'] );

		foreach ( [ '{"tags":{"0":"indieweb","1":"reading"}}', '{"tags":{}}' ] as $object ) {
			delete_transient( 'pkiw_ai_rate_suggest_tags_' . $this->admin );
			PKIW_Fake_Text_Provider::$response = $object;
			$response                          = rest_do_request( $request );
			$this->assertSame( 502, $response->get_status(), 'A JSON object is not a list of tags: ' . $object );
			$this->assertSame( 'pkiw_ai_malformed', $response->get_data()['code'] );
		}

		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( 0, $this->http );
	}

	public function test_status_needs_edit_posts_and_suggestions_need_the_post(): void {
		update_option( 'pkiw_enable_ai', true );
		$this->registry->registerProvider( PKIW_Fake_Text_Provider::class );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', self::STATUS ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$request = new WP_REST_Request( 'POST', self::TAGS );
		$request->set_param( 'post_id', $this->post_id );
		$this->assertSame( 403, rest_do_request( $request )->get_status(), 'An author cannot ask about another user\'s post.' );
		$this->assertSame( [], PKIW_Fake_Text_Provider::$calls );
	}

	public function test_prefill_route_publishes_its_response_schema(): void {
		$options = rest_get_server()->get_route_options( self::PREFILL );
		$this->assertIsCallable( $options['schema'] ?? null );
		$schema = call_user_func( $options['schema'] );

		$this->assertSame( [ 'cite_name', 'cite_author', 'cite_summary' ], $schema['properties']['suggestions']['items']['properties']['field']['enum'] ?? null );
		$this->assertSame( [ 'ai' ], $schema['properties']['suggestions']['items']['properties']['source']['enum'] ?? null );
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public function bad_urls(): array {
		return [
			'array'      => [ [ 'https://example.org/' ] ],
			'object'     => [ [ 'href' => 'https://example.org/' ] ],
			'integer'    => [ 42 ],
			'boolean'    => [ true ],
			'ftp scheme' => [ 'ftp://example.org/file' ],
			'javascript' => [ 'javascript:alert(1)' ],
			'no scheme'  => [ 'example.org/article' ],
		];
	}

	/**
	 * A URL that isn't an http(s) string is a 400 before any permission or
	 * AI check, even for a logged-out visitor on a site with AI off.
	 *
	 * @dataProvider bad_urls
	 *
	 * @param mixed $url URL parameter.
	 */
	public function test_non_http_url_is_a_rest_400_before_permissions( $url ): void {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', self::PREFILL );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'url' => $url ] ) );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] ?? null );
		$this->assertSame( [], PKIW_Fake_Text_Provider::$calls );
	}

	public function test_auto_populate_schema_keeps_the_legacy_keys(): void {
		$options = rest_get_server()->get_route_options( self::PREFILL );
		$schema  = call_user_func( $options['schema'] );

		foreach ( [ 'title', 'summary', 'author' ] as $key ) {
			$this->assertSame( 'string', $schema['properties'][ $key ]['type'] ?? null, $key . ' stays in the response.' );
		}
	}
}
