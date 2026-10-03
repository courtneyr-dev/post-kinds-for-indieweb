<?php
/**
 * Kind lookup coverage for the AI enhancement REST handlers.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\AI_Enhancements;

/**
 * The suggest-tags and content-summary handlers resolve the post's kind
 * before building a prompt. Before #283 they called the Taxonomy instance
 * method get_post_kind() statically (Error on PHP 8) and then treated the
 * returned WP_Term as a slug string (TypeError under strict_types).
 *
 * Prompts are stopped with core's wp_ai_client_prevent_prompt filter, so
 * no provider or network is involved and the handler's own result is what
 * the test sees.
 *
 * @group integration
 */
final class AiEnhancementsKindTest extends WP_UnitTestCase {

	/**
	 * Block every AI prompt and give the handlers a logged-in user.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter( 'wp_ai_client_prevent_prompt', '__return_true' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * Remove the prompt block.
	 */
	public function tear_down(): void {
		remove_filter( 'wp_ai_client_prevent_prompt', '__return_true' );
		parent::tear_down();
	}

	/**
	 * Create a post with an optional kind.
	 *
	 * @param string|null $kind Kind slug, or null for no kind.
	 */
	private function make_post( ?string $kind ): int {
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		if ( null !== $kind ) {
			$this->assertNotWPError( wp_set_object_terms( $post_id, $kind, 'kind' ) );
		}
		return $post_id;
	}

	/**
	 * Build a REST request carrying post_id.
	 *
	 * @param int $post_id Post ID.
	 */
	private function request( int $post_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/post-kinds-indieweb/v1/ai' );
		$request->set_param( 'post_id', $post_id );
		return $request;
	}

	/**
	 * A read post passes the kind gate and reaches the (blocked) AI call.
	 */
	public function test_content_summary_read_post_reaches_ai_request(): void {
		$result = AI_Enhancements::get_instance()->handle_content_summary( $this->request( $this->make_post( 'read' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'prompt_prevented', $result->get_error_code() );
	}

	/**
	 * A like post is rejected by the kind gate before any AI call.
	 */
	public function test_content_summary_rejects_unsupported_kind(): void {
		$result = AI_Enhancements::get_instance()->handle_content_summary( $this->request( $this->make_post( 'like' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'pkiw_invalid_kind', $result->get_error_code() );
	}

	/**
	 * Suggest-tags on a kind post reaches the (blocked) AI call.
	 */
	public function test_suggest_tags_kind_post_reaches_ai_request(): void {
		$result = AI_Enhancements::get_instance()->handle_suggest_tags( $this->request( $this->make_post( 'listen' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'prompt_prevented', $result->get_error_code() );
	}

	/**
	 * Suggest-tags on a post with no kind reaches the (blocked) AI call.
	 */
	public function test_suggest_tags_post_without_kind_reaches_ai_request(): void {
		$result = AI_Enhancements::get_instance()->handle_suggest_tags( $this->request( $this->make_post( null ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'prompt_prevented', $result->get_error_code() );
	}

	/**
	 * Read the text a prompt builder holds.
	 *
	 * Core has no getter for a builder's messages, so this reads the wrapped
	 * PHP AI Client builder's own state.
	 *
	 * @param WP_AI_Client_Prompt_Builder $builder Builder core passed to the filter.
	 */
	private function prompt_text( WP_AI_Client_Prompt_Builder $builder ): string {
		$inner    = ( new ReflectionProperty( $builder, 'builder' ) )->getValue( $builder );
		$messages = ( new ReflectionProperty( $inner, 'messages' ) )->getValue( $inner );

		$text = '';
		foreach ( $messages as $message ) {
			foreach ( $message->getParts() as $part ) {
				$text .= (string) $part->getText();
			}
		}
		return $text;
	}

	/**
	 * The prompt a handler builds is the prompt the AI client receives.
	 *
	 * ai_request() used to create the builder with no prompt and pass the
	 * prompt to generate_text(), which takes no arguments. PHP dropped the
	 * argument, so core rejected every request as an empty prompt.
	 */
	public function test_content_summary_sends_its_prompt_to_the_ai_client(): void {
		$builder = null;
		$capture = static function ( $prevent, $prompt_builder ) use ( &$builder ) {
			$builder = $prompt_builder;
			return $prevent;
		};
		add_filter( 'wp_ai_client_prevent_prompt', $capture, 10, 2 );

		AI_Enhancements::get_instance()->handle_content_summary( $this->request( $this->make_post( 'read' ) ) );

		remove_filter( 'wp_ai_client_prevent_prompt', $capture, 10 );

		$this->assertInstanceOf( WP_AI_Client_Prompt_Builder::class, $builder );
		$this->assertStringStartsWith( 'This is a read post about: ', $this->prompt_text( $builder ) );
	}
}
