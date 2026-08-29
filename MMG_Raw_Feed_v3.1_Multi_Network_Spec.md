# MMG Raw Feed v3.1 — Multi-Network Syndication Spec (Upgraded)

**Authored:** August 4, 2026 (v3.0 by Strategy Claude; v3.1 upgrade + implementation by Claude Code, same day)
**Status:** IMPLEMENTED — plugin code lives alongside this spec in `mmg-raw-feed/`; test harness in `tests/`.
**Supersedes:** `MMG_Raw_Feed_v3_Multi_Network_Spec.md` (Downloads). All v3.0 findings verified against real code; deltas below.

**Source artifacts audited (all verified against actual code, not recollection):**

| Artifact | Version | Serves | Key facts |
|---|---|---|---|
| `mmg-raw-feed-v2/mmg-raw-feed.php` | 2.1.0, 581 lines | `?feed={slug}-raw-feed` + tag feeds (Aigeon email syndication) | UTM links, score categories, wfw/slash noise, CDATA double-escaping |
| `newsbreak-rss-feed (1).php` | 1.3.0, 294 lines | **`?feed=newsbreak`** | Clean links (no UTM), figure-prepended featured image, `photo_credit` meta, media trio + enclosure, **also mutates default WP feeds** |
| MSN Partner Hub audit | `reports/msn-partner-hub-audit/2026-05-07/` | — | MMG (BFY Labs LLC / Game Day Chatter) **is an approved, active MSN partner** |
| Microsoft support docs (primary, fetched 2026-08-04) | — | — | Namespaces, per-item requirements, HTML tag restrictions verified |

**Research as of 2026-08-04.** Per Rule 101, re-verify network specs before acting on this document past 2026-11-04.

---

## 1. Headline findings (upgraded)

### 1.1 v3.0's blocking question Q1 is resolved: there are TWO plugins to merge

`?feed=newsbreak` is served by a **separate plugin** — "NewsBreak RSS Feed" v1.3.0 (`add_feed('newsbreak', …)`), not by mmg-raw-feed. The v2.1.0 comment "same mechanism as ?feed=newsbreak" refers to it. Consequences:

- v3 is a **merger and replacement of two plugins**, not an upgrade of one.
- The Aigeon raw feed and the NewsBreak feed are **different consumers with different shapes** (UTM vs clean links; score categories vs none; excerpt-only vs figure-prepended full content). A three-profile design would force one of them to change shape. **v3.1 therefore has FOUR profiles: `aigeon` (legacy raw-feed), `newsbreak`, `msn`, `yahoo`.**
- The NewsBreak plugin also **modifies WordPress's default feeds** (`rss2_ns`, `rss2_item`, `the_content_feed` filters: media namespace, media tags per item, figure-prepend). Any consumer polling `/feed/` on these sites currently receives that enhanced output. v3 preserves this behind an `enhance_default_feeds` toggle, **default ON** (replica-first: live behavior must not change when the old plugin is deactivated).

### 1.2 The three networks still do not share a feed specification

All v3.0 incompatibilities stand, now with primary-source confirmation for MSN:

1. **Yahoo** prohibits `<script>`/`<style>`/`style=""`/`<object>` and filters to a ~25-tag allowlist; NewsBreak supports third-party scripts via `nb:scripts`.
2. **Yahoo** has deprecated ATOM for new onboarding; MSN accepts RSS and ATOM (v3 emits RSS everywhere).
3. **MSN** hard-rejects headlines over 150 chars without a short title — and (new, primary source) **also rejects titles of 20 characters or fewer** and **articles whose publish date is over 365 days old or in the future**.

### 1.3 New findings that change the build

