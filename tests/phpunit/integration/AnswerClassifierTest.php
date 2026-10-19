<?php
/**
 * Answer classification for question comments.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Answer_Classifier;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class AnswerClassifierTest extends WP_UnitTestCase {

	/** @var array<int, array{hook:string, callback:callable, priority:int}> */
	private array $filters = [];

	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		$this->author_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Answer_Classifier::reset();
	}

	public function tear_down(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter['hook'], $filter['callback'], $filter['priority'] );
		}
		$this->filters = [];
		Answer_Classifier::reset();
		parent::tear_down();
	}

	private function question( array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status' => 'publish',
					'post_title'  => 'How do you name a new post kind?',
					'post_author' => $this->author_id,
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'question', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	private function comment( int $post_id, array $args = [], array $meta = [] ): int {
		$id = self::factory()->comment->create(
			array_merge(
				[
					'comment_post_ID'      => $post_id,
					'comment_approved'     => '1',
					'comment_type'         => '',
					'comment_parent'       => 0,
					'user_id'              => 0,
					'comment_author'       => 'Avery Quill',
					'comment_author_url'   => 'https://a.example.net/',
					'comment_content'      => 'A third-party answer.',
					'comment_date'         => '2026-09-04 12:00:00',
					'comment_date_gmt'     => '2026-09-04 16:00:00',
				],
				$args
			)
		);
		if ( '' === ( $args['comment_type'] ?? 'comment' ) ) {
			// wp_insert_comment() turns an empty type into 'comment'; IndieBlocks rows keep ''.
			global $wpdb;
			$wpdb->update( $wpdb->comments, [ 'comment_type' => '' ], [ 'comment_ID' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			clean_comment_cache( $id );
		}
		foreach ( $meta as $key => $value ) {
			add_comment_meta( $id, $key, $value );
		}

		return (int) $id;
	}

	/**
	 * @return array<string, array{0:string,1:array<string,string>,2:bool}>
	 */
	public static function answer_formats(): array {
		return [
			'native empty type'       => [ '', [], true ],
			'native comment type'     => [ 'comment', [], true ],
			'Webmention 120 chars'    => [ 'comment', [ 'protocol' => 'webmention', 'webmention_source_url' => 'https://b.example.org/reply/120', 'body' => '120' ], true ],
			'Webmention 31 chars'     => [ 'comment', [ 'protocol' => 'webmention', 'webmention_source_url' => 'https://b.example.org/reply/31', 'body' => '31' ], true ],
			'IndieBlocks reply'       => [ '', [ 'indieblocks_webmention_kind' => 'reply', 'webmention_source_url' => 'https://ib.example.net/reply' ], true ],
			'IndieBlocks like'        => [ '', [ 'indieblocks_webmention_kind' => 'like', 'webmention_source_url' => 'https://ib.example.net/like' ], false ],
			'Social importer mention' => [ 'comment', [ 'protocol' => 'webmention', '_swi_original_source_url' => 'https://social.example.net/a', 'webmention_source_url' => 'https://social.example.net/a' ], true ],
			'social-linkback'         => [ 'comment', [ 'protocol' => 'social-linkback', 'webmention_source_url' => 'https://social.example.net/b' ], true ],
		];
	}

	/** @dataProvider answer_formats */
	public function test_each_supported_comment_format_is_classified_by_its_stored_shape( string $type, array $meta, bool $expected ): void {
		$post_id = $this->question();
		$body     = $meta['body'] ?? '';
		unset( $meta['body'] );
		$content = '120' === $body ? str_repeat( 'a', 120 ) : ( '31' === $body ? str_repeat( 'b', 31 ) : 'Answer body' );
		$id      = $this->comment( $post_id, [ 'comment_type' => $type, 'comment_content' => $content ], $meta );

		$this->assertSame( $expected, Answer_Classifier::is_answer( get_comment( $id ), get_post( $post_id ) ) );
		$this->assertSame( $expected ? [ $id ] : [], Answer_Classifier::answer_ids( $post_id ) );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function excluded_types(): array {
		return [
			'mention 400 characters' => [ 'mention', str_repeat( 'm', 400 ) ],
			'like'                   => [ 'like', 'Like' ],
			'repost'                 => [ 'repost', 'Repost' ],
			'favorite'               => [ 'favorite', 'Favorite' ],
			'bookmark'               => [ 'bookmark', 'Bookmark' ],
			'pingback'               => [ 'pingback', 'Pingback' ],
			'trackback'              => [ 'trackback', 'Trackback' ],
			'rsvp yes'               => [ 'rsvp:yes', 'RSVP' ],
		];
	}

	/** @dataProvider excluded_types */
	public function test_non_answer_comment_types_are_excluded_before_php_rules( string $type, string $content ): void {
		$post_id = $this->question();
		$this->comment( $post_id, [ 'comment_type' => $type, 'comment_content' => $content ], [ 'protocol' => 'webmention' ] );

		$this->assertSame( [], Answer_Classifier::answers( $post_id ) );
	}

	public function test_pending_spam_trash_post_trashed_and_children_are_excluded(): void {
		$post_id = $this->question();
		$parent  = $this->comment( $post_id, [ 'comment_type' => 'like' ] );
		$this->comment( $post_id, [ 'comment_approved' => '0' ] );
		$this->comment( $post_id, [ 'comment_approved' => 'spam' ] );
		$this->comment( $post_id, [ 'comment_approved' => 'trash' ] );
		$this->comment( $post_id, [ 'comment_approved' => 'post-trashed' ] );
		$this->comment( $post_id, [ 'comment_parent' => $parent ] );

		$this->assertSame( 0, Answer_Classifier::count( $post_id ) );
	}

	public function test_post_author_is_excluded_but_a_second_administrator_counts(): void {
		$post_id = $this->question();
		$other   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->comment( $post_id, [ 'user_id' => $this->author_id, 'comment_author' => 'Post author' ] );
		$counted = $this->comment( $post_id, [ 'user_id' => $other, 'comment_author' => 'Second administrator' ] );

		$this->assertSame( [ $counted ], Answer_Classifier::answer_ids( $post_id ) );
	}

	public function test_self_sent_webmentions_are_excluded_before_protocol_but_a_subdomain_counts(): void {
		$post_id = $this->question();
		$this->comment( $post_id, [], [ 'protocol' => 'webmention', 'webmention_source_url' => home_url( '/self-ping/' ) ] );
		$this->comment( $post_id, [], [ 'protocol' => 'webmention', 'webmention_source_url' => 'https://www.' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/self-ping-www/' ] );
		$subdomain = $this->comment( $post_id, [], [ 'protocol' => 'webmention', 'webmention_source_url' => 'https://sub.' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/reply/' ] );

		$this->assertSame( [ $subdomain ], Answer_Classifier::answer_ids( $post_id ) );
	}

	public function test_atproto_and_rsschat_are_excluded_by_default_and_filterable(): void {
		$post_id = $this->question();
		$atproto = $this->comment( $post_id, [], [ 'protocol' => 'atproto' ] );
		$rsschat = $this->comment( $post_id, [], [ 'protocol' => 'rsschat' ] );

		$this->assertSame( [ 'webmention', 'social-linkback' ], Answer_Classifier::PROTOCOLS );
		$this->assertSame( [], Answer_Classifier::answer_ids( $post_id ) );
		$add = static fn( array $protocols ): array => array_merge( $protocols, [ 'atproto', 'rsschat' ] );
		add_filter( 'pkiw_answer_protocols', $add );
		$this->filters[] = [ 'hook' => 'pkiw_answer_protocols', 'callback' => $add, 'priority' => 10 ];
		Answer_Classifier::reset();

		$this->assertSame( [ $atproto, $rsschat ], Answer_Classifier::answer_ids( $post_id ) );
	}

	public function test_answers_sort_by_gmt_date_then_comment_id(): void {
		$post_id = $this->question();
		$late    = $this->comment( $post_id, [ 'comment_date_gmt' => '2026-09-04 18:00:00' ] );
		$first   = $this->comment( $post_id, [ 'comment_date_gmt' => '2026-09-04 15:00:00' ] );
		$tie     = $this->comment( $post_id, [ 'comment_date_gmt' => '2026-09-04 15:00:00' ] );

		$this->assertSame( [ $first, $tie, $late ], Answer_Classifier::answer_ids( $post_id ) );
		$this->assertSame( [ $first, $tie, $late ], array_map( static fn( WP_Comment $comment ): int => (int) $comment->comment_ID, Answer_Classifier::answers( $post_id ) ) );
	}

	public function test_pre_get_comments_stand_ins_do_not_change_the_result(): void {
		$post_id = $this->question();
		$answer  = $this->comment( $post_id );
		$before  = Answer_Classifier::answer_ids( $post_id );
		foreach ( [ 10, 11, 11 ] as $priority ) {
			$callback = static function ( WP_Comment_Query $query ): void {
				$query->query_vars['type__not_in'] = [ 'comment', '' ];
				$query->query_vars['meta_query']   = [ [ 'key' => 'stand-in-never-present' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			};
			add_action( 'pre_get_comments', $callback, $priority );
			$this->filters[] = [ 'hook' => 'pre_get_comments', 'callback' => $callback, 'priority' => $priority ];
		}
		Answer_Classifier::reset();

		$this->assertSame( [ $answer ], $before );
		$this->assertSame( $before, Answer_Classifier::answer_ids( $post_id ) );
	}

	public function test_cache_changes_after_comment_status_changes(): void {
		$post_id = $this->question();
		$pending = $this->comment( $post_id, [ 'comment_approved' => '0' ] );

		$this->assertSame( 0, Answer_Classifier::count( $post_id ) );
		$this->assertTrue( wp_set_comment_status( $pending, 'approve' ) );
		$this->assertSame( 1, Answer_Classifier::count( $post_id ) );
	}
}
