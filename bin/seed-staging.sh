#!/usr/bin/env bash
# seed-staging.sh — populate audit-target staging with synthetic test posts.
#
# Per audit prompt Section 3.7. Creates one post per Post Kind (24 default
# PKIW kinds — note: prompt stated 17, actual is 24, see deploy log), one post
# per WordPress Post Format (9), plus overlap posts that exercise plugin
# composition (Bookmark + Link format + XFN, Reply + Aside, Listen + standard).
#
# Idempotent: every seeded post carries post meta `_audit_seed_id` with a
# stable slug. Re-running the script skips posts already present.
#
# Manifest of created post IDs written to /tmp/pkiw-audit/seed-manifest.json
# so other audit sessions can reference seeded fixtures by stable ID.
#
# Requires: wp-cli alias `@staging` configured in ~/.wp-cli/config.yml.
# Author user defaults to ID 1; override via SEED_AUTHOR_ID env var.
#
# Usage:
#   ./bin/seed-staging.sh
#   SEED_AUTHOR_ID=4 ./bin/seed-staging.sh
#   SEED_FORCE=1 ./bin/seed-staging.sh    # delete + recreate all seeded posts

set -u  # NOTE: not -e, since wp-cli emits warnings to stderr that are platform noise

WP="wp @staging"
SEED_AUTHOR_ID="${SEED_AUTHOR_ID:-1}"
SEED_DATE="$(date -u +%Y-%m-%d)"
SEED_TAG="audit-seed-${SEED_DATE}"
MANIFEST="/tmp/pkiw-audit/seed-manifest.json"

mkdir -p "$(dirname "$MANIFEST")"

# Strip platform noise from wp-cli output: PHP deprecation lines, recurring
# WP 7.0-RC2 core warnings, MCP resource errors. Returns just the data lines.
clean_wp() {
    grep -v "^Deprecated\|^Warning:\|^PHP Warning\|^PHP Deprecated\|^PHP Fatal\|McpResource\|innerContent\|attrs\|already defined" || true
}

# Find post by audit-seed slug; echo ID or empty.
find_seeded_post() {
    local seed_slug="$1"
    $WP post list \
        --post_type=post \
        --post_status=any \
        --meta_key=_audit_seed_id \
        --meta_value="$seed_slug" \
        --field=ID \
        --format=csv 2>/dev/null \
        | clean_wp \
        | grep -E "^[0-9]+$" \
        | head -1
}

# Create a seeded post and return its ID. Skips if seed_slug already exists
# (unless SEED_FORCE=1, which deletes the existing one first).
seed_post() {
    local seed_slug="$1"
    local title="$2"
    local content="$3"
    local kind_slug="${4:-}"        # PKIW kind taxonomy term slug, or empty
    local format_slug="${5:-}"      # post_format slug (aside, link, etc.), or empty

    local existing
    existing="$(find_seeded_post "$seed_slug")"

    if [[ -n "$existing" ]]; then
        if [[ "${SEED_FORCE:-0}" == "1" ]]; then
            echo "  [force] deleting existing post $existing for seed $seed_slug" >&2
            $WP post delete "$existing" --force 2>&1 | clean_wp >/dev/null || true
        else
            echo "  [skip] $seed_slug already exists as post $existing" >&2
            echo "$existing"
            return 0
        fi
    fi

    local post_id
    post_id="$(
        $WP post create \
            --post_type=post \
            --post_status=publish \
            --post_author="$SEED_AUTHOR_ID" \
            --post_title="$title" \
            --post_content="$content" \
            --porcelain 2>&1 \
        | clean_wp \
        | grep -E "^[0-9]+$" \
        | tail -1
    )"

    if [[ -z "$post_id" ]]; then
        echo "  [FAIL] seed $seed_slug — post create returned no ID" >&2
        return 1
    fi

    # Mark post with audit-seed meta for idempotent lookups.
    $WP post meta add "$post_id" _audit_seed_id "$seed_slug" 2>&1 | clean_wp >/dev/null || true
    $WP post meta add "$post_id" _audit_seed_run "$SEED_DATE" 2>&1 | clean_wp >/dev/null || true

    # Tag post for filtering in admin / via REST.
    # wp post term set <post-id> <taxonomy> <term-list> — taxonomy SECOND, term THIRD.
    $WP post term set "$post_id" post_tag "$SEED_TAG" 2>&1 | clean_wp >/dev/null || true

    # Set kind taxonomy term (PKIW's `kind` taxonomy) when supplied.
    if [[ -n "$kind_slug" ]]; then
        $WP post term set "$post_id" kind "$kind_slug" 2>&1 | clean_wp >/dev/null || true
    fi

    # Set post format when supplied. WP stores these as `post-format-{slug}`.
    if [[ -n "$format_slug" ]]; then
        $WP post term set "$post_id" post_format "post-format-${format_slug}" 2>&1 | clean_wp >/dev/null || true
    fi

    echo "  [ok] $seed_slug → post $post_id ($title)" >&2
    echo "$post_id"
}

