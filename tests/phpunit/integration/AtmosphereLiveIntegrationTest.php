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
		$listen = $this->make_kind_post( 'listen', [ 'listen_track' => 'Song' ] );
		$note   = $this->make_kind_post( 'note', [], [ 'post_content' => 'A note.' ] );

		$this->assertFalse( \Atmosphere\is_post_publishable( $listen ), 'consumption kinds default to opt-in' );
		$this->assertTrue( \Atmosphere\is_post_publishable( $note ), 'content kinds default to eligible' );

		// The author's explicit toggle wins over the kind default.
		update_post_meta( $listen->ID, ATMOSPHERE_META_DISABLED, '' );
		$this->assertTrue( \Atmosphere\is_post_publishable( $listen ) );
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
}
