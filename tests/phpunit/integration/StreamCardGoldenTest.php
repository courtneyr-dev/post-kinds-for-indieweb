<?php
/**
 * Golden hashes for the Stream card of every kind and for three singles.
 *
 * W5-P0C changes shared Stream code (entry properties, generic card cuts) and
 * the singular microformats, so every public card and the review, article and
 * audio singles are rendered with fixed IDs, dates and an author, and the
 * sha1 of each output is compared with a row captured from pkiw-w4 (b55adde).
 * Only the audio rows differ from pkiw-w4 on purpose: the audio card drops its
 * media region, excerpt, Read more link and badge, and the audio single gains
 * a p-name, an e-content wrapper and a hidden u-audio. Those rows were recorded
 * from the W5-P0C head. W5-P235 changes the jam rows (the generic card and the
 * jam-card post) because a jam now draws its own compact Stream card.
 *
 * To re-record, run with PKIW_GOLDEN_DUMP=/path/rows.json and read the file.
 *
 * @package PKIW
 */

declare(strict_types=1);

/**
 * @group integration
 */
final class StreamCardGoldenTest extends WP_UnitTestCase {

	/**
	 * Author ID and first post ID used by the fixtures.
	 */
	private const AUTHOR_ID = 7001;
	private const FIRST_ID  = 9001;