# JSON manifest accumulator.
manifest_entries=()

manifest_add() {
    local seed_slug="$1"
    local post_id="$2"
    local kind="${3:-}"
    local format="${4:-}"
    manifest_entries+=("    {\"seed_slug\":\"${seed_slug}\",\"post_id\":${post_id},\"kind\":\"${kind}\",\"format\":\"${format}\"}")
}

# ---- Section 1: one post per PKIW kind (24 kinds) -----------------------

echo ""
echo "=== Section 1: one post per PKIW kind ==="

# Each entry: seed_slug | kind_slug | post_title | mf2-flavored content.
# Content includes the canonical mf2 properties for that kind so validators
# have real markup to assert against.
declare -a KIND_SEEDS=(
"kind-note|note|A note from staging seed|<p>Just a quick thought captured here.</p>"
"kind-article|article|Sample article from staging seed|<p>This is an article-shaped long-form post used to exercise the article kind rendering path. It has paragraphs and a typical length.</p>"
"kind-reply|reply|Reply from staging seed|<p>I disagree, and here is why.</p><p class=\"u-in-reply-to h-cite\"><a class=\"u-url\" href=\"https://example.com/post-being-replied-to\">Original post</a> by <span class=\"p-author h-card\"><a class=\"p-name u-url\" href=\"https://example.com/\" rel=\"contact\">Example Author</a></span></p>"
"kind-like|like|Liked: An external thing|<p class=\"u-like-of h-cite\"><a class=\"u-url\" href=\"https://example.com/liked-thing\">Example liked post</a></p>"
"kind-repost|repost|Reposted: An external thing|<p class=\"u-repost-of h-cite\"><a class=\"u-url\" href=\"https://example.com/reposted-thing\">Example reposted post</a></p>"
"kind-bookmark|bookmark|Bookmark: A useful resource|<p class=\"u-bookmark-of h-cite\"><a class=\"u-url p-name\" href=\"https://example.com/bookmarked-resource\">A useful resource</a></p><p class=\"p-summary\">Saved for later — covers the topic at a level worth revisiting.</p>"
"kind-rsvp|rsvp|RSVP yes to the event|<p>I'll be there.</p><data class=\"p-rsvp\" value=\"yes\">yes</data><p class=\"u-in-reply-to h-event\"><a class=\"u-url p-name\" href=\"https://example.com/event\">Example Event</a></p>"
"kind-checkin|checkin|Checked in at a venue|<p>Coffee break.</p><p class=\"p-location h-card\"><span class=\"p-name\">Example Cafe</span>, <span class=\"p-locality\">Cityville</span>, <span class=\"p-region\">State</span></p>"
"kind-listen|listen|Listened to a song|<p class=\"u-listen-of h-cite\"><span class=\"p-name\">Example Song Title</span> by <span class=\"p-author h-card\"><span class=\"p-name\">Example Artist</span></span></p>"
"kind-watch|watch|Watched a film|<p class=\"u-watch-of h-cite\"><span class=\"p-name\">Example Film Title</span> (<span class=\"dt-published\">2025</span>)</p>"
"kind-read|read|Reading a book|<p>Currently reading.</p><p class=\"u-read-of h-cite\"><span class=\"p-name\">Example Book Title</span> by <span class=\"p-author h-card\"><span class=\"p-name\">Example Author</span></span></p><data class=\"p-read-status\" value=\"reading\">reading</data>"
"kind-event|event|Sample event listing|<div class=\"h-event\"><h2 class=\"p-name\">Example Event</h2><p>From <time class=\"dt-start\" datetime=\"2026-06-01T18:00:00-04:00\">June 1, 2026 6:00 PM</time> to <time class=\"dt-end\" datetime=\"2026-06-01T20:00:00-04:00\">8:00 PM</time>.</p><p class=\"p-location h-adr\"><span class=\"p-locality\">Cityville</span></p></div>"
"kind-photo|photo|Photo from staging|<figure><img class=\"u-photo\" src=\"https://qkf.b0d.myftpupload.com/wp-content/uploads/2023/05/cropped-courtney-pirate-jpeg.avif\" alt=\"Sample photo\"/><figcaption>A sample photo for the photo kind.</figcaption></figure>"
"kind-video|video|Video from staging|<p>A sample video kind post.</p><video class=\"u-video\" controls preload=\"metadata\"><source src=\"https://example.com/video.mp4\" type=\"video/mp4\"></video>"
"kind-review|review|Review of an item|<div class=\"h-review\"><p class=\"p-item h-product\"><span class=\"p-name\">Example Product</span></p><p class=\"p-summary\">Solid for the price.</p><p class=\"p-rating\">4 of 5</p><blockquote class=\"e-content\"><p>Detailed review body would go here.</p></blockquote></div>"
"kind-favorite|favorite|Favorited: An external thing|<p class=\"u-favorite-of h-cite\"><a class=\"u-url\" href=\"https://example.com/favorited-thing\">A favorited resource</a></p>"
"kind-jam|jam|This is my jam|<p class=\"u-listen-of h-cite\"><span class=\"p-name\">Jam Track</span> by <span class=\"p-author h-card\"><span class=\"p-name\">Jam Artist</span></span></p><p>This is my jam right now.</p>"
"kind-wish|wish|Wishlist item|<p class=\"u-wish-of h-cite\"><a class=\"u-url p-name\" href=\"https://example.com/wishlist-item\">A thing to acquire someday</a></p>"
"kind-mood|mood|Feeling thoughtful|<p>Reflective today.</p><data class=\"p-mood\" value=\"thoughtful\">thoughtful</data>"
"kind-acquisition|acquisition|Picked up a new thing|<p>Acquired today.</p><p class=\"p-acquired h-cite\"><a class=\"u-url p-name\" href=\"https://example.com/acquired-thing\">A new thing</a></p>"
"kind-drink|drink|Coffee at the cafe|<p>An espresso.</p><p class=\"p-consumed h-food\"><span class=\"p-name\">Espresso</span></p>"
"kind-eat|eat|Lunch report|<p>Avocado toast.</p><p class=\"p-consumed h-food\"><span class=\"p-name\">Avocado toast</span></p>"
"kind-recipe|recipe|Sample recipe|<div class=\"h-recipe\"><h2 class=\"p-name\">Test Recipe</h2><ul class=\"p-ingredient\"><li>1 cup ingredient A</li><li>2 tsp ingredient B</li></ul><div class=\"e-instructions\"><p>Mix and serve.</p></div><p>Yield: <span class=\"p-yield\">2 servings</span></p></div>"
"kind-play|play|Played a game|<p class=\"u-play-of h-cite\"><span class=\"p-name\">Example Game Title</span></p><p>Quick session, fun mechanics.</p>"
)

