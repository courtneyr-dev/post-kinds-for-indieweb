<?php
/**
 * Integration tests for mood label spelling preferences (#207).
 *
 * Mood identity (the `moodKey` block attribute / `_pkiw_mood_key` meta) is
 * stored; the label shown for it resolves at read time from the
 * `mood_spelling` setting. These tests pin the resolution matrix, the
 * never-rewrite guarantee for stored content, and the REST contract that
 * editor pickers and consuming plugins read.
 *
 * @package PKIW
 */

use PKIW\Admin\Admin;
use PKIW\Admin\Settings_Page;
use PKIW\Integrations\Atmosphere_Titles;
use PKIW\Meta_Fields;
use PKIW\Mood_Vocabulary;
use PKIW\Plugin;

/**
 * @covers \PKIW\Mood_Vocabulary
 * @covers \PKIW\mood_card_accessible_name
 */
class MoodVocabularyTest extends WP_UnitTestCase {

	const DOMAIN = 'post-kinds-for-indieweb-in-block-themes';

	/**
	 * Site locale global before the test, restored afterwards.
	 *
	 * @var string|null
	 */
	private ?string $original_site_locale = null;

	public function set_up(): void {
		parent::set_up();

		$this->original_site_locale = $GLOBALS['locale'] ?? null;
		delete_option( 'pkiw_settings' );

		// Tests fake different translations in one process; start each from an
		// empty label cache (a real request never changes translations mid-way).
		( new ReflectionProperty( Mood_Vocabulary::class, 'cache' ) )->setValue( null, [] );

		// The test framework unregisters meta between tests.
		( new Meta_Fields() )->register_meta_fields();
	}

	public function tear_down(): void {
		if ( null === $this->original_site_locale ) {
			unset( $GLOBALS['locale'] );
		} else {
			$GLOBALS['locale'] = $this->original_site_locale;
		}
		unset( $GLOBALS['current_screen'] );
		wp_set_current_user( 0 );

		$this->assertFalse( is_locale_switched(), 'Label resolution leaked a locale switch.' );

		parent::tear_down();
	}

	/*
	 * Resolution matrix.
	 */

	public function test_default_setting_follows_site_language_en_us(): void {
		$this->assertSame( Mood_Vocabulary::SPELLING_SITE, Mood_Vocabulary::get_spelling() );
		$this->assertSame( 'en_US', Mood_Vocabulary::label_locale() );
		$this->assertSame( 'Energized', Mood_Vocabulary::get_label( 'energized' ) );
		$this->assertSame( 'Honored', Mood_Vocabulary::get_label( 'honored' ) );
	}

	public function test_default_setting_follows_site_language_en_gb(): void {
		$this->set_site_locale( 'en_GB' );

		$this->assertSame( 'en_GB', Mood_Vocabulary::label_locale() );
		$this->assertSame( 'Energised', Mood_Vocabulary::get_label( 'energized' ) );
		$this->assertSame( 'Honoured', Mood_Vocabulary::get_label( 'honored' ) );
		$this->assertSame( 'Happy', Mood_Vocabulary::get_label( 'happy' ), 'Labels without a British variant stay as-is.' );
	}

	public function test_explicit_us_spelling_overrides_en_gb_site_language(): void {
		$this->set_site_locale( 'en_GB' );
		$this->set_spelling( 'en_US' );

		$this->assertSame( 'en_US', Mood_Vocabulary::label_locale() );
		$this->assertSame( 'Energized', Mood_Vocabulary::get_label( 'energized' ) );
	}

	public function test_explicit_uk_spelling_on_en_us_site(): void {
		$this->set_spelling( 'en_GB' );

		$this->assertSame( 'en_GB', Mood_Vocabulary::label_locale() );
		$this->assertSame( 'Energised', Mood_Vocabulary::get_label( 'energized' ) );
		$this->assertSame( 'Demoralised', Mood_Vocabulary::get_label( 'demoralized' ) );
	}

	public function test_installed_en_gb_translation_wins_over_variant_map(): void {
		$this->fake_translations( [ 'en_GB' => [ 'Energized' => 'Full of beans' ] ] );
		$this->set_spelling( 'en_GB' );

		$mood = $this->mood( 'energized' );

		$this->assertSame( 'Full of beans', $mood['label'] );
		$this->assertSame( [ 'Energized', 'Energised', 'Full of beans' ], $mood['variants'] );
	}