	/**
	 * Expected sha1 of each rendered row.
	 *
	 * @var array<string, string>
	 */
	private const GOLDEN = [
		'acquisition|generic-titled'     => 'd1bc65cb4d6c1ba28a34bafa06b114d7bc26c8fc',
		'acquisition|generic-untitled'   => '44e10676db3d0574816e506b41878d4d0d8e18df',
		'article|generic-titled'         => '41d0e49649719b0b1f84b6b2278feadb4ad31920',
		'article|generic-untitled'       => '8ad3286e9e3e4e4514f2da6cbdcf4b8f6409b6fb',
		// Recorded from the W5-P0C head: this row changes on purpose.
		'audio|generic-titled'           => '91fd585ee70d90e0b4f5ad2d951d862a5510ac29',
		// Recorded from the W5-P0C head: this row changes on purpose.
		'audio|generic-untitled'         => '7509392adf8ff86f713cb4541b2f8a76c0f83681',
		'block:acquisition|card'         => 'ff8d22f81801b5dc93f728a2af21c4f7174a729c',
		'block:bookmark|card'            => '417930c77cb08b194ec47fa7105fa27ccc2f9428',
		'block:checkin|card'             => 'f93377e3a94d035b3953ee121b0eaf03d708e021',
		'block:comic|card'               => 'd2dcd504084f0df3a180a5ca6abb2eb5a405a729',
		'block:craft|card'               => '8eb4c5b30cc160f51a265d7abd1690eaa68f550b',
		'block:drink|card'               => '5c90251cd0f1b349c77655c56c4682f188999c82',
		'block:eat|card'                 => '693518ddba1af4dd4953493b06d4467c9731b352',
		'block:event|card'               => 'aba562b4d317b335e7d67f9c8df0265442e9c794',
		'block:exercise|card'            => '29913fd6883b345e57c0aaeefd345a9d429d1ce2',
		'block:favorite|card'            => 'fd4dc9233d18eb80b8bb15cea09cfec64a77e5cc',
		// Recorded from the W5-P235 head: this row changes on purpose.
		'block:jam|card'                 => '9c1e62d034ef3fcf1e1e620219723d87d9cfa204',
		'block:like|card'                => 'f7df746df2a5e3cf54977c55efc36ff7aebd6e6f',
		'block:listen|card'              => '3039b72d425574f8e98034d7249ff88b56965793',
		'block:mood|card'                => '8833459451497a6c6cbb5c52bc5c05080ae6d867',
		'block:play|card'                => '470ceca017d67307c53d09fe05cd4f766b0cb0ad',
		'block:read|card'                => 'a103d344ea0a3daa9c6afeb603c2717ef811353d',
		'block:reply|card'               => '199db1be05a9309acb2422926d0947423a1bd11b',
		'block:repost|card'              => 'ab51fce6695f7a5e7de636d365c52e3048350c51',
		'block:rsvp|card'                => 'd6d6ee8d47e320c0dc6c9d0ada007851ab4dea65',
		'block:sleep|card'               => 'c2e897e48c0afa125cfc331fde3e9fb44c0b1c8d',
		'block:watch|card'               => '6dddfa9574216931da0d40d39f329ed722609bb3',
		// Recorded from the W5-T238 fidelity fix: this row changes on purpose (Stream chips replace the price chip; the View item link gains an aria-hidden external-link icon).
		'block:wish|card'                => 'cb7da01d6f0660daeaa44cbf67cb7e185271ccf9',
		'bookmark|generic-titled'        => '12890b60a11b4001f25e040ebc4b0b792be1a3a2',
		'bookmark|generic-untitled'      => '1495408c6baad4667d43e75515204d7d42ce4e21',
		'checkin|generic-titled'         => '444ee2ed8c8f1ddc947c133791eb8c21d94d7af8',
		'checkin|generic-untitled'       => '50b03279fa27827056a5be6c501bcbc3fbeefceb',
		'chicken|generic-titled'         => '1468d836a30317f8a28b7c9438a535325518be0c',
		'chicken|generic-untitled'       => 'c045e01ba87bdceebdb208ec738a07721c5edffd',
		'collection|generic-titled'      => 'd64c4fb49d4de546716cd26ecd7dd5ebef14475a',
		'collection|generic-untitled'    => '59492e0839baa9bc3febc480224a172e82e94dd7',
		'comics|generic-titled'          => '55f07833f56b1d76870d55105b5b4b98b91713c5',
		'comics|generic-untitled'        => '2d79a2a62c0edac0ad6f123b67aca43abe9ad5bd',
		'craft|generic-titled'           => '55a82be0b2d726b4289bb051ff12992863492fc6',
		'craft|generic-untitled'         => '7bfc9bb49260bc01214e5fa628fd688e02633ec8',
		'drink|generic-titled'           => '358d902b8dbd44e13b606b21c2be9b1007832f7c',
		'drink|generic-untitled'         => '694c29dde799fd7e7c929d4b9475acb409278aff',
		'eat|generic-titled'             => '6f76adc2e15f1b5674239b6bdef0447a991b9543',
		'eat|generic-untitled'           => '22bea75b6683259a718b0b5f59394fbcc69bd745',
		'event|generic-titled'           => '192b9b845de39ca2995586ba4d878f74a7d1273b',
		'event|generic-untitled'         => 'd1c5635f629e4f2eb691926ba47a02c1eb692b4a',
		'exercise|generic-titled'        => '3194d750078423a891e274b47dca01fb193a62c1',
		'exercise|generic-untitled'      => '38b61d38b0b0ad9a07616b0ad6673685a40b8a01',
		'favorite|generic-titled'        => '5d7047a355532ee0a73e21ad14f3ff44eec2d361',
		'favorite|generic-untitled'      => '5e32084d8df29c464e6a77cc01dd61d55ba65f11',
		'follow|generic-titled'          => 'fdddb0c0a71a528020d2abc03e26236c8ab9315b',
		'follow|generic-untitled'        => '86e0c6c901df0359c5d42034e77faf6abf070176',
		'issue|generic-titled'           => 'c74a8b0ec3c247b882a3038899e2e15bcdeda5b7',
		'issue|generic-untitled'         => '798a11d59d04687e91954bfa06e8d8c805dfcae4',
		'itinerary|generic-titled'       => 'ec1ff896f0f3ba23ba5730c108e0b6ee0412b34e',
		'itinerary|generic-untitled'     => 'c069a0b65e22da5566877e10032cde34ca54d993',
		// Recorded from the W5-P235 head: this row changes on purpose.
		'jam|generic-titled'             => '09759126b10c786f88b576adc42a6be0da79eb1f',
		// Recorded from the W5-P235 head: this row changes on purpose.
		'jam|generic-untitled'           => '05355c1a30dae61665cfc5a913890a279bc32d51',
		'like|generic-titled'            => '79f65c0f80ecb2d1c5a3e3ebb7c2abcdf94a7b7e',
		'like|generic-untitled'          => '71e9a3ace20218fff135bb5c7658084c348f74f2',
		'listen|generic-titled'          => 'f500726b767662839662bb878c9c26e05c5b0b21',
		'listen|generic-untitled'        => '6c535ee04fa877f197f947189a50f3bd1ae767e2',
		'mood|generic-titled'            => 'eb50045aaaa5395a5b761e47df448552a6856a44',
		'mood|generic-untitled'          => '3abc3038a116d511958488fd22e54b03e11a9ddd',
		'note|generic-titled'            => 'b423c4e7b419bcaa4288bfbae1402e7536687760',
		'note|generic-untitled'          => 'dd010fa03bc3cbb51d9b7c9a352f0c3c8be7eaf9',
		'photo|generic-titled'           => 'c40141b7914fb0405f4b15bb8c119f2c451cb035',
		'photo|generic-untitled'         => '0742069777e176fc3f05de235c9952358e927c92',
		'play|generic-titled'            => 'b21383ccdace26d92597d959077b2bd5c9e15c33',
		'play|generic-untitled'          => '54bbe339f8b37c81ebc10a0b33d35053c1208b4b',
		'presentation|generic-titled'    => 'b1c902b17d2f45b97f84d7b4a3391ae65ac8ee85',
		'presentation|generic-untitled'  => '3affe4a8c20bd95d455c9fbe2375803ed4d73bf9',
		// Recorded from the W5-P256 head: the question card now renders through Question_Archive::stream_card(), so this row changes on purpose.
		'question|generic-titled'        => '2f39ec11ce24952d7e2f566715be4fc2be73ec52',
		// Recorded from the W5-P256 head: see the row above.
		'question|generic-untitled'      => '6d6eefa75a419a749c628c5a967849b9e6111fde',
		'quote|generic-titled'           => '3dea8d240517d7791183b42b067e8ab35e119d89',
		'quote|generic-untitled'         => '910c2c7fb229d9d959a71eece8269d5360c4f0f3',
		'read|generic-titled'            => '26f2405d11dd85f59f13dca87c3ee65e6465335f',
		'read|generic-untitled'          => 'd377c20c6b1c1dd65c67a7de8d1202cb2572cbac',
		'recipe|generic-titled'          => 'f11a27e23117dc99b136854cdbd5bbf9356272b4',
		'recipe|generic-untitled'        => 'c89d3f89f86dbe56e4ec21175ca7220d62443b41',
		'reply|generic-titled'           => '2a0d5bf969cf6f828acc366cab83f189a938dd4d',
		'reply|generic-untitled'         => '6cae015e923d5d0a4b1c26c78ff1ec5f775c0d47',
		'repost|generic-titled'          => '82e0d5a11e1042d591694e98eef0add51461df8e',
		'repost|generic-untitled'        => '3a5758bb7ba13dc1d5573a37310253076619bd1c',
		'review|generic-titled'          => '5057ecfe90362d4bcf258c0d5215f5c05263c158',
		'review|generic-untitled'        => '2b8640893526a5ee15e2c5451bf05dd52f58c6c4',
		'rsvp|generic-titled'            => '4774e878dfee1d0b5c6fc3f8ab61ea7c7804a419',
		'rsvp|generic-untitled'          => 'f1c3d159b9de1707f243c727bcfec780bcc45c20',
		'single:article'                 => '86e79fe1471775c3c04101100eb0469b933046a0',
		// Recorded from the W5-P0C head: this row changes on purpose.
		'single:audio'                   => '2b45041b262566480427a8a2c22f403fa783a3ae',
		'single:review'                  => '612cc619b964642925a55216dc0169255aa62b1c',
		'sleep|generic-titled'           => '5328e2041759355a063f108ac80d36c61c314a92',
		'sleep|generic-untitled'         => '8ce50ad93b1316288da5ec7653b7ca75a5e7c524',
		'tag|generic-titled'             => '4fa9893d8947d068b71d1548df71979dc4b1f205',
		'tag|generic-untitled'           => '8284ba090d865fc81cd9351b0abc7bd92371592d',
		'trip|generic-titled'            => '988f5b501afb2671e5e2b8f9e3a35a2eb3028881',
		'trip|generic-untitled'          => 'c0796cae67e41fba9dc4f9ce4322e27ad87f741f',
		'video|generic-titled'           => 'c6d0b26bfc6875f8c079fc6688b6804f0874b2c9',
		'video|generic-untitled'         => '8d65fa1fabe7ace49bb55ba32531cfa19d31d6ed',
		'watch|generic-titled'           => '721ad34752745248396106ba3631e3f085df5a22',
		'watch|generic-untitled'         => 'cfb3d6d0d50b9c025f0f43c48d2ad0402c5daa52',
		'weather|generic-titled'         => '5c67540d433288b04172a82b69e0e57af7786b52',
		'weather|generic-untitled'       => 'a84e10ca82c6e1c4763057c4707dc556937bff5c',
		'wish|generic-titled'            => 'ea423c950b6bd7ea6ca21a599800e52045d1c7f8',
		'wish|generic-untitled'          => '410f31297a555a16bf711f2c10145c20e766048d',
	];

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', '__return_empty_array' );