for entry in "${KIND_SEEDS[@]}"; do
    IFS='|' read -r seed_slug kind_slug title content <<< "$entry"
    post_id="$(seed_post "$seed_slug" "$title" "$content" "$kind_slug" "")"
    [[ -n "$post_id" ]] && manifest_add "$seed_slug" "$post_id" "$kind_slug" ""
done

# ---- Section 2: one post per WordPress Post Format (9) -----------------

echo ""
echo "=== Section 2: one post per WordPress Post Format ==="

declare -a FORMAT_SEEDS=(
"format-aside|aside|A small aside|<p>Worth noting in passing.</p>"
"format-gallery|gallery|Photo gallery|<!-- wp:gallery -->\n<figure class=\"wp-block-gallery\"><figure><img src=\"https://example.com/1.jpg\" alt=\"\"/></figure></figure>\n<!-- /wp:gallery -->"
"format-link|link|Useful link|<p>Sharing this: <a href=\"https://example.com/linked-resource\">Example linked resource</a></p>"
"format-image|image|Image post|<figure><img src=\"https://example.com/photo.jpg\" alt=\"Sample\"/></figure>"
"format-quote|quote|Memorable quote|<blockquote><p>The best way to predict the future is to invent it.</p><cite>Alan Kay</cite></blockquote>"
"format-status|status|Just thinking|<p>Quiet morning. Thinking about post formats.</p>"
"format-video|video|Sample video|<p><video controls src=\"https://example.com/video.mp4\"></video></p>"
"format-audio|audio|Sample audio|<p><audio controls src=\"https://example.com/audio.mp3\"></audio></p>"
"format-chat|chat|Sample chat log|<pre>Alice: hello\nBob: hi\nAlice: how are you?</pre>"
)

