<?php
/**
 * The sectioned question archive and its 26-post fixture map.
 *
 * @package PKIW
 */

declare(strict_types=1);

use PKIW\Grouped_Archive;
use PKIW\Question_Archive;
use PKIW\Taxonomy;

/**
 * @group integration
 */
final class QuestionArchiveGroupingTest extends WP_UnitTestCase {

	/** @var array<string, int> */
	private array $ids = [];

	private string $original_stylesheet = '';

	public function set_up(): void {
		parent::set_up();
		$this->original_stylesheet = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		( new Taxonomy() )->create_default_terms();
		( new Question_Archive() )->register();
		update_option( 'posts_per_page', 20 );
		update_option( 'posts_per_rss', 30 );
		wp_set_current_user( 0 );
		$this->set_permalink_structure( '/%postname%/' );
		get_taxonomy( Taxonomy::TAXONOMY )->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		switch_theme( $this->original_stylesheet );
		$this->set_permalink_structure( '' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function question( string $key, string $title, string $date, bool $answered = false, array $args = [] ): int {
		$id = self::factory()->post->create(
			array_merge(
				[
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_date'    => $date,
					'post_content' => '<p>Question ' . esc_html( $key ) . '.</p>',
				],
				$args
			)
		);
		wp_set_object_terms( $id, 'question', Taxonomy::TAXONOMY );
		if ( $answered ) {
			self::factory()->comment->create(
				[
					'comment_post_ID'  => $id,
					'comment_approved' => '1',
					'comment_type'     => '',
					'comment_content'  => 'Answer for ' . $key,
				]
			);
		}
		Question_Archive::refresh_status( $id );
		$this->ids[ $key ] = (int) $id;

		return (int) $id;
	}

	private function seed(): void {
		$answered = [ 'S01', 'A01', 'A02', 'A03', 'A04', 'A05', 'A06', 'A07' ];
		$waiting  = [ 'S02', 'S03', 'W01', 'W02', 'W03', 'W04', 'W05', 'W06', 'W07', 'W08', 'W09', 'W10', 'W11', 'W12', 'L01' ];
		$day      = 28;
		foreach ( $answered as $key ) {
			$title = 'S01' === $key ? 'How do you name a new post kind?' : $key . ' answered question';
			$this->question( $key, $title, sprintf( '2026-08-%02d 10:00:00', $day-- ), true );
		}
		$day = 28;
		foreach ( $waiting as $key ) {
			$title = 'S02' === $key ? 'What do you do when a draft stops making sense?' : $key . ' waiting question';
			$this->question( $key, $title, sprintf( '2026-07-%02d 10:00:00', $day-- ) );
		}
		$this->question( 'S04', 'Seed Locked Question', '2026-06-03 10:00:00', true, [ 'post_password' => 'seed-pass-256' ] );
		$this->question( 'C01', 'Protected Question C01', '2026-06-02 10:00:00', true, [ 'post_password' => 'seed-pass-256-b' ] );
		$this->question( 'C02', 'Protected Question C02', '2026-06-01 10:00:00', true, [ 'post_password' => '   ' ] );
		$this->question( 'C03', 'Private Question', '2026-05-03 10:00:00', true, [ 'post_status' => 'private' ] );
		$this->question( 'C04', 'Draft Question', '2026-05-02 10:00:00', true, [ 'post_status' => 'draft' ] );
		$note = self::factory()->post->create( [ 'post_status' => 'publish', 'post_title' => 'C05 note' ] );
		wp_set_object_terms( $note, 'note', Taxonomy::TAXONOMY );
		$this->ids['C05'] = (int) $note;
	}

	private function archive_url(): string {
		$url = get_term_link( 'question', Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/question/', $url );

		return $url;
	}

	private function serve( string $url, array $attributes = [] ): string {
		// The main query groups from the template's entry marker at pre_get_posts, so the swap goes in before go_to().
		$swap = null;
		if ( [] !== $attributes ) {
			$swap = static function ( $templates ) use ( $attributes ) {
				foreach ( $templates as $template ) {
					if ( 'taxonomy-kind-question' === $template->slug ) {
						$template->content = (string) preg_replace(
							'/<!-- wp:post-kinds-indieweb\/question-entry \{[^}]*\} \/-->/',
							'<!-- wp:post-kinds-indieweb/question-entry ' . wp_json_encode( array_merge( [ 'linesPerPage' => 12 ], $attributes ) ) . ' /-->',
							(string) $template->content,
							1
						);
					}
				}

				return $templates;
			};
			add_filter( 'get_block_templates', $swap );
		}

		$this->go_to( $url );
		$template = resolve_block_template( 'taxonomy', [ 'taxonomy-kind-question.php', 'taxonomy-kind.php', 'taxonomy.php', 'archive.php', 'index.php' ], '' );
		if ( null !== $swap ) {
			remove_filter( 'get_block_templates', $swap );
		}
		$this->assertNotNull( $template );
		$this->assertSame( 'post-kinds-for-indieweb//taxonomy-kind-question', $template->id );

		global $_wp_current_template_id, $_wp_current_template_content;
		$_wp_current_template_id      = $template->id;
		$_wp_current_template_content = (string) $template->content;

		return get_the_block_template_html();
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();

		return new DOMXPath( $dom );
	}

	/** @return array<int, array{group:string,heading:string,titles:string[]}> */
	private function sections( string $html ): array {
		$xpath = $this->xpath( $html );
		$out   = [];
		foreach ( $xpath->query( '//section[@data-pkiw-sections]' ) as $section ) {
			$heading = $xpath->query( './*[1]', $section )->item( 0 );
			$titles  = [];
			foreach ( $xpath->query( './/h3/a', $section ) as $link ) {
				$titles[] = trim( $link->textContent );
			}
			$out[] = [
				'group'   => $section->getAttribute( 'data-pkiw-group' ),
				'heading' => $heading instanceof DOMElement ? trim( $heading->textContent ) : '',
				'titles'  => $titles,
			];
		}

		return $out;
	}

	public function test_question_registers_its_status_source_and_editor_labels(): void {
		$this->assertSame( 'question', Question_Archive::KIND );
		$this->assertSame( 'question_status', Question_Archive::SOURCE );
		$this->assertSame( '_pkiw_question_status', Question_Archive::STATUS_KEY );
		$this->assertSame( [ 'answered', 'waiting' ], Question_Archive::STATUSES );
		$this->assertContains( 'question', Grouped_Archive::grouped_kinds() );
		$source = Grouped_Archive::source( Question_Archive::SOURCE );
		$this->assertNotNull( $source );
		$this->assertTrue( $source->files_protected_as_empty() );
		$this->assertSame( [ 'answered' => 'Answered', 'waiting' => 'Waiting for an answer' ], Question_Archive::status_labels() );

		$groups = apply_filters( 'pkiw_archive_sections_groups', [] );
		$this->assertSame( Question_Archive::status_labels(), $groups['question'] );
	}

	public function test_page_one_is_answered_eight_then_waiting_four_with_global_counts(): void {
		$this->seed();
		$sections = $this->sections( $this->serve( $this->archive_url() ) );

		$this->assertSame( [ 'Answered', 'Waiting for an answer' ], array_column( $sections, 'heading' ) );
		$this->assertCount( 8, $sections[0]['titles'] );
		$this->assertCount( 4, $sections[1]['titles'] );
		$this->assertSame( [ 'answered', 'waiting' ], array_column( $sections, 'group' ) );
		$this->assertSame( 12, (int) $GLOBALS['wp_query']->get( 'posts_per_page' ) );
		$this->assertSame( 26, $GLOBALS['wp_query']->found_posts );
		$this->assertSame( 3, $GLOBALS['wp_query']->max_num_pages );
		$this->assertSame( 12, $GLOBALS['wp_query']->post_count );
	}

	public function test_page_two_repeats_waiting_eleven_and_starts_other_with_one(): void {
		$this->seed();
		$sections = $this->sections( $this->serve( $this->archive_url() . 'page/2/' ) );

		$this->assertSame( [ 'Waiting for an answer', 'Other' ], array_column( $sections, 'heading' ) );
		$this->assertCount( 11, $sections[0]['titles'] );
		$this->assertCount( 1, $sections[1]['titles'] );
		$this->assertSame( [ 'waiting', '' ], array_column( $sections, 'group' ) );
	}

	public function test_page_three_has_only_the_other_heading_and_two_protected_posts(): void {
		$this->seed();
		$sections = $this->sections( $this->serve( $this->archive_url() . 'page/3/' ) );

		$this->assertSame( [ 'Other' ], array_column( $sections, 'heading' ) );
		$this->assertCount( 2, $sections[0]['titles'] );
		$this->assertStringNotContainsString( 'Answered', wp_strip_all_tags( $this->serve( $this->archive_url() . 'page/3/' ) ) );
		$this->assertStringNotContainsString( 'Waiting for an answer', wp_strip_all_tags( $this->serve( $this->archive_url() . 'page/3/' ) ) );
	}

	public function test_page_four_is_a_404(): void {
		$this->seed();
		$this->serve( $this->archive_url() . 'page/4/' );

		$this->assertTrue( is_404() );
	}

	public function test_descending_section_order_puts_waiting_before_answered(): void {
		$this->seed();
		$sections = $this->sections( $this->serve( $this->archive_url(), [ 'sectionOrder' => 'desc' ] ) );

		$this->assertSame( 'Waiting for an answer', $sections[0]['heading'] );
		$this->assertSame( 'waiting', $sections[0]['group'] );
	}

	public function test_group_labels_and_empty_label_are_editable_attributes(): void {
		$this->seed();
		$sections = $this->sections(
			$this->serve(
				$this->archive_url(),
				[
					'groupLabels' => [ 'answered' => 'Solved', 'waiting' => 'Still open' ],
					'emptyLabel'  => 'Private questions',
				]
			)
		);

		$this->assertSame( [ 'Solved', 'Still open' ], array_column( $sections, 'heading' ) );
		$page_three = $this->sections( $this->serve( $this->archive_url() . 'page/3/', [ 'emptyLabel' => 'Private questions' ] ) );
		$this->assertSame( 'Private questions', $page_three[0]['heading'] );
	}

	public function test_question_feed_stays_date_descending_at_posts_per_rss(): void {
		$this->seed();
		$feed = get_term_feed_link( get_term_by( 'slug', 'question', Taxonomy::TAXONOMY )->term_id, Taxonomy::TAXONOMY );
		$this->assertSame( 'http://example.org/kind/question/feed/', $feed );
		$this->go_to( $feed );

		$this->assertTrue( is_feed() );
		$this->assertSame( 30, (int) $GLOBALS['wp_query']->get( 'posts_per_page' ) );
		$this->assertSame( '', (string) $GLOBALS['wp_query']->get( 'pkiw_group_source' ) );
		$dates  = wp_list_pluck( $GLOBALS['wp_query']->posts, 'post_date' );
		$sorted = $dates;
		rsort( $sorted );
		$this->assertSame( $sorted, $dates );
	}

	public function test_an_empty_term_prints_no_group_markup(): void {
		$html = $this->serve( $this->archive_url() );

		$this->assertSame( 0, $this->xpath( $html )->query( '//section[@data-pkiw-sections]' )->length );
	}
}
