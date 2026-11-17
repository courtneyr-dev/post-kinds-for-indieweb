<?php
/** Zine privacy eval controls. @package PKIW */
declare(strict_types=1);

/** @group integration */
final class ZineEvalControlsTest extends WP_UnitTestCase {
	public function test_fixture_has_six_unique_control_spans_and_no_ability_reference(): void {
		$text = $this->fixture();
		for ( $case = 0; $case <= 5; $case++ ) {
			$this->assertSame( 1, substr_count( $text, '/*X269:' . $case . '*/' ) );
			$this->assertSame( 1, substr_count( $text, '/*X269:/' . $case . '*/' ) );
		}
		$this->assertStringNotContainsString( 'post-kinds/zine-manifest', $text );
		$this->assertStringNotContainsString( '<?php', $text );
	}

	public function test_unpatched_eval_passes_without_rows_or_stream_page(): void {
		$this->assertSame( 'RESULT PASS', $this->last_line( $this->fixture() ) );
	}

	public function test_unpatched_eval_passes_with_rows_and_stream_page(): void {
		( new PKIW\Taxonomy() )->create_default_terms();
		$post_id = self::factory()->post->create( [ 'post_status' => 'publish', 'post_password' => '', 'post_date' => '2025-03-09 12:00:00' ] );
		wp_set_object_terms( $post_id, 'recipe', PKIW\Taxonomy::TAXONOMY );
		$content = '<!-- wp:post-kinds-indieweb/zine-export {"source":"stream"} /--><!-- wp:query {"queryId":1,"query":{"perPage":10,"postType":"post","order":"desc","orderBy":"date","inherit":false}} --><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- /wp:query -->';
		$page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $content ] );
		$fixture = str_replace( '$page_id = 37724;', '$page_id = ' . $page_id . ';', $this->fixture() );
		$this->assertSame( 'RESULT PASS', $this->last_line( $fixture ) );
	}

	/** @dataProvider controls */
	public function test_each_patched_control_fails( int $case ): void {
		$text = $this->fixture();
		$start = strpos( $text, '/*X269:' . $case . '*/' );
		$end = strpos( $text, '/*X269:/' . $case . '*/' );
		$this->assertIsInt( $start );
		$this->assertIsInt( $end );
		$patched = substr( $text, 0, $start ) . '/*X269:' . $case . '*/ $fail = "control_' . $case . '"; ' . substr( $text, $end );
		$this->assertStringStartsWith( 'RESULT FAIL', $this->last_line( $patched ) );
	}

	public static function controls(): array {
		return [ [ 0 ], [ 1 ], [ 2 ], [ 3 ], [ 4 ], [ 5 ] ];
	}

	private function fixture(): string {
		$text = file_get_contents( dirname( __DIR__ ) . '/fixtures/privacy-evals/x-269-zine.php' );
		$this->assertIsString( $text );
		return $text;
	}

	private function last_line( string $code ): string {
		ob_start();
		try {
			eval( $code );
		} finally {
			$output = (string) ob_get_clean();
		}
		$lines = preg_split( '/\R/', trim( $output ) );
		return (string) end( $lines );
	}
}
