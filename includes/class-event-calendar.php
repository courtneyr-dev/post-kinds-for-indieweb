<?php
/**
 * Event archive calendar block and request behavior.
 *
 * @package PKIW
 * @since   1.10.0
 */

declare(strict_types=1);

namespace PKIW;

use PKIW\Integrations\My_Calendar;
use PKIW\Integrations\My_Calendar_Privacy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own the My Calendar view of the Event archive.
 *
 * @since 1.10.0
 */
final class Event_Calendar {

	/** Event kind slug. */
	public const KIND = 'event';

	/** Dynamic calendar block name. */
	public const BLOCK = 'post-kinds-indieweb/event-calendar';

	/** Event archive template slug. */
	public const TEMPLATE = 'taxonomy-kind-event';

	/** Calendar visibility setting key. */
	public const SETTING_CALENDAR = 'event_calendar';

	/** Site-wide location privacy setting key. */
	public const SETTING_HIDE_ALL = 'event_hide_locations';

	/**
	 * My Calendar query variables whose archive variants must not be indexed.
	 *
	 * @var string[]
	 */
	public const NOINDEX_VARS = [ 'month', 'yr', 'dy', 'mcat', 'format', 'time', 'mc_id', 'cid', 'ltype', 'loc', 'access', 'mcs' ];

	/**
	 * Whether the block rendered during this request.
	 *
	 * @var bool
	 */
	private static bool $rendered = false;

	/**
	 * Register the archive, block, privacy, asset, and robots contracts.
	 */
	public function register(): void {
		add_action( 'init', [ self::class, 'register_blocks' ] );
		add_filter( 'taxonomy_template_hierarchy', [ self::class, 'hierarchy' ] );
		add_filter( 'wp_robots', [ self::class, 'robots' ] );
		add_action( 'template_redirect', [ self::class, 'send_robots_header' ], 0 );

		add_filter( 'mc_display_css_on_archives', '__return_false' );
		add_action( 'plugins_loaded', [ self::class, 'disable_footer_loader' ], 20 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'dequeue_unrendered_assets' ], 21 );
		add_filter( 'perfmatters_rucss_excluded_stylesheets', [ self::class, 'exclude_rucss' ] );