for entry in "${FORMAT_SEEDS[@]}"; do
    IFS='|' read -r seed_slug format_slug title content <<< "$entry"
    post_id="$(seed_post "$seed_slug" "$title" "$content" "" "$format_slug")"
    [[ -n "$post_id" ]] && manifest_add "$seed_slug" "$post_id" "" "$format_slug"
done

# ---- Section 3: overlap posts (3 — composition test surface) -----------

echo ""
echo "=== Section 3: composition overlap posts ==="

# Bookmark + Link format + XFN-tagged author rel=friend met.
seed_post "overlap-bookmark-link-xfn" \
    "Bookmark + Link + XFN composition" \
    '<p>Saving this article from a friend.</p><p class="u-bookmark-of h-cite"><a class="u-url p-name" href="https://example.com/article-by-friend" rel="friend met">A friend article</a> by <span class="p-author h-card"><a class="p-name u-url" href="https://example.com/" rel="contact friend">Example Friend</a></span></p>' \
    "bookmark" \
    "link" >/dev/null && manifest_add "overlap-bookmark-link-xfn" "$(find_seeded_post overlap-bookmark-link-xfn)" "bookmark" "link"

# Reply + Aside format.
seed_post "overlap-reply-aside" \
    "Reply + Aside composition" \
    '<p>Quick reply, no title needed.</p><p class="u-in-reply-to h-cite"><a class="u-url" href="https://example.com/being-replied-to">Original post</a></p>' \
    "reply" \
    "aside" >/dev/null && manifest_add "overlap-reply-aside" "$(find_seeded_post overlap-reply-aside)" "reply" "aside"

# Listen + standard format (no post_format taxonomy term — the WP "standard" default).
seed_post "overlap-listen-standard" \
    "Listen with no post format" \
    '<p>Listened on shuffle this morning.</p><p class="u-listen-of h-cite"><span class="p-name">Some Song</span> by <span class="p-author h-card"><span class="p-name">Some Artist</span></span></p>' \
    "listen" \
    "" >/dev/null && manifest_add "overlap-listen-standard" "$(find_seeded_post overlap-listen-standard)" "listen" ""

# ---- Manifest write -----------------------------------------------------

{
    echo "{"
    echo "  \"seed_run_date\": \"$SEED_DATE\","
    echo "  \"seed_tag\": \"$SEED_TAG\","
    echo "  \"author_id\": $SEED_AUTHOR_ID,"
    echo "  \"posts\": ["
    for i in "${!manifest_entries[@]}"; do
        if [[ $i -eq $((${#manifest_entries[@]} - 1)) ]]; then
            echo "${manifest_entries[$i]}"
        else
            echo "${manifest_entries[$i]},"
        fi
    done
    echo "  ]"
    echo "}"
} > "$MANIFEST"

echo ""
echo "=== Manifest written to $MANIFEST ==="
echo "Total seeded posts: ${#manifest_entries[@]}"
echo "Tag: $SEED_TAG"
echo ""
echo "Find seeded posts at any time via:"
echo "  wp @staging post list --post_type=post --tax_query='[{\"taxonomy\":\"post_tag\",\"field\":\"slug\",\"terms\":\"$SEED_TAG\"}]'"
echo ""
echo "Re-run with SEED_FORCE=1 to recreate all posts (deletes existing first)."
