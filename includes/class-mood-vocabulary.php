<?php
/**
 * Mood vocabulary: stable mood identities and their spelling-resolved labels.
 *
 * A mood's identity is a key ("energized") that never changes. Its label is
 * display text resolved at read time for the site's mood spelling setting:
 * "Follow the site language" translates in the Site Language (never the
 * current user's profile language); "English (United States)" uses the
 * plugin's source strings; "English (United Kingdom)" uses an en_GB
 * translation when one is installed, then the explicit variant map below.
 *
 * Authored text is never rewritten. A stored label is swapped for the
 * resolved label only when the post also stores the mood's key and the
 * stored text is still one of that mood's known spellings — a label the
 * author typed or edited renders exactly as saved.
 *
 * @package PKIW
 * @since   1.9.0
 */

declare(strict_types=1);

namespace PKIW;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mood vocabulary and label resolution.
 *
 * @since 1.9.0
 */
final class Mood_Vocabulary {

	/**
	 * Key of the spelling preference inside the pkiw_settings option.
	 *
	 * @var string
	 */
	public const SETTING = 'mood_spelling';

	/**
	 * Follow the Site Language (the default).
	 *
	 * @var string
	 */
	public const SPELLING_SITE = 'site';

	/**
	 * American English spelling.
	 *
	 * @var string
	 */
	public const SPELLING_US = 'en_US';

	/**
	 * British English spelling.
	 *
	 * @var string
	 */
	public const SPELLING_UK = 'en_GB';

	/**
	 * REST route (under REST_API::NAMESPACE) that exposes the resolved vocabulary.
	 *
	 * @var string
	 */
	public const REST_ROUTE = '/moods';

	/**
	 * British spellings for the moods whose en_US source label differs.
	 *
	 * Scoped to plugin-supplied mood labels only. Used for en_GB when no
	 * installed en_GB translation already changes the label.
	 *
	 * @var array<string, string>
	 */
	private const EN_GB_VARIANTS = [
		'energized'   => 'Energised',
		'honored'     => 'Honoured',
		'mesmerized'  => 'Mesmerised',
		'revitalized' => 'Revitalised',
		'organized'   => 'Organised',
		'demoralized' => 'Demoralised',
	];

	/**
	 * Resolved vocabularies keyed by "spelling|locale".
	 *
	 * The key carries every input the resolution depends on, so a changed
	 * setting or Site Language reads a different entry and never a stale one.
	 *
	 * @var array<string, list<array{key: string, label: string, variants: list<string>}>>
	 */
	private static array $cache = [];

	/**
	 * Hook the REST route.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	/**
	 * Allowed spelling values with their admin labels.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string, string> Setting value => label.
	 */
	public static function spelling_options(): array {
		return [
			self::SPELLING_SITE => __( 'Follow the site language', 'post-kinds-for-indieweb-in-block-themes' ),
			self::SPELLING_US   => __( 'English (United States)', 'post-kinds-for-indieweb-in-block-themes' ),
			self::SPELLING_UK   => __( 'English (United Kingdom)', 'post-kinds-for-indieweb-in-block-themes' ),
		];
	}

	/**
	 * Sanitize a spelling value, falling back to the site default.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed $value Raw value.
	 * @return string One of site, en_US, en_GB.
	 */
	public static function sanitize_spelling( mixed $value ): string {
		$allowed = [ self::SPELLING_SITE, self::SPELLING_US, self::SPELLING_UK ];

		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : self::SPELLING_SITE;
	}

	/**
	 * The stored spelling preference.
	 *
	 * @since 1.9.0
	 *
	 * @return string One of site, en_US, en_GB.
	 */
	public static function get_spelling(): string {
		$settings = get_option( 'pkiw_settings', [] );

		return self::sanitize_spelling( is_array( $settings ) ? ( $settings[ self::SETTING ] ?? null ) : null );
	}

	/**
	 * Locale mood labels resolve in for a spelling preference.
	 *
	 * "site" reads get_locale(), the Site Language, so an administrator whose
	 * profile language differs still authors and publishes in the site's.
	 *
	 * @since 1.9.0
	 *
	 * @param string|null $spelling Spelling preference; null reads the setting.
	 * @return string Locale such as en_US, en_GB or fr_FR.
	 */
	public static function label_locale( ?string $spelling = null ): string {
		$spelling = null === $spelling ? self::get_spelling() : self::sanitize_spelling( $spelling );

		return self::SPELLING_SITE === $spelling ? (string) get_locale() : $spelling;
	}

