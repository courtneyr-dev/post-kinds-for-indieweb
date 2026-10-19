<?php
/**
 * The cached status row for question posts.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Abilities\Core_Abilities;
use PKIW\Answer_Classifier;
use PKIW\Card_Meta_Sync;
use PKIW\Grouped_Archive;
use PKIW\Meta_Fields;
use PKIW\Question_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuestionStatusTest extends WP_UnitTestCase {

	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Meta_Fields() )->register_meta_fields();
		( new Question_Archive() )->register();
		$this->author_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Answer_Classifier::reset();
	}

	public function tear_down(): void {
		Answer_Classifier::reset();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function post( string $kind = 'question', array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => 'Status fixture',
					'post_author' => $this->author_id,
				],
				$args
			)
		);
		wp_set_object_terms( $id, $kind, Taxonomy::TAXONOMY );
		Question_Archive::refresh_status( $id );

		return (int) $id;
	}

	private function comment( int $post_id, array $args = [], array $meta = [] ): int {
		$id = self::factory()->comment->create(
			array_merge(
				[
					'comment_post_ID'  => $post_id,
					'comment_approved' => '1',
					'comment_type'     => '',
					'user_id'          => 0,
					'comment_content'  => 'Third-party answer.',
				],
				$args
			)
		);
		foreach ( $meta as $key => $value ) {
			add_comment_meta( $id, $key, $value );
		}

		return (int) $id;
	}

	private function status( int $post_id ): string {
		return (string) get_metadata_raw( 'post', $post_id, Question_Archive::STATUS_KEY, true );
	}

	private function assertSingleStatusRow( int $post_id, string $expected ): void {
		global $wpdb;
		$this->assertSame( $expected, $this->status( $post_id ) );
		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$post_id,
				Question_Archive::STATUS_KEY
			)
		);
		$this->assertSame( 1, (int) $count );
	}

	public function test_new_unprotected_question_gets_one_waiting_row(): void {
		$id = $this->post();

		$this->assertSingleStatusRow( $id, 'waiting' );
		$this->assertSame( 'waiting', Question_Archive::compute_status( $id ) );
	}

	public function test_wp_after_insert_post_restores_a_missing_status_row(): void {
		$id = $this->post();
		delete_post_meta( $id, Question_Archive::STATUS_KEY );
		$post = get_post( $id );

		do_action( 'wp_after_insert_post', $id, $post, true, $post );

		$this->assertSingleStatusRow( $id, 'waiting' );
	}

	public function test_approve_and_unapprove_flip_the_status(): void {
		$id      = $this->post();
		$comment = $this->comment( $id, [ 'comment_approved' => '0' ] );

		$this->assertSame( 'waiting', $this->status( $id ) );
		wp_set_comment_status( $comment, 'approve' );
		$this->assertSingleStatusRow( $id, 'answered' );
		wp_set_comment_status( $comment, 'hold' );
		$this->assertSingleStatusRow( $id, 'waiting' );
	}

	public function test_spam_trash_restore_and_delete_flip_the_status(): void {
		$id      = $this->post();
		$comment = $this->comment( $id );
		$this->assertSingleStatusRow( $id, 'answered' );

		wp_set_comment_status( $comment, 'spam' );
		$this->assertSame( 'waiting', $this->status( $id ) );
		wp_set_comment_status( $comment, 'approve' );
		$this->assertSame( 'answered', $this->status( $id ) );
		wp_set_comment_status( $comment, 'trash' );
		$this->assertSame( 'waiting', $this->status( $id ) );
		wp_set_comment_status( $comment, 'approve' );
		$this->assertSame( 'answered', $this->status( $id ) );
		wp_delete_comment( $comment, true );
		$this->assertSingleStatusRow( $id, 'waiting' );
	}

	/** @return array<string, array{0:string,1:bool}> */
	public static function unchanged_comment_shapes(): array {
		return [
			'like'     => [ 'like', false ],
			'repost'   => [ 'repost', false ],
			'mention'  => [ 'mention', false ],
			'pingback' => [ 'pingback', false ],
			'author'   => [ '', true ],
		];
	}

	/** @dataProvider unchanged_comment_shapes */
	public function test_non_answers_leave_waiting_unchanged( string $type, bool $author ): void {
		$id = $this->post();
		$this->comment( $id, [ 'comment_type' => $type, 'user_id' => $author ? $this->author_id : 0 ] );

		$this->assertSingleStatusRow( $id, 'waiting' );
	}

	public function test_post_author_change_recomputes_an_existing_comment(): void {
		$id = $this->post();
		$this->comment( $id, [ 'user_id' => $this->author_id ] );
		$this->assertSame( 'waiting', $this->status( $id ) );

		$second_admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_update_post( [ 'ID' => $id, 'post_author' => $second_admin ] );

		$this->assertSingleStatusRow( $id, 'answered' );
	}

	public function test_assigning_question_later_creates_the_row(): void {
		$id = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $id, 'note', Taxonomy::TAXONOMY );
		$this->assertNull( get_metadata_raw( 'post', $id, Question_Archive::STATUS_KEY, true ) );

		wp_set_object_terms( $id, 'question', Taxonomy::TAXONOMY );

		$this->assertSingleStatusRow( $id, 'waiting' );
	}

	public function test_removing_question_with_set_object_terms_deletes_the_row(): void {
		$id = $this->post();

		wp_set_object_terms( $id, [ 'note' ], Taxonomy::TAXONOMY );

		$this->assertNull( get_metadata_raw( 'post', $id, Question_Archive::STATUS_KEY, true ) );
	}

	public function test_removing_question_with_remove_object_terms_deletes_the_row(): void {
		$id = $this->post();

		wp_remove_object_terms( $id, 'question', Taxonomy::TAXONOMY );

		$this->assertNull( get_metadata_raw( 'post', $id, Question_Archive::STATUS_KEY, true ) );
	}

	public function test_comment_meta_arriving_after_insert_recomputes_the_row(): void {
		$id      = $this->post();
		$comment = $this->comment( $id );
		$this->assertSame( 'answered', $this->status( $id ) );

		add_comment_meta( $comment, 'protocol', 'atproto' );
		$this->assertSame( 'waiting', $this->status( $id ) );
		update_comment_meta( $comment, 'protocol', 'webmention' );
		$this->assertSame( 'answered', $this->status( $id ) );
		delete_comment_meta( $comment, 'protocol' );
		$this->assertSingleStatusRow( $id, 'answered' );
	}

	public function test_unrelated_comment_meta_does_not_change_the_row(): void {
		$id      = $this->post();
		$comment = $this->comment( $id );
		update_post_meta( $id, Question_Archive::STATUS_KEY, 'sentinel' );

		add_comment_meta( $comment, 'unrelated', 'value' );

		$this->assertSame( 'sentinel', $this->status( $id ) );
	}

	public function test_trash_and_untrash_leave_the_row_equal_to_the_classifier(): void {
		$id = $this->post();
		$this->comment( $id );
		$this->assertSame( 'answered', $this->status( $id ) );

		wp_trash_post( $id );
		// Core flips the comments to post-trashed with one SQL update and no per-comment hook, so the row may lag in Trash; nothing lists a trashed post.
		$this->assertSame( 'waiting', Question_Archive::compute_status( $id ) );
		wp_untrash_post( $id );
		$this->assertSingleStatusRow( $id, 'answered' );
		$this->assertSame( Question_Archive::compute_status( $id ), $this->status( $id ) );
	}

	public function test_protected_question_has_a_row_but_files_under_the_empty_group(): void {
		$id = $this->post( 'question', [ 'post_password' => 'seed-pass-256' ] );
		$this->comment( $id );
		Question_Archive::refresh_status( $id );

		$this->assertSingleStatusRow( $id, 'answered' );
		$this->assertTrue( Grouped_Archive::group_of_post( 'question', $id )->is_empty() );
	}

	public function test_status_key_is_absent_from_rest_for_anonymous_and_editor_requests(): void {
		$id = $this->post();
		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'editor' ] ) ] as $user_id ) {
			wp_set_current_user( $user_id );
			$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id );
			if ( 0 !== $user_id ) {
				$request->set_param( 'context', 'edit' );
			}
			$response = rest_get_server()->dispatch( $request );
			$this->assertSame( 200, $response->get_status() );
			$this->assertStringNotContainsString( Question_Archive::STATUS_KEY, (string) wp_json_encode( $response->get_data() ) );
		}
	}

	public function test_status_key_is_absent_from_get_and_update_meta_abilities(): void {
		$id        = $this->post();
		$abilities = Core_Abilities::instance();
		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'editor' ] ) ] as $user_id ) {
			wp_set_current_user( $user_id );
			$get = $abilities->execute_get_post_meta( [ 'post_id' => $id, 'meta_keys' => [ 'question_status' ] ] );
			$this->assertIsArray( $get );
			$this->assertArrayNotHasKey( 'question_status', $get['meta'] );
			$update = $abilities->execute_update_post_meta( [ 'post_id' => $id, 'meta_key' => 'question_status', 'meta_value' => 'answered' ] );
			$this->assertWPError( $update );
			$this->assertSame( 0 === $user_id ? 'forbidden' : 'invalid_meta_key', $update->get_error_code() );
		}
		$this->assertFalse( ( new Meta_Fields() )->is_valid_field( 'question_status' ) );
		$this->assertArrayNotHasKey( Question_Archive::STATUS_KEY, get_registered_meta_keys( 'post', 'post' ) );
	}

	public function test_backfill_post_is_idempotent_and_does_not_change_post_modified(): void {
		$id       = $this->post();
		$modified = get_post( $id )->post_modified_gmt;
		delete_post_meta( $id, Question_Archive::STATUS_KEY );

		Question_Archive::backfill_post( $id, get_post( $id ) );
		Question_Archive::backfill_post( $id, get_post( $id ) );

		$this->assertSingleStatusRow( $id, 'waiting' );
		$this->assertSame( $modified, get_post( $id )->post_modified_gmt );
	}

	public function test_backfill_kind_filter_lists_question_once(): void {
		$kinds = apply_filters( 'pkiw_card_meta_backfill_kinds', [ 'question', 'note' ] );

		$this->assertSame( 1, count( array_keys( $kinds, 'question', true ) ) );
	}

	public function test_note_post_never_keeps_a_status_row(): void {
		$id = $this->post( 'note' );
		update_post_meta( $id, Question_Archive::STATUS_KEY, 'answered' );

		Question_Archive::refresh_status( $id );

		$this->assertNull( get_metadata_raw( 'post', $id, Question_Archive::STATUS_KEY, true ) );
	}

	public function test_card_meta_backfill_selects_a_card_less_question_and_fires_the_action(): void {
		$id = $this->post();
		delete_post_meta( $id, Question_Archive::STATUS_KEY );
		$seen  = [];
		$probe = static function ( int $post_id, WP_Post $post ) use ( &$seen ): void {
			$seen[] = [ $post_id, $post->ID ];
		};
		add_action( 'pkiw_card_meta_backfill_post', $probe, 20, 2 );

		$result = Card_Meta_Sync::backfill_batch( $id - 1, 1 );

		remove_action( 'pkiw_card_meta_backfill_post', $probe, 20 );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( [ [ $id, $id ] ], $seen );
		$this->assertSingleStatusRow( $id, 'waiting' );
	}

	public function test_revision_never_gets_a_status_row(): void {
		$id       = $this->post();
		$revision = wp_save_post_revision( $id );

		$this->assertIsInt( $revision );
		$this->assertNull( get_metadata_raw( 'post', $revision, Question_Archive::STATUS_KEY, true ) );
	}
}