- **MSN rejects all YouTube iframes in feed content at ingestion** ("All YouTube 3PP videos submitted through feeds or article HTML will be rejected"). The MSN sanitizer must strip YouTube iframes specifically, even though `<iframe>` is otherwise allowed for 12 platforms.
- **The live NewsBreak feed today carries clean canonical links with no UTM parameters** (v2's raw feed carries UTMs). Q2 resolves itself: per-network UTM toggle, default ON for `aigeon` (preserves current Aigeon measurement), default OFF for `newsbreak`/`msn`/`yahoo` (preserves current NewsBreak behavior; canonical-clean for new networks).
- **The NewsBreak plugin has its own pubDate bug:** `get_the_date('D, d M Y H:i:s +0000', $post)` renders **local** time with a hardcoded `+0000` suffix — every timestamp is wrong by the site's UTC offset unless the site runs UTC. v3 emits true GMT everywhere (`get_post_time('Y-m-d H:i:s', true)` → `mysql2date` with `+0000`), matching v2's already-correct pattern.
- **The NewsBreak plugin's live shape includes elements v3.0 didn't spec:** an `<enclosure>` tag alongside `media:content`/`media:thumbnail`, `<media:title>`/`<media:description>` from image alt/caption, figure-prepended featured image with `photo_credit`/`_photo_credit` post-meta fallback to caption, and regex stripping of `srcset`/`sizes`/`data-*`/`loading`/`decoding`/`fetchpriority` attributes. The v3 NewsBreak profile reproduces all of it (replica-first), implemented over DOMDocument instead of regex.

---

## 2. Resolved open questions (v3.0 §8)

| # | Question | Resolution | Evidence |
|---|---|---|---|
| Q1 | What serves `?feed=newsbreak`? | Separate plugin "NewsBreak RSS Feed" v1.3.0. v3 = merger/replacement; `?feed=newsbreak` becomes the v3 newsbreak profile route, shape-compatible. | Plugin source supplied by Austin 2026-08-04 |
| Q2 | UTMs in `<link>`? | Per-network toggle. Defaults: aigeon ON, newsbreak OFF, msn OFF, yahoo OFF — exactly matches current live behavior of both plugins. | Both plugin sources |
| Q3 | Is MMG an approved MSN partner? | **Yes.** Game Day Chatter (BFY Labs LLC) has active Partner Hub access, walked 2026-05-07. MSN build is not speculative. | `reports/msn-partner-hub-audit/` |
| Q4 | `nb:scripts` measurement surface? | Built, empty by default, admin textarea per spec. Populating it stays gated on Austin's sign-off. | — |
| Q5 | Which brands first? | Unchanged recommendation: low-volume brand for verification, then Gameday. Note Game Day Chatter is the brand already wired into MSN Partner Hub — it is the natural MSN pilot despite volume. | — |
| Q6 | Multi-brand config distribution | Still out of scope for v3.x; deferred. | — |

**Remaining follow-ups (not blockers):**
- MSN expiration/takedown element name: metadata doc confirms an "Expiration date" field ("must be more than 2 hours in future") but not its XML element name. v3 ships exclusion-only (excluded posts drop from the feed); in-feed takedown emission is a documented follow-up pending the element name from Partner Hub's Content Specification page (visible when logged in).
- Yahoo social-embed markup shapes: out of scope per v3.0; embed-bearing posts are logged to the skip log as `embed_review` warnings (non-fatal).

---

## 3. Audit findings — status after v3.1

All v3.0 defects (D1–D8) and gaps (G1–G5) were verified true against v2.1.0 source, with exact line numbers as cited. New defects found in the NewsBreak plugin:

**N1 — Same CDATA double-escaping class as D1.** `dc:creator` (line 89) and `category` (line 94) wrap `esc_html()` inside CDATA. Same fix as D1.

**N2 — pubDate timezone lie.** Line 88: local time with hardcoded `+0000` (see §1.3). Fixed in v3.

**N3 — Default-feed mutation is a live surface.** Lines 33–36 register `rss2_ns`/`rss2_item`/`the_content_feed` filters affecting `/feed/` output globally. Preserved in v3 behind `enhance_default_feeds` (default ON).

**N4 — No no-cache headers on the NewsBreak feed** (v2's raw feed sends them). v3: all feeds send explicit `Cache-Control` headers, and network feeds (not aigeon) are additionally served through a 60-second server-side transient cache keyed on `network|slug|lastpostmodified` — a new publish busts it instantly, and MSN/Yahoo poll load (every 15/5 min × 104 brands) never triggers back-to-back full renders.

Resolution status of every v3.0 item: D1 fixed (CDATA-safe emitter, `]]>` split, no pre-escaping) · D2 fixed (per-network update elements, 60s jitter threshold) · D3 resolved via Q2 (per-network UTM toggle) · D4 fixed (featured → first inline `<img>` cascade → per-profile require/optional policy) · D5 fixed (Yahoo profile defaults `full`, validates real dimensions ≥1280×720) · D6 fixed (DOMDocument sanitizer per profile; ad/related-post filter unhooking documented as `mmgrf_pre_content` action) · D7 fixed (per-profile validation gates + skip log) · D8 fixed (defaults: aigeon 25, newsbreak 50 [current live count], msn 30, yahoo 50) · G1 fixed (`nb:` namespace + `nb:scripts`) · G2 fixed (Yahoo profile declares `http://search.yahoo.com/rss`; others keep `/mrss/`) · G3 fixed (wfw/slash dropped from network profiles, **retained in aigeon for byte-shape parity**) · G4 fixed (per-post per-network exclusion + sponsored master switch + category/tag policy exclusion) · G5 fixed (legacy `{slug}-raw-feed` routes preserved verbatim; network slugs configurable, default = network key).

---

## 4. Target architecture (delta from v3.0)

Same four-layer design, now with four profiles:

```
Layer 1  Content Pipeline (shared)      includes/pipeline.php
Layer 2  Profiles: aigeon · newsbreak · msn · yahoo     profiles/
Layer 3  Emitter (CDATA-safe XML)       includes/emitter.php
         Sanitizer (DOMDocument)        includes/sanitizer.php
         Validator + skip log           includes/validator.php
Layer 4  Admin                          includes/admin.php
Bootstrap + routing                     mmg-raw-feed.php · includes/options.php
```

### 4.1 Profile base contract (as implemented)

```php
abstract class MMGRF_Profile_Base {
    abstract public function get_key();
    abstract public function get_namespaces();          // [prefix => uri]; '' key = default media URI note
    abstract public function get_allowed_tags();        // sanitizer allowlist, null = permissive mode
    abstract public function get_allowed_attributes();  // per-tag attr allowlist
    abstract public function validate_item( $item );    // ['pass'=>bool,'reason'=>string]
    abstract public function render_item( $item, $opts );
    public function get_channel_extras( $opts )  { return ''; }
    public function get_image_policy()           { return 'require'; }  // 'require'|'optional'
    public function get_default_image_size()     { return 'large'; }
    public function get_default_post_count()     { return 25; }
    public function get_max_items()              { return 30; }
    public function utm_default()                { return false; }
    public function strip_style_attributes()     { return false; }
}
```

Pipeline produces one normalized `$item` array per post (id, title, short_title, permalink [clean], link [UTM applied per profile toggle], pub_ts/mod_ts GMT, author(s), categories, tags, score, excerpt, raw content HTML, image cascade result incl. real dimensions and filesize, exclusion flags). Profiles own everything network-shaped.

### 4.2 Aigeon profile (new in v3.1 — the legacy raw feed)

Byte-shape parity with v2.1.0 output, modulo intended fixes, verified by the parity test in `tests/`:

- Same route (`{slug}-raw-feed` + tag feeds), same channel block incl. `sy:` elements, same item order/shape: title, link (with UTMs), pubDate, dc:creator, categories (+`domain="tag"`, `domain="score"`), guid, description, content:encoded, wfw:commentRss, slash:comments, media:content+media:thumbnail.
- Intended deltas ONLY: CDATA values no longer pre-escaped (D1); `<updated>`-style `dcterms:modified` added when modified > published + 60s (additive, harmless to Aigeon's parser); wfw/slash retained.
- Permissive sanitizer (script/style/object stripped; attribute cleanup as NewsBreak's list); no word-count gate; image optional (matches v2: item ships without media:content when imageless).

### 4.3 NewsBreak profile (upgraded with live-plugin parity)

- Route: `?feed=newsbreak` / `/feed/newsbreak/` — shape-compatible replacement for the standalone plugin.
- Namespaces: `content`, `dc`, `media` (`/mrss/`), `atom`, **`nb="https://www.newsbreak.com/"`**.
- Item shape (live-parity): title (plain esc, no CDATA — matches live), link (clean), guid isPermaLink=true (clean permalink — **identical across all MMG feeds carrying the same post**, per NewsBreak's cross-feed GUID consistency requirement), pubDate (true GMT — N2 fix), dc:creator (CDATA), categories (CDATA), description (CDATA excerpt), media:thumbnail + media:content (+media:title from alt, media:description from caption) + enclosure (real filesize when resolvable), content:encoded = figure-prepended featured image (`photo_credit` → `_photo_credit` → caption for figcaption) + sanitized body, `dcterms:modified` when genuinely modified.
- `nb:scripts`: emitted only when the per-feed admin textarea is non-empty. Default empty. Austin sign-off required before populating (executes on NewsBreak's property under MMG's name).
- Sanitizer: permissive; strips script/style/object/meta/noscript subtrees, ad/related-module classes, and the srcset/sizes/data-*/loading/decoding/fetchpriority attribute list; dedupes the featured image if the body already leads with it.
- Validation gate: image present (require), title non-empty, body non-empty.
- Feed window: 50 (matches live).

### 4.4 MSN profile (all values now primary-source)

- Route: `?feed=msn` / `/feed/msn/` — hand MSN the **pretty-permalink form**.
- Namespaces (verified): `content`, `dc`, `media` (`/mrss/`), `dcterms="http://purl.org/dc/terms/"`, **`mi="http://schemas.ingestion.microsoft.com/common/"`**.
- Required per item (verified): durable unique ID (`<guid isPermaLink="false">` = clean permalink string; durable, not display), title **21–150 chars** (>150 allowed only with `<mi:shortTitle>`), pubDate **in the past and <365 days old** (RFC 822 GMT), body (`content:encoded`), link (clean).
- `<mi:shortTitle>`: from `_mmgrf_short_title` meta, emitted whenever set. A >150-char headline with no short title is **rejected and skip-logged** (v3.0 suggested auto-truncation; rejected — an auto-mangled headline on MSN promotional cards is worse than a visible skip an editor can fix). Copilot Discover's ≤54-char guidance is surfaced in the meta box label, not gated.
- `<dcterms:modified>` when modified > published + 60s (verified: MSN re-ingests on updated-date change with stable unique ID).
- Images: `HasSyndicationRights` concept implemented as per-attachment meta `_mmgrf_no_syndication_rights` (checkbox "MMG does NOT hold syndication rights"); when set, the image is **omitted** for MSN (rights-safe default) and the item ships imageless → skip (image policy: require). Getty-workflow flag from v3.0 stands. Thumbnail floor (verified): min 640×360, recommended 1280×720, max 2MB — validator warns under 1280×720, rejects image under 640×360.
- Sanitizer allowlist (verified): `b i em strong sub sup small h1 h2 h3 h4 h5 a img table thead tbody tfoot tr td th col caption colgroup ul ol li p div span br blockquote iframe`; href must be https/http/mailto; absolute img URLs; `<style> <script> <object> <embed> <param> <applet>` deleted with subtrees; **YouTube iframes stripped** (rejected at MSN ingestion); iframe src restricted to the 12 verified platforms (X, Facebook, Instagram, Pinterest, Spotify, Infogram, Google Maps, Giphy, Flourish, Reddit, TikTok — YouTube excluded on purpose); no inline styles.
- Feed window: 30. Validation gate: unique ID, title length band, pubDate freshness window, body non-empty, link, image present with rights.

### 4.5 Yahoo profile (unchanged from v3.0 except noted)

All v3.0 §4.3 requirements implemented as written: `media="http://search.yahoo.com/rss"` namespace, UTF-8 decl, RFC 822 dates with future-date skip, stable-guid/frozen-pubDate/`<updated>` update semantics, the 25-tag allowlist with global `style` attribute stripping, 150-word post-sanitization body floor (+1-word title, 3-word description), 1280×720/5MB image floor with reject-image-not-item, lead image inlined as first `<figure>` of `content:encoded` with caption (fallback `media:content` + `media:description` only when body has no image), affiliate-domain blocklist (admin-configurable, seeded empty + documented), feed window 50, image policy optional.

Delta: `<updated>` element is emitted in RFC 822 GMT (same format family as pubDate) when assets or body changed; embed-bearing posts log an `embed_review` warning to the skip log without being skipped.

---

## 5. Admin (as implemented)

- **Networks section** on the existing settings page: per-network enable (all default OFF), slug, post count, image size, UTM toggle, live URLs in both forms; NewsBreak additionally gets the `nb:scripts` textarea; Yahoo gets the affiliate-blocklist textarea; global `enhance_default_feeds` toggle (default ON).
- **Syndication meta box** (replaces Feed Score box, preserves `_mmgrf_score`): score field, per-network exclude checkboxes (`_mmgrf_exclude_{network}`), sponsored-content master checkbox (sets all four), MSN short title field (`_mmgrf_short_title`).
- **Policy exclusion**: comma-separated category/tag slugs (`exclude_networks_cats` / `exclude_networks_tags`) excluded from all network profiles (not from aigeon legacy) — sponsored/partner-content by policy rather than per-post discipline.
- **Attachment field**: "No syndication rights" checkbox on media items (`_mmgrf_no_syndication_rights`).
- **Skip log**: rolling option (cap 200, 7-day retention), timestamp/network/post/reason, rendered as a table on the settings page. Warnings (embed_review, image-dimension) logged distinctly from skips.

Back-compat: option keys `mmgrf_options`/`mmgrf_tag_feeds` unchanged, no migration; new keys additive with safe defaults. Legacy routes byte-shape preserved via the aigeon profile. Tag feeds unchanged (aigeon-shaped); per-network tag feeds deferred to v3.2 (YAGNI until a partner asks).

---

## 6. Rollout

1. Install v3 alongside both old plugins is NOT possible (duplicate `add_feed` slugs + duplicate function names with v2). Sequence per site: deactivate both old plugins → activate v3 → Settings → Permalinks → Save (flush).
2. All network toggles ship OFF; aigeon legacy routes and default-feed enhancement are live immediately (parity surfaces).
3. Diff legacy feed output against v2 capture on one brand (expect only the D1 unescaping + `dcterms:modified` delta). Diff `?feed=newsbreak` against the standalone plugin's capture (expect N1/N2 fixes + sanitizer attribute cleanup).
4. Enable NewsBreak toggle on one brand, confirm at partner, then roll. MSN next (Game Day Chatter first — already in Partner Hub), Yahoo last (pending Yahoo onboarding — remember new-onboarding is RSS-only, which v3 satisfies).

## 7. Verification

The 13 v3.0 verification steps stand, plus: 14. legacy aigeon parity diff (automated in `tests/test-parity.php`); 15. NewsBreak shape diff vs standalone plugin; 16. MSN title 21-char lower bound and 365-day pubDate window; 17. YouTube iframe stripped from MSN body while X/TikTok iframes survive; 18. default-feed enhancement present when toggle ON, absent when OFF.

Automated coverage (PHP CLI harness, WP stubs, no WordPress install needed): emitter CDATA safety, sanitizer per-profile behavior (unwrap vs delete, style stripping, URL absolutization, attribute cleanup, YouTube/platform iframe rules), validator gates per network (word floors, title bands, date windows, image dims/rights), pipeline image cascade + exclusions, and full-feed snapshot parity for aigeon vs a captured v2 rendering. Run: `php tests/run-tests.php`.

## 8. Scope (actual)

~2,000 lines of plugin code across 11 files + ~1,300 lines of tests (75 tests). All four profiles ship in v3.1.0.

## 9. Deep-audit pass (2026-08-05)

A hostile review after the initial build, focused on behaviors the CLI test stubs could mask on real WordPress. Eight defects found and fixed, each with a regression test (`tests/test-audit.php`):

| # | Defect | Fix |
|---|---|---|
| A1 | `the_content` filtering ran without `setup_postdata()`/global `$post` — shortcodes and block renderers would execute against the wrong post on real WP | Per-item postdata setup + reset, exactly as a `the_post()` loop |
| A2 | Skip log re-logged the same skip on every poll (Yahoo polls 5-min → one post fills the 200-cap in hours) and autoloaded on every page view | Dedupe by (network, post, reason) with timestamp refresh; `autoload=no` |
| A3 | Aigeon profile ignored the saved `image_size` setting (v2 parity regression) | Aigeon reads the stored option |
| A4 | No feed caching despite 104-brand × 5–15-min polling | 60s transient keyed on `lastpostmodified` (publish busts instantly); aigeon stays uncached per current production behavior |
| A5 | MSN iframe rule admitted any `google.com` embed; docs allow Google Maps only | Host+path-prefix rules (`google.com/maps`, `maps.google.com`) |
| A6 | Inline-image fallback could select a `data:` base64 lazy-load placeholder as the item image | Skips non-http(s)/rooted sources; absolutizes relative ones |
| A7 | Derived excerpts ended in a literal `&hellip;` entity inside CDATA | Real `…` character |
| A8 | Yahoo allowlisted `<embed>` but stripped all its attributes, leaving a dead element | `src/type/width/height` attribute allowlist |

---

## Pending for Bible

- `?feed=newsbreak` is served by standalone plugin "NewsBreak RSS Feed" v1.3.0, which also silently enhances the default WP feeds — any future work touching `/feed/` on brand sites must account for this (now preserved behind `enhance_default_feeds` in mmg-raw-feed v3).
- MMG is a live approved MSN Partner Hub partner (BFY Labs LLC / Game Day Chatter) — MSN syndication is buildable today, not speculative.
- MSN ingestion hard rules (primary source, 2026-08-04): title 21–150 chars (short title unlocks >150), pubDate in past and <365 days, YouTube iframes rejected at ingestion, `mi` namespace `http://schemas.ingestion.microsoft.com/common/`, updates via `dcterms:modified` + stable unique ID.
- Live NewsBreak feed sends clean canonical links (no UTMs); Aigeon raw feed sends UTM links — syndication-link policy is per-network in v3.
- NewsBreak plugin had a systemic pubDate timezone bug (local time labeled +0000) — any historical analysis of NewsBreak-side publish timestamps for these brands is skewed by the site's UTC offset.