	/**
	 * The mood vocabulary with labels resolved for a spelling preference.
	 *
	 * @since 1.9.0
	 *
	 * @param string|null $spelling Spelling preference; null reads the setting.
	 * @return list<array{key: string, label: string, variants: list<string>}> Moods in display order.
	 */
	public static function get_moods( ?string $spelling = null ): array {
		$spelling  = null === $spelling ? self::get_spelling() : self::sanitize_spelling( $spelling );
		$locale    = self::label_locale( $spelling );
		$cache_key = $spelling . '|' . $locale;

		if ( ! isset( self::$cache[ $cache_key ] ) ) {
			self::$cache[ $cache_key ] = self::build( $locale );
		}

		$base   = self::$cache[ $cache_key ];
		$labels = array_column( $base, 'label', 'key' );

		/**
		 * Filters the resolved mood labels, keyed by mood identity.
		 *
		 * Change a label, remove a mood, or add a site-specific mood. Keys
		 * are stored in posts, so keep them stable once published.
		 *
		 * @since 1.9.0
		 *
		 * @param array<string, string> $labels   Mood key => resolved label.
		 * @param string                $locale   Locale the labels resolved in (en_US, en_GB, fr_FR, ...).
		 * @param string                $spelling Stored preference: site, en_US or en_GB.
		 */
		$filtered = apply_filters( 'pkiw_mood_labels', $labels, $locale, $spelling );
		if ( ! is_array( $filtered ) ) {
			$filtered = $labels;
		}

		$variants = array_column( $base, 'variants', 'key' );
		$moods    = [];

		foreach ( $filtered as $key => $label ) {
			$key   = sanitize_key( (string) $key );
			$label = is_string( $label ) ? trim( $label ) : '';
			if ( '' === $key || '' === $label ) {
				continue;
			}

			$moods[] = [
				'key'      => $key,
				'label'    => $label,
				'variants' => array_values( array_unique( array_merge( $variants[ $key ] ?? [], [ $label ] ) ) ),
			];
		}

		return $moods;
	}

	/**
	 * A mood's resolved label.
	 *
	 * @since 1.9.0
	 *
	 * @param string      $key      Mood key.
	 * @param string|null $spelling Spelling preference; null reads the setting.
	 * @return string The label, or '' for an unknown key.
	 */
	public static function get_label( string $key, ?string $spelling = null ): string {
		$mood = self::find( $key, $spelling );

		return null === $mood ? '' : $mood['label'];
	}

	/**
	 * Display text for a stored mood label.
	 *
	 * Returns the resolved label only when the stored key names a known mood
	 * and the stored text is empty or still one of that mood's spellings.
	 * Anything else — no key, an unknown key, a typed or edited label —
	 * comes back exactly as authored.
	 *
	 * @since 1.9.0
	 *
	 * @param string $authored Stored label text.
	 * @param string $key      Stored mood key ('' when none).
	 * @return string Text to display.
	 */
	public static function display_label( string $authored, string $key = '' ): string {
		if ( '' === $key ) {
			return $authored;
		}

		$mood = self::find( $key );
		if ( null === $mood ) {
			return $authored;
		}

		$text = trim( $authored );
		if ( '' === $text || in_array( $text, $mood['variants'], true ) ) {
			return $mood['label'];
		}

		return $authored;
	}

	/**
	 * Register the read-only vocabulary route.
	 *
	 * @since 1.9.0
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			REST_API::NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'rest_get_moods' ],
				'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			]
		);
	}

	/**
	 * REST callback: the vocabulary resolved for the stored preference.
	 *
	 * `version` changes whenever the preference, Site Language or any label
	 * changes, so clients that keep a copy know when to refresh it.
	 *
	 * @since 1.9.0
	 *
	 * @return \WP_REST_Response
	 */
	public static function rest_get_moods(): \WP_REST_Response {
		$spelling = self::get_spelling();
		$locale   = self::label_locale( $spelling );
		$moods    = self::get_moods( $spelling );

		return new \WP_REST_Response(
			[
				'spelling' => $spelling,
				'locale'   => $locale,
				'version'  => md5( (string) wp_json_encode( [ $spelling, $locale, $moods ] ) ),
				'moods'    => $moods,
			]
		);
	}

