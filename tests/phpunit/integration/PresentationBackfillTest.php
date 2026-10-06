<?php
/**
 * Integration tests for the presentation backfill behind
 * `wp postkind presentation backfill [--dry-run]`.
 *
 * Posts are created, then stripped of the kind the save hook gave them,
 * to stand in for posts written before the classifier existed.
 *
 * @package PKIW
 */

declare(strict_types=1);

namespace PKIW\Tests\Integration;

use WP_UnitTestCase;
use PKIW\Plugin;
use PKIW\Presentation_Classifier;
use PKIW\Taxonomy;

class PresentationBackfillTest extends WP_UnitTestCase {

	private const OLD_MODIFIED = '2021-07-22 10:00:00';

	private const WPTV_URL = 'https://wordpress.tv/2021/07/22/hari-shanker-hauwa-abashiya-courtney-robertson-help-shape-content-on-learn-wordpress/';

	private const SLIDESHARE_URL = 'https://www.slideshare.net/slideshow/embed_code/key/fRg05TpkEwlzD9';

	private Taxonomy $taxonomy;

	/** @var array<string, int> */
	private array $ids = [];

	public function set_up(): void {
		parent::set_up();

		foreach ( [ 'presentation', 'note', 'article' ] as $slug ) {
			if ( ! term_exists( $slug, Taxonomy::TAXONOMY ) ) {
				wp_insert_term( $slug, Taxonomy::TAXONOMY );
			}
		}

		$taxonomy = Plugin::get_instance()->get_taxonomy();
		$this->assertInstanceOf( Taxonomy::class, $taxonomy );
		$this->taxonomy = $taxonomy;

		$wptv_embed  = '<!-- wp:embed {"url":"' . self::WPTV_URL . '","type":"video","providerNameSlug":"wordpress-tv-embed","responsive":true} -->'
			. "\n<figure class=\"wp-block-embed is-type-video is-provider-wordpress-tv-embed wp-block-embed-wordpress-tv-embed\"><div class=\"wp-block-embed__wrapper\">\n" . self::WPTV_URL . "\n</div></figure>\n<!-- /wp:embed -->";
		$slideshare  = '<p><iframe src="//www.slideshare.net/slideshow/embed_code/key/fRg05TpkEwlzD9" width="1200" height="628" frameborder="0" allowfullscreen> </iframe></p>';
		$youtube     = '<!-- wp:embed {"url":"https://www.youtube.com/watch?v=Zr1m5aYk0aQ","type":"video","providerNameSlug":"youtube","responsive":true} -->'
			. "\n<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v=Zr1m5aYk0aQ\n</div></figure>\n<!-- /wp:embed -->";
		$speakerdeck = '<!-- wp:embed {"url":"https://speakerdeck.com/courtneyr/blocks-for-everyone","type":"rich","providerNameSlug":"speaker-deck"} -->'
			. "\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttps://speakerdeck.com/courtneyr/blocks-for-everyone\n</div></figure>\n<!-- /wp:embed -->";

		// 7184-like: no kind, WordPress.tv recording.
		$this->ids['talk'] = $this->legacy_post( 'Help Shape Content on Learn WordPress', $wptv_embed, null );
		// 5221-like: article picked by a person, SlideShare deck.
		$this->ids['article'] = $this->legacy_post( 'Your Ultimate WordPress Website Checklist', $slideshare, 'article' );
		// FemTechConf-like: YouTube only.
		$this->ids['youtube'] = $this->legacy_post( 'FemTechConf', $youtube, null );
		// The core default term counts as not chosen.
		$this->ids['note'] = $this->legacy_post( 'Deck on a note', $speakerdeck, 'note' );
		// Already classified.
		$this->ids['done'] = $this->legacy_post( 'Already a presentation', $speakerdeck, 'presentation', true );
	}

	/**
	 * A post with the given content and kind, as an older install left it.
	 */
	private function legacy_post( string $title, string $content, ?string $kind, bool $auto = false ): int {
		global $wpdb;

		$post_id = self::factory()->post->create(
			[
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'publish',
			]
		);

		wp_delete_object_term_relationships( $post_id, Taxonomy::TAXONOMY );
		delete_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY );
		if ( null !== $kind ) {
			wp_set_object_terms( $post_id, $kind, Taxonomy::TAXONOMY );
		}
		if ( $auto && null !== $kind ) {
			update_post_meta( $post_id, Taxonomy::AUTO_KIND_META_KEY, $kind );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		$wpdb->update(
			$wpdb->posts,
			[
				'post_modified'     => self::OLD_MODIFIED,
				'post_modified_gmt' => self::OLD_MODIFIED,
			],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );

		return $post_id;
	}

	/**
	 * @return string[]
	 */
	private function kind_slugs( int $post_id ): array {
		$slugs = wp_get_object_terms( $post_id, Taxonomy::TAXONOMY, [ 'fields' => 'slugs' ] );
		return is_array( $slugs ) ? $slugs : [];
	}

	/**
	 * @param array<string, mixed> $report Backfill report.
	 * @return array<int, array<string, mixed>> Rows keyed by post ID.
	 */
	private function rows_by_id( array $report ): array {
		$rows = [];
		foreach ( $report['rows'] as $row ) {
			$rows[ $row['post_id'] ] = $row;
		}
		return $rows;
	}

