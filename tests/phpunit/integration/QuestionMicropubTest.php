<?php
/**
 * Micropub input contracts for question posts.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Micropub_Content_Builder;
use PKIW\Question_Archive;
use PKIW\Question_Location;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuestionMicropubTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Question_Archive() )->register();
		( new Question_Location() )->register();
		Question_Location::reset();
	}

	public function tear_down(): void {
		Question_Location::reset();
		$this->go_to( home_url( '/' ) );
		wp_reset_query();
		wp_reset_postdata();
		parent::tear_down();
	}

	/**
	 * Build the post row wordpress-micropub would create from one h-entry.
	 *
	 * @param array<string, array<int, string>> $properties Micropub properties.
	 */
	private function create_from_request( array $properties ): int {
		$content = Micropub_Content_Builder::fill_empty_content( '', [ 'properties' => $properties ] );
		$title   = isset( $properties['name'][0] ) ? $properties['name'][0] : '';
		$id      = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			]
		);
		wp_set_object_terms( $id, 'question', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	private function singular_content( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test setup.
		setup_postdata( $GLOBALS['post'] );

		return apply_filters( 'the_content', (string) get_post_field( 'post_content', $post_id ) );
	}

	public function test_question_name_and_content_build_a_typed_paragraph_title_and_body(): void {
		$properties = [
			'question' => [ 'What do you do when a draft stops making sense?' ],
			'name'     => [ 'A question about stalled drafts' ],
			'content'  => [ 'I have tried outlining the missing transition.' ],
		];
		$id         = $this->create_from_request( $properties );
		$stored     = (string) get_post_field( 'post_content', $id );

		$this->assertSame( 'A question about stalled drafts', get_the_title( $id ) );
		$this->assertStringContainsString( '<span class="p-question">What do you do when a draft stops making sense?</span>', $stored );
		$this->assertStringContainsString( 'I have tried outlining the missing transition.', $stored );
		$this->assertNotSame( '', trim( $stored ) );
	}

	public function test_q_source_round_trip_returns_the_stored_content_unchanged(): void {
		$id     = $this->create_from_request(
			[
				'question' => [ 'Which source shape survives a round trip?' ],
				'name'     => [ 'Source round trip' ],
				'content'  => [ 'Keep this body byte for byte.' ],
			]
		);
		$source = (string) get_post_field( 'post_content', $id );

		$this->assertSame( $source, (string) get_post( $id )->post_content, 'The q=source representation is the stored block source.' );
	}

	public function test_location_meta_stays_stored_while_question_output_hides_it(): void {
		$id = $this->create_from_request(
			[
				'question' => [ 'Where should this question be answered?' ],
				'content'  => [ 'The visible question body.' ],
				'location' => [ 'geo:0.1,0.2' ],
			]
		);
		update_post_meta( $id, 'geo_latitude', '0.1' );
		update_post_meta( $id, 'geo_longitude', '0.2' );
		update_post_meta( $id, 'geo_address', 'Seed Cove, Test Ocean' );
		update_post_meta( $id, 'geo_public', '1' );

		$this->assertSame( '0.1', get_post_meta( $id, 'geo_latitude', true ) );
		$this->assertSame( '0.2', get_post_meta( $id, 'geo_longitude', true ) );
		$this->assertSame( 'Seed Cove, Test Ocean', get_post_meta( $id, 'geo_address', true ) );
		$output = $this->singular_content( $id );
		$this->assertStringNotContainsString( 'Seed Cove', $output );
		$this->assertStringNotContainsString( 'geo:0.1,0.2', $output );
	}

	public function test_question_hint_and_content_alone_keep_the_content_micropub_derived(): void {
		// Micropub derives post_content from `content` itself, so the builder only fills an EMPTY post. It keeps a non-empty one as it is.
		$derived = '<p>What belongs in the smallest useful draft?</p>';
		$content = Micropub_Content_Builder::fill_empty_content(
			$derived,
			[
				'properties' => [
					'pkiw-kind' => [ 'question' ],
					'content'   => [ 'What belongs in the smallest useful draft?' ],
				],
			]
		);

		$this->assertSame( $derived, $content );
	}

	public function test_micropub_content_building_never_writes_the_derived_status_key(): void {
		$content = Micropub_Content_Builder::fill_empty_content(
			'',
			[ 'question' => [ 'Does the inbound path own status?' ] ]
		);
		$id      = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => $content,
			]
		);

		$this->assertNull( get_metadata_raw( 'post', $id, Question_Archive::STATUS_KEY, true ) );
		$this->assertStringNotContainsString( Question_Archive::STATUS_KEY, $content );
	}
}