		// PKIW boots at init priority 0, after plugins_loaded has fired. Register
		// the inert provider filters now while retaining the plugins_loaded hook
		// for alternate bootstraps and tests.
		if ( did_action( 'plugins_loaded' ) ) {
			self::disable_footer_loader();
			My_Calendar_Privacy::register();
		} else {
			add_action( 'plugins_loaded', [ My_Calendar_Privacy::class, 'register' ] );
		}
	}

	/**
	 * Whether the Event archive can render the calendar.
	 */
	public static function will_render(): bool {
		$settings = get_option( 'pkiw_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];
		$enabled  = array_key_exists( self::SETTING_CALENDAR, $settings )
			? (bool) $settings[ self::SETTING_CALENDAR ]
			: true;
		$enabled  = ( new My_Calendar() )->is_active() && $enabled;

		return (bool) apply_filters( 'pkiw_event_calendar_enabled', $enabled );
	}

	/**
	 * Register editor assets, styles, and the dynamic block.
	 */
	public static function register_blocks(): void {
		wp_register_script(
			'pkiw-event-calendar-editor',
			\PKIW_URL . 'assets/js/event-calendar-editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ],
			\PKIW_VERSION,
			true
		);
		wp_set_script_translations( 'pkiw-event-calendar-editor', 'post-kinds-for-indieweb-in-block-themes' );

		$style_path = \PKIW_PATH . 'styles/event-calendar.css';
		wp_register_style(
			'pkiw-event-calendar',
			\PKIW_URL . 'styles/event-calendar.css',
			[],
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : \PKIW_VERSION
		);

		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			return;
		}

		/**
		 * Block type arguments.
		 *
		 * @var array<string, mixed> $args
		 */
		$args = [
			'api_version'     => 3,
			'title'           => __( 'Event calendar', 'post-kinds-for-indieweb-in-block-themes' ),
			'category'        => 'post-kinds-indieweb',
			'attributes'      => [
				'format' => [
					'type'    => 'string',
					'enum'    => [ 'calendar', 'list', 'mini' ],
					'default' => 'calendar',
				],
				'label'  => [
					'type'    => 'string',
					'default' => __( 'Event calendar', 'post-kinds-for-indieweb-in-block-themes' ),
					'role'    => 'content',
				],
			],
			'render_callback' => [ self::class, 'render' ],
			'editor_script'   => 'pkiw-event-calendar-editor',
			'style'           => 'pkiw-event-calendar',
			'supports'        => [
				'html'     => false,
				'reusable' => false,
			],
		];
		register_block_type( self::BLOCK, $args );
	}

	/**
	 * Render the My Calendar shortcode inside an accessible region.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Saved block content (unused).
	 * @param \WP_Block|null       $block      Block instance (unused).
	 */
	public static function render( array $attributes, string $content = '', ?\WP_Block $block = null ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress render callback signature.
		if ( ! self::will_render() ) {
			return '';
		}

		$format = isset( $attributes['format'] ) && is_string( $attributes['format'] )
			? $attributes['format']
			: 'calendar';
		if ( ! in_array( $format, [ 'calendar', 'list', 'mini' ], true ) ) {
			$format = 'calendar';
		}

		$label = isset( $attributes['label'] ) && is_scalar( $attributes['label'] )
			? trim( (string) $attributes['label'] )
			: __( 'Event calendar', 'post-kinds-for-indieweb-in-block-themes' );
		if ( '' === $label ) {
			$label = __( 'Event calendar', 'post-kinds-for-indieweb-in-block-themes' );
		}

		self::$rendered = true;
		self::enqueue_my_calendar_assets();
		$wrapper = get_block_wrapper_attributes( [ 'class' => 'pkiw-event-calendar' ] );

		return '<section ' . $wrapper . ' aria-label="' . esc_attr( $label ) . '">'
			. do_shortcode( '[my_calendar format="' . $format . '"]' )
			. '</section>';
	}

	/**
	 * Remove the Event-specific template when the calendar view can't answer.
	 *
	 * @param string[] $templates Template hierarchy candidates.
	 * @return string[]
	 */
	public static function hierarchy( array $templates ): array {
		if ( ! is_tax( Taxonomy::TAXONOMY, self::KIND ) ) {
			return $templates;
		}

		if ( self::will_render() && ! is_paged() ) {
			return $templates;
		}

		return array_values(
			array_diff( $templates, [ self::TEMPLATE, self::TEMPLATE . '.php' ] )
		);
	}

	/** Remove My Calendar's archive-wide footer loader. */
	public static function disable_footer_loader(): void {
		remove_action( 'wp_footer', 'mc_footer_js' );
	}

	/** Dequeue My Calendar styles unless this request rendered the block. */
	public static function dequeue_unrendered_assets(): void {
		if ( self::$rendered ) {
			return;
		}

		foreach ( [ 'my-calendar-style', 'my-calendar-reset', 'my-calendar-lists' ] as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	/**
	 * Keep conditional calendar styles out of used-CSS removal.
	 *
	 * @param string[] $styles Existing exclusions.
	 * @return string[]
	 */
	public static function exclude_rucss( array $styles ): array {
		foreach ( [ 'styles/event-calendar.css', 'my-calendar/css/reset.css', 'my-calendar/css/list-presets.css' ] as $style ) {
			if ( ! in_array( $style, $styles, true ) ) {
				$styles[] = $style;
			}
		}

		return $styles;
	}

	/**
	 * Add noindex,follow to calendar variants of the Event archive.
	 *
	 * @param array<string, bool> $robots Existing robots directives.
	 * @return array<string, bool>
	 */
	public static function robots( array $robots ): array {
		if ( ! self::is_noindex_request() ) {
			return $robots;
		}

		$robots['noindex'] = true;
		$robots['follow']  = true;

		return $robots;
	}

	/**
	 * Send the robots header before My Calendar can render its print view.
	 */
	public static function send_robots_header(): void {
		$value = self::robots_header_value();
		if ( null !== $value && ! headers_sent() ) {
			header( 'X-Robots-Tag: ' . $value, true );
		}
	}

	/**
	 * Get the robots header value for the current request.
	 *
	 * @return string|null Header value for a calendar variant.
	 */
	public static function robots_header_value(): ?string {
		return self::is_noindex_request() ? 'noindex, follow' : null;
	}

	/** Enqueue My Calendar's registered styles and scripts for the block. */
	private static function enqueue_my_calendar_assets(): void {
		if ( function_exists( 'mc_enqueue_calendar_styles' ) && function_exists( 'mc_get_style_path' ) && function_exists( 'mc_get_option' ) ) {
			$stylesheet = apply_filters( 'mc_registered_stylesheet', \mc_get_style_path( \mc_get_option( 'css_file' ), 'url' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- My Calendar's public filter.
			\mc_enqueue_calendar_styles( $stylesheet );
		}

		if ( function_exists( 'mc_enqueue_calendar_js' ) ) {
			\mc_enqueue_calendar_js();
		}
	}

	/** Whether the request is an Event archive variant My Calendar controls. */
	private static function is_noindex_request(): bool {
		if ( ! is_tax( Taxonomy::TAXONOMY, self::KIND ) ) {
			return false;
		}

		foreach ( self::NOINDEX_VARS as $variable ) {
			if ( array_key_exists( $variable, $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public archive query keys only; values aren't read.
				return true;
			}
		}

		return false;
	}
}