		// The fixtures use fixed IDs from FIRST_ID up. Earlier tests in a full run can have rooted a post with the same ID as an entry, and the Microformats instance and the Stream card remember that for the whole process.
		unset( $GLOBALS['pkiw_stream_card_root_seen'] );
		$microformats = \PKIW\Plugin::get_instance()->get_microformats();
		if ( null !== $microformats ) {
			( new ReflectionProperty( $microformats, 'post_class_seen' ) )->setValue( $microformats, [] );
		}
	}

	public function test_every_public_card_and_three_singles_match_their_recorded_hashes(): void {
		$rows = $this->render_rows();

		$dump = getenv( 'PKIW_GOLDEN_DUMP' );
		if ( is_string( $dump ) && '' !== $dump ) {
			file_put_contents( $dump, (string) wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$this->assertGreaterThan( 60, count( $rows ), 'Every default kind and every card block renders.' );
		$this->assertSame( array_keys( self::GOLDEN ), array_keys( $rows ), 'The same rows exist.' );
		foreach ( self::GOLDEN as $key => $hash ) {
			$this->assertSame( $hash, $rows[ $key ], "Row {$key} is byte-identical to its recorded output." );
		}
	}

	/**
	 * Render every row.
	 *
	 * @return array<string, string> Row key => sha1 of the output.
	 */
	private function render_rows(): array {
		$taxonomy = \PKIW\Plugin::get_instance()->get_taxonomy();
		$this->assertInstanceOf( \PKIW\Taxonomy::class, $taxonomy );
		$defaults = $taxonomy->get_default_kinds();
		$kinds    = array_keys( $defaults );
		sort( $kinds );
		// Pin each term to its shipped name so an earlier test's term edits can't change a kind label.
		foreach ( $kinds as $kind ) {
			$term = term_exists( $kind, 'kind' );
			if ( ! $term ) {
				wp_insert_term( $defaults[ $kind ]['name'], 'kind', [ 'slug' => $kind ] );
			} else {
				wp_update_term( (int) $term['term_id'], 'kind', [ 'name' => $defaults[ $kind ]['name'] ] );
			}
		}

		global $wpdb;
		$created = wp_insert_user(
			[
				'user_login'   => 'golden-author',
				'user_pass'    => 'golden-pass',
				'user_email'   => 'golden-author@example.org',
				'display_name' => 'Golden Author',
				'role'         => 'editor',
			]
		);
		$this->assertIsInt( $created );
		// Core ignores import_id for users; give the author a fixed ID so author URLs repeat.
		$wpdb->update( $wpdb->users, [ 'ID' => self::AUTHOR_ID ], [ 'ID' => $created ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->usermeta, [ 'user_id' => self::AUTHOR_ID ], [ 'user_id' => $created ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		clean_user_cache( $created );
		clean_user_cache( self::AUTHOR_ID );
		$this->assertSame( self::AUTHOR_ID, (int) get_user_by( 'login', 'golden-author' )->ID, 'The author has a fixed ID.' );
		wp_set_current_user( 0 );

		$rows    = [];
		$next_id = self::FIRST_ID;

		foreach ( $kinds as $kind ) {
			foreach ( [ 'titled', 'untitled' ] as $variant ) {
				$post_id = $this->fixture(
					$next_id++,
					$kind,
					'titled' === $variant ? 'Golden ' . $kind : '',
					'<!-- wp:paragraph --><p>Body for the ' . $kind . ' fixture.</p><!-- /wp:paragraph -->'
				);
				$rows[ $kind . '|generic-' . $variant ] = sha1( $this->card( $post_id ) );
			}
		}

		// Card-rooted path: a post holding its own card block.
		$aliases = [ 'comic' => 'comics' ];
		$blocks  = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
		sort( $blocks );
		foreach ( $blocks as $name ) {
			if ( 1 !== preg_match( '#^post-kinds-indieweb/([a-z-]+)-card$#', $name, $match ) || 'stream' === $match[1] ) {
				continue;
			}
			$kind = $aliases[ $match[1] ] ?? $match[1];
			if ( ! in_array( $kind, $kinds, true ) ) {
				continue;
			}
			$post_id = $this->fixture(
				$next_id++,
				$kind,
				'Golden card ' . $kind,
				'<!-- wp:' . $name . ' {"title":"Golden item","url":"https://example.com/golden"} /-->'
			);
			$rows[ 'block:' . $match[1] . '|card' ] = sha1( $this->card( $post_id ) );
		}

		foreach ( [ 'review', 'article', 'audio' ] as $kind ) {
			$post_id                    = $this->fixture(
				$next_id++,
				$kind,
				'Golden single ' . $kind,
				'<!-- wp:paragraph --><p>Single body for the ' . $kind . ' fixture.</p><!-- /wp:paragraph -->'
			);
			$rows[ 'single:' . $kind ] = sha1( $this->single( $post_id ) );
		}

		ksort( $rows );
		return $rows;
	}

	/**
	 * A public post with a fixed ID, date and author.
	 *
	 * @param int    $id      Post ID.
	 * @param string $kind    Kind slug.
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @return int Post ID.
	 */
	private function fixture( int $id, string $kind, string $title, string $content ): int {
		$post_id = wp_insert_post(
			[
				'import_id'     => $id,
				'post_status'   => 'publish',
				'post_author'   => self::AUTHOR_ID,
				'post_title'    => $title,
				'post_name'     => 'golden-' . $id,
				'post_excerpt'  => 'Excerpt for ' . $kind,
				'post_content'  => $content,
				'post_date'     => '2026-01-02 03:04:05',
				'post_date_gmt' => '2026-01-02 03:04:05',
			]
		);
		$this->assertSame( $id, $post_id, 'The post has a fixed ID.' );
		wp_set_object_terms( $post_id, $kind, 'kind' );

		return $post_id;
	}

	/**
	 * The Stream card block rendered for a post inside a loop.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function card( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$html = '';
		while ( have_posts() ) {
			the_post();
			$html .= do_blocks( '<!-- wp:post-kinds-indieweb/stream-card /-->' );
		}
		wp_reset_postdata();

		return $html;
	}

	/**
	 * The singular content a visitor gets for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function single( int $post_id ): string {
		$this->go_to( get_permalink( $post_id ) );
		$html = '';
		while ( have_posts() ) {
			the_post();
			$html .= apply_filters( 'the_content', get_the_content() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		}
		wp_reset_postdata();

		return $html;
	}
}
