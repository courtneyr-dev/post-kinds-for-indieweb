<?php
/** Zine privacy eval controls. @package PKIW */
declare(strict_types=1);

/**
 * The eval as written passes; each control injects a real fault and the eval names it.
 *
 * @group integration
 */
final class ZineEvalControlsTest extends WP_UnitTestCase {
	private int $page_id = 0;

	public function set_up(): void {
		parent::set_up();
		( new PKIW\Taxonomy() )->create_default_terms();
		self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	public function test_fixture_has_six_unique_spans_and_no_ability_reference(): void {
		$text = $this->fixture();
		for ( $case = 0; $case <= 5; $case++ ) {
			$this->assertSame( 1, substr_count( $text, '/*X269:' . $case . '*/' ) );
			$this->assertSame( 1, substr_count( $text, '/*X269:/' . $case . '*/' ) );
		}
		$this->assertStringNotContainsString( 'post-kinds/zine-manifest', $text );
		$this->assertStringNotContainsString( 'override_', $text, 'The eval runs on live: no test seam.' );
		$this->assertStringNotContainsString( '<?php', $text );
	}

	public function test_unpatched_eval_passes_without_rows_or_stream_page(): void {
		$this->assertSame( 'RESULT PASS', $this->run_eval( $this->fixture() ) );
	}

	public function test_unpatched_eval_passes_with_rows_and_stream_page(): void {
		$this->seed();
		$this->assertSame( 'RESULT PASS', $this->run_eval( $this->fixture() ) );
	}

	public function test_case_0_counts_a_write(): void {
		$code = $this->insert_after( 0, '$GLOBALS[\'wpdb\']->query( "UPDATE {$GLOBALS[\'wpdb\']->options} SET option_value = option_value WHERE 1 = 0" );' );
		$this->assertSame( 'RESULT FAIL write_attempted:1', $this->run_eval( $code ) );
	}

	public function test_case_1_detects_a_missing_block(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$block    = $registry->get_registered( 'post-kinds-indieweb/zine-export' );
		$this->assertInstanceOf( WP_Block_Type::class, $block );
		$registry->unregister( 'post-kinds-indieweb/zine-export' );
		try {
			$this->assertSame( 'RESULT FAIL seam_missing:zine', $this->run_eval( $this->fixture() ) );
		} finally {
			$registry->register( $block );
		}
	}

	public function test_case_2_detects_a_stripped_listing_subquery(): void {
		$this->seed();
		$strip = static fn( string $where ): string => (string) preg_replace( '/ AND NOT EXISTS \( SELECT 1 FROM \S+ pkiw_apv .*? \) \)/', '', $where );
		add_filter( 'posts_where', $strip, PHP_INT_MAX );
		try {
			$this->assertSame( 'RESULT FAIL query_unfiltered', $this->run_eval( $this->fixture() ) );
		} finally {
			remove_filter( 'posts_where', $strip, PHP_INT_MAX );
		}
	}

	public function test_case_3_detects_stream_vars_that_depend_on_the_caller(): void {
		$this->seed();
		$calls  = 0;
		$unstable = static function ( array $vars ) use ( &$calls ): array {
			$vars['pkiw_zine_control'] = ++$calls;
			return $vars;
		};
		add_filter( 'query_loop_block_query_vars', $unstable );
		try {
			$this->assertSame( 'RESULT FAIL stream_parity', $this->run_eval( $this->fixture() ) );
		} finally {
			remove_filter( 'query_loop_block_query_vars', $unstable );
		}
	}

	public function test_case_4_detects_rows_that_differ_between_contexts(): void {
		$this->seed();
		// Reverse the second stream build's order: the two included lists then differ.
		$fault = '$pkiw_control_calls = 0; add_filter( "posts_request", static function ( $sql, $query ) use ( &$pkiw_control_calls ) { if ( $query instanceof WP_Query && true === $query->get( "pkiw_public_listing" ) && 2 === ++$pkiw_control_calls ) { $sql = str_replace( " DESC", " ASC", $sql ); } return $sql; }, PHP_INT_MAX, 2 );';
		$this->assertSame( 'RESULT FAIL stream_parity_rows', $this->run_eval( $this->insert_after( 3, $fault ) ) );
	}

	public function test_case_5_detects_an_allowed_frame_in_an_entry(): void {
		$ids  = $this->seed();
		$code = $this->insert_before( 5, 'add_filter( "pkiw_zine_allowed_html", static fn( $allowed ) => $allowed + [ "iframe" => [ "src" => true ] ] );' );
		$this->assertSame( 'RESULT FAIL rows_real:' . $ids['frame'], $this->run_eval( $code ) );
	}

	public function test_case_5_accepts_core_gallery_class_counters(): void {
		$this->seed();
		$gallery = '$pkiw_gallery_calls = 0; add_filter( "render_block", static function ( $html ) use ( &$pkiw_gallery_calls ) { ++$pkiw_gallery_calls; return $html . "<figure class=\"wp-block-gallery " . wp_unique_id( "wp-block-gallery-" ) . "\"></figure>"; } );';
		$code = $this->insert_before( 5, $gallery );
		$code = str_replace( '/*X269:/5*/', 'if ( $pkiw_gallery_calls < 2 ) { $fail = "control_not_exercised"; } /*X269:/5*/', $code );
		$this->assertSame( 'RESULT PASS', $this->run_eval( $code ) );
	}

	public function test_case_5_detects_rendering_that_depends_on_the_user(): void {
		$this->seed();
		$code = $this->insert_before( 5, 'add_filter( "render_block", static fn( $html ) => $html . "<span>user-" . get_current_user_id() . "</span>" );' );
		$this->assertStringStartsWith( 'RESULT FAIL rows_real:', $this->run_eval( $code ) );
	}

	public function test_case_5_fails_when_no_administrator_can_be_compared(): void {
		$this->seed();
		foreach ( get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] ) as $admin ) {
			( new WP_User( (int) $admin ) )->set_role( 'editor' );
		}
		$this->assertSame( 'RESULT FAIL rows_real:no_admin', $this->run_eval( $this->fixture() ) );
	}

	/** Seed recipe rows, one with a frame in its content, and a stream page holding the block. */
	private function seed(): array {
		kses_remove_filters();
		$frame = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_date'    => '2025-03-10 12:00:00',
				'post_content' => '<!-- wp:html --><iframe src="https://qx7.example/"></iframe><!-- /wp:html -->',
			]
		);
		kses_init_filters();
		$older = self::factory()->post->create( [ 'post_status' => 'publish', 'post_date' => '2025-03-09 12:00:00' ] );
		foreach ( [ $frame, $older ] as $id ) {
			wp_set_object_terms( $id, 'recipe', PKIW\Taxonomy::TAXONOMY );
		}
		$content       = '<!-- wp:post-kinds-indieweb/zine-export {"source":"stream"} /--><!-- wp:query {"queryId":1,"query":{"perPage":10,"postType":"post","order":"desc","orderBy":"date","inherit":false}} --><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --><!-- /wp:query -->';
		$this->page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $content ] );

		return [
			'frame' => $frame,
			'older' => $older,
		];
	}

	private function insert_after( int $case, string $code ): string {
		$marker = '/*X269:/' . $case . '*/';
		return str_replace( $marker, $marker . "\n" . $code . "\n", $this->fixture() );
	}

	private function insert_before( int $case, string $code ): string {
		$marker = '/*X269:' . $case . '*/';
		return str_replace( $marker, $code . "\n" . $marker, $this->fixture() );
	}

	private function fixture(): string {
		$text = file_get_contents( dirname( __DIR__ ) . '/fixtures/privacy-evals/x-269-zine.php' );
		$this->assertIsString( $text );
		return $text;
	}

	/** Run the eval the way the gate does (plain PHP, no opener) and return its last line. */
	private function run_eval( string $code ): string {
		if ( 0 < $this->page_id ) {
			$code = str_replace( '$page_id = 37724;', '$page_id = ' . $this->page_id . ';', $code );
		}
		ob_start();
		try {
			eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- The gate runs this fixture through eval().
		} finally {
			$output = (string) ob_get_clean();
		}
		$lines = preg_split( '/\R/', trim( $output ) );
		return (string) end( $lines );
	}
}
