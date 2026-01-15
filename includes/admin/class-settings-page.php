<?php
/**
 * Settings Page
 *
 * Main settings page for plugin configuration.
 *
 * @package PostKindsForIndieWeb
 * @since 1.0.0
 */

namespace PostKindsForIndieWeb\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page class.
 */
class Settings_Page {

	/**
	 * Admin instance.
	 *
	 * @var Admin
	 */
	private Admin $admin;

	/**
	 * Active tab.
	 *
	 * @var string
	 */
	private string $active_tab = 'general';

	/**
	 * Constructor.
	 *
	 * @param Admin $admin Admin instance.
	 */
	public function __construct( Admin $admin ) {
		$this->admin = $admin;
	}

	/**
	 * Initialize settings page.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_init', [ $this, 'register_sections_and_fields' ] );
	}

	/**
	 * Register settings sections and fields.
	 *
	 * @return void
	 */
	public function register_sections_and_fields(): void {
		// General section.
		add_settings_section(
			'post_kinds_indieweb_general_section',
			__( 'General Settings', 'post-kinds-for-indieweb' ),
			[ $this, 'render_general_section' ],
			'post_kinds_indieweb_general'
		);

		$this->add_general_fields();

		// Content section.
		add_settings_section(
			'post_kinds_indieweb_content_section',
			__( 'Content Settings', 'post-kinds-for-indieweb' ),
			[ $this, 'render_content_section' ],
			'post_kinds_indieweb_content'
		);

		$this->add_content_fields();

		// Listen section.
		add_settings_section(
			'post_kinds_indieweb_listen_section',
			__( 'Listen Posts', 'post-kinds-for-indieweb' ),
			[ $this, 'render_listen_section' ],
			'post_kinds_indieweb_listen'
		);

		$this->add_listen_fields();

		// Watch section.
		add_settings_section(
			'post_kinds_indieweb_watch_section',
			__( 'Watch Posts', 'post-kinds-for-indieweb' ),
			[ $this, 'render_watch_section' ],
			'post_kinds_indieweb_watch'
		);

		$this->add_watch_fields();

		// Read section.
		add_settings_section(
			'post_kinds_indieweb_read_section',
			__( 'Read Posts', 'post-kinds-for-indieweb' ),
			[ $this, 'render_read_section' ],
			'post_kinds_indieweb_read'
		);

		$this->add_read_fields();

		// Checkin section.
		add_settings_section(
			'post_kinds_indieweb_checkin_section',
			__( 'Checkin Posts', 'post-kinds-for-indieweb' ),
			[ $this, 'render_checkin_section' ],
			'post_kinds_indieweb_checkin'
		);

		$this->add_checkin_fields();

		// Performance section.
		add_settings_section(
			'post_kinds_indieweb_performance_section',
			__( 'Performance', 'post-kinds-for-indieweb' ),
			[ $this, 'render_performance_section' ],
			'post_kinds_indieweb_performance'
		);

		$this->add_performance_fields();

		// Integrations section.
		add_settings_section(
			'post_kinds_indieweb_integrations_section',
			__( 'Third-Party Integrations', 'post-kinds-for-indieweb' ),
			[ $this, 'render_integrations_section' ],
			'post_kinds_indieweb_integrations'
		);

		$this->add_integrations_fields();
	}