	public function test_admin_user_locale_does_not_change_site_labels(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		update_user_meta( $admin, 'locale', 'de_DE' );
		wp_set_current_user( $admin );
		$this->enter_admin_screen();
		$this->fake_translations(
			[
				'de_DE' => [
					'Energized' => 'Energiegeladen',
					'Happy'     => 'Glücklich',
				],
			]
		);

		// Control: the fake translation is live for the admin's interface.
		$this->assertSame( 'de_DE', determine_locale() );
		$this->assertSame( 'Energiegeladen', __( 'Energized', self::DOMAIN ) );

		$data = $this->get_moods_response( 'current' )->get_data();

		$this->assertSame( 'en_US', $data['locale'] );
		$this->assertSame( 'Energized', $this->label_in( $data, 'energized' ) );
		$this->assertSame( 'Happy', $this->label_in( $data, 'happy' ) );
		$this->assertSame( 'de_DE', determine_locale(), 'The admin interface locale must be restored.' );
		$this->assertSame( 'Energiegeladen', __( 'Energized', self::DOMAIN ) );

		$html = do_blocks( $this->mood_card( 'Energized', 'energized' ) );
		$this->assertStringContainsString( 'aria-label="Energized"', $html );
	}

	public function test_non_english_site_uses_its_translation(): void {
		$this->set_site_locale( 'es_ES' );
		$this->fake_translations( [ 'es_ES' => [ 'Energized' => 'Con energía' ] ] );

		$mood = $this->mood( 'energized' );

		$this->assertSame( 'Con energía', $mood['label'] );
		$this->assertContains( 'Energized', $mood['variants'] );
		$this->assertSame( 'Tired', Mood_Vocabulary::get_label( 'tired' ), 'Untranslated strings fall back to the source label.' );
	}

	public function test_non_english_site_without_language_files_falls_back_to_source(): void {
		// fr_FR has no language files in the test install.
		$this->set_site_locale( 'fr_FR' );

		$this->assertSame( 'fr_FR', Mood_Vocabulary::label_locale() );
		$this->assertSame( 'Energized', Mood_Vocabulary::get_label( 'energized' ) );
		$this->assertSame( 'Bien, merci', Mood_Vocabulary::display_label( 'Bien, merci', 'energized' ) );
	}

	public function test_uninstalled_site_locale_does_not_borrow_admin_locale(): void {
		$this->set_site_locale( 'fr_FR' );
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		update_user_meta( $admin, 'locale', 'de_DE' );
		wp_set_current_user( $admin );
		$this->enter_admin_screen();
		$this->fake_translations( [ 'de_DE' => [ 'Energized' => 'Energiegeladen' ] ] );

		$this->assertSame( 'Energized', Mood_Vocabulary::get_label( 'energized' ) );
		$this->assertSame( 'de_DE', determine_locale() );
	}

	/*
	 * Stored content is never rewritten.
	 */

	public function test_existing_post_is_byte_identical_before_and_after_switching(): void {
		$content = implode(
			"\n\n",
			[
				$this->mood_card( 'Energized', 'energized' ),
				'<!-- wp:paragraph --><p>Honored to be asked. "Energized," she said.</p><!-- /wp:paragraph -->',
			]
		);
		$post_id = self::factory()->post->create( [ 'post_content' => $content ] );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'mood_label', 'Energized' );
		update_post_meta( $post_id, Meta_Fields::PREFIX . 'mood_key', 'energized' );

		$stored_content = $this->fresh_post( $post_id )->post_content;
		$stored_meta    = get_post_meta( $post_id );

		$us = do_blocks( $stored_content );
		$this->set_spelling( 'en_GB' );
		$uk = do_blocks( $this->fresh_post( $post_id )->post_content );
		$this->set_spelling( 'en_US' );
		$us_again = do_blocks( $this->fresh_post( $post_id )->post_content );

		$this->assertStringContainsString( 'aria-label="Energized"', $us );
		$this->assertStringContainsString( 'aria-label="Energised"', $uk );
		$this->assertSame( $us, $us_again );
		$this->assertStringContainsString( 'Honored to be asked. "Energized," she said.', $uk, 'Authored prose and quotations are untouched.' );

