<?php
/**
 * A title generated from location data follows the location's privacy.
 *
 * Importers write "Checked in at <venue>" into post_title. On a post whose
 * venue name is hidden, that title printed the venue in the h1, the
 * document title, feeds and every link to the post (issue 224).
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Meta_Fields;
use PKIW\Title_Privacy;

/**
 * @group integration
 */
final class TitlePrivacyTest extends WP_UnitTestCase {

	private const VENUE = 'Sentinel Venue Zyx9';

	/**
	 * What a hidden generated title prints as, for a post dated 2026-09-12.
	 */
	private const SAFE_TITLE = 'Check-in, September 12, 2026';

	public function set_up(): void {
		parent::set_up();
		( new Meta_Fields() )->register_meta_fields();
		wp_set_current_user( 0 );
	}

	/**
	 * A published check-in.
	 *
	 * @param string               $title   Post title.
	 * @param string               $privacy _pkiw_geo_privacy value.
	 * @param array<string, mixed> $meta    Extra meta.
	 */
	private function checkin( string $title, string $privacy, array $meta = [] ): int {
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_date'   => '2026-09-12 14:30:00',
			]
		);
		wp_set_object_terms( $post_id, 'checkin', 'kind' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'checkin_name', self::VENUE );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'geo_privacy', $privacy );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	private function generated( string $privacy, array $meta = [] ): int {
		$post_id = $this->checkin( 'Checked in at ' . self::VENUE, $privacy, $meta );
		Title_Privacy::mark_location_title( $post_id );

		return $post_id;
	}

	public function test_generated_title_on_a_private_post_names_no_venue(): void {
		$post_id = $this->generated( 'private' );

		$this->assertSame( self::SAFE_TITLE, get_the_title( $post_id ) );
		$this->assertSame( self::SAFE_TITLE, pkiw_get_safe_title( $post_id ) );
		$this->assertSame( 'Checked in at ' . self::VENUE, get_post_field( 'post_title', $post_id ), 'stored title must stay intact' );
	}

	public function test_generated_title_hidden_when_simple_location_hides_the_post(): void {
		$post_id = $this->generated( 'public', [ 'geo_public' => '0' ] );

		$this->assertStringNotContainsString( self::VENUE, get_the_title( $post_id ) );
	}

	/**
	 * @dataProvider visible_tiers
	 */
	public function test_generated_title_stays_while_the_venue_name_is_visible( string $privacy ): void {
		$post_id = $this->generated( $privacy );

		$this->assertSame( 'Checked in at ' . self::VENUE, get_the_title( $post_id ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function visible_tiers(): array {
		return [
			'public'      => [ 'public' ],
			'approximate' => [ 'approximate' ],
		];
	}

	public function test_author_title_stays_on_a_private_post(): void {
		$post_id = $this->checkin( 'Evening walk', 'private' );

		$this->assertSame( 'Evening walk', get_the_title( $post_id ) );
		$this->assertSame( 'Evening walk', pkiw_get_safe_title( $post_id ) );
	}

	/**
	 * Posts imported before the marker existed.
	 *
	 * @dataProvider legacy_titles
	 */
	public function test_legacy_generated_title_is_recognized_by_its_form( string $title, array $meta ): void {
		$post_id = $this->checkin( $title, 'private', $meta );

		$this->assertSame( self::SAFE_TITLE, get_the_title( $post_id ) );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public function legacy_titles(): array {
		return [
			'importer form'        => [ 'Checked in at ' . self::VENUE, [] ],
			'different case'       => [ 'checked in at ' . strtolower( self::VENUE ), [] ],
			'venue name alone'     => [ self::VENUE, [] ],
			'legacy venue key'     => [ 'Checked in at Old Key Venue', [ '_pkiw_checkin_venue' => 'Old Key Venue' ] ],
			'unknown venue'        => [ 'Checked in at Unknown Venue', [] ],
		];
	}

	public function test_editor_sees_the_safe_title_on_the_front_end_too(): void {
		$post_id = $this->generated( 'private' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( self::SAFE_TITLE, get_the_title( $post_id ), 'Feeds and federation run in the publishing editor\'s request.' );
	}

	public function test_admin_screens_keep_the_stored_title(): void {
		$post_id = $this->generated( 'private' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		set_current_screen( 'edit-post' );

		$this->assertSame( 'Checked in at ' . self::VENUE, get_the_title( $post_id ) );

		set_current_screen( 'front' );
	}

	public function test_document_title_and_feed_title_name_no_venue(): void {
		$post_id = $this->generated( 'private' );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertStringNotContainsString( self::VENUE, wp_get_document_title() );
		$this->assertStringContainsString( self::SAFE_TITLE, wp_get_document_title() );

		$GLOBALS['post'] = get_post( $post_id );
		$this->assertStringNotContainsString( self::VENUE, get_the_title_rss() );
	}

	/**
	 * Yoast SEO reads post_title for the title tag, the social titles and
	 * its schema graph.
	 */
	public function test_yoast_titles_and_schema_for_the_viewed_post_name_no_venue(): void {
		$post_id = $this->generated( 'private' );
		$this->go_to( get_permalink( $post_id ) );

		$stored = 'Checked in at ' . self::VENUE;
		// The Yoast integration hooks this to wpseo_title, wpseo_opengraph_title,
		// wpseo_twitter_title and wpseo_schema_graph when Yoast is active.
		$this->assertSame( self::SAFE_TITLE . ' - Site', Title_Privacy::scrub_stored_title( $stored . ' - Site' ) );

		$graph = Title_Privacy::scrub_stored_title(
			[
				[
					'@type' => 'WebPage',
					'name'  => $stored . ' - Site',
				],
				[
					'@type'           => 'BreadcrumbList',
					'itemListElement' => [
						[
							'name' => $stored,
						],
					],
				],
			]
		);
		$this->assertStringNotContainsString( self::VENUE, (string) wp_json_encode( $graph ) );
		$this->assertSame( self::SAFE_TITLE, $graph[1]['itemListElement'][0]['name'] );
	}

	/**
	 * Yoast builds the same head for the REST `yoast_head` fields, where no
	 * post is being viewed; it passes its presentation along.
	 */
	public function test_yoast_title_built_outside_the_post_page_names_no_venue(): void {
		$post_id = $this->generated( 'private' );
		$this->go_to( home_url( '/' ) );

		$presentation = (object) [
			'model' => (object) [
				'object_type' => 'post',
				'object_id'   => $post_id,
			],
		];

		$this->assertSame( self::SAFE_TITLE . ' - Site', Title_Privacy::scrub_stored_title( 'Checked in at ' . self::VENUE . ' - Site', $presentation ) );
		$this->assertSame( 'Checked in at ' . self::VENUE . ' - Site', Title_Privacy::scrub_stored_title( 'Checked in at ' . self::VENUE . ' - Site' ), 'With no post in view and none named, nothing changes.' );
	}

	/**
	 * A context Yoast built for a term, user or archive is left alone, even
	 * when its object id matches a post with a hidden generated title.
	 */
	public function test_yoast_context_for_another_object_type_is_left_alone(): void {
		$post_id = $this->generated( 'private' );
		$this->go_to( get_permalink( $post_id ) );

		$stored = 'Checked in at ' . self::VENUE . ' - Site';
		$term   = (object) [
			'model' => (object) [
				'object_type' => 'term',
				'object_id'   => $post_id,
			],
		];
		$untyped = (object) [
			'model' => (object) [
				'object_id' => $post_id,
			],
		];

		$this->assertSame( $stored, Title_Privacy::scrub_stored_title( $stored, $term ) );
		$this->assertSame( self::SAFE_TITLE . ' - Site', Title_Privacy::scrub_stored_title( $stored, $untyped ), 'No type: the post being viewed decides.' );
	}

	public function test_oembed_title_names_no_venue(): void {
		$post_id = $this->generated( 'private' );
		// Another plugin may put the stored title back after core fills it in.
		add_filter(
			'oembed_response_data',
			static function ( $data, $post ) {
				$data['title'] = $post->post_title;
				return $data;
			},
			20,
			2
		);

		$data = get_oembed_response_data( $post_id, 600 );

		$this->assertSame( self::SAFE_TITLE, $data['title'] );
	}

	public function test_yoast_title_untouched_while_the_venue_is_visible(): void {
		$post_id = $this->generated( 'public' );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( 'Checked in at ' . self::VENUE . ' - Site', Title_Privacy::scrub_stored_title( 'Checked in at ' . self::VENUE . ' - Site' ) );
	}

	public function test_rest_rendered_title_names_no_venue(): void {
		$post_id = $this->generated( 'private' );

		$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) )->get_data();

		$this->assertSame( self::SAFE_TITLE, $data['title']['rendered'] );
	}

	public function test_retitling_a_post_by_hand_clears_the_marker(): void {
		$post_id = $this->generated( 'private' );

		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Evening walk',
			]
		);

		$this->assertSame( '', get_post_meta( $post_id, Title_Privacy::META_KEY, true ) );
		$this->assertSame( 'Evening walk', get_the_title( $post_id ) );
	}

	public function test_saving_without_a_title_change_keeps_the_marker(): void {
		$post_id = $this->generated( 'private' );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'A note.',
			]
		);

		$this->assertSame( Title_Privacy::SOURCE_LOCATION, get_post_meta( $post_id, Title_Privacy::META_KEY, true ) );
	}

	public function test_foursquare_import_marks_its_title(): void {
		$sync   = ( new ReflectionClass( \PKIW\Sync\Foursquare_Checkin_Sync::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( $sync, 'import_checkin' );
		$method->setAccessible( true );

		$post_id = $method->invoke(
			$sync,
			[
				'id'        => 'sentinel-4sq-1',
				'createdAt' => 1789223400,
				'venue'     => [
					'id'       => 'v1',
					'name'     => self::VENUE,
					'location' => [ 'city' => 'Sentinelville' ],
				],
			]
		);

		$this->assertIsInt( $post_id );
		$this->assertSame( Title_Privacy::SOURCE_LOCATION, get_post_meta( $post_id, Title_Privacy::META_KEY, true ) );
	}

	public function test_import_manager_marks_a_checkin_title(): void {
		$manager = ( new ReflectionClass( \PKIW\Import_Manager::class ) )->newInstanceWithoutConstructor();
		$method  = new ReflectionMethod( $manager, 'create_post_from_item' );
		$method->setAccessible( true );

		$post_id = $method->invoke(
			$manager,
			[
				'venue_name' => self::VENUE,
				'timestamp'  => 1789223400,
			],
			[
				'name' => 'Sentinel Source',
				'kind' => 'checkin',
			],
			[],
			'sentinel',
			self::factory()->user->create( [ 'role' => 'editor' ] )
		);

		$this->assertIsInt( $post_id );
		$this->assertSame( Title_Privacy::SOURCE_LOCATION, get_post_meta( $post_id, Title_Privacy::META_KEY, true ) );
	}
}