	/**
	 * Add general settings fields.
	 *
	 * @return void
	 */
	private function add_general_fields(): void {
		add_settings_field(
			'default_post_status',
			__( 'Default Post Status', 'post-kinds-for-indieweb' ),
			[ $this, 'render_select_field' ],
			'post_kinds_indieweb_general',
			'post_kinds_indieweb_general_section',
			[
				'id'      => 'default_post_status',
				'options' => [
					'publish' => __( 'Published', 'post-kinds-for-indieweb' ),
					'draft'   => __( 'Draft', 'post-kinds-for-indieweb' ),
					'pending' => __( 'Pending Review', 'post-kinds-for-indieweb' ),
					'private' => __( 'Private', 'post-kinds-for-indieweb' ),
				],
				'desc'    => __( 'Default status for new reaction posts.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'default_post_format',
			__( 'Default Post Format', 'post-kinds-for-indieweb' ),
			[ $this, 'render_select_field' ],
			'post_kinds_indieweb_general',
			'post_kinds_indieweb_general_section',
			[
				'id'      => 'default_post_format',
				'options' => [
					'standard' => __( 'Standard', 'post-kinds-for-indieweb' ),
					'aside'    => __( 'Aside', 'post-kinds-for-indieweb' ),
					'status'   => __( 'Status', 'post-kinds-for-indieweb' ),
					'link'     => __( 'Link', 'post-kinds-for-indieweb' ),
				],
				'desc'    => __( 'Default post format for reaction posts.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'enable_microformats',
			__( 'Enable Microformats', 'post-kinds-for-indieweb' ),
			[ $this, 'render_checkbox_field' ],
			'post_kinds_indieweb_general',
			'post_kinds_indieweb_general_section',
			[
				'id'   => 'enable_microformats',
				'desc' => __( 'Add microformats2 markup to reaction posts for IndieWeb compatibility.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'enable_syndication',
			__( 'Enable Syndication', 'post-kinds-for-indieweb' ),
			[ $this, 'render_checkbox_field' ],
			'post_kinds_indieweb_general',
			'post_kinds_indieweb_general_section',
			[
				'id'   => 'enable_syndication',
				'desc' => __( 'Allow sending reactions to syndication targets (requires Syndication Links plugin).', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'import_storage_mode',
			__( 'Import Post Storage', 'post-kinds-for-indieweb' ),
			[ $this, 'render_select_field' ],
			'post_kinds_indieweb_general',
			'post_kinds_indieweb_general_section',
			[
				'id'      => 'import_storage_mode',
				'options' => [
					'standard' => __( 'Standard Posts', 'post-kinds-for-indieweb' ),
					'cpt'      => __( 'Custom Post Type (Reactions)', 'post-kinds-for-indieweb' ),
					'hidden'   => __( 'Standard Posts (Hidden from Blog)', 'post-kinds-for-indieweb' ),
				],
				'desc'    => __( 'How imported posts are stored. Standard Posts appear in your blog feed. Custom Post Type keeps imports separate. Hidden removes imports from the main blog but keeps them accessible via kind archives. Only affects new imports.', 'post-kinds-for-indieweb' ),
			]
		);

		// Post Format Sync Settings.
		add_settings_field(
			'sync_formats_to_kinds',
			__( 'Sync Post Formats to Kinds', 'post-kinds-for-indieweb' ),
			[ $this, 'render_checkbox_field' ],
			'post_kinds_indieweb_general',
			'post_kinds_indieweb_general_section',
			[
				'id'   => 'sync_formats_to_kinds',
				'desc' => __( 'Automatically set Post Kind when Post Format changes (and vice versa).', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'format_kind_mappings',
			__( 'Post Format Mappings', 'post-kinds-for-indieweb' ),
			[ $this, 'render_format_mappings_field' ],
			'post_kinds_indieweb_general',
			'post_kinds_indieweb_general_section',
			[
				'id'   => 'format_kind_mappings',
				'desc' => __( 'Map WordPress Post Formats to Reaction Kinds. When a post format is selected, the corresponding kind will be set automatically.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'enabled_kinds',
			__( 'Enabled Reaction Types', 'post-kinds-for-indieweb' ),
			[ $this, 'render_enabled_kinds_field' ],
			'post_kinds_indieweb_general',
			'post_kinds_indieweb_general_section',
			[
				'id'   => 'enabled_kinds',
				'desc' => __( 'Choose which reaction types are available in your site. Disabled types will not appear in the editor, taxonomy, or blocks.', 'post-kinds-for-indieweb' ),
			]
		);
	}

	/**
	 * Add content settings fields.
	 *
	 * @return void
	 */
	private function add_content_fields(): void {
		add_settings_field(
			'auto_fetch_metadata',
			__( 'Auto-fetch Metadata', 'post-kinds-for-indieweb' ),
			[ $this, 'render_checkbox_field' ],
			'post_kinds_indieweb_content',
			'post_kinds_indieweb_content_section',
			[
				'id'   => 'auto_fetch_metadata',
				'desc' => __( 'Automatically fetch metadata from external APIs when creating posts.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'cache_duration',
			__( 'Cache Duration', 'post-kinds-for-indieweb' ),
			[ $this, 'render_select_field' ],
			'post_kinds_indieweb_content',
			'post_kinds_indieweb_content_section',
			[
				'id'      => 'cache_duration',
				'options' => [
					'3600'   => __( '1 hour', 'post-kinds-for-indieweb' ),
					'21600'  => __( '6 hours', 'post-kinds-for-indieweb' ),
					'43200'  => __( '12 hours', 'post-kinds-for-indieweb' ),
					'86400'  => __( '24 hours', 'post-kinds-for-indieweb' ),
					'259200' => __( '3 days', 'post-kinds-for-indieweb' ),
					'604800' => __( '1 week', 'post-kinds-for-indieweb' ),
				],
				'desc'    => __( 'How long to cache API responses.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'image_handling',
			__( 'Image Handling', 'post-kinds-for-indieweb' ),
			[ $this, 'render_select_field' ],
			'post_kinds_indieweb_content',
			'post_kinds_indieweb_content_section',
			[
				'id'      => 'image_handling',
				'options' => [
					'sideload' => __( 'Download to Media Library', 'post-kinds-for-indieweb' ),
					'hotlink'  => __( 'Link to External URL', 'post-kinds-for-indieweb' ),
					'none'     => __( 'Do Not Include Images', 'post-kinds-for-indieweb' ),
				],
				'desc'    => __( 'How to handle cover images and artwork from external sources.', 'post-kinds-for-indieweb' ),
			]
		);
	}

	/**
	 * Add listen settings fields.
	 *
	 * @return void
	 */
	private function add_listen_fields(): void {
		add_settings_field(
			'listen_auto_import',
			__( 'Auto-Sync Music', 'post-kinds-for-indieweb' ),
			[ $this, 'render_source_auto_sync_field' ],
			'post_kinds_indieweb_listen',
			'post_kinds_indieweb_listen_section',
			[
				'id'          => 'listen_auto_import',
				'source_type' => 'music',
				'icon'        => 'format-audio',
				'desc'        => __( 'Automatically import new music scrobbles from your connected music service.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'listen_import_source',
			__( 'Music Import Source', 'post-kinds-for-indieweb' ),
			[ $this, 'render_select_field' ],
			'post_kinds_indieweb_listen',
			'post_kinds_indieweb_listen_section',
			[
				'id'      => 'listen_import_source',
				'options' => [
					'listenbrainz' => 'ListenBrainz',
					'lastfm'       => 'Last.fm',
				],
				'desc'    => __( 'Primary source for importing music/scrobble history.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'listen_embed_source',
			__( 'Embed Player', 'post-kinds-for-indieweb' ),
			[ $this, 'render_select_field' ],
			'post_kinds_indieweb_listen',
			'post_kinds_indieweb_listen_section',
			[
				'id'      => 'listen_embed_source',
				'options' => [
					'none'        => __( 'None (no embed)', 'post-kinds-for-indieweb' ),
					'spotify'     => __( 'Spotify', 'post-kinds-for-indieweb' ),
					'apple_music' => __( 'Apple Music', 'post-kinds-for-indieweb' ),
					'youtube'     => __( 'YouTube Music', 'post-kinds-for-indieweb' ),
					'bandcamp'    => __( 'Bandcamp', 'post-kinds-for-indieweb' ),
					'soundcloud'  => __( 'SoundCloud', 'post-kinds-for-indieweb' ),
				],
				'desc'    => __( 'Preferred music service for embedding players in listen posts. The plugin will search for and embed tracks from this service when importing.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'listen_podcast_auto_import',
			__( 'Auto-Sync Podcasts', 'post-kinds-for-indieweb' ),
			[ $this, 'render_source_auto_sync_field' ],
			'post_kinds_indieweb_listen',
			'post_kinds_indieweb_listen_section',
			[
				'id'          => 'listen_podcast_auto_import',
				'source_type' => 'podcasts',
				'icon'        => 'microphone',
				'desc'        => __( 'Automatically import podcast episodes with highlights from Readwise/Snipd.', 'post-kinds-for-indieweb' ),
				'source_name' => 'Readwise',
			]
		);

		add_settings_field(
			'listen_default_rating',
			__( 'Default Rating', 'post-kinds-for-indieweb' ),
			[ $this, 'render_number_field' ],
			'post_kinds_indieweb_listen',
			'post_kinds_indieweb_listen_section',
			[
				'id'   => 'listen_default_rating',
				'min'  => 0,
				'max'  => 10,
				'step' => 1,
				'desc' => __( 'Default rating for listen posts (0 = no rating).', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'listen_sync_to_lastfm',
			__( 'Scrobble to Last.fm', 'post-kinds-for-indieweb' ),
			[ $this, 'render_checkbox_field' ],
			'post_kinds_indieweb_listen',
			'post_kinds_indieweb_listen_section',
			[
				'id'   => 'listen_sync_to_lastfm',
				'desc' => __( 'Automatically scrobble listen posts to Last.fm when published (requires Last.fm session key).', 'post-kinds-for-indieweb' ),
			]
		);
	}

	/**
	 * Add watch settings fields.
	 *
	 * @return void
	 */
	private function add_watch_fields(): void {
		add_settings_field(
			'watch_auto_import',
			__( 'Auto-Sync Movies & TV', 'post-kinds-for-indieweb' ),
			[ $this, 'render_source_auto_sync_field' ],
			'post_kinds_indieweb_watch',
			'post_kinds_indieweb_watch_section',
			[
				'id'          => 'watch_auto_import',
				'source_type' => 'watch',
				'icon'        => 'video-alt3',
				'desc'        => __( 'Automatically import movies and TV shows you watch.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'watch_import_source',
			__( 'Import Source', 'post-kinds-for-indieweb' ),
			[ $this, 'render_select_field' ],
			'post_kinds_indieweb_watch',
			'post_kinds_indieweb_watch_section',
			[
				'id'      => 'watch_import_source',
				'options' => [
					'trakt' => 'Trakt',
					'simkl' => 'Simkl',
				],
				'desc'    => __( 'Primary source for importing watch history.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'watch_default_rating',
			__( 'Default Rating', 'post-kinds-for-indieweb' ),
			[ $this, 'render_number_field' ],
			'post_kinds_indieweb_watch',
			'post_kinds_indieweb_watch_section',
			[
				'id'   => 'watch_default_rating',
				'min'  => 0,
				'max'  => 10,
				'step' => 1,
				'desc' => __( 'Default rating for watch posts (0 = no rating).', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'watch_include_rewatches',
			__( 'Include Rewatches', 'post-kinds-for-indieweb' ),
			[ $this, 'render_checkbox_field' ],
			'post_kinds_indieweb_watch',
			'post_kinds_indieweb_watch_section',
			[
				'id'   => 'watch_include_rewatches',
				'desc' => __( 'Create posts for rewatched content (may create duplicates).', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'watch_sync_to_trakt',
			__( 'Sync to Trakt', 'post-kinds-for-indieweb' ),
			[ $this, 'render_checkbox_field' ],
			'post_kinds_indieweb_watch',
			'post_kinds_indieweb_watch_section',
			[
				'id'   => 'watch_sync_to_trakt',
				'desc' => __( 'Automatically sync watch posts to Trakt history when published (requires Trakt OAuth).', 'post-kinds-for-indieweb' ),
			]
		);
	}

	/**
	 * Add read settings fields.
	 *
	 * @return void
	 */
	private function add_read_fields(): void {
		add_settings_field(
			'read_auto_import',
			__( 'Auto-Sync Books', 'post-kinds-for-indieweb' ),
			[ $this, 'render_source_auto_sync_field' ],
			'post_kinds_indieweb_read',
			'post_kinds_indieweb_read_section',
			[
				'id'          => 'read_auto_import',
				'source_type' => 'books',
				'icon'        => 'book',
				'desc'        => __( 'Automatically import books you\'re reading or have read.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'read_import_source',
			__( 'Book Import Source', 'post-kinds-for-indieweb' ),
			[ $this, 'render_select_field' ],
			'post_kinds_indieweb_read',
			'post_kinds_indieweb_read_section',
			[
				'id'      => 'read_import_source',
				'options' => [
					'hardcover'      => 'Hardcover',
					'readwise_books' => 'Readwise Books',
				],
				'desc'    => __( 'Primary source for importing book reading history. Readwise imports include Kindle highlights.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'read_articles_auto_import',
			__( 'Auto-Sync Articles', 'post-kinds-for-indieweb' ),
			[ $this, 'render_source_auto_sync_field' ],
			'post_kinds_indieweb_read',
			'post_kinds_indieweb_read_section',
			[
				'id'          => 'read_articles_auto_import',
				'source_type' => 'articles',
				'icon'        => 'media-text',
				'desc'        => __( 'Automatically import articles with highlights from Readwise.', 'post-kinds-for-indieweb' ),
				'source_name' => 'Readwise',
			]
		);

		add_settings_field(
			'read_default_status',
			__( 'Default Read Status', 'post-kinds-for-indieweb' ),
			[ $this, 'render_select_field' ],
			'post_kinds_indieweb_read',
			'post_kinds_indieweb_read_section',
			[
				'id'      => 'read_default_status',
				'options' => [
					'to-read'   => __( 'To Read', 'post-kinds-for-indieweb' ),
					'reading'   => __( 'Currently Reading', 'post-kinds-for-indieweb' ),
					'finished'  => __( 'Finished', 'post-kinds-for-indieweb' ),
					'abandoned' => __( 'Abandoned', 'post-kinds-for-indieweb' ),
				],
				'desc'    => __( 'Default status for new read posts.', 'post-kinds-for-indieweb' ),
			]
		);
	}

	/**
	 * Add checkin settings fields.
	 *
	 * @return void
	 */
	private function add_checkin_fields(): void {
		add_settings_field(
			'enable_checkin_cpt',
			__( 'Enable Check-in Post Type', 'post-kinds-for-indieweb' ),
			[ $this, 'render_checkbox_field' ],
			'post_kinds_indieweb_checkin',
			'post_kinds_indieweb_checkin_section',
			[
				'id'   => 'enable_checkin_cpt',
				'desc' => __( 'Use a dedicated post type for check-ins instead of standard posts. This creates a "Check-ins" menu in the admin and a /checkins/ archive on the frontend.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'checkin_auto_import',
			__( 'Auto-Sync Checkins', 'post-kinds-for-indieweb' ),
			[ $this, 'render_source_auto_sync_field' ],
			'post_kinds_indieweb_checkin',
			'post_kinds_indieweb_checkin_section',
			[
				'id'          => 'checkin_auto_import',
				'source_type' => 'checkins',
				'icon'        => 'location-alt',
				'desc'        => __( 'Automatically import checkins from Foursquare/Swarm.', 'post-kinds-for-indieweb' ),
				'source_name' => 'Foursquare',
			]
		);

		add_settings_field(
			'checkin_default_privacy',
			__( 'Default Location Privacy', 'post-kinds-for-indieweb' ),
			[ $this, 'render_privacy_field' ],
			'post_kinds_indieweb_checkin',
			'post_kinds_indieweb_checkin_section',
			[
				'id' => 'checkin_default_privacy',
			]
		);

		add_settings_field(
			'checkin_coordinate_handling',
			__( 'Coordinate Handling', 'post-kinds-for-indieweb' ),
			[ $this, 'render_coordinate_handling_field' ],
			'post_kinds_indieweb_checkin',
			'post_kinds_indieweb_checkin_section',
			[
				'id' => 'checkin_coordinate_handling',
			]
		);

		add_settings_field(
			'checkin_venue_source',
			__( 'Venue Search Source', 'post-kinds-for-indieweb' ),
			[ $this, 'render_select_field' ],
			'post_kinds_indieweb_checkin',
			'post_kinds_indieweb_checkin_section',
			[
				'id'      => 'checkin_venue_source',
				'options' => [
					'nominatim'  => __( 'OpenStreetMap (Nominatim)', 'post-kinds-for-indieweb' ),
					'foursquare' => __( 'Foursquare (requires API key)', 'post-kinds-for-indieweb' ),
					'both'       => __( 'Both (Foursquare first, OSM fallback)', 'post-kinds-for-indieweb' ),
				],
				'desc'    => __( 'Which service to use for venue/location search in the block editor.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'checkin_sync_to_foursquare',
			__( 'Sync to Foursquare', 'post-kinds-for-indieweb' ),
			[ $this, 'render_checkbox_field' ],
			'post_kinds_indieweb_checkin',
			'post_kinds_indieweb_checkin_section',
			[
				'id'   => 'checkin_sync_to_foursquare',
				'desc' => __( 'Post checkins to Foursquare when publishing (requires Foursquare OAuth connection). This is a POSSE approach - Publish on your Own Site, Syndicate Elsewhere.', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'foursquare_connection',
			__( 'Foursquare Connection', 'post-kinds-for-indieweb' ),
			[ $this, 'render_foursquare_connection_field' ],
			'post_kinds_indieweb_checkin',
			'post_kinds_indieweb_checkin_section',
			[
				'id' => 'foursquare_connection',
			]
		);
	}

	/**
	 * Add performance settings fields.
	 *
	 * @return void
	 */
	private function add_performance_fields(): void {
		add_settings_field(
			'enable_background_sync',
			__( 'Automatic Sync', 'post-kinds-for-indieweb' ),
			[ $this, 'render_auto_sync_field' ],
			'post_kinds_indieweb_performance',
			'post_kinds_indieweb_performance_section',
			[
				'id' => 'enable_background_sync',
			]
		);

		add_settings_field(
			'rate_limit_delay',
			__( 'Rate Limit Delay', 'post-kinds-for-indieweb' ),
			[ $this, 'render_number_field' ],
			'post_kinds_indieweb_performance',
			'post_kinds_indieweb_performance_section',
			[
				'id'   => 'rate_limit_delay',
				'min'  => 0,
				'max'  => 10000,
				'step' => 100,
				'desc' => __( 'Milliseconds to wait between API requests (to avoid rate limits).', 'post-kinds-for-indieweb' ),
			]
		);

		add_settings_field(
			'batch_size',
			__( 'Import Batch Size', 'post-kinds-for-indieweb' ),
			[ $this, 'render_number_field' ],
			'post_kinds_indieweb_performance',
			'post_kinds_indieweb_performance_section',
			[
				'id'   => 'batch_size',
				'min'  => 1,
				'max'  => 500,
				'step' => 1,
				'desc' => __( 'Number of items to process per batch during imports.', 'post-kinds-for-indieweb' ),
			]
		);
	}

	/**
	 * Add integrations settings fields.
	 *
	 * @return void
	 */
	private function add_integrations_fields(): void {
		// WP Recipe Maker integration.
		add_settings_field(
			'wprm_auto_kind',
			__( 'WP Recipe Maker', 'post-kinds-for-indieweb' ),
			[ $this, 'render_wprm_integration_field' ],
			'post_kinds_indieweb_integrations',
			'post_kinds_indieweb_integrations_section',
			[
				'id' => 'wprm_auto_kind',
			]
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

		?>
		<div class="wrap post-kinds-indieweb-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php $this->render_tabs(); ?>
			</nav>

			<form method="post" action="options.php" class="post-kinds-indieweb-form">
				<?php
				settings_fields( 'post_kinds_indieweb_general' );

				switch ( $this->active_tab ) {
					case 'general':
						$this->render_general_tab();
						break;
					case 'content':
						$this->render_content_tab();
						break;
					case 'listen':
						$this->render_listen_tab();
						break;
					case 'watch':
						$this->render_watch_tab();
						break;
					case 'read':
						$this->render_read_tab();
						break;
					case 'checkin':
						$this->render_checkin_tab();
						break;
					case 'integrations':
						$this->render_integrations_tab();
						break;
					case 'performance':
						$this->render_performance_tab();
						break;
					case 'tools':
						$this->render_tools_tab();
						break;
				}

				if ( 'tools' !== $this->active_tab ) {
					submit_button();
				}
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render navigation tabs.
	 *
	 * @return void
	 */
	private function render_tabs(): void {
		$tabs = [
			'general'      => __( 'General', 'post-kinds-for-indieweb' ),
			'content'      => __( 'Content', 'post-kinds-for-indieweb' ),
			'listen'       => __( 'Listen', 'post-kinds-for-indieweb' ),
			'watch'        => __( 'Watch', 'post-kinds-for-indieweb' ),
			'read'         => __( 'Read', 'post-kinds-for-indieweb' ),
			'checkin'      => __( 'Checkin', 'post-kinds-for-indieweb' ),
			'integrations' => __( 'Integrations', 'post-kinds-for-indieweb' ),
			'performance'  => __( 'Performance', 'post-kinds-for-indieweb' ),
			'tools'        => __( 'Tools', 'post-kinds-for-indieweb' ),
		];

		foreach ( $tabs as $slug => $label ) {
			$active = $this->active_tab === $slug ? ' nav-tab-active' : '';
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( add_query_arg( 'tab', $slug, admin_url( 'admin.php?page=post-kinds-for-indieweb' ) ) ),
				esc_attr( $active ),
				esc_html( $label )
			);
		}
	}

	/**
	 * Render general section description.
	 *
	 * @return void
	 */
	public function render_general_section(): void {
		echo '<p>' . esc_html__( 'Configure general plugin behavior and defaults.', 'post-kinds-for-indieweb' ) . '</p>';
	}

	/**
	 * Render content section description.
	 *
	 * @return void
	 */
	public function render_content_section(): void {
		echo '<p>' . esc_html__( 'Configure how content and metadata is handled.', 'post-kinds-for-indieweb' ) . '</p>';
	}

	/**
	 * Render listen section description.
	 *
	 * @return void
	 */
	public function render_listen_section(): void {
		echo '<p>' . esc_html__( 'Configure settings for listen/scrobble posts.', 'post-kinds-for-indieweb' ) . '</p>';
	}

	/**
	 * Render watch section description.
	 *
	 * @return void
	 */
	public function render_watch_section(): void {
		echo '<p>' . esc_html__( 'Configure settings for watch posts (movies and TV shows).', 'post-kinds-for-indieweb' ) . '</p>';
	}

	/**
	 * Render read section description.
	 *
	 * @return void
	 */
	public function render_read_section(): void {
		echo '<p>' . esc_html__( 'Configure settings for read/book posts.', 'post-kinds-for-indieweb' ) . '</p>';
	}

	/**
	 * Render checkin section description.
	 *
	 * @return void
	 */
	public function render_checkin_section(): void {
		echo '<p>' . esc_html__( 'Configure settings for location checkin posts.', 'post-kinds-for-indieweb' ) . '</p>';
	}

	/**
	 * Render performance section description.
	 *
	 * @return void
	 */
	public function render_performance_section(): void {
		echo '<p>' . esc_html__( 'Configure performance and rate limiting settings.', 'post-kinds-for-indieweb' ) . '</p>';
	}

	/**
	 * Render integrations section description.
	 *
	 * @return void
	 */
	public function render_integrations_section(): void {
		echo '<p>' . esc_html__( 'Configure integration with third-party WordPress plugins.', 'post-kinds-for-indieweb' ) . '</p>';
	}

	/**
	 * Render general tab content.
	 *
	 * @return void
	 */
	private function render_general_tab(): void {
		echo '<input type="hidden" name="post_kinds_indieweb_settings[_active_tab]" value="general">';
		do_settings_sections( 'post_kinds_indieweb_general' );
	}

	/**
	 * Render content tab content.
	 *
	 * @return void
	 */
	private function render_content_tab(): void {
		echo '<input type="hidden" name="post_kinds_indieweb_settings[_active_tab]" value="content">';
		do_settings_sections( 'post_kinds_indieweb_content' );
	}

	/**
	 * Render listen tab content.
	 *
	 * @return void
	 */
	private function render_listen_tab(): void {
		echo '<input type="hidden" name="post_kinds_indieweb_settings[_active_tab]" value="listen">';
		do_settings_sections( 'post_kinds_indieweb_listen' );
	}

	/**
	 * Render watch tab content.
	 *
	 * @return void
	 */
	private function render_watch_tab(): void {
		echo '<input type="hidden" name="post_kinds_indieweb_settings[_active_tab]" value="watch">';
		do_settings_sections( 'post_kinds_indieweb_watch' );
	}

	/**
	 * Render read tab content.
	 *
	 * @return void
	 */
	private function render_read_tab(): void {
		echo '<input type="hidden" name="post_kinds_indieweb_settings[_active_tab]" value="read">';
		do_settings_sections( 'post_kinds_indieweb_read' );
	}

	/**
	 * Render checkin tab content.
	 *
	 * @return void
	 */
	private function render_checkin_tab(): void {
		echo '<input type="hidden" name="post_kinds_indieweb_settings[_active_tab]" value="checkin">';
		do_settings_sections( 'post_kinds_indieweb_checkin' );
	}

	/**
	 * Render performance tab content.
	 *
	 * @return void
	 */
	private function render_performance_tab(): void {
		echo '<input type="hidden" name="post_kinds_indieweb_settings[_active_tab]" value="performance">';
		do_settings_sections( 'post_kinds_indieweb_performance' );
	}

	/**
	 * Render integrations tab content.
	 *
	 * @return void
	 */
	private function render_integrations_tab(): void {
		echo '<input type="hidden" name="post_kinds_indieweb_settings[_active_tab]" value="integrations">';

		// Render the comprehensive integrations documentation.
		$this->render_integrations_documentation();

		// Then render the settings sections.
		do_settings_sections( 'post_kinds_indieweb_integrations' );
	}

	/**
	 * Render comprehensive integrations documentation.
	 *
	 * @return void
	 */
	private function render_integrations_documentation(): void {
		$plugin = \PostKindsForIndieWeb\Plugin::get_instance();
		?>
		<div class="post-kinds-integrations-docs">
			<style>
				.post-kinds-integrations-docs {
					margin-bottom: 30px;
				}
				.integration-card {
					background: #fff;
					border: 1px solid #c3c4c7;
					border-radius: 4px;
					padding: 20px;
					margin-bottom: 20px;
				}
				.integration-card h3 {
					margin-top: 0;
					display: flex;
					align-items: center;
					gap: 10px;
				}
				.integration-card .status-badge {
					display: inline-flex;
					align-items: center;
					padding: 4px 10px;
					border-radius: 3px;
					font-size: 12px;
					font-weight: 600;
					text-transform: uppercase;
				}
				.integration-card .status-active {
					background: #d4edda;
					color: #155724;
				}
				.integration-card .status-inactive {
					background: #f8f9fa;
					color: #6c757d;
				}
				.integration-card .status-required {
					background: #fff3cd;
					color: #856404;
				}
				.integration-card .status-builtin {
					background: #cce5ff;
					color: #004085;
				}
				.integration-card ul {
					margin: 15px 0;
					padding-left: 20px;
				}
				.integration-card li {
					margin-bottom: 8px;
				}
				.integration-card .integration-actions {
					margin-top: 15px;
					padding-top: 15px;
					border-top: 1px solid #eee;
				}
				.integration-card .integration-actions a {
					text-decoration: none;
				}
				.integration-category {
					margin: 30px 0 15px;
					padding-bottom: 10px;
					border-bottom: 2px solid #2271b1;
				}
				.integration-category:first-child {
					margin-top: 0;
				}
			</style>

			<h2 class="integration-category"><?php esc_html_e( 'Core IndieWeb Plugins', 'post-kinds-for-indieweb' ); ?></h2>

			<?php // IndieBlocks. ?>
			<div class="integration-card">
				<h3>
					<span class="dashicons dashicons-editor-code"></span>
					IndieBlocks
					<?php if ( $plugin->is_indieblocks_active() ) : ?>
						<span class="status-badge status-active"><?php esc_html_e( 'Active', 'post-kinds-for-indieweb' ); ?></span>
					<?php else : ?>
						<span class="status-badge status-required"><?php esc_html_e( 'Recommended', 'post-kinds-for-indieweb' ); ?></span>
					<?php endif; ?>
				</h3>
				<p><?php esc_html_e( 'IndieBlocks provides Gutenberg blocks for IndieWeb interactions - replies, likes, reposts, and bookmarks. It also registers the "kind" taxonomy that this plugin uses to categorize posts.', 'post-kinds-for-indieweb' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Reply Block:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Create reply posts that link to the original content with proper microformats.', 'post-kinds-for-indieweb' ); ?></li>
					<li><strong><?php esc_html_e( 'Like Block:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Show a "liked" indicator linking to the original post.', 'post-kinds-for-indieweb' ); ?></li>
					<li><strong><?php esc_html_e( 'Repost Block:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Share and quote content from other sites.', 'post-kinds-for-indieweb' ); ?></li>
					<li><strong><?php esc_html_e( 'Bookmark Block:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Save and display links with proper IndieWeb markup.', 'post-kinds-for-indieweb' ); ?></li>
					<li><strong><?php esc_html_e( 'Context Block:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Automatically fetches and displays context for the content you are responding to.', 'post-kinds-for-indieweb' ); ?></li>
				</ul>
				<div class="integration-actions">
					<?php if ( ! $plugin->is_indieblocks_active() ) : ?>
						<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=indieblocks&tab=search&type=term' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Install IndieBlocks', 'post-kinds-for-indieweb' ); ?>
						</a>
					<?php else : ?>
						<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
						<?php esc_html_e( 'IndieBlocks is active and providing core blocks.', 'post-kinds-for-indieweb' ); ?>
					<?php endif; ?>
					<a href="https://wordpress.org/plugins/indieblocks/" target="_blank" rel="noopener noreferrer" class="button" style="margin-left: 10px;">
						<?php esc_html_e( 'View on WordPress.org', 'post-kinds-for-indieweb' ); ?>
					</a>
				</div>
			</div>

			<?php // Webmention. ?>
			<div class="integration-card">
				<h3>
					<span class="dashicons dashicons-admin-comments"></span>
					Webmention
					<?php if ( class_exists( 'Webmention_Plugin' ) || function_exists( 'webmention_init' ) ) : ?>
						<span class="status-badge status-active"><?php esc_html_e( 'Active', 'post-kinds-for-indieweb' ); ?></span>
					<?php else : ?>
						<span class="status-badge status-inactive"><?php esc_html_e( 'Not Installed', 'post-kinds-for-indieweb' ); ?></span>
					<?php endif; ?>
				</h3>
				<p><?php esc_html_e( 'Webmention enables cross-site conversations on the IndieWeb. When you reply to, like, or bookmark content on another site, Webmention notifies that site of your interaction.', 'post-kinds-for-indieweb' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Send Webmentions:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Automatically notify other sites when you link to them.', 'post-kinds-for-indieweb' ); ?></li>
					<li><strong><?php esc_html_e( 'Receive Webmentions:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Accept notifications when others link to your content.', 'post-kinds-for-indieweb' ); ?></li>
					<li><strong><?php esc_html_e( 'Comment Display:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Show webmentions as comments on your posts.', 'post-kinds-for-indieweb' ); ?></li>
				</ul>
				<div class="integration-actions">
					<?php if ( ! class_exists( 'Webmention_Plugin' ) && ! function_exists( 'webmention_init' ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=webmention&tab=search&type=term' ) ); ?>" class="button">
							<?php esc_html_e( 'Install Webmention', 'post-kinds-for-indieweb' ); ?>
						</a>
					<?php else : ?>
						<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
						<?php esc_html_e( 'Webmention is active. Your reactions will notify other sites.', 'post-kinds-for-indieweb' ); ?>
					<?php endif; ?>
					<a href="https://wordpress.org/plugins/webmention/" target="_blank" rel="noopener noreferrer" class="button" style="margin-left: 10px;">
						<?php esc_html_e( 'View on WordPress.org', 'post-kinds-for-indieweb' ); ?>
					</a>
				</div>
			</div>

			<?php // Syndication Links. ?>
			<div class="integration-card">
				<h3>
					<span class="dashicons dashicons-share"></span>
					Syndication Links
					<?php if ( class_exists( 'Syndication_Links' ) || class_exists( 'Syn_Config' ) ) : ?>
						<span class="status-badge status-active"><?php esc_html_e( 'Active', 'post-kinds-for-indieweb' ); ?></span>
					<?php else : ?>
						<span class="status-badge status-inactive"><?php esc_html_e( 'Not Installed', 'post-kinds-for-indieweb' ); ?></span>
					<?php endif; ?>
				</h3>
				<p><?php esc_html_e( 'Syndication Links helps you POSSE (Publish on your Own Site, Syndicate Elsewhere) by tracking where your content has been shared and displaying those links.', 'post-kinds-for-indieweb' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Syndication Targets:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Define where to syndicate content (Twitter/X, Mastodon, etc.).', 'post-kinds-for-indieweb' ); ?></li>
					<li><strong><?php esc_html_e( 'Link Display:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Show "Also on:" links to syndicated copies.', 'post-kinds-for-indieweb' ); ?></li>
					<li><strong><?php esc_html_e( 'Microformats:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Proper u-syndication markup for IndieWeb compatibility.', 'post-kinds-for-indieweb' ); ?></li>
				</ul>
				<div class="integration-actions">
					<?php if ( ! class_exists( 'Syndication_Links' ) && ! class_exists( 'Syn_Config' ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=syndication+links&tab=search&type=term' ) ); ?>" class="button">
							<?php esc_html_e( 'Install Syndication Links', 'post-kinds-for-indieweb' ); ?>
						</a>
					<?php else : ?>
						<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
						<?php esc_html_e( 'Syndication Links is active.', 'post-kinds-for-indieweb' ); ?>
					<?php endif; ?>
					<a href="https://wordpress.org/plugins/syndication-links/" target="_blank" rel="noopener noreferrer" class="button" style="margin-left: 10px;">
						<?php esc_html_e( 'View on WordPress.org', 'post-kinds-for-indieweb' ); ?>
					</a>
				</div>
			</div>

			<h2 class="integration-category"><?php esc_html_e( 'Content Enhancement Plugins', 'post-kinds-for-indieweb' ); ?></h2>

			<?php // Bookmark Card. ?>
			<div class="integration-card">
				<h3>
					<span class="dashicons dashicons-admin-links"></span>
					Bookmark Card
					<?php if ( $plugin->is_bookmark_card_active() ) : ?>
						<span class="status-badge status-active"><?php esc_html_e( 'Active', 'post-kinds-for-indieweb' ); ?></span>
					<?php else : ?>
						<span class="status-badge status-inactive"><?php esc_html_e( 'Not Installed', 'post-kinds-for-indieweb' ); ?></span>
					<?php endif; ?>
				</h3>
				<p><?php esc_html_e( 'Bookmark Card creates beautiful link preview cards for your bookmarks. When installed, the Bookmark post kind can automatically insert rich preview cards that show the title, description, and image from bookmarked URLs.', 'post-kinds-for-indieweb' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Rich Previews:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Automatically fetches Open Graph and metadata to display link previews.', 'post-kinds-for-indieweb' ); ?></li>
					<li><strong><?php esc_html_e( 'Smart Fallback:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'When active, acts as a fallback when URLs don\'t support oEmbed.', 'post-kinds-for-indieweb' ); ?></li>
					<li><strong><?php esc_html_e( 'Metadata Sync:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Card data automatically syncs to post meta for feeds and microformats.', 'post-kinds-for-indieweb' ); ?></li>
				</ul>
				<div class="integration-actions">
					<?php if ( ! $plugin->is_bookmark_card_active() ) : ?>
						<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=bookmark+card&tab=search&type=term' ) ); ?>" class="button">
							<?php esc_html_e( 'Install Bookmark Card', 'post-kinds-for-indieweb' ); ?>
						</a>
					<?php else : ?>
						<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
						<?php esc_html_e( 'Bookmark Card is active. Rich link previews are available for bookmarks.', 'post-kinds-for-indieweb' ); ?>
					<?php endif; ?>
					<a href="https://wordpress.org/plugins/bookmark-card/" target="_blank" rel="noopener noreferrer" class="button" style="margin-left: 10px;">
						<?php esc_html_e( 'View on WordPress.org', 'post-kinds-for-indieweb' ); ?>
					</a>
				</div>
			</div>

			<h2 class="integration-category"><?php esc_html_e( 'Built-in WordPress Features', 'post-kinds-for-indieweb' ); ?></h2>

			<?php // oEmbed. ?>
			<div class="integration-card">
				<h3>
					<span class="dashicons dashicons-embed-video"></span>
					<?php esc_html_e( 'WordPress oEmbed', 'post-kinds-for-indieweb' ); ?>
					<span class="status-badge status-builtin"><?php esc_html_e( 'Built-in', 'post-kinds-for-indieweb' ); ?></span>
				</h3>
				<p><?php esc_html_e( 'WordPress includes native support for embedding content from many popular services. When you create a Bookmark post, the plugin can automatically check if the URL supports oEmbed and insert a rich embed.', 'post-kinds-for-indieweb' ); ?></p>
				<p><strong><?php esc_html_e( 'Supported providers include:', 'post-kinds-for-indieweb' ); ?></strong></p>
				<ul style="column-count: 3; column-gap: 20px;">
					<li>YouTube</li>
					<li>Vimeo</li>
					<li>Twitter/X</li>
					<li>Spotify</li>
					<li>SoundCloud</li>
					<li>TikTok</li>
					<li>Reddit</li>
					<li>Flickr</li>
					<li>Instagram</li>
					<li>WordPress.com</li>
					<li>WordPress.tv</li>
					<li>Tumblr</li>
				</ul>
				<p class="description" style="margin-top: 15px;">
					<?php esc_html_e( 'For Bookmark posts, you can choose between Auto (tries oEmbed first, then Bookmark Card), oEmbed Only, Bookmark Card Only, or None.', 'post-kinds-for-indieweb' ); ?>
				</p>
			</div>

			<?php
			// Check for Post Formats for Block Themes plugin.
			$post_formats_block_themes_active = class_exists( 'Post_Formats_Block_Themes' ) ||
				in_array( 'post-formats-for-block-themes/post-formats-for-block-themes.php', (array) get_option( 'active_plugins', [] ), true );
			?>
			<?php // Post Formats for Block Themes. ?>
			<div class="integration-card">
				<h3>
					<span class="dashicons dashicons-format-status"></span>
					<?php esc_html_e( 'Post Formats for Block Themes', 'post-kinds-for-indieweb' ); ?>
					<?php if ( $post_formats_block_themes_active ) : ?>
						<span class="status-badge status-active"><?php esc_html_e( 'Active', 'post-kinds-for-indieweb' ); ?></span>
					<?php else : ?>
						<span class="status-badge status-inactive"><?php esc_html_e( 'Not Installed', 'post-kinds-for-indieweb' ); ?></span>
					<?php endif; ?>
				</h3>
				<p><?php esc_html_e( 'Restores Post Format support to block themes. Many modern block themes dropped Post Format support, but this plugin brings it back with a clean UI in the block editor sidebar.', 'post-kinds-for-indieweb' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Block Editor UI:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Adds a Post Format selector panel to the editor sidebar.', 'post-kinds-for-indieweb' ); ?></li>
					<li><strong><?php esc_html_e( 'Theme Compatibility:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Enables Post Formats on any theme, even those that don\'t declare support.', 'post-kinds-for-indieweb' ); ?></li>
					<li><strong><?php esc_html_e( 'Kind Sync:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Works with Post Kinds for IndieWeb\'s format-to-kind sync feature.', 'post-kinds-for-indieweb' ); ?></li>
				</ul>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to settings */
						esc_html__( 'Configure format-to-kind mappings in the %s tab.', 'post-kinds-for-indieweb' ),
						'<a href="' . esc_url( admin_url( 'options-general.php?page=post-kinds-indieweb&tab=general' ) ) . '">' . esc_html__( 'General', 'post-kinds-for-indieweb' ) . '</a>'
					);
					?>
				</p>
				<div class="integration-actions">
					<?php if ( ! $post_formats_block_themes_active ) : ?>
						<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=post+formats+for+block+themes&tab=search&type=term' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Install Plugin', 'post-kinds-for-indieweb' ); ?>
						</a>
					<?php else : ?>
						<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
						<?php esc_html_e( 'Post Formats for Block Themes is active.', 'post-kinds-for-indieweb' ); ?>
					<?php endif; ?>
					<a href="https://wordpress.org/plugins/post-formats-for-block-themes/" target="_blank" rel="noopener noreferrer" class="button" style="margin-left: 10px;">
						<?php esc_html_e( 'View on WordPress.org', 'post-kinds-for-indieweb' ); ?>
					</a>
				</div>
			</div>

			<?php
			// Check for Link Extension for XFN plugin.
			$xfn_extension_active = class_exists( 'Link_Extension_XFN' ) ||
				in_array( 'link-extension-for-xfn/link-extension-for-xfn.php', (array) get_option( 'active_plugins', [] ), true );
			?>
			<?php // Link Extension for XFN. ?>
			<div class="integration-card">
				<h3>
					<span class="dashicons dashicons-groups"></span>
					<?php esc_html_e( 'Link Extension for XFN', 'post-kinds-for-indieweb' ); ?>
					<?php if ( $xfn_extension_active ) : ?>
						<span class="status-badge status-active"><?php esc_html_e( 'Active', 'post-kinds-for-indieweb' ); ?></span>
					<?php else : ?>
						<span class="status-badge status-inactive"><?php esc_html_e( 'Not Installed', 'post-kinds-for-indieweb' ); ?></span>
					<?php endif; ?>
				</h3>
				<p><?php esc_html_e( 'Adds XFN (XHTML Friends Network) relationship attributes to links in the block editor. Essential for IndieWeb interactions where you want to indicate your relationship with the person you\'re replying to or mentioning.', 'post-kinds-for-indieweb' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'Link Popover UI:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Adds XFN options directly in the link editing popover.', 'post-kinds-for-indieweb' ); ?></li>
					<li><strong><?php esc_html_e( 'Identity Links:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Easily add rel="me" to links to your other profiles.', 'post-kinds-for-indieweb' ); ?></li>
					<li><strong><?php esc_html_e( 'Relationship Types:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'Friend, acquaintance, contact, colleague, family members, and more.', 'post-kinds-for-indieweb' ); ?></li>
					<li><strong><?php esc_html_e( 'IndieWeb Compatible:', 'post-kinds-for-indieweb' ); ?></strong> <?php esc_html_e( 'XFN attributes are recognized by microformats parsers for social graph discovery.', 'post-kinds-for-indieweb' ); ?></li>
				</ul>
				<div class="integration-actions">
					<?php if ( ! $xfn_extension_active ) : ?>
						<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=link+extension+for+xfn&tab=search&type=term' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Install Plugin', 'post-kinds-for-indieweb' ); ?>
						</a>
					<?php else : ?>
						<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
						<?php esc_html_e( 'Link Extension for XFN is active.', 'post-kinds-for-indieweb' ); ?>
					<?php endif; ?>
					<a href="https://wordpress.org/plugins/link-extension-for-xfn/" target="_blank" rel="noopener noreferrer" class="button" style="margin-left: 10px;">
						<?php esc_html_e( 'View on WordPress.org', 'post-kinds-for-indieweb' ); ?>
					</a>
				</div>
			</div>

			<h2 class="integration-category"><?php esc_html_e( 'Recipe & Content Plugins', 'post-kinds-for-indieweb' ); ?></h2>

		</div>
		<?php
	}

	/**
	 * Render tools tab content.
	 *
	 * @return void
	 */
	private function render_tools_tab(): void {
		?>
		<div class="post-kinds-indieweb-tools">
			<h2><?php esc_html_e( 'Cache Management', 'post-kinds-for-indieweb' ); ?></h2>
			<p><?php esc_html_e( 'Clear cached API responses and metadata.', 'post-kinds-for-indieweb' ); ?></p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Clear API Cache', 'post-kinds-for-indieweb' ); ?></th>
					<td>
						<button type="button" class="button post-kinds-clear-cache" data-type="api">
							<?php esc_html_e( 'Clear API Cache', 'post-kinds-for-indieweb' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Clear cached responses from external APIs.', 'post-kinds-for-indieweb' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Clear Metadata Cache', 'post-kinds-for-indieweb' ); ?></th>
					<td>
						<button type="button" class="button post-kinds-clear-cache" data-type="metadata">
							<?php esc_html_e( 'Clear Metadata Cache', 'post-kinds-for-indieweb' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Clear cached media metadata.', 'post-kinds-for-indieweb' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Clear All Caches', 'post-kinds-for-indieweb' ); ?></th>
					<td>
						<button type="button" class="button button-secondary post-kinds-clear-cache" data-type="all">
							<?php esc_html_e( 'Clear All Caches', 'post-kinds-for-indieweb' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Clear all cached data.', 'post-kinds-for-indieweb' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<hr>

			<h2><?php esc_html_e( 'Export / Import Settings', 'post-kinds-for-indieweb' ); ?></h2>
			<p><?php esc_html_e( 'Export or import plugin settings.', 'post-kinds-for-indieweb' ); ?></p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Export Settings', 'post-kinds-for-indieweb' ); ?></th>
					<td>
						<button type="button" class="button post-kinds-export-settings">
							<?php esc_html_e( 'Export Settings', 'post-kinds-for-indieweb' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Download settings as a JSON file (API keys excluded).', 'post-kinds-for-indieweb' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Import Settings', 'post-kinds-for-indieweb' ); ?></th>
					<td>
						<input type="file" id="post-kinds-import-file" accept=".json">
						<button type="button" class="button post-kinds-import-settings" disabled>
							<?php esc_html_e( 'Import Settings', 'post-kinds-for-indieweb' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Import settings from a previously exported JSON file.', 'post-kinds-for-indieweb' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<hr>

			<h2><?php esc_html_e( 'Debug Information', 'post-kinds-for-indieweb' ); ?></h2>
			<p><?php esc_html_e( 'Technical information for troubleshooting.', 'post-kinds-for-indieweb' ); ?></p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Plugin Version', 'post-kinds-for-indieweb' ); ?></th>
					<td><code><?php echo esc_html( \POST_KINDS_INDIEWEB_VERSION ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'WordPress Version', 'post-kinds-for-indieweb' ); ?></th>
					<td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'PHP Version', 'post-kinds-for-indieweb' ); ?></th>
					<td><code><?php echo esc_html( PHP_VERSION ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'IndieBlocks', 'post-kinds-for-indieweb' ); ?></th>
					<td>
						<?php
						// Check multiple ways IndieBlocks might be detected.
						$indieblocks_active = class_exists( 'IndieBlocks\\IndieBlocks' )
							|| class_exists( 'IndieBlocks\\Plugin' )
							|| function_exists( 'indieblocks' )
							|| defined( 'INDIEBLOCKS_VERSION' )
							|| is_plugin_active( 'indieblocks/indieblocks.php' );
						?>
						<?php if ( $indieblocks_active ) : ?>
							<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
							<?php esc_html_e( 'Installed', 'post-kinds-for-indieweb' ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-warning" style="color: #dc3232;"></span>
							<?php esc_html_e( 'Not installed', 'post-kinds-for-indieweb' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Active Imports', 'post-kinds-for-indieweb' ); ?></th>
					<td>
						<?php
						$active_imports = get_option( 'post_kinds_indieweb_active_imports', [] );
						echo esc_html( count( $active_imports ) );
						?>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Render a select field.
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_select_field( array $args ): void {
		$settings = get_option( 'post_kinds_indieweb_settings', $this->admin->get_default_settings() );
		$value    = $settings[ $args['id'] ] ?? '';

		printf(
			'<select name="post_kinds_indieweb_settings[%s]" id="%s">',
			esc_attr( $args['id'] ),
			esc_attr( $args['id'] )
		);

		foreach ( $args['options'] as $option_value => $option_label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $option_value ),
				selected( $value, $option_value, false ),
				esc_html( $option_label )
			);
		}

		echo '</select>';

		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_checkbox_field( array $args ): void {
		$settings = get_option( 'post_kinds_indieweb_settings', $this->admin->get_default_settings() );
		$checked  = ! empty( $settings[ $args['id'] ] );

		printf(
			'<label><input type="checkbox" name="post_kinds_indieweb_settings[%s]" id="%s" value="1"%s> %s</label>',
			esc_attr( $args['id'] ),
			esc_attr( $args['id'] ),
			checked( $checked, true, false ),
			! empty( $args['label'] ) ? esc_html( $args['label'] ) : ''
		);

		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	/**
	 * Render a number field.
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_number_field( array $args ): void {
		$settings = get_option( 'post_kinds_indieweb_settings', $this->admin->get_default_settings() );
		$value    = $settings[ $args['id'] ] ?? 0;

		printf(
			'<input type="number" name="post_kinds_indieweb_settings[%s]" id="%s" value="%s" min="%s" max="%s" step="%s" class="small-text">',
			esc_attr( $args['id'] ),
			esc_attr( $args['id'] ),
			esc_attr( $value ),
			esc_attr( $args['min'] ?? 0 ),
			esc_attr( $args['max'] ?? 100 ),
			esc_attr( $args['step'] ?? 1 )
		);

		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	/**
	 * Render a text field.
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_text_field( array $args ): void {
		$settings = get_option( 'post_kinds_indieweb_settings', $this->admin->get_default_settings() );
		$value    = $settings[ $args['id'] ] ?? '';

		printf(
			'<input type="text" name="post_kinds_indieweb_settings[%s]" id="%s" value="%s" class="regular-text">',
			esc_attr( $args['id'] ),
			esc_attr( $args['id'] ),
			esc_attr( $value )
		);

		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	/**
	 * Render format to kind mappings field.
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_format_mappings_field( array $args ): void {
		$settings = get_option( 'post_kinds_indieweb_settings', $this->admin->get_default_settings() );
		$mappings = $settings['format_kind_mappings'] ?? $this->get_default_format_mappings();

		// WordPress Post Formats.
		$post_formats = [
			'standard' => __( 'Standard', 'post-kinds-for-indieweb' ),
			'aside'    => __( 'Aside', 'post-kinds-for-indieweb' ),
			'audio'    => __( 'Audio', 'post-kinds-for-indieweb' ),
			'chat'     => __( 'Chat', 'post-kinds-for-indieweb' ),
			'gallery'  => __( 'Gallery', 'post-kinds-for-indieweb' ),
			'image'    => __( 'Image', 'post-kinds-for-indieweb' ),
			'link'     => __( 'Link', 'post-kinds-for-indieweb' ),
			'quote'    => __( 'Quote', 'post-kinds-for-indieweb' ),
			'status'   => __( 'Status', 'post-kinds-for-indieweb' ),
			'video'    => __( 'Video', 'post-kinds-for-indieweb' ),
		];

		// Reaction Kinds.
		$kinds = [
			''         => __( '— No mapping —', 'post-kinds-for-indieweb' ),
			'note'     => __( 'Note', 'post-kinds-for-indieweb' ),
			'article'  => __( 'Article', 'post-kinds-for-indieweb' ),
			'reply'    => __( 'Reply', 'post-kinds-for-indieweb' ),
			'like'     => __( 'Like', 'post-kinds-for-indieweb' ),
			'repost'   => __( 'Repost', 'post-kinds-for-indieweb' ),
			'bookmark' => __( 'Bookmark', 'post-kinds-for-indieweb' ),
			'rsvp'     => __( 'RSVP', 'post-kinds-for-indieweb' ),
			'checkin'  => __( 'Check-in', 'post-kinds-for-indieweb' ),
			'listen'   => __( 'Listen', 'post-kinds-for-indieweb' ),
			'watch'    => __( 'Watch', 'post-kinds-for-indieweb' ),
			'read'     => __( 'Read', 'post-kinds-for-indieweb' ),
			'event'    => __( 'Event', 'post-kinds-for-indieweb' ),
			'photo'    => __( 'Photo', 'post-kinds-for-indieweb' ),
			'video'    => __( 'Video', 'post-kinds-for-indieweb' ),
			'review'   => __( 'Review', 'post-kinds-for-indieweb' ),
		];

		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description" style="margin-bottom: 12px;">%s</p>', esc_html( $args['desc'] ) );
		}

		echo '<div class="format-mappings-grid" style="display: grid; grid-template-columns: repeat(2, minmax(200px, 280px)); gap: 8px 24px; max-width: 600px;">';

		foreach ( $post_formats as $format_slug => $format_label ) {
			$current_value = $mappings[ $format_slug ] ?? '';

			echo '<div class="format-mapping-item" style="display: flex; align-items: center; gap: 8px;">';
			printf(
				'<label for="format_mapping_%s" style="min-width: 70px; font-weight: 500;">%s</label>',
				esc_attr( $format_slug ),
				esc_html( $format_label )
			);
			echo '<span style="color: #8c8f94;">→</span>';
			printf(
				'<select name="post_kinds_indieweb_settings[format_kind_mappings][%s]" id="format_mapping_%s" style="flex: 1; max-width: 150px;">',
				esc_attr( $format_slug ),
				esc_attr( $format_slug )
			);

			foreach ( $kinds as $kind_slug => $kind_label ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $kind_slug ),
					selected( $current_value, $kind_slug, false ),
					esc_html( $kind_label )
				);
			}

			echo '</select>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Get default format to kind mappings.
	 *
	 * @return array<string, string>
	 */
	private function get_default_format_mappings(): array {
		return [
			'standard' => 'article',
			'aside'    => 'note',
			'audio'    => 'listen',
			'chat'     => '',
			'gallery'  => 'photo',
			'image'    => 'photo',
			'link'     => 'bookmark',
			'quote'    => 'repost',
			'status'   => 'note',
			'video'    => 'watch',
		];
	}

	/**
	 * Render enabled kinds field with checkboxes.
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_enabled_kinds_field( array $args ): void {
		$settings      = get_option( 'post_kinds_indieweb_settings', $this->admin->get_default_settings() );
		$enabled_kinds = $settings['enabled_kinds'] ?? $this->get_default_enabled_kinds();

		// All available kinds with descriptions.
		$all_kinds = [
			'note'     => [
				'label' => __( 'Note', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Short posts, similar to tweets or status updates', 'post-kinds-for-indieweb' ),
				'icon'  => 'format-status',
			],
			'article'  => [
				'label' => __( 'Article', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Long-form content with a title', 'post-kinds-for-indieweb' ),
				'icon'  => 'media-document',
			],
			'reply'    => [
				'label' => __( 'Reply', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Response to someone else\'s content', 'post-kinds-for-indieweb' ),
				'icon'  => 'format-chat',
			],
			'like'     => [
				'label' => __( 'Like', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Indicate appreciation for external content', 'post-kinds-for-indieweb' ),
				'icon'  => 'heart',
			],
			'repost'   => [
				'label' => __( 'Repost', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Share someone else\'s content on your site', 'post-kinds-for-indieweb' ),
				'icon'  => 'controls-repeat',
			],
			'bookmark' => [
				'label' => __( 'Bookmark', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Save and share links to interesting content', 'post-kinds-for-indieweb' ),
				'icon'  => 'bookmark',
			],
			'rsvp'     => [
				'label' => __( 'RSVP', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Respond to event invitations', 'post-kinds-for-indieweb' ),
				'icon'  => 'calendar-alt',
			],
			'checkin'  => [
				'label' => __( 'Check-in', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Share your location at a venue or place', 'post-kinds-for-indieweb' ),
				'icon'  => 'location-alt',
			],
			'listen'   => [
				'label' => __( 'Listen', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Music scrobbles, podcasts, audio content', 'post-kinds-for-indieweb' ),
				'icon'  => 'format-audio',
			],
			'watch'    => [
				'label' => __( 'Watch', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Movies, TV shows, videos you\'ve watched', 'post-kinds-for-indieweb' ),
				'icon'  => 'video-alt3',
			],
			'read'     => [
				'label' => __( 'Read', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Books, articles, and reading progress', 'post-kinds-for-indieweb' ),
				'icon'  => 'book',
			],
			'event'    => [
				'label' => __( 'Event', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Create and share events', 'post-kinds-for-indieweb' ),
				'icon'  => 'calendar',
			],
			'photo'    => [
				'label' => __( 'Photo', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Image-focused posts', 'post-kinds-for-indieweb' ),
				'icon'  => 'format-image',
			],
			'video'    => [
				'label' => __( 'Video', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Video posts you create', 'post-kinds-for-indieweb' ),
				'icon'  => 'format-video',
			],
			'review'   => [
				'label' => __( 'Review', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Reviews with ratings', 'post-kinds-for-indieweb' ),
				'icon'  => 'star-filled',
			],
		];

		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description" style="margin-bottom: 16px;">%s</p>', esc_html( $args['desc'] ) );
		}

		echo '<div class="enabled-kinds-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px;">';

		foreach ( $all_kinds as $kind_slug => $kind_data ) {
			$is_enabled = in_array( $kind_slug, $enabled_kinds, true );

			echo '<label class="enabled-kind-item" style="display: flex; align-items: flex-start; gap: 8px; padding: 12px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">';
			printf(
				'<input type="checkbox" name="post_kinds_indieweb_settings[enabled_kinds][]" value="%s"%s style="margin-top: 2px;">',
				esc_attr( $kind_slug ),
				checked( $is_enabled, true, false )
			);
			echo '<span class="dashicons dashicons-' . esc_attr( $kind_data['icon'] ) . '" style="color: #2271b1; margin-top: 2px;"></span>';
			echo '<span>';
			printf( '<strong style="display: block;">%s</strong>', esc_html( $kind_data['label'] ) );
			printf( '<span class="description" style="font-size: 12px; color: #646970;">%s</span>', esc_html( $kind_data['desc'] ) );
			echo '</span>';
			echo '</label>';
		}

		echo '</div>';

		echo '<div style="margin-top: 16px;">';
		echo '<button type="button" class="button" id="enable-all-kinds">' . esc_html__( 'Enable All', 'post-kinds-for-indieweb' ) . '</button> ';
		echo '<button type="button" class="button" id="disable-all-kinds">' . esc_html__( 'Disable All', 'post-kinds-for-indieweb' ) . '</button>';
		echo '</div>';

		// JavaScript for enable/disable all buttons.
		?>
		<script>
		document.getElementById('enable-all-kinds').addEventListener('click', function() {
			document.querySelectorAll('.enabled-kind-item input[type="checkbox"]').forEach(cb => cb.checked = true);
		});
		document.getElementById('disable-all-kinds').addEventListener('click', function() {
			document.querySelectorAll('.enabled-kind-item input[type="checkbox"]').forEach(cb => cb.checked = false);
		});
		</script>
		<?php
	}

	/**
	 * Get default enabled kinds (all enabled by default).
	 *
	 * @return array<string>
	 */
	private function get_default_enabled_kinds(): array {
		return [
			'note',
			'article',
			'reply',
			'like',
			'repost',
			'bookmark',
			'rsvp',
			'checkin',
			'listen',
			'watch',
			'read',
			'event',
			'photo',
			'video',
			'review',
		];
	}

	/**
	 * Render privacy field with detailed explanations.
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_privacy_field( array $args ): void {
		$settings = get_option( 'post_kinds_indieweb_settings', $this->admin->get_default_settings() );
		$value    = $settings[ $args['id'] ] ?? 'approximate';

		$options = [
			'public'      => [
				'label' => __( 'Public (exact location)', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Shows full address, venue name, and precise coordinates. Best for public venues like restaurants or parks where you want others to find the same place.', 'post-kinds-for-indieweb' ),
			],
			'approximate' => [
				'label' => __( 'Approximate (city level)', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Shows city/region but hides street address and exact coordinates. Good balance of sharing where you are without revealing precise location.', 'post-kinds-for-indieweb' ),
			],
			'private'     => [
				'label' => __( 'Private (hidden)', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Location is stored but never displayed publicly. Use this for home, work, or other private locations you want to log but not share.', 'post-kinds-for-indieweb' ),
			],
		];

		echo '<fieldset>';
		foreach ( $options as $option_value => $option_data ) {
			printf(
				'<label style="display: block; margin-bottom: 12px;">
                    <input type="radio" name="post_kinds_indieweb_settings[%s]" value="%s"%s>
                    <strong>%s</strong>
                    <p class="description" style="margin-left: 24px; margin-top: 4px;">%s</p>
                </label>',
				esc_attr( $args['id'] ),
				esc_attr( $option_value ),
				checked( $value, $option_value, false ),
				esc_html( $option_data['label'] ),
				esc_html( $option_data['desc'] )
			);
		}
		echo '</fieldset>';
		echo '<p class="description" style="margin-top: 16px; padding: 12px; background: #f0f0f1; border-left: 4px solid #2271b1;">';
		esc_html_e( 'This setting determines the default for new checkins. You can override it per-post in the block editor.', 'post-kinds-for-indieweb' );
		echo '</p>';
	}

	/**
	 * Render coordinate handling field with detailed explanations.
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_coordinate_handling_field( array $args ): void {
		$settings = get_option( 'post_kinds_indieweb_settings', $this->admin->get_default_settings() );
		$value    = $settings[ $args['id'] ] ?? 'store_hide';

		$options = [
			'store_hide' => [
				'label' => __( 'Store but hide coordinates', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Exact coordinates are saved in the database (for your records, maps, or future use) but never shown publicly. This lets you keep a precise location history while protecting privacy.', 'post-kinds-for-indieweb' ),
			],
			'round'      => [
				'label' => __( 'Round coordinates (reduce precision)', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Coordinates are rounded to ~1km precision before storing. This provides approximate mapping while making it impossible to pinpoint exact locations. Good if you want some geographic context without precision.', 'post-kinds-for-indieweb' ),
			],
			'discard'    => [
				'label' => __( 'Discard coordinates entirely', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Coordinates are never saved. Only venue name and address text are stored. Use this for maximum privacy, but note that coordinates cannot be recovered later.', 'post-kinds-for-indieweb' ),
			],
			'store_show' => [
				'label' => __( 'Store and show coordinates', 'post-kinds-for-indieweb' ),
				'desc'  => __( 'Exact coordinates are saved and displayed publicly (when privacy is set to Public). Enables precise mapping and IndieWeb geo microformats.', 'post-kinds-for-indieweb' ),
			],
		];

		echo '<fieldset>';
		foreach ( $options as $option_value => $option_data ) {
			printf(
				'<label style="display: block; margin-bottom: 12px;">
                    <input type="radio" name="post_kinds_indieweb_settings[%s]" value="%s"%s>
                    <strong>%s</strong>
                    <p class="description" style="margin-left: 24px; margin-top: 4px;">%s</p>
                </label>',
				esc_attr( $args['id'] ),
				esc_attr( $option_value ),
				checked( $value, $option_value, false ),
				esc_html( $option_data['label'] ),
				esc_html( $option_data['desc'] )
			);
		}
		echo '</fieldset>';

		echo '<div style="margin-top: 16px; padding: 12px; background: #fff8e5; border-left: 4px solid #dba617;">';
		echo '<strong>' . esc_html__( 'Why does this matter?', 'post-kinds-for-indieweb' ) . '</strong>';
		echo '<p class="description" style="margin-top: 8px;">';
		esc_html_e( 'Precise coordinates can reveal patterns about where you live, work, or spend time. Even if you hide your home address, checking in at nearby cafes regularly can expose your neighborhood. Consider your threat model when choosing.', 'post-kinds-for-indieweb' );
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Render the automatic sync toggle with clear status.
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_auto_sync_field( array $args ): void {
		$settings = get_option( 'post_kinds_indieweb_settings', $this->admin->get_default_settings() );
		$enabled  = ! empty( $settings['enable_background_sync'] );

		// Get next/last sync times from scheduled sync.
		$plugin         = \PostKindsForIndieWeb\Plugin::get_instance();
		$scheduled_sync = $plugin->get_scheduled_sync();
		$last_sync      = $scheduled_sync ? $scheduled_sync->get_last_sync_time() : null;
		$next_sync      = $scheduled_sync ? $scheduled_sync->get_next_sync_time() : null;

		// Check which auto-imports are enabled.
		$auto_imports_enabled = [];
		if ( ! empty( $settings['listen_auto_import'] ) ) {
			$auto_imports_enabled[] = __( 'Music', 'post-kinds-for-indieweb' );
		}
		if ( ! empty( $settings['listen_podcast_auto_import'] ) ) {
			$auto_imports_enabled[] = __( 'Podcasts', 'post-kinds-for-indieweb' );
		}
		if ( ! empty( $settings['watch_auto_import'] ) ) {
			$auto_imports_enabled[] = __( 'Movies & TV', 'post-kinds-for-indieweb' );
		}
		if ( ! empty( $settings['read_auto_import'] ) ) {
			$auto_imports_enabled[] = __( 'Books', 'post-kinds-for-indieweb' );
		}
		if ( ! empty( $settings['read_articles_auto_import'] ) ) {
			$auto_imports_enabled[] = __( 'Articles', 'post-kinds-for-indieweb' );
		}
		if ( ! empty( $settings['checkin_auto_import'] ) ) {
			$auto_imports_enabled[] = __( 'Checkins', 'post-kinds-for-indieweb' );
		}

		?>
		<div class="auto-sync-settings" style="max-width: 600px;">
			<!-- Main Toggle -->
			<div style="display: flex; align-items: center; gap: 16px; padding: 16px; background: <?php echo $enabled ? '#f0f6fc' : '#f9f9f9'; ?>; border: 2px solid <?php echo $enabled ? '#2271b1' : '#ddd'; ?>; border-radius: 8px; margin-bottom: 16px;">
				<label class="auto-sync-toggle" style="display: flex; align-items: center; gap: 12px; cursor: pointer; flex: 1;">
					<input
						type="checkbox"
						name="post_kinds_indieweb_settings[enable_background_sync]"
						id="enable_background_sync"
						value="1"
						<?php checked( $enabled ); ?>
						style="width: 20px; height: 20px;"
					>
					<span style="font-size: 16px; font-weight: 600;">
						<?php esc_html_e( 'Enable Automatic Sync', 'post-kinds-for-indieweb' ); ?>
					</span>
				</label>
				<span style="padding: 4px 12px; border-radius: 4px; font-size: 12px; font-weight: 500; background: <?php echo $enabled ? '#2271b1' : '#ddd'; ?>; color: <?php echo $enabled ? '#fff' : '#666'; ?>;">
					<?php echo $enabled ? esc_html__( 'ON', 'post-kinds-for-indieweb' ) : esc_html__( 'OFF', 'post-kinds-for-indieweb' ); ?>
				</span>
			</div>

			<!-- Description -->
			<p class="description" style="margin-bottom: 16px;">
				<?php esc_html_e( 'When enabled, the plugin will automatically import new content from your connected services every hour using WordPress cron.', 'post-kinds-for-indieweb' ); ?>
			</p>

			<?php if ( $enabled ) : ?>
				<!-- Status Info -->
				<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin-bottom: 16px;">
					<h4 style="margin: 0 0 8px 0; font-size: 13px; color: #1d2327;">
						<?php esc_html_e( 'Sync Status', 'post-kinds-for-indieweb' ); ?>
					</h4>
					<dl style="margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; font-size: 13px;">
						<dt style="color: #646970;"><?php esc_html_e( 'Last sync:', 'post-kinds-for-indieweb' ); ?></dt>
						<dd style="margin: 0;">
							<?php
							if ( $last_sync ) {
								echo esc_html( human_time_diff( $last_sync ) . ' ' . __( 'ago', 'post-kinds-for-indieweb' ) );
							} else {
								esc_html_e( 'Never', 'post-kinds-for-indieweb' );
							}
							?>
						</dd>
						<dt style="color: #646970;"><?php esc_html_e( 'Next sync:', 'post-kinds-for-indieweb' ); ?></dt>
						<dd style="margin: 0;">
							<?php
							if ( $next_sync ) {
								if ( $next_sync <= time() ) {
									esc_html_e( 'Pending (waiting for cron)', 'post-kinds-for-indieweb' );
								} else {
									echo esc_html(
										sprintf(
										/* translators: %s: human time diff */
											__( 'in %s', 'post-kinds-for-indieweb' ),
											human_time_diff( time(), $next_sync )
										)
									);
								}
							} else {
								esc_html_e( 'Not scheduled', 'post-kinds-for-indieweb' );
							}
							?>
						</dd>
					</dl>
				</div>

				<!-- Active Auto-Imports -->
				<?php if ( ! empty( $auto_imports_enabled ) ) : ?>
					<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 12px;">
						<h4 style="margin: 0 0 8px 0; font-size: 13px; color: #1d2327;">
							<?php esc_html_e( 'Active Auto-Imports', 'post-kinds-for-indieweb' ); ?>
						</h4>
						<p style="margin: 0; font-size: 13px;">
							<?php echo esc_html( implode( ', ', $auto_imports_enabled ) ); ?>
						</p>
						<p class="description" style="margin: 8px 0 0 0; font-size: 12px;">
							<?php esc_html_e( 'Configure individual auto-imports in their respective tabs above.', 'post-kinds-for-indieweb' ); ?>
						</p>
					</div>
				<?php else : ?>
					<div style="background: #fff8e5; border: 1px solid #dba617; border-radius: 4px; padding: 12px;">
						<p style="margin: 0; font-size: 13px; color: #614b00;">
							<span class="dashicons dashicons-warning" style="font-size: 16px; vertical-align: text-bottom;"></span>
							<?php esc_html_e( 'No auto-imports are enabled. Enable auto-import in the Listen, Watch, Read, or Checkin tabs for automatic sync to work.', 'post-kinds-for-indieweb' ); ?>
						</p>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<!-- Disabled Info -->
				<div style="background: #f0f0f1; border: 1px solid #ddd; border-radius: 4px; padding: 12px; color: #646970;">
					<p style="margin: 0; font-size: 13px;">
						<?php esc_html_e( 'Automatic sync is disabled. Imports will only run when you manually trigger them from the Import page.', 'post-kinds-for-indieweb' ); ?>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a per-source auto-sync toggle field with prominent styling.
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_source_auto_sync_field( array $args ): void {
		$settings    = get_option( 'post_kinds_indieweb_settings', $this->admin->get_default_settings() );
		$enabled     = ! empty( $settings[ $args['id'] ] );
		$icon        = $args['icon'] ?? 'admin-generic';
		$source_name = $args['source_name'] ?? '';
		$source_type = $args['source_type'] ?? '';

		// Check if the main background sync is enabled.
		$background_sync_enabled = ! empty( $settings['enable_background_sync'] );

		// Check if relevant API is configured.
		$is_configured  = true;
		$config_message = '';

		$credentials = get_option( 'post_kinds_indieweb_api_credentials', [] );

		if ( 'Readwise' === $source_name ) {
			$is_configured  = ! empty( $credentials['readwise']['access_token'] );
			$config_message = __( 'Requires Readwise API token', 'post-kinds-for-indieweb' );
		} elseif ( 'Foursquare' === $source_name ) {
			$is_configured  = ! empty( $credentials['foursquare']['access_token'] );
			$config_message = __( 'Requires Foursquare connection', 'post-kinds-for-indieweb' );
		}

		?>
		<div class="source-auto-sync-field" style="max-width: 500px;">
			<div style="display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: <?php echo $enabled ? '#f0f6fc' : '#f9f9f9'; ?>; border: 2px solid <?php echo $enabled ? '#2271b1' : '#ddd'; ?>; border-radius: 6px; <?php echo ! $is_configured ? 'opacity: 0.7;' : ''; ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" style="font-size: 24px; width: 24px; height: 24px; color: <?php echo $enabled ? '#2271b1' : '#8c8f94'; ?>;"></span>

				<label style="display: flex; align-items: center; gap: 10px; cursor: <?php echo $is_configured ? 'pointer' : 'not-allowed'; ?>; flex: 1;">
					<input
						type="checkbox"
						name="post_kinds_indieweb_settings[<?php echo esc_attr( $args['id'] ); ?>]"
						id="<?php echo esc_attr( $args['id'] ); ?>"
						value="1"
						<?php checked( $enabled ); ?>
						<?php disabled( ! $is_configured ); ?>
						style="width: 18px; height: 18px;"
					>
					<span style="font-weight: 500;">
						<?php esc_html_e( 'Enable Auto-Sync', 'post-kinds-for-indieweb' ); ?>
						<?php if ( $source_name ) : ?>
							<span style="font-weight: 400; color: #646970;">(<?php echo esc_html( $source_name ); ?>)</span>
						<?php endif; ?>
					</span>
				</label>

				<span style="padding: 3px 10px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; background: <?php echo $enabled ? '#2271b1' : '#ddd'; ?>; color: <?php echo $enabled ? '#fff' : '#666'; ?>;">
					<?php echo $enabled ? esc_html__( 'ON', 'post-kinds-for-indieweb' ) : esc_html__( 'OFF', 'post-kinds-for-indieweb' ); ?>
				</span>
			</div>

			<?php if ( ! empty( $args['desc'] ) ) : ?>
				<p class="description" style="margin-top: 8px; margin-left: 4px;">
					<?php echo esc_html( $args['desc'] ); ?>
				</p>
			<?php endif; ?>

			<?php if ( ! $is_configured && $config_message ) : ?>
				<p style="margin-top: 8px; margin-left: 4px; color: #d63638; font-size: 13px;">
					<span class="dashicons dashicons-warning" style="font-size: 14px; vertical-align: text-bottom;"></span>
					<?php echo esc_html( $config_message ); ?>.
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=post-kinds-indieweb-apis' ) ); ?>"><?php esc_html_e( 'Configure API', 'post-kinds-for-indieweb' ); ?></a>
				</p>
			<?php elseif ( $enabled && ! $background_sync_enabled ) : ?>
				<p style="margin-top: 8px; margin-left: 4px; color: #dba617; font-size: 13px;">
					<span class="dashicons dashicons-info" style="font-size: 14px; vertical-align: text-bottom;"></span>
					<?php esc_html_e( 'Note: Main background sync is off.', 'post-kinds-for-indieweb' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=post-kinds-for-indieweb&tab=performance' ) ); ?>"><?php esc_html_e( 'Enable in Performance tab', 'post-kinds-for-indieweb' ); ?></a>
				</p>
			<?php endif; ?>

			<?php
			// Show sync start date picker if enabled.
			if ( $is_configured ) :
				$sync_source_key  = $this->get_sync_source_key( $args['id'], $source_type );
				$sync_start_dates = $settings['sync_start_dates'] ?? [];
				$current_date     = $sync_start_dates[ $sync_source_key ] ?? '';
				// Convert ISO date to input date format (YYYY-MM-DD).
				$date_value = $current_date ? substr( $current_date, 0, 10 ) : '';
				?>
				<div class="sync-start-date-field" style="margin-top: 12px; padding: 12px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
					<label for="sync_start_date_<?php echo esc_attr( $sync_source_key ); ?>" style="display: block; font-weight: 500; margin-bottom: 6px;">
						<?php esc_html_e( 'Sync Start Date', 'post-kinds-for-indieweb' ); ?>
					</label>
					<div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
						<input
							type="date"
							id="sync_start_date_<?php echo esc_attr( $sync_source_key ); ?>"
							name="post_kinds_indieweb_settings[sync_start_dates][<?php echo esc_attr( $sync_source_key ); ?>]"
							value="<?php echo esc_attr( $date_value ); ?>"
							class="regular-text"
							style="width: auto;"
						>
						<button type="button" class="button button-small set-today-btn" data-target="sync_start_date_<?php echo esc_attr( $sync_source_key ); ?>">
							<?php esc_html_e( 'Set to Today', 'post-kinds-for-indieweb' ); ?>
						</button>
						<button type="button" class="button button-small clear-date-btn" data-target="sync_start_date_<?php echo esc_attr( $sync_source_key ); ?>">
							<?php esc_html_e( 'Clear', 'post-kinds-for-indieweb' ); ?>
						</button>
					</div>
					<p class="description" style="margin-top: 6px;">
						<?php esc_html_e( 'Only auto-sync items from this date forward. Leave empty to import all history.', 'post-kinds-for-indieweb' ); ?>
					</p>
				</div>
				<script>
				document.addEventListener('DOMContentLoaded', function() {
					document.querySelectorAll('.set-today-btn').forEach(function(btn) {
						btn.addEventListener('click', function() {
							var target = document.getElementById(this.dataset.target);
							if (target) target.value = new Date().toISOString().split('T')[0];
						});
					});
					document.querySelectorAll('.clear-date-btn').forEach(function(btn) {
						btn.addEventListener('click', function() {
							var target = document.getElementById(this.dataset.target);
							if (target) target.value = '';
						});
					});
				});
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get the sync source key for a given setting ID and source type.
	 *
	 * @param string $setting_id  The setting ID (e.g., 'listen_auto_import').
	 * @param string $source_type The source type (e.g., 'music', 'podcasts').
	 * @return string The sync source key for storing in sync_start_dates.
	 */
	private function get_sync_source_key( string $setting_id, string $source_type ): string {
		// Map setting IDs and source types to their sync source keys.
		$mappings = [
			'listen_auto_import'         => 'listenbrainz', // Will be overridden by import source.
			'listen_podcast_auto_import' => 'readwise_podcasts',
			'watch_auto_import'          => 'trakt_movies', // Will be overridden by import source.
			'read_auto_import'           => 'hardcover',    // Will be overridden by import source.
			'read_articles_auto_import'  => 'readwise_articles',
			'checkin_auto_import'        => 'foursquare',
		];

		return $mappings[ $setting_id ] ?? $source_type;
	}

	/**
	 * Render Foursquare connection status and actions.
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_foursquare_connection_field( array $args ): void {
		$credentials   = get_option( 'post_kinds_indieweb_api_credentials', [] );
		$foursquare    = $credentials['foursquare'] ?? [];
		$is_connected  = ! empty( $foursquare['access_token'] );
		$username      = $foursquare['username'] ?? '';
		$has_client_id = ! empty( $foursquare['client_id'] );

		echo '<div class="foursquare-connection-status">';

		if ( $is_connected ) {
			echo '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">';
			echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 20px;"></span>';
			echo '<span style="font-weight: 500;">' . esc_html__( 'Connected to Foursquare', 'post-kinds-for-indieweb' ) . '</span>';
			if ( $username ) {
				echo '<span style="color: #646970;">(' . esc_html( $username ) . ')</span>';
			}
			echo '</div>';

			echo '<div style="display: flex; gap: 8px; flex-wrap: wrap;">';

			// Import button.
			echo '<button type="button" class="button" id="foursquare-import-checkins">';
			echo '<span class="dashicons dashicons-download" style="margin-top: 4px;"></span> ';
			esc_html_e( 'Import Checkins from Foursquare', 'post-kinds-for-indieweb' );
			echo '</button>';

			// Disconnect button.
			echo '<button type="button" class="button" id="foursquare-disconnect" style="color: #d63638;">';
			echo '<span class="dashicons dashicons-no" style="margin-top: 4px;"></span> ';
			esc_html_e( 'Disconnect', 'post-kinds-for-indieweb' );
			echo '</button>';

			echo '</div>';

			echo '<p class="description" style="margin-top: 12px;">';
			esc_html_e( 'With Foursquare connected, you can:', 'post-kinds-for-indieweb' );
			echo '</p>';
			echo '<ul style="margin: 8px 0 0 24px; list-style: disc;">';
			echo '<li>' . esc_html__( 'POSSE: Publish checkins on your site first, automatically sync to Foursquare', 'post-kinds-for-indieweb' ) . '</li>';
			echo '<li>' . esc_html__( 'PESOS: Import existing Foursquare checkins to your site', 'post-kinds-for-indieweb' ) . '</li>';
			echo '</ul>';

		} elseif ( $has_client_id ) {
			echo '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">';
			echo '<span class="dashicons dashicons-warning" style="color: #dba617; font-size: 20px;"></span>';
			echo '<span>' . esc_html__( 'Foursquare app configured but not connected', 'post-kinds-for-indieweb' ) . '</span>';
			echo '</div>';

			echo '<p>';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=post-kinds-indieweb-apis' ) ) . '" class="button button-primary">';
			esc_html_e( 'Connect to Foursquare', 'post-kinds-for-indieweb' );
			echo '</a>';
			echo '</p>';

		} else {
			echo '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">';
			echo '<span class="dashicons dashicons-info" style="color: #72aee6; font-size: 20px;"></span>';
			echo '<span>' . esc_html__( 'Foursquare not configured', 'post-kinds-for-indieweb' ) . '</span>';
			echo '</div>';

			echo '<p class="description">';
			esc_html_e( 'To enable bidirectional checkin sync with Foursquare:', 'post-kinds-for-indieweb' );
			echo '</p>';
			echo '<ol style="margin: 8px 0 12px 24px;">';
			echo '<li>' . wp_kses(
				sprintf(
					/* translators: %s: URL to Foursquare developers */
					__( 'Create an app at <a href="%s" target="_blank" rel="noopener">foursquare.com/developers/apps</a>', 'post-kinds-for-indieweb' ),
					'https://foursquare.com/developers/apps'
				),
				[
					'a' => [
						'href'   => [],
						'target' => [],
						'rel'    => [],
					],
				]
			) . '</li>';
			echo '<li>' . esc_html__( 'Copy the Client ID and Client Secret', 'post-kinds-for-indieweb' ) . '</li>';
			echo '<li>' . wp_kses(
				sprintf(
					/* translators: %s: URL to API settings page */
					__( 'Enter them in the <a href="%s">API Settings</a> page', 'post-kinds-for-indieweb' ),
					admin_url( 'admin.php?page=post-kinds-indieweb-apis' )
				),
				[ 'a' => [ 'href' => [] ] ]
			) . '</li>';
			echo '<li>' . esc_html__( 'Click "Connect to Foursquare" to authorize', 'post-kinds-for-indieweb' ) . '</li>';
			echo '</ol>';

			echo '<p>';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=post-kinds-indieweb-apis' ) ) . '" class="button">';
			esc_html_e( 'Go to API Settings', 'post-kinds-for-indieweb' );
			echo '</a>';
			echo '</p>';
		}

		echo '</div>';

		// JavaScript for import and disconnect actions.
		if ( $is_connected ) {
			?>
			<script>
			document.getElementById('foursquare-import-checkins')?.addEventListener('click', function() {
				const btn = this;
				const originalText = btn.innerHTML;
				btn.disabled = true;
				btn.innerHTML = '<span class="dashicons dashicons-update" style="margin-top: 4px; animation: rotation 1s linear infinite;"></span> <?php echo esc_js( __( 'Importing...', 'post-kinds-for-indieweb' ) ); ?>';

				fetch(ajaxurl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: new URLSearchParams({
						action: 'post_kinds_foursquare_import',
						nonce: '<?php echo esc_js( wp_create_nonce( 'post_kinds_indieweb_admin' ) ); ?>'
					})
				})
				.then(response => response.json())
				.then(data => {
					btn.disabled = false;
					btn.innerHTML = originalText;
					if (data.success) {
						alert('<?php echo esc_js( __( 'Import complete!', 'post-kinds-for-indieweb' ) ); ?> ' + data.data.message);
					} else {
						alert('<?php echo esc_js( __( 'Import failed:', 'post-kinds-for-indieweb' ) ); ?> ' + data.data.message);
					}
				})
				.catch(error => {
					btn.disabled = false;
					btn.innerHTML = originalText;
					alert('<?php echo esc_js( __( 'Import failed:', 'post-kinds-for-indieweb' ) ); ?> ' + error.message);
				});
			});

			document.getElementById('foursquare-disconnect')?.addEventListener('click', function() {
				if (!confirm('<?php echo esc_js( __( 'Are you sure you want to disconnect from Foursquare? You can reconnect later.', 'post-kinds-for-indieweb' ) ); ?>')) {
					return;
				}

				const btn = this;
				btn.disabled = true;

				fetch(ajaxurl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: new URLSearchParams({
						action: 'post_kinds_foursquare_disconnect',
						nonce: '<?php echo esc_js( wp_create_nonce( 'post_kinds_indieweb_admin' ) ); ?>'
					})
				})
				.then(response => response.json())
				.then(data => {
					if (data.success) {
						location.reload();
					} else {
						btn.disabled = false;
						alert('<?php echo esc_js( __( 'Disconnect failed:', 'post-kinds-for-indieweb' ) ); ?> ' + data.data.message);
					}
				})
				.catch(error => {
					btn.disabled = false;
					alert('<?php echo esc_js( __( 'Disconnect failed:', 'post-kinds-for-indieweb' ) ); ?> ' + error.message);
				});
			});
			</script>
			<style>
			@keyframes rotation {
				from { transform: rotate(0deg); }
				to { transform: rotate(360deg); }
			}
			</style>
			<?php
		}
	}

	/**
	 * Render the WP Recipe Maker integration field.
	 *
	 * @param array<string, mixed> $args Field arguments.
	 * @return void
	 */
	public function render_wprm_integration_field( array $args ): void {
		$settings     = get_option( 'post_kinds_indieweb_settings', $this->admin->get_default_settings() );
		$wprm_active  = class_exists( 'WPRM_Recipe_Manager' );
		$auto_enabled = ! empty( $settings[ $args['id'] ] );

		if ( ! $wprm_active ) {
			?>
			<p class="description">
				<span class="dashicons dashicons-warning" style="color: #d63638;"></span>
				<?php
				printf(
					/* translators: %s: plugin URL */
					esc_html__( 'WP Recipe Maker is not installed. %s to use this integration.', 'post-kinds-for-indieweb' ),
					'<a href="' . esc_url( admin_url( 'plugin-install.php?s=wp+recipe+maker&tab=search&type=term' ) ) . '">' . esc_html__( 'Install it', 'post-kinds-for-indieweb' ) . '</a>'
				);
				?>
			</p>
			<?php
			return;
		}

		?>
		<fieldset>
			<label>
				<input type="checkbox"
						name="post_kinds_indieweb_settings[<?php echo esc_attr( $args['id'] ); ?>]"
						id="<?php echo esc_attr( $args['id'] ); ?>"
						value="1"
						<?php checked( $auto_enabled ); ?>>
				<?php esc_html_e( 'Auto-detect recipes and set Recipe kind', 'post-kinds-for-indieweb' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'Automatically sets the "Recipe" post kind when a WP Recipe Maker recipe is detected in a post.', 'post-kinds-for-indieweb' ); ?>
			</p>

			<br>

			<p>
				<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
				<?php esc_html_e( 'WP Recipe Maker is active and ready.', 'post-kinds-for-indieweb' ); ?>
			</p>

			<p class="description">
				<?php esc_html_e( 'When enabled, the plugin will:', 'post-kinds-for-indieweb' ); ?>
			</p>
			<ul style="list-style-type: disc; margin-left: 20px;">
				<li><?php esc_html_e( 'Detect WPRM recipe blocks and shortcodes in posts', 'post-kinds-for-indieweb' ); ?></li>
				<li><?php esc_html_e( 'Automatically assign the "Recipe" kind to posts containing recipes', 'post-kinds-for-indieweb' ); ?></li>
				<li><?php esc_html_e( 'Sync recipe metadata (servings, prep time, cook time) to reaction fields', 'post-kinds-for-indieweb' ); ?></li>
			</ul>
		</fieldset>
		<?php
	}
}
