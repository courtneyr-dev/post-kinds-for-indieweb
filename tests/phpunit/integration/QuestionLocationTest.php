<?php
/**
 * Simple Location stays off question posts.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Question_Archive;
use PKIW\Question_Location;
use PKIW\Micropub_Content_Builder;
use PKIW\Taxonomy;
use PKIW\Venue_Taxonomy;

/**
 * @group integration
 */
final class QuestionLocationTest extends WP_UnitTestCase {

	/** @var array<int, array{0:string,1:callable,2:int}> */
	private array $probes = [];

	private bool $registered_location = false;

	private ?WP_Query $original_main = null;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Question_Archive() )->register();
		$this->original_main = $GLOBALS['wp_the_query'] ?? null;
		Question_Location::reset();
		( new Question_Location() )->register();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		foreach ( $this->probes as $probe ) {
			remove_filter( $probe[0], $probe[1], $probe[2] );
		}
		remove_filter( 'the_content', [ 'Geo_Data', 'content_map' ], 11 );
		remove_filter( 'the_content', [ 'Geo_Data', 'location_content' ], 12 );
		foreach ( self::feed_hooks() as $row ) {
			remove_filter( $row[0], [ 'Geo_Base', 'georss_item' ], 10 );
		}
		remove_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ], 10 );
		remove_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ], 12 );
		if ( $this->registered_location && taxonomy_exists( 'location' ) ) {
			unregister_taxonomy( 'location' );
		}
		Question_Location::reset();
		$this->go_to( home_url( '/' ) );
		if ( null !== $this->original_main ) {
			$GLOBALS['wp_the_query'] = $this->original_main;
		}
		wp_reset_query();
		wp_reset_postdata();
		parent::tear_down();
	}

	private function register_simple_location_callbacks(): void {
		add_filter( 'the_content', [ 'Geo_Data', 'content_map' ], 11 );
		add_filter( 'the_content', [ 'Geo_Data', 'location_content' ], 12 );
		foreach ( self::feed_hooks() as $row ) {
			add_action( $row[0], [ 'Geo_Base', 'georss_item' ], 10 );
		}
		add_action( 'wp_head', [ 'Geo_Base', 'meta_tags' ], 10 );
		$this->assertSame( [ 11, 12 ], $this->content_state() );
	}

	private function add_probe( string $hook, callable $callback, int $priority ): void {
		add_filter( $hook, $callback, $priority );
		$this->probes[] = [ $hook, $callback, $priority ];
	}

	private function post( string $kind, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => ucfirst( $kind ) . ' fixture',
					'post_content' => '<p>Question body.</p>',
					'post_excerpt' => '',
				],
				$args
			)
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );

		return (int) $id;
	}

	private function located_question(): int {
		$id = $this->post( 'question', [ 'post_title' => 'L01 located question' ] );
		update_post_meta( $id, 'geo_latitude', '0.1' );
		update_post_meta( $id, 'geo_longitude', '0.2' );
		update_post_meta( $id, 'geo_address', 'Seed Cove, Test Ocean' );
		update_post_meta( $id, 'geo_public', '1' );

		return $id;
	}

	private function set_global_post( int $post_id ): void {
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );
	}

	/** @return array{0:int|false,1:int|false} */
	private function content_state(): array {
		return [
			has_filter( 'the_content', [ 'Geo_Data', 'content_map' ] ),
			has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ),
		];
	}

	private function direct_pass( int $post_id, callable $inside ): void {
		$this->set_global_post( $post_id );
		Question_Location::gate_content( 'x' );
		try {
			$inside();
		} finally {
			Question_Location::restore_content( 'x' );
		}
	}

	public function test_note_content_pass_leaves_callbacks_in_place(): void {
		$this->register_simple_location_callbacks();
		$seen = [];
		$this->direct_pass( $this->post( 'note' ), function () use ( &$seen ): void { $seen[] = $this->content_state(); } );

		$this->assertSame( [ [ 11, 12 ] ], $seen );
		$this->assertSame( [ 11, 12 ], $this->content_state() );
	}

	public function test_question_content_pass_removes_and_exactly_restores_both_callbacks(): void {
		$this->register_simple_location_callbacks();
		$this->set_global_post( $this->post( 'question' ) );
		$seen = [];
		$this->add_probe( 'the_content', function ( string $content ) use ( &$seen ): string { $seen[] = $this->content_state(); return $content; }, 12 );

		apply_filters( 'the_content', 'body' );

		$this->assertSame( [ [ false, false ] ], $seen );
		$this->assertSame( [ 11, 12 ], $this->content_state() );
	}

	public function test_every_question_pass_is_gated_again(): void {
		$this->register_simple_location_callbacks();
		$this->set_global_post( $this->post( 'question' ) );
		$seen = [];
		$this->add_probe( 'the_content', function ( string $content ) use ( &$seen ): string { $seen[] = $this->content_state(); return $content; }, 12 );

		apply_filters( 'the_content', 'one' );
		apply_filters( 'the_content', 'two' );

		$this->assertSame( [ [ false, false ], [ false, false ] ], $seen );
		$this->assertSame( [ 11, 12 ], $this->content_state() );
	}

	public function test_with_nothing_registered_nothing_is_added(): void {
		$this->set_global_post( $this->post( 'question' ) );
		apply_filters( 'the_content', 'body' );
		do_action( 'rss2_item' );

		$this->assertSame( [ false, false ], $this->content_state() );
		$this->assertFalse( has_filter( 'rss2_item', [ 'Geo_Base', 'georss_item' ] ) );
		$this->assertFalse( has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );
	}

	public function test_nested_note_inside_question_keeps_the_outer_removal(): void {
		$this->register_simple_location_callbacks();
		$question = $this->post( 'question' );
		$note     = $this->post( 'note' );
		$states   = [];
		$this->direct_pass(
			$question,
			function () use ( $question, $note, &$states ): void {
				$states['outer before'] = $this->content_state();
				$this->direct_pass( $note, function () use ( &$states ): void { $states['inner'] = $this->content_state(); } );
				$this->set_global_post( $question );
				$states['outer after'] = $this->content_state();
			}
		);

		$this->assertSame( [ 'outer before' => [ false, false ], 'inner' => [ false, false ], 'outer after' => [ false, false ] ], $states );
		$this->assertSame( [ 11, 12 ], $this->content_state() );
	}

	public function test_nested_question_inside_note_restores_before_note_continues(): void {
		$this->register_simple_location_callbacks();
		$question = $this->post( 'question' );
		$note     = $this->post( 'note' );
		$states   = [];
		$this->direct_pass(
			$note,
			function () use ( $question, &$states ): void {
				$states['outer before'] = $this->content_state();
				$this->direct_pass( $question, function () use ( &$states ): void { $states['inner'] = $this->content_state(); } );
				$states['outer after'] = $this->content_state();
			}
		);

		$this->assertSame( [ 'outer before' => [ 11, 12 ], 'inner' => [ false, false ], 'outer after' => [ 11, 12 ] ], $states );
	}

	public function test_question_term_is_found_when_it_is_not_first_and_for_protected_private_posts(): void {
		$this->register_simple_location_callbacks();
		$ids = [
			$this->post( 'note' ),
			$this->post( 'question', [ 'post_password' => 'secret' ] ),
			$this->post( 'question', [ 'post_status' => 'private' ] ),
		];
		wp_set_object_terms( $ids[0], [ 'craft', 'question' ], Taxonomy::TAXONOMY );
		foreach ( $ids as $id ) {
			$this->direct_pass( $id, function (): void { $this->assertSame( [ false, false ], $this->content_state() ); } );
		}
	}

	public function test_string_and_leading_backslash_callback_forms_restore_exactly(): void {
		add_filter( 'the_content', 'Geo_Data::content_map', 11 );
		add_filter( 'the_content', [ '\\Geo_Data', 'location_content' ], 12 );
		$this->set_global_post( $this->post( 'question' ) );
		Question_Location::gate_content( 'x' );
		$this->assertFalse( has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertFalse( has_filter( 'the_content', [ '\\Geo_Data', 'location_content' ] ) );
		Question_Location::restore_content( 'x' );

		$this->assertSame( 11, has_filter( 'the_content', 'Geo_Data::content_map' ) );
		$this->assertSame( 12, has_filter( 'the_content', [ '\\Geo_Data', 'location_content' ] ) );
		remove_filter( 'the_content', 'Geo_Data::content_map', 11 );
		remove_filter( 'the_content', [ '\\Geo_Data', 'location_content' ], 12 );
	}

	public function test_neighbor_callbacks_are_left_alone_and_unmatched_restore_adds_nothing(): void {
		$this->register_simple_location_callbacks();
		$neighbor = [ 'Other_Plugin', 'content_map' ];
		add_filter( 'the_content', $neighbor, 11 );
		$this->set_global_post( $this->post( 'question' ) );
		Question_Location::gate_content( 'x' );
		$this->assertSame( 11, has_filter( 'the_content', $neighbor ) );
		Question_Location::restore_content( 'x' );
		remove_filter( 'the_content', $neighbor, 11 );

		Question_Location::reset();
		$this->assertSame( 'x', Question_Location::restore_content( 'x' ) );
	}

	/** @return array<string, array{0:string}> */
	public static function feed_hooks(): array {
		return [ 'rss' => [ 'rss_item' ], 'rss2' => [ 'rss2_item' ], 'atom' => [ 'atom_entry' ], 'rdf' => [ 'rdf_item' ] ];
	}

	/** @dataProvider feed_hooks */
	public function test_georss_is_off_for_question_on_every_feed_hook_and_restored_for_note( string $hook ): void {
		$this->register_simple_location_callbacks();
		$question = $this->post( 'question' );
		$note     = $this->post( 'note' );
		$seen     = [];
		foreach ( [ $question, $note, $question ] as $id ) {
			$this->set_global_post( $id );
			$GLOBALS['wp_current_filter'][] = $hook;
			Question_Location::gate_feed_item();
			$seen[] = has_filter( $hook, [ 'Geo_Base', 'georss_item' ] );
			Question_Location::restore_feed_item();
			array_pop( $GLOBALS['wp_current_filter'] );
		}

		$this->assertSame( [ false, 10, false ], $seen );
		$this->assertSame( 10, has_filter( $hook, [ 'Geo_Base', 'georss_item' ] ) );
	}

	/** @dataProvider feed_hooks */
	public function test_registered_feed_hooks_remove_georss_during_question_item( string $hook ): void {
		$this->register_simple_location_callbacks();
		$seen = [];
		$this->add_probe( $hook, function () use ( &$seen, $hook ): void { $seen[] = has_filter( $hook, [ 'Geo_Base', 'georss_item' ] ); }, 10 );
		$this->set_global_post( $this->post( 'question' ) );
		do_action( $hook );

		$this->assertSame( [ false ], $seen );
		$this->assertSame( 10, has_filter( $hook, [ 'Geo_Base', 'georss_item' ] ) );
	}

	public function test_json_feed_geo_is_stripped_only_for_question(): void {
		$this->add_probe( 'json_feed_item', static function ( array $item ): array { $item['geo'] = [ 'latitude' => 0.1 ]; return $item; }, 10 );
		$question = get_post( $this->post( 'question' ) );
		$note     = get_post( $this->post( 'note' ) );

		$this->assertArrayNotHasKey( 'geo', apply_filters( 'json_feed_item', [ 'title' => 'x' ], $question ) );
		$this->assertArrayHasKey( 'geo', apply_filters( 'json_feed_item', [ 'title' => 'x' ], $note ) );
		$this->assertSame( 'not an item', Question_Location::strip_json_feed_geo( 'not an item', $question ) );
	}

	public function test_head_meta_is_removed_only_on_question_single(): void {
		$this->register_simple_location_callbacks();
		$question = $this->post( 'question' );
		$note     = $this->post( 'note' );
		$this->go_to( get_permalink( $question ) );
		do_action( 'template_redirect' );
		$this->assertFalse( has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );

		add_action( 'wp_head', [ 'Geo_Base', 'meta_tags' ], 10 );
		$this->go_to( get_permalink( $note ) );
		do_action( 'template_redirect' );
		$this->assertSame( 10, has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );

		$this->go_to( get_term_link( 'question', Taxonomy::TAXONOMY ) );
		do_action( 'template_redirect' );
		$this->assertSame( 10, has_filter( 'wp_head', [ 'Geo_Base', 'meta_tags' ] ) );
	}

	private function location_response( int $id ): WP_REST_Response {
		return new WP_REST_Response( [ 'id' => $id, 'latitude' => 0.1, 'longitude' => 0.2, 'geo_address' => 'Seed Cove, Test Ocean', 'timezone' => 'UTC' ] );
	}

	public function test_rest_strips_coordinates_and_address_outside_edit_context_only_for_question(): void {
		$question = get_post( $this->located_question() );
		$note     = get_post( $this->post( 'note' ) );
		$view     = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $question->ID );
		$view->set_param( 'context', 'view' );
		$edit = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $question->ID );
		$edit->set_param( 'context', 'edit' );

		$data = apply_filters( 'rest_prepare_post', $this->location_response( $question->ID ), $question, $view )->get_data();
		foreach ( [ 'latitude', 'longitude', 'geo_address', 'timezone' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $data );
		}
		$this->assertArrayHasKey( 'latitude', apply_filters( 'rest_prepare_post', $this->location_response( $question->ID ), $question, $edit )->get_data() );
		$note_data = apply_filters( 'rest_prepare_post', $this->location_response( $note->ID ), $note, $view )->get_data();
		$this->assertArrayHasKey( 'latitude', $note_data );
		$this->assertArrayHasKey( 'timezone', $note_data );
	}

	public function test_real_rest_request_removes_location_fields_added_at_priority_ten(): void {
		$this->add_probe(
			'rest_prepare_post',
			static function ( WP_REST_Response $response ): WP_REST_Response {
				$data                = $response->get_data();
				$data['latitude']    = 0.1;
				$data['longitude']   = 0.2;
				$data['geo_address'] = 'Seed Cove, Test Ocean';
				$response->set_data( $data );

				return $response;
			},
			10
		);
		$question = $this->located_question();
		$note     = $this->post( 'note' );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $question ) )->get_data();
		foreach ( [ 'latitude', 'longitude', 'geo_address' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $data );
		}
		$this->assertStringNotContainsString( 'Seed Cove', (string) wp_json_encode( $data ) );
		$this->assertArrayHasKey( 'latitude', rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $note ) )->get_data() );
	}

	public function test_non_post_rest_values_pass_through_unchanged(): void {
		$this->assertSame( 'sentinel', Question_Location::strip_rest_location( 'sentinel', null, null ) );
		$response = new WP_REST_Response( [ 'latitude' => 0.1 ] );
		$this->assertSame( $response, Question_Location::strip_rest_location( $response, get_post( $this->post( 'question' ) ), 'not a request' ) );
	}

	/** @param array<string, mixed> $args */
	private function main_query( array $args ): array {
		$query                   = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		return array_map( 'intval', $query->query( array_merge( [ 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ], $args ) ) );
	}

	public function test_map_geo_location_and_venue_main_queries_exclude_question_and_keep_note(): void {
		if ( ! taxonomy_exists( 'location' ) ) {
			$this->registered_location = true;
			register_taxonomy( 'location', 'post', [ 'public' => true, 'query_var' => 'location' ] );
		}
		$question = $this->located_question();
		$note     = $this->post( 'note' );
		$this->assertSame( [ $note ], array_values( array_intersect( $this->main_query( [ 'map' => '1' ] ), [ $question, $note ] ) ) );
		$this->assertSame( [ $note ], array_values( array_intersect( $this->main_query( [ 'geo' => 'public' ] ), [ $question, $note ] ) ) );
		$this->assertEqualsCanonicalizing( [ $question, $note ], array_values( array_intersect( $this->main_query( [] ), [ $question, $note ] ) ) );

		foreach ( [ 'location', Venue_Taxonomy::TAXONOMY ] as $taxonomy ) {
			$term = wp_insert_term( 'Seed Cove ' . $taxonomy, $taxonomy );
			$this->assertIsArray( $term );
			wp_set_object_terms( $question, [ (int) $term['term_id'] ], $taxonomy, true );
			wp_set_object_terms( $note, [ (int) $term['term_id'] ], $taxonomy, true );
			$ids = $this->main_query( [ 'tax_query' => [ [ 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => [ (int) $term['term_id'] ] ] ] ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$this->assertSame( [ $note ], $ids );
		}
	}

	public function test_non_main_query_is_untouched_and_existing_tax_query_is_kept(): void {
		$question = $this->post( 'question' );
		$other    = new WP_Query( [ 'map' => '1', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1 ] );
		$this->assertContains( $question, array_map( 'intval', $other->posts ) );

		$note = $this->post( 'note' );
		$ids  = $this->main_query( [ 'map' => '1', 'tax_query' => [ [ 'taxonomy' => Taxonomy::TAXONOMY, 'field' => 'slug', 'terms' => [ 'note', 'question' ] ] ] ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		$this->assertContains( $note, $ids );
		$this->assertNotContains( $question, $ids );
	}

	public function test_no_question_class_assigns_a_location_or_venue_term(): void {
		foreach ( [ 'class-question-archive.php', 'class-question-answers.php', 'class-question-location.php' ] as $file ) {
			$source = (string) file_get_contents( PKIW_PATH . 'includes/' . $file );
			foreach ( [ 'wp_set_object_terms', 'wp_set_post_terms', 'wp_insert_term', 'wp_add_object_terms', 'wp_set_post_categories' ] as $call ) {
				$this->assertStringNotContainsString( $call . '(', $source, $file . ' calls ' . $call . '.' );
			}
		}
	}

	public function test_micropub_question_location_creates_no_location_or_venue_term(): void {
		if ( ! taxonomy_exists( 'location' ) ) {
			$this->registered_location = true;
			register_taxonomy( 'location', 'post', [ 'public' => true ] );
		}
		$input   = [
			'question' => [ 'Where should this answer be filed?' ],
			'location' => [ 'geo:0.1,0.2' ],
		];
		$content = Micropub_Content_Builder::fill_empty_content( '', $input );
		$id      = self::factory()->post->create( [ 'post_status' => 'publish', 'post_content' => $content ] );
		Micropub_Content_Builder::apply( $input, [ 'ID' => $id ] );

		$this->assertTrue( has_term( 'question', Taxonomy::TAXONOMY, $id ) );
		$this->assertSame( [], wp_get_object_terms( $id, [ 'location', Venue_Taxonomy::TAXONOMY ] ) );
		$this->assertStringNotContainsString( '0.1', (string) get_post_field( 'post_content', $id ) );
		$this->assertStringNotContainsString( 'geo:', (string) get_post_field( 'post_content', $id ) );
	}

	public function test_l01_single_stream_and_rest_excerpt_never_hold_seed_cove(): void {
		$id = $this->located_question();
		$this->register_simple_location_callbacks();
		$simulate_location = static function ( string $content ): string {
			return false !== has_filter( 'the_content', [ 'Geo_Data', 'location_content' ] ) ? $content . '<p class="p-location">Seed Cove, Test Ocean</p>' : $content;
		};
		$this->add_probe( 'the_content', $simulate_location, 12 );
		$this->go_to( get_permalink( $id ) );
		$this->set_global_post( $id );

		$single = apply_filters( 'the_content', '<p>L01 body.</p>' );
		$this->assertStringNotContainsString( 'Seed Cove', $single );
		$this->assertStringNotContainsString( 'p-location', $single );

		$GLOBALS['post'] = get_post( $id );
		$card = \PKIW\render_stream_card();
		$this->assertStringNotContainsString( 'Seed Cove', $card );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id );
		$response = rest_get_server()->dispatch( $request );
		$this->assertStringNotContainsString( 'Seed Cove', (string) wp_json_encode( $response->get_data()['excerpt'] ?? [] ) );
	}

	private function comment_on( int $post_id, string $text, array $args = [] ): WP_Comment {
		$id = self::factory()->comment->create(
			array_merge(
				[
					'comment_post_ID'  => $post_id,
					'comment_approved' => '1',
					'comment_content'  => $text,
					'comment_author'   => 'Avery Quill',
				],
				$args
			)
		);
		foreach ( [ 'geo_latitude' => '0.1', 'geo_longitude' => '0.2', 'geo_address' => 'Seed Cove, Test Ocean', 'geo_public' => '1' ] as $key => $value ) {
			add_comment_meta( $id, $key, $value );
		}

		return get_comment( $id );
	}

	/**
	 * Register Simple Location's comment callback by name, and a probe at the same priority that prints the place only while it is registered.
	 */
	private function register_comment_location(): void {
		add_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ], 12, 3 );
		$probe = static function ( $text ): string {
			return false !== has_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ] ) ? $text . '<p>Seed Cove, Test Ocean</p>' : (string) $text;
		};
		$this->add_probe( 'get_comment_text', $probe, 12 );
		$this->assertSame( 12, has_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ] ) );
	}

	public function test_comment_on_a_question_loses_the_simple_location_comment_filter_for_its_pass_and_gets_it_back(): void {
		$this->register_comment_location();
		$comment = $this->comment_on( $this->located_question(), 'A01 answer text.' );

		$text = apply_filters( 'get_comment_text', 'A01 answer text.', $comment, [] );

		$this->assertSame( 'A01 answer text.', $text );
		$this->assertSame( 12, has_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ] ), 'The restore puts it back at its own priority.' );
	}

	public function test_non_answer_comment_on_a_question_single_prints_no_place(): void {
		$this->register_comment_location();
		$id      = $this->located_question();
		$author  = (int) get_post_field( 'post_author', $id );
		$comment = $this->comment_on( $id, 'Author follow-up.', [ 'user_id' => $author ] );
		$this->go_to( get_permalink( $id ) );

		ob_start();
		comment_text( $comment );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Author follow-up.', $html );
		$this->assertStringNotContainsString( 'Seed Cove', $html );
		$this->assertSame( 12, has_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ] ) );
	}

	public function test_question_comment_feed_prints_no_place_for_a_commenter(): void {
		$this->register_comment_location();
		$id = $this->located_question();
		$this->comment_on( $id, 'A01 feed answer.' );
		$this->go_to( get_post_comments_feed_link( $id ) );
		$this->assertTrue( is_feed() );

		ob_start();
		try {
			@require ABSPATH . WPINC . '/feed-rss2-comments.php'; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Core's template emits headers under the test runner.
		} finally {
			$feed = (string) ob_get_clean();
		}

		$this->assertStringContainsString( 'A01 feed answer.', $feed );
		$this->assertStringNotContainsString( 'Seed Cove', $feed );
		$this->assertSame( 12, has_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ] ) );
	}

	public function test_comment_on_a_note_keeps_the_simple_location_comment_filter(): void {
		$this->register_comment_location();
		$comment = $this->comment_on( $this->post( 'note' ), 'Note comment.' );
		$seen    = [];

		Question_Location::gate_comment_text( 'x', $comment );
		try {
			$seen[] = has_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ] );
		} finally {
			Question_Location::restore_comment_text( 'x' );
		}

		$this->assertSame( [ 12 ], $seen, 'A note comment keeps Simple Location output.' );
		$this->assertSame( 12, has_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ] ) );
	}

	public function test_nested_comment_passes_restore_only_what_each_pass_removed(): void {
		$this->register_comment_location();
		$question = $this->comment_on( $this->post( 'question' ), 'Q comment.' );
		$note     = $this->comment_on( $this->post( 'note' ), 'N comment.' );
		$seen     = [];

		Question_Location::gate_comment_text( 'x', $question );
		$seen[] = has_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ] );
		Question_Location::gate_comment_text( 'x', $note );
		Question_Location::restore_comment_text( 'x' );
		$seen[] = has_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ] );
		Question_Location::restore_comment_text( 'x' );
		$seen[] = has_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ] );

		$this->assertSame( [ false, false, 12 ], $seen );
	}

	public function test_comment_gate_is_inert_without_simple_location(): void {
		$comment = $this->comment_on( $this->post( 'question' ), 'No SL.' );

		Question_Location::gate_comment_text( 'x', $comment );
		Question_Location::restore_comment_text( 'x' );

		$this->assertFalse( has_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ] ) );
	}

	public function test_registering_twice_adds_no_duplicate_callbacks(): void {
		( new Question_Location() )->register();
		( new Question_Location() )->register();

		$this->assertSame( 10, has_filter( 'the_content', [ Question_Location::class, 'gate_content' ] ) );
		$this->assertSame( 13, has_filter( 'the_content', [ Question_Location::class, 'restore_content' ] ) );
		$this->assertSame( 30, has_filter( 'rest_prepare_post', [ Question_Location::class, 'strip_rest_location' ] ) );
		$this->assertSame( 9, has_filter( 'rss2_item', [ Question_Location::class, 'gate_feed_item' ] ) );
		$this->assertSame( 11, has_filter( 'rss2_item', [ Question_Location::class, 'restore_feed_item' ] ) );
		$this->assertSame( 11, has_filter( 'json_feed_item', [ Question_Location::class, 'strip_json_feed_geo' ] ) );
		$this->assertSame( 10, has_filter( 'template_redirect', [ Question_Location::class, 'suppress_head_meta' ] ) );
		$this->assertSame( 10, has_filter( 'get_comment_text', [ Question_Location::class, 'gate_comment_text' ] ) );
		$this->assertSame( 13, has_filter( 'get_comment_text', [ Question_Location::class, 'restore_comment_text' ] ) );
		$this->assertSame( 10, has_filter( 'pre_get_posts', [ Question_Location::class, 'exclude_from_place_listings' ] ) );
	}
}