	public function test_dry_run_reports_candidates_and_writes_nothing(): void {
		$report = Presentation_Classifier::backfill( $this->taxonomy, true );

		$this->assertSame( 2, $report['would_change'] );
		$this->assertSame( 0, $report['changed'] );
		$this->assertSame( 1, $report['protected'] );
		$this->assertSame( 1, $report['unchanged'] );
		$this->assertGreaterThanOrEqual( 5, $report['scanned'] );

		$rows = $this->rows_by_id( $report );
		$this->assertSame(
			[ $this->ids['talk'], $this->ids['article'], $this->ids['note'], $this->ids['done'] ],
			array_keys( $rows ),
			'candidates in ID order; the YouTube-only post is not one'
		);
		$this->assertSame( 'eligible', $rows[ $this->ids['talk'] ]['status'] );
		$this->assertSame( 'protected', $rows[ $this->ids['article'] ]['status'] );
		$this->assertSame( 'eligible', $rows[ $this->ids['note'] ]['status'] );
		$this->assertSame( 'same', $rows[ $this->ids['done'] ]['status'] );
		$this->assertSame( [ self::WPTV_URL ], $rows[ $this->ids['talk'] ]['signals']['talk_recording'] );

		$this->assertSame( [], $this->kind_slugs( $this->ids['talk'] ) );
		$this->assertSame( [ 'note' ], $this->kind_slugs( $this->ids['note'] ) );
		$this->assertSame( '', (string) get_post_meta( $this->ids['talk'], Taxonomy::AUTO_KIND_META_KEY, true ) );
		$this->assertSame( '', (string) get_post_meta( $this->ids['note'], Taxonomy::AUTO_KIND_META_KEY, true ) );
	}

	public function test_dry_run_lines_name_each_candidate_and_its_signals(): void {
		$report = Presentation_Classifier::backfill( $this->taxonomy, true );
		$rows   = $this->rows_by_id( $report );

		$this->assertSame(
			sprintf(
				'#%d "Help Shape Content on Learn WordPress": would change (no kind -> presentation); talk_recording: %s',
				$this->ids['talk'],
				self::WPTV_URL
			),
			Presentation_Classifier::backfill_line( $rows[ $this->ids['talk'] ], true )
		);
		$this->assertSame(
			sprintf(
				'#%d "Your Ultimate WordPress Website Checklist": protected (article, not auto-assigned); deck: %s',
				$this->ids['article'],
				self::SLIDESHARE_URL
			),
			Presentation_Classifier::backfill_line( $rows[ $this->ids['article'] ], true )
		);
		$this->assertStringStartsWith(
			sprintf( '#%d "Deck on a note": would change (note -> presentation); deck: ', $this->ids['note'] ),
			Presentation_Classifier::backfill_line( $rows[ $this->ids['note'] ], true )
		);
		$this->assertStringStartsWith(
			sprintf( '#%d "Already a presentation": already presentation; deck: ', $this->ids['done'] ),
			Presentation_Classifier::backfill_line( $rows[ $this->ids['done'] ], true )
		);
	}

	public function test_run_sets_only_the_kind_on_eligible_posts(): void {
		$report = Presentation_Classifier::backfill( $this->taxonomy, false );

		$this->assertSame( 2, $report['changed'] );
		$this->assertSame( 0, $report['would_change'] );
		$this->assertSame( 1, $report['protected'] );

		$this->assertSame( [ 'presentation' ], $this->kind_slugs( $this->ids['talk'] ) );
		$this->assertSame( 'presentation', get_post_meta( $this->ids['talk'], Taxonomy::AUTO_KIND_META_KEY, true ) );
		$this->assertSame( [ 'presentation' ], $this->kind_slugs( $this->ids['note'] ) );
		$this->assertSame( [ 'article' ], $this->kind_slugs( $this->ids['article'] ) );
		$this->assertSame( [], $this->kind_slugs( $this->ids['youtube'] ) );

		$rows = $this->rows_by_id( $report );
		$this->assertSame( 'changed', $rows[ $this->ids['talk'] ]['status'] );
		$this->assertStringContainsString( ': changed (no kind -> presentation);', Presentation_Classifier::backfill_line( $rows[ $this->ids['talk'] ], false ) );

		// Only the term moved: no post update, so no new modified date or revision.
		clean_post_cache( $this->ids['talk'] );
		$this->assertSame( self::OLD_MODIFIED, get_post( $this->ids['talk'] )->post_modified );
		$this->assertSame( [], wp_get_post_revisions( $this->ids['talk'] ) );
	}

	public function test_second_run_changes_nothing(): void {
		Presentation_Classifier::backfill( $this->taxonomy, false );
		$report = Presentation_Classifier::backfill( $this->taxonomy, false );

		$this->assertSame( 0, $report['changed'] );
		$this->assertSame( 3, $report['unchanged'] );
		$this->assertSame( 1, $report['protected'] );
	}

	public function test_missing_presentation_term_writes_nothing(): void {
		$term = get_term_by( 'slug', 'presentation', Taxonomy::TAXONOMY );
		$this->assertInstanceOf( \WP_Term::class, $term );
		wp_delete_term( $term->term_id, Taxonomy::TAXONOMY );

		$report = Presentation_Classifier::backfill( $this->taxonomy, false );

		$this->assertSame( 0, $report['changed'] );
		$this->assertSame( [], $report['rows'] );
		$this->assertNull( term_exists( 'presentation', Taxonomy::TAXONOMY ), 'the backfill must not create the term' );
		$this->assertSame( [], $this->kind_slugs( $this->ids['talk'] ) );
	}
}
