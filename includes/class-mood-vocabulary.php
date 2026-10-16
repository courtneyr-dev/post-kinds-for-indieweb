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
 * Every mood also belongs to a family (joy, calm, energy and so on; issue
 * 248). A family has a label and a one- or two-letter code the mood year grid
 * prints, so the grid never relies on color alone. Typed moods the
 * vocabulary doesn't know land in 'other' unless a filter files them.
 * facts() is the one reader a theme uses for a mood post.
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

// family() and families() are this plugin's mood methods, not the removed fam extension's functions.
// phpcs:disable PHPCompatibility.Extensions.RemovedExtensions.famRemoved

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
	 * Highest intensity a mood card holds.
	 *
	 * @var int
	 */
	public const MAX_INTENSITY = 5;

	/**
	 * The family of each plugin-supplied mood, by key.
	 *
	 * The twelve moods the theme's catalog also lists keep its family. The
	 * other seven take the family of their nearest catalog synonym: honored
	 * (proud), mesmerized (enthralled, amazed), revitalized (refreshed,
	 * rejuvenated), organized (accomplished, productive), neutral
	 * (indifferent), overwhelmed (stressed) and demoralized (hopeless).
	 *
	 * @var array<string, string>
	 */
	public const FAMILY_OF_KEY = [
		'happy'       => 'joy',
		'excited'     => 'energy',
		'grateful'    => 'social',
		'calm'        => 'calm',
		'hopeful'     => 'thoughtful',
		'inspired'    => 'thoughtful',
		'energized'   => 'energy',
		'honored'     => 'joy',
		'mesmerized'  => 'joy',
		'revitalized' => 'energy',
		'organized'   => 'work',
		'thoughtful'  => 'thoughtful',
		'neutral'     => 'calm',
		'tired'       => 'physical',
		'sad'         => 'sad',
		'anxious'     => 'anxious',
		'overwhelmed' => 'anxious',
		'angry'       => 'angry',
		'demoralized' => 'sad',
	];

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
		Kind_Facts::register( 'mood', [ self::class, 'facts' ] );
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
	 * The mood families, in the order the theme's catalog lists them.
	 *
	 * Each is `[ 'key' => 'joy', 'label' => 'Joy', 'code' => 'J' ]`. A code
	 * is one or two characters and unique across the list; 'other' is always
	 * present, last unless the filter adds more after it.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string, array{key: string, label: string, code: string}> Family key => family.
	 */
	public static function families(): array {
		$defaults = self::default_families();

		/**
		 * Filters the mood families.
		 *
		 * Add a family, rename one, or change a code. Codes longer than two
		 * characters are cut, an empty or taken code is replaced with the first
		 * free one built from the family key, and 'other' is put back if removed.
		 * To file a mood in a family, use `pkiw_mood_family`.
		 *
		 * @since 1.9.0
		 *
		 * @param array<string, array{label: string, code: string}> $families Family key => label and code.
		 */
		$filtered = apply_filters( 'pkiw_mood_families', $defaults );
		if ( ! is_array( $filtered ) ) {
			$filtered = $defaults;
		}

		$pending = [];
		foreach ( $filtered as $raw_key => $row ) {
			$key = sanitize_key( (string) $raw_key );
			if ( '' === $key || ! is_array( $row ) || isset( $pending[ $key ] ) ) {
				continue;
			}
			$pending[ $key ] = [
				is_scalar( $row['label'] ?? null ) ? trim( wp_strip_all_tags( (string) $row['label'] ) ) : '',
				is_scalar( $row['code'] ?? null ) ? self::trim_code( (string) $row['code'] ) : '',
			];
		}
		if ( ! isset( $pending['other'] ) ) {
			$pending['other'] = [ $defaults['other']['label'], $defaults['other']['code'] ];
		}

		$families = [];
		$claimed  = [];
		foreach ( $pending as $key => [ $label, $given ] ) {
			$code = '';
			foreach ( self::code_candidates( $key, $given ) as $candidate ) {
				if ( ! isset( $claimed[ $candidate ] ) ) {
					$code = $candidate;
					break;
				}
			}
			if ( '' === $code ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html( sprintf( 'Mood family %s has no free code and was dropped. Its moods read as other.', $key ) ),
					'1.9.0'
				);
				continue;
			}

			$claimed[ $code ] = true;
			$families[ $key ] = [
				'key'   => $key,
				'label' => '' !== $label ? $label : ucfirst( str_replace( [ '-', '_' ], ' ', $key ) ),
				'code'  => $code,
			];
		}

		return $families;
	}

	/**
	 * One family, falling back to 'other' for a key the list doesn't hold.
	 *
	 * @since 1.9.0
	 *
	 * @param string $family_key Family key.
	 * @return array{key: string, label: string, code: string}
	 */
	public static function family( string $family_key ): array {
		$families = self::families();

		return $families[ $family_key ] ?? $families['other'] ?? [
			'key'   => 'other',
			'label' => __( 'Other', 'post-kinds-for-indieweb-in-block-themes' ),
			'code'  => 'O',
		];
	}

	/**
	 * The family key of a mood.
	 *
	 * Resolves in this order: a stored key the vocabulary knows and
	 * FAMILY_OF_KEY lists; with no key, a label that equals one of the
	 * vocabulary's spellings, ignoring case; then the `pkiw_mood_family`
	 * filter. Anything that isn't a family on the list becomes 'other'.
	 * Displayed text is untouched: display_label() still prints a typed
	 * label as saved.
	 *
	 * @since 1.9.0
	 *
	 * @param string $label Stored label text.
	 * @param string $key   Stored mood key ('' when none).
	 * @return string A key of families().
	 */
	public static function family_of( string $label, string $key = '' ): string {
		$family = '';

		if ( '' !== $key ) {
			if ( null !== self::find( $key ) ) {
				$family = self::FAMILY_OF_KEY[ $key ] ?? '';
			}
		} else {
			$needle = mb_strtolower( trim( $label ) );
			if ( '' !== $needle ) {
				foreach ( self::get_moods() as $mood ) {
					foreach ( $mood['variants'] as $variant ) {
						if ( mb_strtolower( $variant ) === $needle ) {
							$family = self::FAMILY_OF_KEY[ $mood['key'] ] ?? '';
							break 2;
						}
					}
				}
			}
		}

		/**
		 * Files a mood in a family.
		 *
		 * The family is '' when the vocabulary didn't place the mood: a typed
		 * label, or a mood a site added with `pkiw_mood_labels`. Return a key
		 * of `pkiw_mood_families`. Anything else reads as 'other'.
		 *
		 * @since 1.9.0
		 *
		 * @param string $family Family key the vocabulary found, or ''.
		 * @param string $key    Stored mood key, or ''.
		 * @param string $label  Stored label text.
		 */
		$family = apply_filters( 'pkiw_mood_family', $family, $key, $label );
		$family = is_string( $family ) ? sanitize_key( $family ) : '';

		return array_key_exists( $family, self::families() ) ? $family : 'other';
	}

	/**
	 * Intensity text for a 1-5 rating, such as "Intensity 4 of 5".
	 *
	 * A pure formatter, so the facts reader and a theme's editor preview
	 * print one string.
	 *
	 * @since 1.9.0
	 *
	 * @param int|null $intensity Rating.
	 * @return string The text, or '' for null or a rating outside 1-5.
	 */
	public static function intensity_text( ?int $intensity ): string {
		if ( null === $intensity || $intensity < 1 || $intensity > self::MAX_INTENSITY ) {
			return '';
		}

		return sprintf(
			/* translators: 1: mood intensity from 1 to 5; 2: the highest intensity, 5. */
			__( 'Intensity %1$d of %2$d', 'post-kinds-for-indieweb-in-block-themes' ),
			$intensity,
			self::MAX_INTENSITY
		);
	}

	/**
	 * A mood post's intensity, 1-5, or null when nobody picked one.
	 *
	 * Reads the saved rating (meta 1-5) first, then an intensity attribute
	 * the mood card's block comment actually serializes. It never reads the
	 * block.json default of 3: parse_blocks() returns only serialized
	 * attributes, and an explicit pick of 3 is stored only in meta.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return int|null
	 */
	public static function intensity( int $post_id ): ?int {
		$meta = self::rating_meta( $post_id );
		if ( null !== $meta ) {
			return $meta;
		}

		return self::attribute_intensity( self::card_attrs( $post_id ) );
	}

	/**
	 * The facts a theme prints for a mood post.
	 *
	 * Label and key come from the post's first mood card, then meta;
	 * intensity from meta, then a serialized attribute. No note, emoji, time
	 * or location is among them, and a post the requester can't read has none.
	 *
	 * @since 1.9.0
	 *
	 * @param int $post_id Post ID.
	 * @return array{label: string, key: string, has_label: bool, name: string, family: string, family_label: string, family_code: string, intensity: int|null, intensity_text: string, day: string}|array{} Facts, or [] when the post can't be shown.
	 */
	public static function facts( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! Kind_Facts::can_show( $post ) ) {
			return [];
		}

		$card     = self::card_attrs( $post_id );
		$authored = is_array( $card ) && is_string( $card['mood'] ?? null ) ? (string) $card['mood'] : '';
		$key      = is_array( $card ) && is_string( $card['moodKey'] ?? null ) ? sanitize_key( (string) $card['moodKey'] ) : '';
		if ( '' === trim( $authored ) && '' === $key ) {
			$authored = (string) get_metadata_raw( 'post', $post_id, Meta_Fields::PREFIX . 'mood_label', true );
			$key      = sanitize_key( (string) get_metadata_raw( 'post', $post_id, Meta_Fields::PREFIX . 'mood_key', true ) );
		}

		$label     = trim( self::display_label( $authored, $key ) );
		$family    = self::family( self::family_of( $authored, $key ) );
		$intensity = self::rating_meta( $post_id ) ?? self::attribute_intensity( $card );
		$day       = get_post_datetime( $post, 'date', 'local' );

		return [
			'label'          => $label,
			'key'            => $key,
			'has_label'      => '' !== $label,
			'name'           => '' !== $label ? $label : __( 'Mood', 'post-kinds-for-indieweb-in-block-themes' ),
			'family'         => $family['key'],
			'family_label'   => $family['label'],
			'family_code'    => $family['code'],
			'intensity'      => $intensity,
			'intensity_text' => self::intensity_text( $intensity ),
			'day'            => $day instanceof \DateTimeImmutable ? $day->format( 'Y-m-d' ) : '',
		];
	}

	/**
	 * The saved 1-5 rating, or null when there's none.
	 *
	 * The registered default is 0, and a post that never saved the key has
	 * no row; both read as null.
	 *
	 * @param int $post_id Post ID.
	 * @return int|null
	 */
	private static function rating_meta( int $post_id ): ?int {
		$value = get_metadata_raw( 'post', $post_id, Meta_Fields::PREFIX . 'mood_rating', true );
		$value = is_numeric( $value ) ? (int) $value : 0;

		return $value >= 1 && $value <= self::MAX_INTENSITY ? $value : null;
	}

	/**
	 * An intensity attribute the mood card's comment serializes, 1-5.
	 *
	 * @param array<string, mixed>|null $card Card attributes, or null with no card.
	 * @return int|null
	 */
	private static function attribute_intensity( ?array $card ): ?int {
		if ( null === $card || ! array_key_exists( 'intensity', $card ) ) {
			return null;
		}

		$value = $card['intensity'];
		if ( is_float( $value ) && floor( $value ) === $value ) {
			$value = (int) $value;
		}

		return is_int( $value ) && $value >= 1 && $value <= self::MAX_INTENSITY ? $value : null;
	}

	/**
	 * The serialized attributes of a post's first mood card, found at any depth.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null Null when the post has no mood card.
	 */
	private static function card_attrs( int $post_id ): ?array {
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( ! str_contains( $content, 'wp:post-kinds-indieweb/mood-card' ) ) {
			return null;
		}

		foreach ( flatten_blocks( parse_blocks( $content ) ) as $block ) {
			if ( 'post-kinds-indieweb/mood-card' === ( $block['blockName'] ?? '' ) ) {
				return is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
			}
		}

		return null;
	}

	/**
	 * The plugin's families before the filter.
	 *
	 * @return array<string, array{label: string, code: string}>
	 */
	private static function default_families(): array {
		$rows = [
			[ 'joy', __( 'Joy', 'post-kinds-for-indieweb-in-block-themes' ), _x( 'J', 'mood family code: Joy', 'post-kinds-for-indieweb-in-block-themes' ) ],
			[ 'calm', __( 'Calm', 'post-kinds-for-indieweb-in-block-themes' ), _x( 'C', 'mood family code: Calm', 'post-kinds-for-indieweb-in-block-themes' ) ],
			[ 'energy', __( 'Energy', 'post-kinds-for-indieweb-in-block-themes' ), _x( 'E', 'mood family code: Energy', 'post-kinds-for-indieweb-in-block-themes' ) ],
			[ 'thoughtful', __( 'Thoughtful', 'post-kinds-for-indieweb-in-block-themes' ), _x( 'T', 'mood family code: Thoughtful', 'post-kinds-for-indieweb-in-block-themes' ) ],
			[ 'uncertain', __( 'Uncertain', 'post-kinds-for-indieweb-in-block-themes' ), _x( 'U', 'mood family code: Uncertain', 'post-kinds-for-indieweb-in-block-themes' ) ],
			[ 'sad', __( 'Sad', 'post-kinds-for-indieweb-in-block-themes' ), _x( 'S', 'mood family code: Sad', 'post-kinds-for-indieweb-in-block-themes' ) ],
			[ 'angry', __( 'Angry', 'post-kinds-for-indieweb-in-block-themes' ), _x( 'A', 'mood family code: Angry', 'post-kinds-for-indieweb-in-block-themes' ) ],
			[ 'anxious', __( 'Anxious', 'post-kinds-for-indieweb-in-block-themes' ), _x( 'Ax', 'mood family code: Anxious', 'post-kinds-for-indieweb-in-block-themes' ) ],
			[ 'social', __( 'Social', 'post-kinds-for-indieweb-in-block-themes' ), _x( 'So', 'mood family code: Social', 'post-kinds-for-indieweb-in-block-themes' ) ],
			[ 'physical', __( 'Physical', 'post-kinds-for-indieweb-in-block-themes' ), _x( 'P', 'mood family code: Physical', 'post-kinds-for-indieweb-in-block-themes' ) ],
			[ 'work', __( 'Work', 'post-kinds-for-indieweb-in-block-themes' ), _x( 'W', 'mood family code: Work', 'post-kinds-for-indieweb-in-block-themes' ) ],
			[ 'playful', __( 'Playful', 'post-kinds-for-indieweb-in-block-themes' ), _x( 'Pl', 'mood family code: Playful', 'post-kinds-for-indieweb-in-block-themes' ) ],
			[ 'other', __( 'Other', 'post-kinds-for-indieweb-in-block-themes' ), _x( 'O', 'mood family code: Other', 'post-kinds-for-indieweb-in-block-themes' ) ],
		];

		$families = [];
		foreach ( $rows as [ $key, $label, $code ] ) {
			$families[ $key ] = [
				'label' => $label,
				'code'  => $code,
			];
		}

		return $families;
	}

	/**
	 * A family code cut to two graphemes.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	private static function trim_code( string $code ): string {
		$code = trim( wp_strip_all_tags( $code ) );

		return function_exists( 'grapheme_substr' ) ? (string) grapheme_substr( $code, 0, 2 ) : mb_substr( $code, 0, 2 );
	}

	/**
	 * The codes a family may take, best first.
	 *
	 * Its own code; then the first two letters of its key; then its first
	 * letter with each later letter of the key in turn; then its first
	 * letter with 2 through 9 ('other' keeps going to 99, so it is never
	 * left without one).
	 *
	 * @param string $key   Family key.
	 * @param string $given Code the list asked for ('' for none).
	 * @return list<string>
	 */
	private static function code_candidates( string $key, string $given ): array {
		$first        = ucfirst( substr( $key, 0, 1 ) );
		$length       = strlen( $key );
		$candidates   = '' !== $given ? [ $given ] : [];
		$candidates[] = ucfirst( substr( $key, 0, 2 ) );
		for ( $at = 1; $at < $length; $at++ ) {
			$candidates[] = $first . $key[ $at ];
		}
		for ( $number = 2, $last = 'other' === $key ? 99 : 9; $number <= $last; $number++ ) {
			$candidates[] = $first . $number;
		}

		return array_values( array_filter( array_unique( $candidates ), static fn( string $code ): bool => '' !== $code ) );
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
