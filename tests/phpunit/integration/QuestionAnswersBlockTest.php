<?php
/**
 * The Answers settings block and rendered answer section.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Answer_Classifier;
use PKIW\Question_Answers;
use PKIW\Question_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuestionAnswersBlockTest extends WP_UnitTestCase {

	private int $author_id;

	/** @var array<string, int> */
	private array $comments = [];

	/** @var array<int, array{hook:string,callback:callable|array|string,priority:int}> */
	private array $filters = [];

	public function set_up(): void {
		parent::set_up();
		( new Taxonomy() )->create_default_terms();
		( new Question_Archive() )->register();
		( new Question_Answers() )->register();
		update_option( 'thread_comments', 1 );
		$this->author_id = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Post Author' ] );
		Answer_Classifier::reset();
	}

	public function tear_down(): void {
		foreach ( $this->filters as $filter ) {
			remove_filter( $filter['hook'], $filter['callback'], $filter['priority'] );
		}
		$this->filters = [];
		remove_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ], 12 );
		Answer_Classifier::reset();
		wp_set_current_user( 0 );
		$this->go_to( home_url( '/' ) );
		wp_reset_query();
		wp_reset_postdata();
		parent::tear_down();
	}

	private function question( array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => 'How do you name a new post kind?',
					'post_author'  => $this->author_id,
					'post_content' => '<!-- wp:paragraph --><p>Dear readers, this is S01.</p><!-- /wp:paragraph --><!-- wp:post-kinds-indieweb/answers /-->',
					'comment_status' => 'open',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'question', Taxonomy::TAXONOMY );

		return (int) $id;
	}

	private function comment( int $post_id, string $key, array $args = [], array $meta = [] ): int {
		$id = self::factory()->comment->create(
			array_merge(
				[
					'comment_post_ID'    => $post_id,
					'comment_approved'   => '1',
					'comment_type'       => '',
					'comment_parent'     => 0,
					'user_id'            => 0,
					'comment_author'     => 'Avery Quill',
					'comment_author_url' => 'https://a.example.net/',
					'comment_content'    => 'Text for ' . $key . '.',
					'comment_date'       => '2026-09-04 12:00:00',
					'comment_date_gmt'   => '2026-09-04 16:00:00',
				],
				$args
			)
		);
		if ( '' === ( $args['comment_type'] ?? '' ) ) {
			// wp_insert_comment() normalizes an empty type; IndieBlocks rows retain it.
			global $wpdb;
			$wpdb->update( $wpdb->comments, [ 'comment_type' => '' ], [ 'comment_ID' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			clean_comment_cache( $id );
		}
		foreach ( $meta as $name => $value ) {
			add_comment_meta( $id, $name, $value );
		}
		$this->comments[ $key ] = (int) $id;

		return (int) $id;
	}

	private function s01(): int {
		$id = $this->question();
		$c1 = $this->comment( $id, 'c01', [ 'comment_author' => 'Avery Quill', 'comment_author_url' => 'https://a.example.net/', 'comment_date_gmt' => '2026-09-04 15:00:00' ] );
		$this->comment( $id, 'c02', [ 'comment_type' => 'comment', 'comment_author' => 'Bryn Alder', 'comment_author_url' => '', 'comment_date_gmt' => '2026-09-04 16:00:00' ], [ 'protocol' => 'webmention', 'webmention_source_url' => 'https://b.example.org/reply/1' ] );
		$this->comment( $id, 'c03', [ 'comment_parent' => $c1, 'user_id' => $this->author_id, 'comment_author' => 'Post Author', 'comment_author_url' => '', 'comment_date_gmt' => '2026-09-04 17:00:00' ] );
		$this->comment( $id, 'c04', [ 'comment_type' => 'like' ], [ 'protocol' => 'webmention' ] );
		$this->comment( $id, 'c05', [ 'comment_approved' => '0', 'comment_author' => 'Corin Vale' ] );
		$this->comment( $id, 'c06', [ 'comment_approved' => 'spam' ] );
		$this->comment( $id, 'c07', [ 'user_id' => $this->author_id, 'comment_author' => 'Post Author', 'comment_author_url' => '' ] );
		Answer_Classifier::reset();

		return $id;
	}

	/**
	 * IndieBlocks removes reaction types from the singular comment list; the site runs it, so the count tests do too.
	 */
	private function indieblocks_stand_in(): void {
		$callback = static function ( WP_Comment_Query $query ): void {
			$query->query_vars['type__not_in'] = [ 'like', 'repost', 'favorite', 'bookmark', 'mention', 'pingback', 'trackback' ];
		};
		add_action( 'pre_get_comments', $callback, 10 );
		$this->filters[] = [ 'hook' => 'pre_get_comments', 'callback' => $callback, 'priority' => 10 ];
	}

	private function render_content( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id );
		setup_postdata( $GLOBALS['post'] );

		return apply_filters( 'the_content', (string) get_post_field( 'post_content', $post_id ) );
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	public function test_block_registers_in_php_and_renders_empty_in_every_context(): void {
		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'post-kinds-indieweb/answers' );

		$this->assertNotNull( $block );
		$this->assertSame( 3, $block->api_version );
		$this->assertSame( 'Answers', $block->attributes['headingLabel']['default'] );
		$this->assertSame( 2, $block->attributes['headingLevel']['default'] );
		$this->assertSame( 'Leave an answer', $block->attributes['responseLinkLabel']['default'] );
		$this->assertSame( 'content', $block->attributes['headingLabel']['role'] );
		$this->assertSame( 'content', $block->attributes['responseLinkLabel']['role'] );
		$this->assertFalse( $block->supports['multiple'] );
		$this->assertFalse( $block->supports['html'] );
		$this->assertFalse( $block->supports['reusable'] );
		$this->assertContains( 'pkiw-question-answers-editor', $block->editor_script_handles );
		$this->assertContains( 'pkiw-question-answers', $block->style_handles );
		$this->assertSame( '', render_block( [ 'blockName' => 'post-kinds-indieweb/answers', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => [] ] ) );
	}

	public function test_s01_heading_has_editable_label_count_and_answers_in_ascending_order(): void {
		$id    = $this->s01();
		$html  = $this->render_content( $id );
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//section[contains(@class,"pkiw-answers") and @aria-labelledby="pkiw-answers-' . $id . '"]' )->length );
		$this->assertSame( 'Answers 2', trim( $xpath->query( '//h2[@id="pkiw-answers-' . $id . '"]' )->item( 0 )->textContent ) );
		$items = $xpath->query( '//ol[contains(@class,"pkiw-answers__list")]/li[contains(@class,"pkiw-answer") and not(contains(@class,"pkiw-answer--reply"))]' );
		$this->assertSame( 2, $items->length );
		$this->assertSame( 'comment-' . $this->comments['c01'], $items->item( 0 )->getAttribute( 'id' ) );
		$this->assertSame( 'comment-' . $this->comments['c02'], $items->item( 1 )->getAttribute( 'id' ) );
		$this->assertSame( 2, Answer_Classifier::count( $id ) );
	}

	public function test_zero_answers_has_no_heading_or_list_and_response_link_only_when_open(): void {
		$open = $this->question( [ 'post_title' => 'Open S02' ] );
		$html = $this->render_content( $open );
		$this->assertStringContainsString( 'pkiw-answers pkiw-answers--empty', $html );
		$this->assertStringContainsString( 'href="#respond"', $html );
		$this->assertStringNotContainsString( 'pkiw-answers__list', $html );
		$this->assertStringNotContainsString( 'pkiw-answers__heading', $html );

		$closed = $this->question( [ 'post_title' => 'Closed A07', 'comment_status' => 'closed' ] );
		$this->assertStringNotContainsString( 'pkiw-answers', $this->render_content( $closed ) );
	}

	public function test_response_link_uses_saved_label_only_when_comments_are_open(): void {
		$id = $this->question(
			[
				'post_content' => '<!-- wp:post-kinds-indieweb/answers {"responseLinkLabel":"Offer a reply","headingLabel":"Responses"} /-->',
			]
		);
		$html = $this->render_content( $id );

		$this->assertStringContainsString( '>Offer a reply<span aria-hidden="true">', $html );
		$this->assertStringNotContainsString( '>Responses<span aria-hidden="true">', $html );
	}

	public function test_child_follow_up_nests_under_c01_and_is_not_counted_as_an_answer(): void {
		$id    = $this->s01();
		$html  = $this->render_content( $id );
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//li[@id="comment-' . $this->comments['c01'] . '"]//ol[contains(@class,"pkiw-answer__replies")]/li[@id="comment-' . $this->comments['c03'] . '" and contains(@class,"pkiw-answer--reply")]' )->length );
		$this->assertSame( 2, Answer_Classifier::count( $id ) );
		$this->assertSame( 1, substr_count( $html, 'Text for c03.' ) );
	}

	public function test_author_microformats_and_native_and_webmention_date_targets(): void {
		$id    = $this->s01();
		$html  = $this->render_content( $id );
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//li[@id="comment-' . $this->comments['c01'] . '"]//span[contains(@class,"p-author") and contains(@class,"h-card")]/a[contains(@class,"p-name") and contains(@class,"u-url") and @rel="external nofollow ugc" and @href="https://a.example.net/"]' )->length );
		$this->assertSame( get_comment_link( $this->comments['c01'] ), $xpath->query( '//li[@id="comment-' . $this->comments['c01'] . '"]/a[@class="u-url"]' )->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( 'https://b.example.org/reply/1', $xpath->query( '//li[@id="comment-' . $this->comments['c02'] . '"]/a[@class="u-url"]' )->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( 1, $xpath->query( '//li[@id="comment-' . $this->comments['c02'] . '"]//span[@class="p-name"]' )->length );
	}

	public function test_comment_html_allowlist_strips_every_remote_media_and_script_tag(): void {
		$id = $this->question();
		$body = '<p>Allowed <strong>answer</strong>.</p><img src="https://remote.example/a.jpg"><picture><source srcset="x"></picture><video src="x"></video><audio src="x"></audio><iframe src="x"></iframe><object data="x"></object><embed src="x"><svg><circle/></svg><style>x</style><script>alert(1)</script>';
		$this->comment( $id, 'media', [ 'comment_content' => $body ] );
		$html = $this->render_content( $id );

		$this->assertStringContainsString( '<strong>answer</strong>', $html );
		foreach ( [ '<img', '<picture', '<source', '<video', '<audio', '<iframe', '<object', '<embed', '<svg', '<style', '<script' ] as $tag ) {
			$this->assertStringNotContainsString( $tag, $html );
		}
	}

	public function test_a01_answer_omits_seed_cove_and_restores_simple_location_comment_filter_exactly(): void {
		$id      = $this->question( [ 'post_title' => 'A01 located answer' ] );
		$comment = $this->comment( $id, 'A01', [ 'comment_content' => 'A01 answer text.' ] );
		foreach ( [ 'geo_latitude' => '0.1', 'geo_longitude' => '0.2', 'geo_address' => 'Seed Cove, Test Ocean', 'geo_public' => '1' ] as $key => $value ) {
			add_comment_meta( $comment, $key, $value );
		}
		add_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ], 12, 3 );
		$probe = static function ( string $text ): string {
			return false !== has_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ] ) ? $text . '<p>Seed Cove, Test Ocean</p>' : $text;
		};
		add_filter( 'get_comment_text', $probe, 12 );
		$this->filters[] = [ 'hook' => 'get_comment_text', 'callback' => $probe, 'priority' => 12 ];

		$html = $this->render_content( $id );

		$this->assertStringContainsString( 'A01 answer text.', $html );
		$this->assertStringNotContainsString( 'Seed Cove', $html );
		$this->assertSame( 12, has_filter( 'get_comment_text', [ 'Geo_Data', 'location_comment' ] ) );
	}

	public function test_non_question_comment_text_keeps_the_simple_location_line(): void {
		$note = self::factory()->post->create( [ 'post_status' => 'publish' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );
		$comment = $this->comment( $note, 'note-location', [ 'comment_content' => 'Note comment.' ] );
		$probe = static fn( string $text ): string => $text . '<p>Seed Cove, Test Ocean</p>';
		add_filter( 'get_comment_text', $probe, 12 );
		$this->filters[] = [ 'hook' => 'get_comment_text', 'callback' => $probe, 'priority' => 12 ];

		$this->assertStringContainsString( 'Seed Cove, Test Ocean', get_comment_text( $comment ) );
	}

	public function test_rendered_answers_are_excluded_from_core_queries_but_author_comment_remains(): void {
		$id = $this->s01();
		$this->render_content( $id );
		$query = new WP_Comment_Query( [ 'post_id' => $id, 'status' => 'approve', 'hierarchical' => 'flat' ] );
		$ids   = array_map( 'intval', wp_list_pluck( $query->comments, 'comment_ID' ) );

		$this->assertNotContains( $this->comments['c01'], $ids );
		$this->assertNotContains( $this->comments['c02'], $ids );
		$this->assertContains( $this->comments['c07'], $ids );
	}

	public function test_without_section_render_every_answer_stays_in_core_query(): void {
		$id = $this->s01();
		$query = new WP_Comment_Query( [ 'post_id' => $id, 'status' => 'approve', 'hierarchical' => 'flat' ] );
		$ids   = array_map( 'intval', wp_list_pluck( $query->comments, 'comment_ID' ) );

		$this->assertContains( $this->comments['c01'], $ids );
		$this->assertContains( $this->comments['c02'], $ids );
	}

	public function test_pending_top_level_reply_is_not_an_answer_and_pending_child_can_nest_for_its_viewer(): void {
		$id     = $this->s01();
		$viewer = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $viewer );
		$top = $this->comment( $id, 'pending-top', [ 'comment_approved' => '0', 'user_id' => $viewer ] );
		$child = $this->comment( $id, 'pending-child', [ 'comment_approved' => '0', 'user_id' => $viewer, 'comment_parent' => $this->comments['c01'] ] );

		$html = $this->render_content( $id );
		$this->assertStringNotContainsString( 'Text for pending-top.', $html );
		$this->assertStringContainsString( 'Text for pending-child.', $html );
		$this->assertNotContains( $top, Answer_Classifier::answer_ids( $id ) );
		$this->assertNotContains( $child, Answer_Classifier::answer_ids( $id ) );

		$block = new WP_Block(
			[
				'blockName'    => 'core/comment-template',
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			],
			[ 'postId' => $id ]
		);
		$query = new WP_Comment_Query( build_comment_query_vars_from_block( $block ) );
		$core  = (string) wp_list_comments( [ 'echo' => false ], $query->comments );

		$this->assertSame( 1, substr_count( $core, 'Text for pending-top.' ) );
		$this->assertStringContainsString( 'awaiting moderation', strtolower( $core ) );
		$this->assertStringNotContainsString( 'Text for pending-child.', $core );
		$this->assertSame( 1, substr_count( $html . $core, 'Text for pending-top.' ) );
		$this->assertSame( 1, substr_count( $html . $core, 'Text for pending-child.' ) );
	}

	public function test_comments_title_count_is_a_string_and_equals_the_remaining_core_list(): void {
		$id = $this->s01();
		$this->indieblocks_stand_in();
		$this->render_content( $id );
		$count = apply_filters( 'get_comments_number', 99, $id );

		$this->assertIsString( $count );
		$this->assertSame( '1', $count );
	}

	public function test_count_filter_runs_at_1001_so_indieblocks_at_999_cannot_overwrite_it(): void {
		$id = $this->s01();
		$this->indieblocks_stand_in();
		// IndieBlocks replaces get_comments_number at 999 with its own SQL count (4 for S01: c01, c02, c03, c07).
		$overwrite = static fn( $count, $post_id ): string => '4';
		add_filter( 'get_comments_number', $overwrite, 999, 2 );
		$this->filters[] = [ 'hook' => 'get_comments_number', 'callback' => $overwrite, 'priority' => 999 ];
		$this->render_content( $id );

		$this->assertSame( 1001, has_filter( 'get_comments_number', [ Question_Answers::class, 'remaining_comment_count' ] ) );
		$this->assertSame( '1', apply_filters( 'get_comments_number', 4, $id ) );
		$this->assertSame( '1', (string) get_comments_number( $id ), 'The Comments title reads the measured list, not the 999 count.' );
	}

	public function test_zero_answer_post_count_is_untouched(): void {
		$id = $this->question();
		$this->render_content( $id );

		$this->assertSame( 17, apply_filters( 'get_comments_number', 17, $id ) );
	}

	public function test_replies_under_answer_are_not_double_counted_in_comments_title(): void {
		$id = $this->s01();
		$this->indieblocks_stand_in();
		$this->render_content( $id );

		$this->assertSame( '1', apply_filters( 'get_comments_number', 4, $id ) );
	}

	public function test_pre_get_comments_stand_ins_do_not_change_answers_or_measured_count(): void {
		$id = $this->s01();
		$this->indieblocks_stand_in();
		foreach ( [ 10, 11, 11 ] as $priority ) {
			$callback = static function ( WP_Comment_Query $query ): void {
				$query->query_vars['orderby'] = 'comment_date_gmt';
			};
			add_action( 'pre_get_comments', $callback, $priority );
			$this->filters[] = [ 'hook' => 'pre_get_comments', 'callback' => $callback, 'priority' => $priority ];
		}
		$html = $this->render_content( $id );

		$this->assertSame( 2, Answer_Classifier::count( $id ) );
		$this->assertSame( '1', apply_filters( 'get_comments_number', 4, $id ) );
		$this->assertSame( 1, substr_count( $html, 'Text for c01.' ) );
		$this->assertSame( 1, substr_count( $html, 'Text for c02.' ) );
	}
}