	/**
	 * Look up one mood by key.
	 *
	 * @param string      $key      Mood key.
	 * @param string|null $spelling Spelling preference; null reads the setting.
	 * @return array{key: string, label: string, variants: list<string>}|null
	 */
	private static function find( string $key, ?string $spelling = null ): ?array {
		foreach ( self::get_moods( $spelling ) as $mood ) {
			if ( $mood['key'] === $key ) {
				return $mood;
			}
		}

		return null;
	}

	/**
	 * Resolve every mood's label and known spellings in a locale.
	 *
	 * @param string $locale Target locale.
	 * @return list<array{key: string, label: string, variants: list<string>}>
	 */
	private static function build( string $locale ): array {
		$source = self::labels_in_locale( 'en_US' );
		$local  = 'en_US' === $locale ? $source : self::labels_in_locale( $locale );
		$moods  = [];

		foreach ( $source as $key => $source_label ) {
			$british = self::EN_GB_VARIANTS[ $key ] ?? $source_label;
			$label   = $local[ $key ] ?? $source_label;

			// No installed en_GB translation changed this label: use the variant map.
			if ( self::SPELLING_UK === $locale && $label === $source_label ) {
				$label = $british;
			}

			$moods[] = [
				'key'      => $key,
				'label'    => $label,
				'variants' => array_values( array_unique( [ $source_label, $british, $label ] ) ),
			];
		}

		return $moods;
	}

	/**
	 * Translate the vocabulary in a locale without leaking the switch.
	 *
	 * A locale with no installed language files falls back to en_US, which
	 * is what that site's visitors see for untranslated plugin strings.
	 *
	 * @param string $locale Target locale.
	 * @return array<string, string> Mood key => label.
	 */
	private static function labels_in_locale( string $locale ): array {
		if ( determine_locale() === $locale ) {
			return self::definitions();
		}

		$switched = switch_to_locale( $locale );
		if ( ! $switched && 'en_US' !== determine_locale() ) {
			$switched = switch_to_locale( 'en_US' );
		}

		$labels = self::definitions();

		if ( $switched ) {
			restore_previous_locale();
		}

		return $labels;
	}

	/**
	 * Plugin-supplied moods: identity => translatable en_US label.
	 *
	 * @return array<string, string>
	 */
	private static function definitions(): array {
		return [
			'happy'       => __( 'Happy', 'post-kinds-for-indieweb-in-block-themes' ),
			'excited'     => __( 'Excited', 'post-kinds-for-indieweb-in-block-themes' ),
			'grateful'    => __( 'Grateful', 'post-kinds-for-indieweb-in-block-themes' ),
			'calm'        => __( 'Calm', 'post-kinds-for-indieweb-in-block-themes' ),
			'hopeful'     => __( 'Hopeful', 'post-kinds-for-indieweb-in-block-themes' ),
			'inspired'    => __( 'Inspired', 'post-kinds-for-indieweb-in-block-themes' ),
			'energized'   => __( 'Energized', 'post-kinds-for-indieweb-in-block-themes' ),
			'honored'     => __( 'Honored', 'post-kinds-for-indieweb-in-block-themes' ),
			'mesmerized'  => __( 'Mesmerized', 'post-kinds-for-indieweb-in-block-themes' ),
			'revitalized' => __( 'Revitalized', 'post-kinds-for-indieweb-in-block-themes' ),
			'organized'   => __( 'Organized', 'post-kinds-for-indieweb-in-block-themes' ),
			'thoughtful'  => __( 'Thoughtful', 'post-kinds-for-indieweb-in-block-themes' ),
			'neutral'     => __( 'Neutral', 'post-kinds-for-indieweb-in-block-themes' ),
			'tired'       => __( 'Tired', 'post-kinds-for-indieweb-in-block-themes' ),
			'sad'         => __( 'Sad', 'post-kinds-for-indieweb-in-block-themes' ),
			'anxious'     => __( 'Anxious', 'post-kinds-for-indieweb-in-block-themes' ),
			'overwhelmed' => __( 'Overwhelmed', 'post-kinds-for-indieweb-in-block-themes' ),
			'angry'       => __( 'Angry', 'post-kinds-for-indieweb-in-block-themes' ),
			'demoralized' => __( 'Demoralized', 'post-kinds-for-indieweb-in-block-themes' ),
		];
	}
}
