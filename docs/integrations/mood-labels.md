# Mood labels — spelling preference and consumer contract

Post Kinds supplies a small mood vocabulary (Happy, Energized, Honored, …)
as suggestions for the mood label. Since 1.9.0 the spelling of those
plugin-supplied labels follows a site setting, and the resolved labels are
available to PHP, the block editor and companion plugins through one source:
`PKIW\Mood_Vocabulary`. This document is the contract for consumers such as
Outpost.

Issue: [#207](https://github.com/courtneyr-dev/post-kinds-for-indieweb/issues/207).

## What was there before

- The mood label is **free text**: the mood card's `mood` attribute and the
  `_pkiw_mood_label` meta. There was no fixed vocabulary and no suggestion
  list; the editor only showed a placeholder.
- Plugin-supplied mood words in 1.8.1 were the emoji picker's CLDR names,
  its six internal category keys (Happy, Neutral, Sad, Angry, Tired,
  Anxious — used as React keys, never shown), the Level select's Low /
  Neutral / High and the "Mood" fallback. None had a US/UK spelling variant.
- Micropub `mood` (Outpost's Life > Mood) arrives as free text and becomes
  the mood card's `mood` attribute.

## The setting

| Stored in | `pkiw_settings['mood_spelling']` |
|---|---|
| Values | `site` (default), `en_US`, `en_GB` |
| Admin UI | Post Kinds settings, General tab, "Mood label spelling" |
| Invalid values | Sanitized to `site` on save and ignored on read |

`site` resolves labels in `get_locale()` — the Site Language — never the
current user's profile language. An administrator with a German profile on
an en_US site still sees and publishes en_US labels; the editor chrome stays
German.

Resolution per mood:

1. **en_US**: the plugin's source string.
2. **en_GB**: an installed en_GB translation of the string if it differs
   from the source, otherwise the explicit variant map in
   `Mood_Vocabulary::EN_GB_VARIANTS` (Energised, Honoured, Mesmerised,
   Revitalised, Organised, Demoralised), otherwise the source.
3. **Any other Site Language** (for example `fr_FR`, `es_ES`, `en_AU`): the
   installed translation, or the source string when that locale has no
   language files. No spelling map applies.

Translations run inside `switch_to_locale()` and always restore the
previous locale.

## Identity versus label

| Identity (stable, stored) | Label (display, resolved at read time) |
|---|---|
| Mood card attribute `moodKey` | Mood card attribute `mood` (authored text, unchanged) |
| Post meta `_pkiw_mood_key` (registered, `show_in_rest`, `sanitize_key`) | Post meta `_pkiw_mood_label` (authored text, unchanged) |

The editor sets `moodKey` only when the typed or picked text exactly equals
a vocabulary label in the current spelling, and removes it as soon as the
text is edited to anything else.

**Display rule** (`Mood_Vocabulary::display_label( $authored, $key )`, mirrored
by `moodDisplayLabel()` in `src/blocks/shared/mood-vocabulary.js`):

- no key, or a key the vocabulary doesn't know -> the authored text, byte for byte;
- known key and the authored text is empty or (after trimming) one of that
  mood's `variants` -> the resolved label;
- anything else (typed, edited, different casing) -> the authored text.

Nothing ever writes a resolved label back to a post. Changing the setting
changes rendered output for untouched vocabulary picks only. Tests:
`tests/phpunit/integration/MoodVocabularyTest.php`, with the JS/PHP rule
table shared through `tests/phpunit/fixtures/mood-label-cases.json`.

Surfaces that apply the rule: mood card `aria-label` and hidden `p-name`,
the Stream card's mood pin `aria-label` (`PKIW\mood_card_accessible_name()`),
the ATmosphere derived title ("Mood: {emoji} {label}"), and the mood label
fields in the mood card and the Kind fields sidebar. Feeds carry only the
emoji and the authored note, so they don't change.

## PHP API

```php
use PKIW\Mood_Vocabulary;

Mood_Vocabulary::get_moods( ?string $spelling = null ): array;
// list<array{key: string, label: string, variants: list<string>}>

Mood_Vocabulary::get_label( string $key, ?string $spelling = null ): string; // '' when unknown
Mood_Vocabulary::display_label( string $authored, string $key = '' ): string;
Mood_Vocabulary::get_spelling(): string;                       // site | en_US | en_GB
Mood_Vocabulary::label_locale( ?string $spelling = null ): string; // en_US, en_GB, fr_FR, …
```

Guard calls from another plugin with `class_exists( '\PKIW\Mood_Vocabulary' )`.

## Filter

```php
/**
 * @param array<string, string> $labels   Mood key => resolved label.
 * @param string                $locale   Locale the labels resolved in.
 * @param string                $spelling Stored preference: site, en_US or en_GB.
 */
apply_filters( 'pkiw_mood_labels', $labels, $locale, $spelling );
```

Return the array to change a label, `unset()` a key to remove a mood, or add
`'my_key' => 'Label'` for a site-specific mood. Keys are stored in posts —
keep them stable. Stored spellings of a relabelled mood still resolve to the
new label; a removed mood's posts show their authored text.

## REST route

`GET /wp-json/post-kinds-indieweb/v1/moods` — requires `edit_posts`
(401 signed out, 403 for subscribers).

```json
{
	"spelling": "en_GB",
	"locale": "en_GB",
	"version": "5d41402abc4b2a76b9719d911017c592",
	"moods": [
		{ "key": "happy", "label": "Happy", "variants": [ "Happy" ] },
		{ "key": "energized", "label": "Energised", "variants": [ "Energized", "Energised" ] }
	]
}
```

- `spelling`: the stored preference. `locale`: what labels resolved in.
- `moods`: display order; `label` is what to show and store; `variants` are
  every spelling that still counts as the untouched pick.
- `version`: md5 of spelling, locale and moods. It changes when the setting,
  the Site Language, a translation or a `pkiw_mood_labels` filter changes the
  output. Clients that keep a copy should refetch and compare it rather than
  hold labels indefinitely.

Server-side labels are cached per request, keyed by spelling and locale, so a
changed setting is read on the next call; nothing is persisted in options,
transients or the object cache.

## Micropub

Incoming `mood` text is stored exactly as sent, with no `moodKey`, so it
renders as authored under every spelling. There is no Micropub property for
the mood key yet.

## Outpost follow-up (not implemented here)

1. In the PWA Life > Mood variant (`pwa/src/components/modes/life-mode.tsx`),
   when the site reports `post-kinds.mood`, fetch
   `/wp-json/post-kinds-indieweb/v1/moods` with the credentials Outpost uses
   for its other WordPress REST calls, keep the response keyed by `version`,
   and offer `moods[].label` as suggestions while keeping free text.
2. Keep sending the chosen text as the Micropub `mood` property. Don't ship
   an Outpost-side mood list or spelling map.
3. For server-rendered previews in PHP, call
   `\PKIW\Mood_Vocabulary::get_moods()` behind `class_exists()` from the
   companion adapter (`includes/companions/class-post-kinds-adapter.php`)
   instead of hard-coding labels.
4. Add a contract test against the response shape above (keys `spelling`,
   `locale`, `version`, `moods[].key|label|variants`) and the 401/403 cases.
5. If Outpost picks should follow later spelling changes, that needs a PKIW
   change first: accept a Micropub property (for example `pkiw-mood-key`)
   and store it as `moodKey` / `_pkiw_mood_key`.

## Not covered

- No per-user spelling override. Authoring and public output both follow the
  site setting.
- English locales other than en_GB (en_AU, en_CA, en_NZ, en_ZA) get
  translations or source strings, not the British map.
- The admin Quick Post mood form is still a plain text field with no
  suggestions.
- The emoji picker's CLDR names and the Level labels are interface strings in
  the user's language; the setting doesn't touch them.
