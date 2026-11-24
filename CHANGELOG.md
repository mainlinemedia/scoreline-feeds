# Changelog

Release notes for Scoreline Feeds. Versions before 3.6.0 are described in the
GitHub release for each tag.

## 3.6.0 — 2026-11-24

Enterprise hardening release: the result of a section-by-section audit of
the whole plugin by six independent reviewers, with every confirmed finding
fixed test-first. 129 new regression tests (299 total), 122 of which fail
against 3.5.3.

### What changes for an operator

- **Publish settle window (new, default 60 seconds).** A newly published post
  is held back from the Yahoo, MSN and NewsBreak feeds for one minute, so a
  partner can never pick it up before its categories, featured image and
  Syndication box have finished saving. Syndication cannot be undone; this
  closes the gap. Change or disable under *Settings → Scoreline Feeds →
  Publish Settle Window*. The legacy Aigeon feed is not delayed.
- **Feed slugs are validated.** A network or tag feed can no longer be given
  a core WordPress feed name (`feed`, `rss2`, `atom`…) or a slug another
  Scoreline feed already uses.
- **No more Permalinks re-save.** New and renamed feed URLs register
  themselves; the settings page says so.
- **Problems are reported, not discovered.** Plain permalinks, a still-active
  legacy NewsBreak plugin, a missing PHP extension, or the plugin installed
  under the wrong folder name now show on the settings page and in *Tools →
  Site Health*.
- **Auto-updates can be enabled at any time.** The "Enable auto-updates"
  link in the Plugins list used to appear only while an update was pending.
- **New skip-log codes:** `render_error`, `filter_term_missing`,
  `exclusion_term_missing`, `inline_image_duplicate`, `slide_duplicate_image`,
  `slideshow_layout_ambiguous`, `image_format_unsupported`,
  `video_not_self_hosted`, `video_below_minimum`, `video_dimensions_unknown`.

### Content that was being shipped wrong

- **Truncated articles.** A stray closing `</div>` in a post, or an `<embed>`,
  silently cut the article off at that point on every network. Fixed.
- **Garbled text.** One invalid byte, or a pasted `<meta charset>`, turned
  every later accent and curly quote in the article into mojibake. Fixed.
- **Whole feed lost to one character.** A control character or invalid UTF-8
  byte in any title, excerpt, author or category made the entire feed
  ill-formed XML, so the partner lost every item. All output is now scrubbed.
- **Duplicate photos (Yahoo).** The featured image placed lower in the body,
  the same photo inserted twice, renditions of large ("-scaled") uploads and
  CDN/Photon URLs could all still list one photo twice in an item. Each photo
  now ships exactly once and the lead figure is always the first element
  (a featured photo found lower in the body is moved to the top, keeping the
  caption and alt text typed on its block).
- **Fused words and sentences.** Unwrapped blocks ran sentences together
  ("…ends here.Second…") and whitespace-only spans from Google Docs pastes
  joined words. Fixed, and the Yahoo 150-word floor is now measured on the
  body that actually ships.
- **Double-escaped names.** Category, tag and author names containing `&`
  (and the site title on never-saved settings) shipped as `&amp;amp;`.
- **Markup in plain-text fields.** HTML in a headline, manual excerpt,
  caption or credit shipped as visible tags.
- **Paginated posts** shipped page one only.
- **Player and share-button fallback text** ("Your browser does not support
  the audio element") leaked into Yahoo and MSN bodies.

### Content that should never have shipped

- **Slideshow and video feeds ignored every withholding rule**: "Sponsored
  content", per-network exclusions, the policy categories and tags, and
  password protection. They now apply exactly the article feed's rules.
- **Sticky posts** jumped to the top of every network feed regardless of age,
  past the item cap and past the sponsored-content exclusions.
- **Password-protected posts** were queried for syndication.
- **Child categories** of an excluded category were not excluded.
- **A renamed or deleted tag** silently turned a topic feed into every post
  on the site, and silently switched an exclusion off. Include filters now
  fail closed (empty feed, logged); missing exclusions are logged.
- **MSN:** a rights-flagged image inserted in the article body shipped; only
  the thumbnail was checked. A video thumbnail was never checked.
- **Video:** a video block pointing at another publisher's file was
  syndicated under our name.
- **Slideshows:** a photo placed above its heading paired every title with
  the next slide's photo. Both layouts are now read correctly, and a post
  whose layout cannot be determined is withheld rather than guessed.
- **Preview could un-sponsor a live post.** Unticking "Sponsored content" and
  clicking Preview cleared the exclusion on the published post.

### Reliability

- One post that throws (a broken shortcode) now costs that one item, not the
  whole feed on every network; the failure is logged as `render_error`.
- If a whole render fails, the partner is served the last good copy.
- Output printed by a third-party filter can no longer land in front of the
  XML declaration.
- **Conditional requests are now correct.** WordPress core answers
  `If-Modified-Since` itself before the plugin runs; a settings change, an
  unpublished post or a media-library edit (including a rights takedown) was
  therefore answered "304 Not Modified" until the next post was published.
  A partner that polls while a post is in its settle window receives it on
  its next request whether it echoes `Last-Modified` or sends its own fetch
  time.
- A server missing the DOM or mbstring extension answers 503 instead of a
  broken or empty feed.
- Inline images of large uploads now resolve to their attachment (WordPress
  stores them as `-scaled`), from the `wp-image-{id}` class where present —
  which also removes most database lookups from every render. Images served
  from an off-domain CDN can be declared with the `mmgrf_media_hosts` filter.

### Performance

- One cached copy per feed instead of one orphaned database row per publish;
  cache window raised from 60 seconds to 5 minutes (a post edit or settings
  change still refreshes it immediately).
- The skip log is written once per render instead of once per entry, and not
  at all when nothing changed.
- No row-count query; no extra option queries on ordinary page views.
- Sanitizing a large stats table: 37 seconds → under one second.

### Security

- Scriptable URL schemes, `srcdoc`, `<base>`, `<link>` and SVG animation
  elements are stripped in every sanitizer mode.
- The updater accepts only this repository's release asset for the exact
  version announced; a failed manifest fetch is cached for 15 minutes, not 6
  hours; "Check again" bypasses the cache; "View details" is answered locally.
- No absolute server paths in the author-visible Syndication Log.

### Engineering

- Continuous integration: the suite on PHP 7.4 through 8.4 (normal, reverse
  and shuffled order, warnings fail a test) plus an integration job that
  installs real WordPress, seeds real content and asserts the feeds over HTTP.
- `tests/check-release.php` verifies version, manifest and changelog agree.

### Deliberately unchanged

- NewsBreak still withholds posts carrying the slideshow or video marker tag
  (it has no typed feed; shipping them as articles is a separate decision).
- GUIDs remain the permalink. (Editing a slug after publish still makes
  partners see a new article; freezing the GUID is a separate decision.)
- The syndication-rights flag still applies to MSN only.
- No uninstall cleanup: deleting and re-uploading the plugin keeps settings.
