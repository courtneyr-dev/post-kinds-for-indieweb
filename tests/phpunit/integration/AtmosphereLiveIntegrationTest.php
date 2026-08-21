<?php
/**
 * Both-plugins integration tests against a real ATmosphere checkout.
 *
 * Runs only when the test bootstrap loaded ATmosphere (set
 * PKIW_TESTS_ATMOSPHERE_FILE to a checkout's atmosphere.php). Exercises
 * the real Document transformer, the real is_post_publishable() gate,
 * a full Publisher::publish_post() cycle with the network short-circuited
 * through ATmosphere's atmosphere_pre_apply_writes seam, and the head
 * verification tags — proving the enrichment and eligibility layers work
 * against ATmosphere's actual code, not this plugin's assumptions.
 *
 * @package PKIW
 * @group   atmosphere
 */

namespace PKIW\Tests\Integration;

use PKIW\Integrations\Atmosphere;
use PKIW\Meta_Fields;

/**
 * Live ATmosphere integration tests.
 *
 * @group atmosphere
 */
class AtmosphereLiveIntegrationTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'ATMOSPHERE_VERSION' ) ) {
			$this->markTestSkipped( 'Set PKIW_TESTS_ATMOSPHERE_FILE to run the both-plugins suite.' );
		}

		// The WP test framework unregisters ALL meta keys between tests,
		// so ATmosphere's init-time registration only survives the first
		// test in a process. Re-register as a production request would;
		// without this, REST meta writes silently no-op from the second
		// test on (found the hard way — see the implementation record).
		if ( ! registered_meta_key_exists( 'post', 'atmosphere_disabled', 'post' ) ) {
			( new \Atmosphere\Atmosphere() )->register_share_meta();
		}
	}

	public function tear_down(): void {
		remove_all_filters( 'atmosphere_pre_apply_writes' );
		delete_option( 'atmosphere_connection' );
		delete_option( 'atmosphere_identity' );
		delete_option( 'atmosphere_did' );
		delete_option( 'atmosphere_publication_tid' );
		delete_option( 'atmosphere_publication_cid' );
		parent::tear_down();
	}

	/**
	 * Create a post of a given kind.
	 *
	 * @param string               $kind Kind slug.
	 * @param array<string, mixed> $meta Meta suffix => value.
	 * @param array<string, mixed> $args Post args.
	 * @return \WP_Post
	 */
	private function make_kind_post( string $kind, array $meta = [], array $args = [] ): \WP_Post {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_title'  => '',
					'post_status' => 'publish',
				],
				$args
			)
		);
		wp_set_object_terms( $post_id, $kind, 'kind' );

		foreach ( $meta as $suffix => $value ) {
			update_post_meta( $post_id, Meta_Fields::PREFIX . $suffix, $value );
		}

		return get_post( $post_id );
	}

	/**
	 * Seed a fake connected state the way ATmosphere's own tests do.
	 *
	 * @return void
	 */
	private function seed_connection(): void {
		update_option(
			'atmosphere_connection',
			[
				'access_token' => \Atmosphere\OAuth\Encryption::encrypt( 'test-token' ),
				'did'          => 'did:plc:pkiwtest123',
				'pds_endpoint' => 'https://pds.example.test',
				'dpop_jwk'     => \Atmosphere\OAuth\Encryption::encrypt( (string) wp_json_encode( \Atmosphere\OAuth\DPoP::generate_key() ) ),
				'expires_at'   => time() + HOUR_IN_SECONDS,
			]
		);
	}

	public function test_integration_literals_match_atmosphere_constants() {
		$this->assertSame( 'atmosphere_disabled', ATMOSPHERE_META_DISABLED );
		$this->assertSame( '_atmosphere_doc_uri', \Atmosphere\Transformer\Document::META_URI );
		$this->assertSame( '_atmosphere_bsky_tid', \Atmosphere\Transformer\Post::META_TID );
		$this->assertTrue(
			version_compare( ATMOSPHERE_VERSION, Atmosphere::MIN_VERSION, '>=' ),
			'the checkout under test is older than the integration minimum'
		);
	}

	public function test_untitled_listen_document_record_is_enriched() {
		$post = $this->make_kind_post(
			'listen',
			[
				'listen_track'  => 'Range Life',
				'listen_artist' => 'Pavement',
			]
		);

		$record = ( new \Atmosphere\Transformer\Document( $post ) )->transform();

		$this->assertSame( 'site.standard.document', $record['$type'] );
		$this->assertSame( 'Listened to Range Life by Pavement', $record['title'] );
		$this->assertContains( 'listen', $record['tags'] );
		$this->assertArrayHasKey( 'publishedAt', $record );
		// No publication record in this environment: standalone-site form.
		$this->assertSame( untrailingslashit( get_home_url() ), $record['site'] );
	}

	public function test_kind_defaults_gate_atmosphere_eligibility() {
		$checkin = $this->make_kind_post( 'checkin', [ 'checkin_name' => 'Somewhere' ] );
		$listen  = $this->make_kind_post( 'listen', [ 'listen_track' => 'Song' ] );
		$note    = $this->make_kind_post( 'note', [], [ 'post_content' => 'A note.' ] );

		$this->assertFalse( \Atmosphere\is_post_publishable( $checkin ), 'privacy-sensitive kinds default to opt-in' );
		$this->assertTrue( \Atmosphere\is_post_publishable( $listen ), 'public consumption logs default to eligible' );
		$this->assertTrue( \Atmosphere\is_post_publishable( $note ), 'content kinds default to eligible' );

		// The author's explicit toggle wins over the kind default.
		update_post_meta( $checkin->ID, ATMOSPHERE_META_DISABLED, '' );
		$this->assertTrue( \Atmosphere\is_post_publishable( get_post( $checkin->ID ) ) );
	}

	public function test_publish_writes_the_enriched_record_and_document_meta() {
		$this->seed_connection();

		$post = $this->make_kind_post(
			'review',
			[ 'review_item_name' => 'Middlemarch' ],
			[ 'post_content' => 'A review body.' ]
		);

		$captured = [];
		add_filter(
			'atmosphere_pre_apply_writes',
			static function ( $short_circuit, array $writes ) use ( &$captured ) {
				$captured = $writes;
				$results  = [];
				foreach ( $writes as $write ) {
					$results[] = [
						'uri' => sprintf( 'at://did:plc:pkiwtest123/%s/%s', $write['collection'] ?? '', $write['rkey'] ?? '' ),
						'cid' => 'bafyreib' . substr( md5( (string) wp_json_encode( $write['value'] ?? [] ) ), 0, 20 ),
					];
				}

				return [ 'results' => $results ];
			},
			10,
			2
		);

		$result = \Atmosphere\Publisher::publish_post( $post );

		$this->assertNotWPError( $result );
		$this->assertNotEmpty( $captured, 'no writes reached the applyWrites seam' );

		$document = null;
		foreach ( $captured as $write ) {
			if ( 'site.standard.document' === ( $write['collection'] ?? '' ) ) {
				$document = $write['value'];
			}
		}

		$this->assertIsArray( $document, 'no site.standard.document write in the batch' );
		$this->assertSame( 'Review: Middlemarch', $document['title'] );
		$this->assertContains( 'review', $document['tags'] );

		$doc_uri = (string) get_post_meta( $post->ID, \Atmosphere\Transformer\Document::META_URI, true );
		$this->assertStringStartsWith( 'at://did:plc:pkiwtest123/site.standard.document/', $doc_uri );
	}

	public function test_document_link_tag_is_emitted_exactly_once() {
		$this->seed_connection();

		$post = $this->make_kind_post( 'note', [], [ 'post_content' => 'A note.' ] );
		update_post_meta(
			$post->ID,
			\Atmosphere\Transformer\Document::META_URI,
			'at://did:plc:pkiwtest123/site.standard.document/3testrkey'
		);
		update_post_meta( $post->ID, \Atmosphere\Transformer\Document::META_DID, 'did:plc:pkiwtest123' );

		$this->go_to( get_permalink( $post->ID ) );

		ob_start();
		do_action( 'wp_head' );
		$head = (string) ob_get_clean();

		$this->assertSame(
			1,
			substr_count( $head, 'rel="site.standard.document"' ),
			'the verification link must appear exactly once — ATmosphere emits it, this plugin must not add another'
		);
	}

	public function test_rest_toggle_write_survives_the_kind_default() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$post = $this->make_kind_post( 'checkin', [ 'checkin_name' => 'Somewhere' ] );

		$this->assertFalse( \Atmosphere\is_post_publishable( $post ), 'checkin defaults to opt-in' );

		// The editor toggle writes the registered boolean over REST. An
		// opt-in writes `false` — the registered default — which is the
		// exact case where core might delete the row instead of storing
		// it, silently re-applying the kind default.
		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts/' . $post->ID );
		$request->set_body_params( [ 'meta' => [ 'atmosphere_disabled' => false ] ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue(
			metadata_exists( 'post', $post->ID, 'atmosphere_disabled' ),
			'the explicit opt-in must persist as a stored row, or the kind default silently wins again'
		);
		$this->assertTrue( \Atmosphere\is_post_publishable( get_post( $post->ID ) ), 'REST opt-in must stick' );

		// And the opposite direction: explicit opt-out over REST.
		$note    = $this->make_kind_post( 'note', [], [ 'post_content' => 'x' ] );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts/' . $note->ID );
		$request->set_body_params( [ 'meta' => [ 'atmosphere_disabled' => true ] ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( \Atmosphere\is_post_publishable( get_post( $note->ID ) ), 'REST opt-out must stick' );
	}

	public function test_revisions_and_autosaves_pass_through_untouched() {
		$post = $this->make_kind_post( 'checkin' );

		$revision_id = wp_save_post_revision( $post->ID );
		if ( $revision_id ) {
			$this->assertSame(
				'',
				(string) get_post_meta( $revision_id, 'atmosphere_disabled', true ),
				'revisions have no kind terms; the default filter must not touch them'
			);
		}

		$autosave_id = wp_create_post_autosave(
			[
				'post_ID'      => $post->ID,
				'post_title'   => 'autosave',
				'post_content' => 'autosave body',
				'post_type'    => 'post',
			]
		);
		if ( is_int( $autosave_id ) && $autosave_id > 0 ) {
			$this->assertSame( '', (string) get_post_meta( $autosave_id, 'atmosphere_disabled', true ) );
		}
	}

	public function test_ordinary_edits_do_not_disturb_the_default() {
		$post = $this->make_kind_post( 'checkin' );

		// Quick-edit / bulk-edit shaped update: fields only, no meta.
		wp_update_post(
			[
				'ID'         => $post->ID,
				'post_title' => 'Edited title',
			]
		);

		$this->assertFalse( metadata_exists( 'post', $post->ID, 'atmosphere_disabled' ) );
		$this->assertFalse( \Atmosphere\is_post_publishable( get_post( $post->ID ) ) );
	}

	public function test_narrowing_the_site_default_never_retracts_published_posts() {
		$note = $this->make_kind_post( 'note', [], [ 'post_content' => 'x' ] );
		update_post_meta( $note->ID, \Atmosphere\Transformer\Document::META_URI, 'at://did:plc:abc/site.standard.document/3xyz' );

		// Administrator unchecks every kind after this post published.
		update_option( \PKIW\Integrations\Atmosphere_Eligibility::OPTION, [ 'eligible_kinds' => [] ] );

		$this->assertTrue(
			\Atmosphere\is_post_publishable( get_post( $note->ID ) ),
			'a published record is exempt from any later default change'
		);

		delete_option( \PKIW\Integrations\Atmosphere_Eligibility::OPTION );
	}

	public function test_reserved_but_unpublished_doc_tid_stays_default_gated() {
		// A failed publish reserves the document TID without a URI.
		// has_post_records() deliberately ignores the doc TID, and the
		// eligibility guard mirrors that: the post is still governed by
		// the kind default, so a failed write on a default-off kind does
		// not quietly convert the post to eligible.
		$post = $this->make_kind_post( 'checkin' );
		update_post_meta( $post->ID, \Atmosphere\Transformer\Document::META_TID, '3sometid' );

		$this->assertFalse( \Atmosphere\is_post_publishable( get_post( $post->ID ) ) );
	}
}
