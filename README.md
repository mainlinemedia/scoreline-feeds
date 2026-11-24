# Scoreline Feeds

A WordPress plugin that turns one site into compliance-profiled syndication
feeds for each network Mainline Media Group distributes to.

| Feed | URL (pretty permalinks) | Notes |
|---|---|---|
| Aigeon (legacy raw feed) | `/?feed={slug}-raw-feed`, plus tag feeds | always on; shape-compatible with MMG Raw Feed v2 |
| NewsBreak | `/feed/newsbreak/` | off until enabled |
| MSN | `/feed/msn/` | off until enabled |
| Yahoo | `/feed/yahoo/` | off until enabled |
| Yahoo / MSN slideshows | `/feed/{slug}-slideshows/` | posts tagged `slideshow` |
| Yahoo / MSN videos | `/feed/{slug}-videos/` | posts tagged `video` |

Networks type each source, so articles, slideshows and videos are separate
URLs registered separately with the partner. The settings page shows the
exact URL to hand over for each.

Requires WordPress 5.8+, PHP 7.4+ with the `dom` and `mbstring` extensions.

## Install and update

Upload the release zip once (*Plugins → Add New → Upload*). After that the
plugin updates itself from this repository through WordPress's own update
screen — no re-uploading. *Plugins → Scoreline Feeds → Enable auto-updates*
makes it hands-off.

Everything is configured under **Settings → Scoreline Feeds**. Feed URLs
register themselves; there is no need to re-save Permalinks.

## Operating it

- **Skip log** (settings page) and **Tools → Syndication Log** (writers see
  their own posts) answer "why isn't this article on the network?" — partners
  reject silently, so this is the only place that answer lives. Each entry has
  a stable code and a plain-language detail.
- **Syndication readiness** box in the post editor predicts, before
  publishing, what each enabled network will do with the post.
- **Syndication** box on each post: MSN short title, per-network exclusion,
  and the *Sponsored content* master switch.
- **Tools → Site Health** reports misconfiguration (plain permalinks, missing
  PHP extensions, a still-active legacy NewsBreak plugin).
- The `<generator>` element of every feed carries the plugin version, so the
  deployed version is always visible from the feed itself.

### Authoring slideshows and videos

- **Slideshow:** a normal post tagged `slideshow`. Each **H2 followed by a
  photo** is one slide; the paragraphs under it are the slide's description.
  The featured image is the cover. Yahoo needs 3 slides, MSN 4, every photo at
  least 1280×720 (JPEG, PNG or GIF).
- **Video:** a normal post tagged `video` containing a video uploaded to this
  site's media library (MP4 or MOV; at least 1280×720, 720×1280 vertical or
  1080×1080 square) and a featured image for the thumbnail.

A post with either tag ships **only** through its typed feed on Yahoo and
MSN. On NewsBreak — which has no gallery type and accepts video inside the
article body — the same post ships as an ordinary article. The marker tags
can be changed in settings.

## Development

No WordPress install is needed for the fast suite:

```
php tests/run-tests.php          # full suite against WordPress stubs
php tests/check-release.php      # version / manifest / changelog agree
php tests/render-samples.php     # writes samples/sample-*.xml
```

`MMGRF_TEST_ORDER=reverse` or `shuffle[:seed]` runs the suite in another
order. A warning, notice or deprecation fails the test that triggered it.

CI (`.github/workflows/ci.yml`) runs that suite on PHP 7.4 through 8.4 and an
**integration job against real WordPress**: it installs core, seeds real
posts and media (`tests/integration/seed.php`) and asserts the feeds over
HTTP (`tests/integration/assert.php`). Bugs that depend on what core really
returns — entity-encoded names, `-scaled` uploads, sticky posts, core
answering conditional GETs first — are only visible there.

### Layout

```
scoreline-feeds/
  scoreline-feeds.php        bootstrap, feed routes, default-feed enhancement
  includes/emitter.php       XML escaping and CDATA
  includes/sanitizer.php     DOM sanitizer, body-image pass, pruning, link guard
  includes/pipeline.php      query, item build, render, cache, conditional GET
  includes/slideshow.php     slideshow feeds
  includes/video-feed.php    video feeds
  includes/validator.php     skip log
  includes/options.php       options and defaults
  includes/admin.php         settings page, meta box, media rights field
  includes/syndication-check.php   editor readiness check (REST + meta box)
  includes/health.php        misconfiguration notices, Site Health test
  includes/updater.php       self-update from this repository
  profiles/                  one class per network (rules, gates, item shape)
```

### Releasing

1. Bump `Version:` and `MMGRF_VERSION` in `scoreline-feeds/scoreline-feeds.php`;
   add the `CHANGELOG.md` entry.
2. Open a pull request; merge when CI is green.
3. `php tests/build-zip.php` → `gh release create vX.Y.Z scoreline-feeds-X.Y.Z.zip`.
4. Update `manifest.json` to the new version and push. **This last commit is
   the fleet rollout** — every site sees the update on its next check. To
   stage a release, install the zip on one site first and bump the manifest
   only after its live feeds have been checked.
