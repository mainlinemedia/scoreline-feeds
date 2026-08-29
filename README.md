# MMG Raw Feed v3.1.0 — Multi-Network Syndication

One plugin, four compliance-profiled feeds per brand site:

| Profile | Route | Replaces | Default |
|---|---|---|---|
| Aigeon (legacy raw feed) | `/?feed={slug}-raw-feed` + tag feeds | MMG Raw Feed v2.1.0 | always on |
| NewsBreak | `/?feed=newsbreak` | NewsBreak RSS Feed v1.3.0 | **off** |
| MSN | `/feed/msn/` (give MSN the pretty form) | — new | **off** |
| Yahoo | `/?feed=yahoo` | — new | **off** |

Spec: `MMG_Raw_Feed_v3.1_Multi_Network_Spec.md` (this folder). Plugin source: `mmg-raw-feed/`.

## Install (per site) — zero-downtime path

The old **NewsBreak RSS Feed plugin can stay active** during migration — v3 detects it and automatically defers on `?feed=newsbreak` and the default-feed enhancement, so live syndication is never interrupted:

1. Deactivate old "MMG Raw Feed" v2.x only (fatal name collision with v3), activate v3. The Aigeon raw-feed URL swaps implementations in place. Leave "NewsBreak RSS Feed" v1.x running.
2. Enable the NewsBreak network with slug `newsbreak-v3` → validate `/?feed=newsbreak-v3` (diff against the live feed / have NewsBreak validate it).
3. Cutover when satisfied: deactivate the old NewsBreak plugin, change the slug back to `newsbreak`, save, flush permalinks. The URL NewsBreak polls never changes and never 404s.

Full replacement (no parallel validation):

1. **Deactivate BOTH old plugins first** — "MMG Raw Feed" (v2.x) and "NewsBreak RSS Feed" (v1.x). v2 shares function names with v3; simultaneous activation is a fatal error.
2. Upload/activate `mmg-raw-feed-v3.1.0.zip`.
3. Settings → Permalinks → Save (flushes rewrites).
4. Verify the legacy raw-feed URL and `?feed=newsbreak` against pre-upgrade captures. Expected deltas ONLY: unescaped entities inside CDATA (the v2 double-escaping bug is fixed), `dcterms:modified` on edited posts, correct GMT pubDates on the NewsBreak feed, and lazy-load/tracking attributes stripped from body HTML.
5. Existing settings carry over untouched (`mmgrf_options` / `mmgrf_tag_feeds` keys unchanged).

## Enabling networks

Settings → MMG Raw Feed → Syndication Networks. Everything defaults off. Recommended order: NewsBreak on one low-volume brand → diff at partner → MSN (Game Day Chatter first; it's already in Partner Hub) → Yahoo (needs Yahoo onboarding).

Per-post controls live in the **Syndication** box on the post editor (score, MSN short title, per-network exclusion, sponsored-content master switch). Getty/licensed images: flag "MMG does NOT hold syndication rights" on the attachment — the image is withheld from MSN and the item skips rather than shipping a rights-violating image.

**Skip log** (bottom of the settings page) answers "why isn't this article on X" — MSN and Yahoo both reject silently on their side.

## Tests

No WordPress install needed:

```
php tests/run-tests.php      # 66 tests: emitter, sanitizer, profiles, pipeline, routing, parity
php tests/render-samples.php # renders samples/sample-{aigeon,newsbreak,msn,yahoo}.xml from fixtures
```