		$this->assertSame( $stored_content, $this->fresh_post( $post_id )->post_content );
		$this->assertSame( $content, $this->fresh_post( $post_id )->post_content );
		$this->assertSame( $stored_meta, get_post_meta( $post_id ) );
	}

	public function test_legacy_post_without_key_renders_as_authored(): void {
		$legacy = '<!-- wp:post-kinds-indieweb/mood-card {"mood":"Energized","emoji":"⚡"} /-->';

		$before = do_blocks( $legacy );
		$this->set_spelling( 'en_GB' );
		$after = do_blocks( $legacy );

		$this->assertSame( $before, $after );
		$this->assertStringContainsString( 'aria-label="Energized"', $after );
	}

	public function test_custom_and_edited_labels_are_preserved_exactly(): void {
		$this->set_spelling( 'en_GB' );

		$this->assertSame( 'Buzzing, mostly', Mood_Vocabulary::display_label( 'Buzzing, mostly', 'energized' ) );
		$this->assertSame( 'energized', Mood_Vocabulary::display_label( 'energized', 'energized' ) );
		$this->assertSame( 'Energized', Mood_Vocabulary::display_label( 'Energized' ) );
		$this->assertSame( 'Zen', Mood_Vocabulary::display_label( 'Zen', 'zen' ) );
		$this->assertSame( '', Mood_Vocabulary::display_label( '', 'zen' ) );

		$html = do_blocks( $this->mood_card( 'Buzzing, mostly', 'energized' ) );
		$this->assertStringContainsString( 'aria-label="Buzzing, mostly"', $html );
		$this->assertStringContainsString( '<data class="p-name" value="Buzzing, mostly" hidden>', $html );
	}

	public function test_micropub_mood_text_is_stored_as_authored_without_a_key(): void {
		$this->set_spelling( 'en_GB' );

		$markup = \PKIW\Micropub_Content_Builder::fill_empty_content( '', [ 'properties' => [ 'mood' => [ 'Energized' ] ] ] );

		$this->assertStringNotContainsString( 'moodKey', $markup );
		// Micropub cards carry no emoji, so the label surfaces as the hidden p-name.
		$this->assertStringContainsString( '<data class="p-name" value="Energized" hidden>', do_blocks( $markup ) );
	}

	/*
	 * REST save and reopen.
	 */

	public function test_rest_save_and_reopen_keeps_content_and_meta_and_matches_picker(): void {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$content = serialize_block(
			[
				'blockName'    => 'post-kinds-indieweb/mood-card',
				'attrs'        => [
					'mood'    => 'Energized',
					'moodKey' => 'energized',
					'emoji'   => '⚡',
					'note'    => 'Long walk, then coffee.',
				],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
		$meta    = [
			'_pkiw_mood_label' => 'Energized',
			'_pkiw_mood_key'   => 'energized',
			'_pkiw_mood_emoji' => '⚡',
		];

		$create = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$create->set_body_params(
			[
				'title'   => 'Walk',
				'status'  => 'publish',
				'content' => $content,
				'meta'    => $meta,
			]
		);
		$created = rest_do_request( $create );
		$this->assertSame( 201, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$post_id = (int) $created->get_data()['id'];

		$opened = $this->get_post_for_edit( $post_id );
		$this->assertSame( $content, $opened['content']['raw'] );
		$this->assert_meta_subset( $meta, $opened['meta'] );
		$this->assert_picker_matches_render( $opened['content']['rendered'], 'Energized' );

		// Switch the setting, then save something unrelated and reopen.
		$this->set_spelling( 'en_GB' );
		$update = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$update->set_body_params( [ 'title' => 'Walk, again' ] );
		$this->assertSame( 200, rest_do_request( $update )->get_status() );

		$reopened = $this->get_post_for_edit( $post_id );
		$this->assertSame( $content, $reopened['content']['raw'] );
		$this->assert_meta_subset( $meta, $reopened['meta'] );
		$this->assert_picker_matches_render( $reopened['content']['rendered'], 'Energised' );
	}

	/*
	 * Public outputs agree with the picker.
	 */

	/**
	 * @dataProvider spelling_expectations
	 */
	public function test_public_outputs_agree_with_picker_labels( string $spelling, string $expected ): void {
		$this->set_spelling( $spelling );
		$picker = $this->label_in( $this->get_moods_response( 'editor' )->get_data(), 'energized' );
		$this->assertSame( $expected, $picker );

		$card = $this->mood_card( 'Energized', 'energized', 'Out the door early.' );

		// Mood card block.
		$html = do_blocks( $card );
		$this->assertStringContainsString( 'aria-label="' . $picker . '"', $html );
		$this->assertStringContainsString( '<data class="p-name" value="' . $picker . '" hidden>', $html );

		// Stream card accessible name.
		$this->ensure_kind_term( 'mood' );
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Early start',
				'post_content' => $card . "\n\n<!-- wp:paragraph -->\n<p>More words.</p>\n<!-- /wp:paragraph -->",
			]
		);
		wp_set_object_terms( $post_id, 'mood', 'kind' );
		$this->assertSame( $picker, \PKIW\mood_card_accessible_name( $card ) );
		$GLOBALS['post'] = get_post( $post_id );
		$this->assertStringContainsString( 'aria-label="' . $picker . '">⚡</span>', \PKIW\render_stream_card() );

		// ATmosphere derived title (meta-backed).
		$untitled = self::factory()->post->create( [ 'post_title' => '' ] );
		wp_set_object_terms( $untitled, 'mood', 'kind' );
		update_post_meta( $untitled, Meta_Fields::PREFIX . 'mood_emoji', '⚡' );
		update_post_meta( $untitled, Meta_Fields::PREFIX . 'mood_label', 'Energized' );
		update_post_meta( $untitled, Meta_Fields::PREFIX . 'mood_key', 'energized' );
		$this->assertSame( 'Mood: ⚡ ' . $picker, Atmosphere_Titles::derive( get_post( $untitled ) ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function spelling_expectations(): array {
		return [
			'follow site language (en_US)' => [ 'site', 'Energized' ],
			'explicit US'                  => [ 'en_US', 'Energized' ],
			'explicit UK'                  => [ 'en_GB', 'Energised' ],
		];
	}

	public function test_feed_output_keeps_authored_note_under_every_spelling(): void {
		$post_id = self::factory()->post->create(
			[
				'post_content' => $this->mood_card( 'Energized', 'energized', 'Energized, honored and a little tired' ),
			]
		);

		$us = $this->feed_content_for( $post_id );
		$this->set_spelling( 'en_GB' );
		$uk = $this->feed_content_for( $post_id );

		// The feed carries emoji + authored note only; the note is never respelled.
		$this->assertSame( '<p>⚡ Energized, honored and a little tired</p>', trim( $uk ) );
		$this->assertSame( $us, $uk );
	}

	/*
	 * REST interface for consumers.
	 */

	public function test_rest_route_returns_resolved_vocabulary_shape(): void {
		$this->set_spelling( 'en_GB' );

		$response = $this->get_moods_response( 'author' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'spelling', 'locale', 'version', 'moods' ], array_keys( $data ) );
		$this->assertSame( 'en_GB', $data['spelling'] );
		$this->assertSame( 'en_GB', $data['locale'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $data['version'] );
		$this->assertNotEmpty( $data['moods'] );

		foreach ( $data['moods'] as $mood ) {
			$this->assertSame( [ 'key', 'label', 'variants' ], array_keys( $mood ) );
			$this->assertMatchesRegularExpression( '/^[a-z0-9_-]+$/', $mood['key'] );
			$this->assertIsString( $mood['label'] );
			$this->assertContains( $mood['label'], $mood['variants'] );
		}

		$this->assertSame(
			[
				'key'      => 'energized',
				'label'    => 'Energised',
				'variants' => [ 'Energized', 'Energised' ],
			],
			$this->mood( 'energized' )
		);
	}

	public function test_rest_route_requires_edit_posts(): void {
		$this->assertSame( 401, $this->get_moods_response( null )->get_status() );
		$this->assertSame( 403, $this->get_moods_response( 'subscriber' )->get_status() );
		$this->assertSame( 200, $this->get_moods_response( 'contributor' )->get_status() );
	}

	public function test_labels_filter_can_change_add_and_remove_moods(): void {
		$seen = [];
		add_filter(
			'pkiw_mood_labels',
			static function ( $labels, $locale, $spelling ) use ( &$seen ) {
				$seen                = [ $locale, $spelling ];
				$labels['energized'] = 'Charged';
				$labels['zen']       = 'Zen';
				unset( $labels['angry'] );
				return $labels;
			},
			10,
			3
		);
		$this->set_spelling( 'en_GB' );

		$data = $this->get_moods_response()->get_data();
		$keys = array_column( $data['moods'], 'key' );

		$this->assertSame( [ 'en_GB', 'en_GB' ], $seen );
		$this->assertSame( 'Charged', $this->label_in( $data, 'energized' ) );
		$this->assertSame( 'Zen', $this->label_in( $data, 'zen' ) );
		$this->assertNotContains( 'angry', $keys );
		$this->assertSame( 'Charged', Mood_Vocabulary::display_label( 'Energized', 'energized' ), 'Filtered labels still recognise stored spellings.' );
		$this->assertSame( 'Zen', Mood_Vocabulary::display_label( '', 'zen' ) );
		$this->assertSame( 'Angry', Mood_Vocabulary::display_label( 'Angry', 'angry' ), 'A removed mood keeps its authored text.' );
	}

	public function test_changing_the_setting_refreshes_cached_labels_and_version(): void {
		$this->set_spelling( 'en_US' );
		$us = $this->get_moods_response()->get_data();
		// Warm the in-process cache through every entry point.
		Mood_Vocabulary::get_moods();
		Mood_Vocabulary::display_label( 'Energized', 'energized' );

		$this->set_spelling( 'en_GB' );
		$uk = $this->get_moods_response()->get_data();

		$this->assertSame( 'Energized', $this->label_in( $us, 'energized' ) );
		$this->assertSame( 'Energised', $this->label_in( $uk, 'energized' ) );
		$this->assertSame( 'Energised', Mood_Vocabulary::display_label( 'Energized', 'energized' ) );
		$this->assertNotSame( $us['version'], $uk['version'] );

		// Following this en_US site gives en_US labels, but the preference changed too.
		$this->set_spelling( 'site' );
		$site = $this->get_moods_response()->get_data();
		$this->assertSame( $us['moods'], $site['moods'] );
		$this->assertNotSame( $us['version'], $site['version'] );
	}

	/*
	 * Setting storage.
	 */

	public function test_invalid_setting_values_fall_back_to_site_default(): void {
		foreach ( [ 'en_AU', 'EN_GB', '', 'fr_FR', null, [ 'en_GB' ], 42 ] as $invalid ) {
			$this->assertSame( 'site', Mood_Vocabulary::sanitize_spelling( $invalid ), wp_json_encode( $invalid ) );
		}

		// Stored directly, bypassing the settings sanitizer.
		update_option( 'pkiw_settings', [ 'mood_spelling' => 'en_AU' ] );
		$this->assertSame( 'site', Mood_Vocabulary::get_spelling() );
		$this->assertSame( 'Energized', Mood_Vocabulary::get_label( 'energized' ) );

		$admin = new Admin( Plugin::get_instance() );
		$this->assertSame( 'site', $admin->sanitize_general_settings( [ 'mood_spelling' => 'en_AU' ] )['mood_spelling'] );
		$this->assertSame( 'en_GB', $admin->sanitize_general_settings( [ 'mood_spelling' => 'en_GB' ] )['mood_spelling'] );

		// Saving another settings tab keeps the stored preference.
		update_option( 'pkiw_settings', [ 'mood_spelling' => 'en_US' ] );
		$this->assertSame( 'en_US', $admin->sanitize_general_settings( [ '_active_tab' => 'content' ] )['mood_spelling'] );
		$this->assertSame( 'site', $admin->get_default_settings()['mood_spelling'] );
	}

	public function test_settings_field_offers_the_three_choices(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		global $wp_settings_fields;

		$page = new Settings_Page( new Admin( Plugin::get_instance() ) );
		$page->register_sections_and_fields();
		$field = $wp_settings_fields['pkiw_general']['pkiw_general_section']['mood_spelling'] ?? null;

		$this->assertNotNull( $field );
		$this->assertSame( [ 'site', 'en_US', 'en_GB' ], array_keys( $field['args']['options'] ) );

		ob_start();
		$page->render_select_field( $field['args'] );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<option value="site" selected=\'selected\'>Follow the site language</option>', $html );
	}

	/*
	 * Shared JS/PHP rule table.
	 */

	public function test_shared_display_cases_match_php_rule(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/mood-label-cases.json' ), true );
		$this->set_spelling( $fixture['spelling'] );

		foreach ( $fixture['moods'] as $expected ) {
			$this->assertSame( $expected, $this->mood( $expected['key'] ), 'mood-label-cases.json moods drifted from the REST output.' );
		}

		foreach ( $fixture['display'] as $case ) {
			$this->assertSame( $case['expected'], Mood_Vocabulary::display_label( $case['authored'], $case['key'] ), $case['case'] );
		}
	}

	/*
	 * Helpers.
	 */

	private function set_site_locale( string $locale ): void {
		$GLOBALS['locale'] = $locale;
	}

	private function set_spelling( string $spelling ): void {
		$settings                  = (array) get_option( 'pkiw_settings', [] );
		$settings['mood_spelling'] = $spelling;
		update_option( 'pkiw_settings', $settings );
	}

	/**
	 * Stand in for installed language packs of this plugin's text domain.
	 *
	 * @param array<string, array<string, string>> $by_locale Locale => [ source => translation ].
	 */
	private function fake_translations( array $by_locale ): void {
		add_filter(
			'gettext',
			static function ( $translation, $text, $domain ) use ( $by_locale ) {
				if ( self::DOMAIN !== $domain ) {
					return $translation;
				}
				return $by_locale[ determine_locale() ][ $text ] ?? $translation;
			},
			10,
			3
		);
	}

	private function enter_admin_screen(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		set_current_screen( 'edit-post' );
		$this->assertTrue( is_admin() );
	}

	/**
	 * @return array{key: string, label: string, variants: list<string>}
	 */
	private function mood( string $key ): array {
		foreach ( Mood_Vocabulary::get_moods() as $mood ) {
			if ( $key === $mood['key'] ) {
				return $mood;
			}
		}
		$this->fail( "Mood {$key} is not in the vocabulary." );
	}

	private function mood_card( string $mood, string $key = '', string $note = '' ): string {
		$attrs = array_filter(
			[
				'mood'    => $mood,
				'moodKey' => $key,
				'emoji'   => '⚡',
				'note'    => $note,
			]
		);
		return '<!-- wp:post-kinds-indieweb/mood-card ' . wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE ) . ' /-->';
	}

	private function fresh_post( int $post_id ): WP_Post {
		clean_post_cache( $post_id );
		return get_post( $post_id );
	}

	/**
	 * @param string|null $role Role for a new user, 'current' to keep the current user, null for signed out.
	 */
	private function get_moods_response( ?string $role = 'administrator' ): WP_REST_Response {
		if ( null === $role ) {
			wp_set_current_user( 0 );
		} elseif ( 'current' !== $role ) {
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
		}
		return rest_do_request( new WP_REST_Request( 'GET', '/post-kinds-indieweb/v1/moods' ) );
	}

	/**
	 * @param array<string, mixed> $data REST data.
	 */
	private function label_in( array $data, string $key ): string {
		foreach ( $data['moods'] as $mood ) {
			if ( $key === $mood['key'] ) {
				return $mood['label'];
			}
		}
		return '';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_post_for_edit( int $post_id ): array {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	/**
	 * @param array<string, string> $expected Meta key => value.
	 * @param array<string, mixed>  $actual   REST meta.
	 */
	private function assert_meta_subset( array $expected, array $actual ): void {
		foreach ( $expected as $key => $value ) {
			$this->assertSame( $value, $actual[ $key ] ?? null, $key );
		}
	}

	private function assert_picker_matches_render( string $rendered, string $expected ): void {
		$user   = get_current_user_id();
		$picker = $this->label_in( rest_do_request( new WP_REST_Request( 'GET', '/post-kinds-indieweb/v1/moods' ) )->get_data(), 'energized' );
		wp_set_current_user( $user );

		$this->assertSame( $expected, $picker );
		$this->assertStringContainsString( 'aria-label="' . $picker . '"', $rendered );
	}

	private function ensure_kind_term( string $slug ): void {
		if ( ! term_exists( $slug, 'kind' ) ) {
			wp_insert_term( ucfirst( $slug ), 'kind', [ 'slug' => $slug ] );
		}
	}

	private function feed_content_for( int $post_id ): string {
		$this->go_to( '/?feed=rss2' );
		$this->assertTrue( is_feed(), 'Feed context did not take.' );

		while ( have_posts() ) {
			the_post();
			if ( get_the_ID() === $post_id ) {
				return get_the_content_feed( 'rss2' );
			}
		}

		$this->fail( sprintf( 'Post %d never appeared in the feed loop.', $post_id ) );
	}
}
