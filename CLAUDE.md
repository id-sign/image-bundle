# ImageBundle — Symfony Bundle for Image Optimization

- **Composer:** `id-sign/image-bundle`
- **Namespace:** `IdSign\ImageBundle`
- **Bundle class:** `IdSign\ImageBundle\IdSignImageBundle`

## Project Overview

Symfony bundle providing a `<twig:Image>` component that generates `<picture>` tags with optimized images — automatic
resize, client-side format selection (AVIF/WebP via `<source>` elements), responsive `srcset`, optional blur
placeholder, named watermark profiles, HMAC-signed URLs, and aggressive HTTP cache headers.

## Requirements

- PHP 8.2+
- Symfony ^7.4 | ^8.0
- ext-imagick
- `symfony/ux-twig-component` (bundle dependency)
- `symfony/yaml` (the DI extension loads `services.yaml`)
- `symfony/service-contracts` (`ResetInterface`, `ServiceProviderInterface`)

### Dev dependencies

- `friendsofphp/php-cs-fixer`
- `phpstan/phpstan` + `phpstan/phpstan-symfony` (level 9)
- `phpunit/phpunit`
- `symfony/property-access` (the component test writes props the way ux-twig-component does — `quality="90"` must
  coerce into `int|array|null`)

## Architecture

### Directory structure

```
src/
├── IdSignImageBundle.php
├── DependencyInjection/
│   ├── IdSignImageExtension.php
│   └── Configuration.php
├── Controller/
│   └── ImageController.php
├── Service/
│   ├── ImageProcessorInterface.php
│   ├── ImagickProcessor.php
│   ├── ExifOrientation.php
│   ├── ColorProfile.php
│   ├── ExifInteroperability.php
│   ├── FormatNegotiator.php
│   ├── ImageUrlGenerator.php
│   ├── SrcsetGenerator.php
│   ├── BlurPlaceholderGenerator.php
│   ├── ImageMetadataReader.php
│   ├── QualityResolver.php
│   ├── SourceSizeValidator.php
│   ├── SourceTooLargeException.php
│   ├── UrlSigner.php
│   ├── WatermarkOptions.php
│   └── WatermarkRegistry.php
├── Source/
│   ├── ImageSourceInterface.php
│   ├── LocalFilesystemSource.php
│   ├── NamedImageSource.php
│   ├── SourcePath.php
│   └── UnknownImageSourceException.php
├── Cache/
│   ├── CacheStorageInterface.php
│   ├── LocalFilesystemCacheStorage.php
│   └── CachePathResolver.php
├── Twig/
│   ├── ImageUrlExtension.php
│   └── Component/
│       └── ImageComponent.php
└── Command/
    └── PurgeCacheCommand.php

templates/components/
    └── Image.html.twig

config/
├── services.yaml
└── routes.yaml

resources/
├── sRGB2014.icc
└── AdobeCompat-v2.icc
```

### Key design decisions

#### Format selection via `<picture>` (not server-side content negotiation)

The component generates `<picture>` with `<source>` elements for each configured format (avif, webp) plus an `<img>`
fallback in the original format. The browser selects the best supported format client-side.

This approach was chosen over server-side content negotiation (Accept header) because:

- Works with public serve mode (try_files) — each format has its own URL and file on disk
- No `Vary: Accept` needed — simpler CDN caching (one URL = one file)
- Only the format the browser actually requests gets generated — no wasted processing
- No PHP overhead on cache hit in public mode

#### HMAC-signed URLs

All image URLs are signed with HMAC-SHA256 derived from `kernel.secret`. The signature is part of the URL path. This
prevents cache exhaustion attacks (arbitrary width/quality combinations) without needing a whitelist of allowed sizes.

Signature is 16 hex characters. Verification cost: ~0.4 μs per request. The key is derived via
`hash_hmac('sha256', 'id_sign_image', $kernelSecret)` so compromising the image key does not compromise `kernel.secret`.

HMAC overhead per image lifecycle:

- **Public mode, cache hit:** 1× (sign in Twig component only, no PHP on image request)
- **Public mode, cache miss:** 2× (sign in component + verify in controller)
- **Controller mode:** 2× always (sign in component + verify in controller)

The payload has no format, so `UrlSigner` memoizes signatures per payload for one request (`ResetInterface`, cleared
between requests in worker mode): one HMAC covers every `<source>` and the fallback of a width — 8 instead of 17 per
image at `width=1920` with the default breakpoints. With per-format quality (see Quality per format) it is one HMAC per
width and distinct quality — up to 17 again, only for applications that set different qualities. Measured on the
component at `width=1920`: 47.1 → 53.0 μs per raster image with three distinct qualities (avif, webp, JPEG fallback);
with one quality the resolver costs nothing measurable (46.5 μs before the feature).
`CachePathResolver::encodeForUrl()` is a single `rawurlencode()` pass with `%2F` restored to `/`. Measured on the
component: 57.7 → 46.7 μs per raster image, 53.8 → 2.6 μs per SVG (see the SVG note under Twig component).

#### Quality per format

The formats do not compress alike (AVIF looks like a WebP/JPEG at a clearly lower value), so `format_quality:
{format: 1…100}` sets the quality per output format and `default_quality` covers the rest. `QualityResolver` is the one
place that decides: `resolve($format, $quality)` returns the per-image value when it is an int, else the map entry for
that format, else `format_quality[$format]`, else `default_quality`. A per-image map therefore overrides only the
formats it names — an int still flattens every format, as before. The component resolves per `<source>` and for the
fallback;
`image_url()` passes `quality` through untouched and `ImageUrlGenerator::generate()` resolves it after the format is
known (negotiated, explicit or the CLI default), which also retired the generator's hard-coded `$quality = 80` that
ignored `default_quality`.

`QualityResolver::assertValid()` runs at the entry points — `ImageComponent::postMount()`, `image_url()` (before its
metadata I/O) and `ImageUrlGenerator::generate()` (for direct PHP callers) — before the SVG early return, so the prop
contract is uniform like `width`: an int outside 1…100, a map value that is not such an int, or a key outside
`FormatNegotiator::OUTPUT_FORMATS` (`jpg` included, the bundle writes `jpeg`) throws. A typo in a map key would
otherwise fall back silently. A valid key for a format this source never gets (`png` on a JPEG, `avif` without it in
`formats`) is ignored on purpose: one map can serve every image of a template, like `format_quality` serves every
source. `resolve()` trusts its input (it runs per format; `assertValid()` once per image). `Configuration` enforces
the same range on `default_quality`, `format_quality` and `blur.quality` (`format_quality` keys merge across config
files, a `when@prod` block can tune one format). Before `format_quality` there was no range check at all: `quality=0`
reached Imagick. `CachePathResolverTest` pins `OUTPUT_FORMATS` to the extensions `parse()` accepts.

Security trade-off, decided together with `format_quality`: the HMAC payload still has no format. With different
qualities per format, a signed `…_55.avif` can also be requested as `.webp`/`.jpeg` (`isOffered()` passes), so a width
has up to one variant per offered format × distinct quality — 9 instead of 3 with avif, webp and a JPEG fallback (each
extra PNG variant of a PNG source 20–30 MB at 3840 px). Bounded, and only when qualities differ. Two ways to close it
were weighed. Signing the format always changes every URL (after `image:purge` the URLs in sent e-mails and
share-image caches answer 403) and drops the per-width memo for everyone. Signing it only for images with per-format
quality — the controller verifying the format-free payload first, then the format-bound one — would keep every
existing URL and restore the 3-variant bound (lossless included: AVIF lossless forces q100, so cross-format quality
variants are byte-identical encodes), at the price of a second scheme in `UrlSigner`/controller and a URL change for
every format, tuned or not, when `format_quality` is switched on. Declined by the maintainer for now; it is the
preferred route if the bound ever needs closing.

#### Path-based URLs

URLs are path-based (not query string) so they work with web server `try_files`:

```
/_image/{src}/{signature}_{w}_{h}_{fit}_{q}[_lossless][_wm-{profile}].{format}
```

Example: `/_image/uploads/photo.jpg/a1b2c3d4e5f6g7h8_800_600_cover_80.avif`
With watermark: `/_image/uploads/photo.jpg/b2c3d4e5f6g7h8a1_800_600_cover_80_wm-copyright.avif`
Lossless: `/_image/icons/logo.png/c3d4e5f6g7h8a1b2_800_auto_none_100_lossless.webp`
Lossless + watermark: `/_image/icons/logo.png/...._800_auto_none_100_lossless_wm-copyright.webp`
SVG (no signature, no parameters): `/_image/icons/logo.svg`

The URL path doubles as the filesystem cache path. `CachePathResolver` generates paths, and can also parse parameters
back from a path (`resolve()` and `parse()`). The `parse()` pattern is anchored with the `D` modifier: a bare `$` also
matches before a trailing newline, and the route's `.+` requirement lets `%0A` through — a known signed URL plus `%0A`
would otherwise parse and verify like the original and write a second variant file with `\n` in its name (2× CPU and
disk per public URL).

Source path (`src`) is the first path segment — this means all cached data for a source image (variants, `meta.json`,
`blur.txt`) live under the same directory prefix. `deleteBySource('uploads/photo.jpg')` = delete the
`uploads/photo.jpg/` directory, which removes everything at once. This colocation is intentional — if metadata or blur
cache were stored elsewhere, `deleteBySource()` would need to know about and clean up multiple locations. SVG files are
cached as a single file at `{src}` (no variants directory), so `deleteBySource('icons/logo.svg')` deletes the file
directly. The deletion is recursive, so a directory prefix works too: `deleteBySource('uploads/2024')` or
`deleteBySource('@reference')` removes every variants directory below it — that is what `image:purge @reference --force`
relies on. `rmdir()` failures are ignored on purpose: a directory that a concurrent request refilled with a fresh
variant after its old files were unlinked must stay. `image:purge <src>` validates `src` through
`CacheStorageInterface::getAbsolutePath()` before counting or deleting, so the dry run reports exactly what `--force`
deletes and never walks outside the cache directory (`..`, `.` or an empty path — e.g. `@default/` normalized — exit
with an error).

#### Image source abstraction

`ImageSourceInterface` with two methods: `exists(string $path): bool` and `getAbsolutePath(string $path): string`.

Sources are named. The `ImageSourceInterface` alias points to `NamedImageSource`, a composite that dispatches
`@name/rest` to the source `name` (a `ServiceLocator` of services tagged `id_sign_image.source`, indexed by their
`name` attribute) and paths without a prefix to the `default` source (constructor argument bound to the alias
`id_sign_image.source.default`, which points to `LocalFilesystemSource`). The prefix stays part of `src` everywhere
else — URL, HMAC payload, cache directory, `meta.json`/`blur.txt` location, `image:purge` — so no other layer knows
about sources, and `@reference/logo.png` and `logo.png` can never share a cache entry.

`SourcePath::normalize()` strips a leading `/` and `@default/` at the four entry points where `src` comes from user
code (`ImageComponent::postMount()`, `ImageUrlExtension::imageUrl()`, `ImageUrlGenerator::generate()`,
`PurgeCacheCommand`), so `@default/x.jpg`, `/x.jpg` and `x.jpg` yield one URL and one cache directory. The slash
matters: `LocalFilesystemSource` and the cache storage ignore it, but it would stay in the signed payload and produce
`/_image//x.jpg/…` — nginx merges the slashes (`merge_slashes` is on by default), the signature no longer matches and
every request answers 403, while the Symfony dev server keeps the double slash and works. It strips in a loop, so it
is idempotent — `image_url()` normalizes and `ImageUrlGenerator::generate()` normalizes again. `NamedImageSource`
deliberately rejects `@default/` and unknown names: `exists()` returns false, `getAbsolutePath()` throws
`UnknownImageSourceException` (an `InvalidArgumentException`, same contract as `..`; `forDefaultPrefix()` carries a
message pointing to `SourcePath::normalize()` instead of "unknown source"). An unknown source name therefore
behaves like a missing file — page renders without `height`/blur, image URL returns 404 (a `@default/` inserted into a
signed URL returns 403 because the signature covers a different string).

Configuration: `sources: {name: absolute path}`. `Configuration` validates names against `[A-Za-z][A-Za-z0-9_-]*`
(the leading letter also rejects the integer keys of a YAML list written where a map was expected), requires absolute
paths (`/…`, `%param%…` or a Windows drive — a relative path would resolve against the CWD, which differs between web
server and console) and keeps keys verbatim (`normalizeKeys(false)`, the name ends up in URLs). It also adds
`default: Configuration::DEFAULT_SOURCE_PATH` (`%kernel.project_dir%/data`, the only place that literal lives) when
missing, as a `validate()->always()` rule: validation runs on the merged value of all config files, whereas a
`beforeNormalization()` default would let a `when@prod` block that only adds named sources reset the base file's
default. `IdSignImageExtension` sets `$basePath` of the `LocalFilesystemSource` service from `sources.default`
(`services.yaml` declares the service without arguments; an application-level override of that definition still wins,
as before 0.4) and registers every other source as `id_sign_image.source.{name}` (`LocalFilesystemSource`) tagged
`id_sign_image.source`. Custom implementations plug in through the same tag; a custom default re-points the
`id_sign_image.source.default` alias. Path-segment validation (`..`, `.`, empty, and `\` — Windows takes it as a
separator) lives in each `LocalFilesystemSource` and in the cache storage, so it applies inside every source; `NamedImageSource` additionally refuses `@name/` and
`@name//x` — the inner source would trim the slash and report a file the cache layer rejects, and the controller would
answer 500 instead of 404. `$default` in `NamedImageSource` must stay explicitly bound in `services.yaml` — autowiring
by type would resolve to the interface alias, i.e. to the composite itself.

Performance: a warm-cache render costs one `ltrim()` and one `str_starts_with` per image (normalization); the prefix
split (~0.5 μs,
about one HMAC) runs only where `exists()`/`getAbsolutePath()` were already called — on cache miss. Public-mode cache
hits never reach PHP, so the feature is free there.

#### Cache storage

`CacheStorageInterface` with methods: `has()`, `getAbsolutePath()`, `write()`, `delete()`, `deleteBySource()`,
`purgeAll()`.

Default implementation: `LocalFilesystemCacheStorage` using native PHP filesystem functions. Cache is always local (no
remote storage for cached variants). `write()` uses `rename()` from temp file for atomicity, then `chmod()` to set
configured file permissions (needed because `tempnam()` creates files with `0600` and `rename()` preserves them).

#### Two serve modes

- **`public` (default):** Cache path matches URL path. Web server serves files directly via `try_files`. PHP only called
  on first request per variant. TTL config has no effect.
- **`controller`:** Every request goes through PHP. Enables TTL-based invalidation — `has()` checks `filemtime()`
  against configured TTL. Use for access-controlled images — with `cache.path` outside the document root: under
  `public/` the web server's `try_files` serves a cached variant without PHP and the firewall never runs.

In both modes a cached variant is served without consulting the source: `try_files` cannot, and the controller checks
the cache before `ImageSourceInterface::exists()`, so a warm hit costs no source I/O (one `stat()` locally, a
round-trip for a remote source). A variant of a deleted source therefore lives until the TTL expires or
`image:purge` / `deleteBySource()` removes it — applications that delete sources call `deleteBySource()`, which they
need anyway to avoid orphaned cache entries.

Controller mode works with `X-Accel-Redirect` / `X-Sendfile` out of the box through `BinaryFileResponse`
(`framework.trust_x_sendfile_type_header` + web server mapping, documented in README); no bundle code is involved.

### Image processing

- **ext-imagick directly** — thin wrapper, no third-party libraries
- `ImagickProcessor` calls `$imagick->clear()` in `finally` block after every operation — critical for FrankenPHP worker
  mode. `Imagick::destroy()` is not used (deprecated).
- Processing writes via `CacheStorageInterface::writeLocked(cachePath, callable)` — the storage creates an intermediate
  tmp file in the TARGET directory (guaranteeing atomic intra-filesystem rename), invokes the callback with that path,
  then atomic-renames to the final cache location. The lock is `flock()` on the target directory itself: no lock files
  to leave behind or to purge, and the variants of one source share one lock, so concurrent generation of different
  variants of the same image serializes (rare — a browser requests one format and one width per image). On a
  filesystem where `fopen()` of a directory fails (Windows) the write proceeds unlocked. `meta.json` and `blur.txt` go
  through the lock-free `write()` instead (staged next to the target, then `rename()` — atomic all the same): the lock
  is held for the whole encode of a variant, and a page render must never wait for that.
- **Shrink-on-load for JPEG:** `ImagickProcessor` pings the source first (~0.04 ms) and, when a JPEG is at least twice
  the output box in both dimensions, sets `jpeg:size` to exactly 2× the box (in stored orientation) so libjpeg scales
  by 1/2, 1/4 or 1/8 during the IDCT — 24 MP → 800 px drops from ~415 ms / 210 MB to ~80 ms / 70 MB. ImageMagick
  rounds the scale factor, so the decode is still ≥ 1.5× the box; the hint never exceeds the source (libjpeg would
  upscale). The real, orientation-swapped source size from the ping feeds `proportionalHeight()`, so heights match
  `ImageMetadataReader` to the pixel. Other formats have no shrink-on-load and decode fully.
- Autorotation from EXIF orientation data via `ExifOrientation` — the single rule also used by `ImageMetadataReader`
  and `BlurPlaceholderGenerator`, so render-time dimensions and placeholders match the processed output. The processor
  applies it **after the fit** (`applyOrientation()` with the orientation captured before): a quarter turn swaps the
  fit box, the fit runs in the stored orientation and the 90° rotation — a lossless pixel permutation — touches only
  the small output. Saves ~136 ms per variant on a 24 MP PNG/WebP/HEIC source (~6 ms on JPEG, where shrink-on-load
  already decodes small). Output is pixel-identical for the proportional resize; `cover`/`contain` may differ from
  rotate-first by ≤1/255 (integer crop offset, 16-bit intermediate rounding in `thumbnailImage`), far below encoder
  noise, so cached variants stay valid. The watermark is applied after the rotation.
- **Colour management:** `ColorProfile::normalizeToSrgb()` runs after the fit and rotation, before the watermark and
  `stripImage()`. An image with an embedded ICC profile is converted through it with `profileImage('icc', sRGB2014)`
  (LCMS). `transformImageColorspace(sRGB)` alone — what the bundle did up to 0.3 — is a no-op there: ImageMagick
  reports an RGB-encoded image as sRGB whatever its profile says, so Display P3 photos (every iPhone), Adobe RGB and
  CMYK sources came out washed out once the profile was stripped. Profile-less images are assumed sRGB and get only
  the colorspace transform (CMYK/gray without profile); a profile LCMS rejects falls back to that too. Before
  converting, `isSrgb()` converts a 16×16 probe pattern (6×6×6 cube + gray ramp) through the profile: ≤1/255
  everywhere means one of the many sRGB byte-variants (HP, Apple/Google compact, GIMP built-in — 22 distinct blobs
  among 47 RGB profiles in one sample, so a hash list would not do) and the image is left alone. Cost: 2 ms for an
  sRGB-tagged 800×600 (was 36 ms, 0.5 s at 3840×2160), ~80 ns per pixel plus the probe for a real conversion (33 ms
  at 800×600), 0 without a profile; cache miss only. Target: the ICC's `sRGB2014.icc` in `resources/` — measured against the
  sRGB profiles found in real files (HP "sRGB IEC61966-2.1", Apple/Google compact "sRGB"/"c2", GIMP built-in) the
  conversion changes ≤1/255 on a few values, i.e. nothing, while the deprecated "no black scaling" profile would lift
  dark tones by up to 30/255. Identical results on ImageMagick 6.9.12 (Ubuntu 24.04) and 7.1.2. A profile LCMS
  rejects (truncated, or an RGB profile on CMYK pixels — ImageMagick reports the mismatch as an error) is logged as
  a warning through the optional `?LoggerInterface` of the processor and the blur generator and stripped without
  conversion; the wrong-coloured variant is cached, so the log line is the only trace. `applyWatermark()` converts
  the watermark the same way before compositing. `IdSignImageExtension::load()` refuses to build the container when
  `resources/sRGB2014.icc` or `AdobeCompat-v2.icc` is unreadable (a package shipped without `resources/`), instead
  of a 500 at the first cache miss on a profiled source.
- **Adobe RGB without a profile:** DCF cameras set to Adobe RGB embed no ICC profile and write EXIF
  `ColorSpace = Uncalibrated` + `InteroperabilityIndex = "R03"` instead. `ColorProfile::tagFromExif()` attaches
  `resources/AdobeCompat-v2.icc` (CC0, converts identically to Adobe's profile) when an image has no ICC profile and
  that tag, so `normalizeToSrgb()` converts it like a profiled source. It runs right after `readImage()` in the
  processor and the blur generator: `thumbnailImage()`/`cropThumbnailImage()` drop the EXIF block (they keep ICC).
  `ExifInteroperability::index()` reads the tag from the raw EXIF profile (IFD0 → Exif IFD → Interoperability IFD),
  because ImageMagick 7.1 exposes that IFD only as `exif:Thumbnail.InteroperabilityIndex` and 6.9 not at all. Cost
  5–30 µs per profile-less source (EXIF size), 1 µs with a profile; `Uncalibrated` alone means nothing (Photoshop exports and iPhones set it too). Not handled:
  PNG `cHRM`/`gAMA` without `iCCP` (1 of 1 023 real PNGs had non-sRGB primaries), AVIF/HEIC with `nclx` only.
- **Source limits:** `SourceSizeValidator::assertFits()` checks `max_source_bytes`, `assertPingedFits()` checks
  `max_source_pixels` (default 64 MP) against the pixels of **every frame** of the `pingImage()` the processor, the
  metadata reader and the blur generator do before decoding — the blur generator pings for this reason alone, since a
  non-JPEG source would otherwise be decoded in full at render time. `pingImage()` leaves the iterator on the last
  frame, so checking the current frame let a GIF with a 1×1 last frame through while `readImage()` decoded all of them
  (25 kB → 500 MB); the three readers also read `path[0]`, the first frame only (brackets in file names are fine, IM
  resolves the literal name first). The byte limit alone lets a ~15 MB 100 MP JPEG or a flat
  30000×30000 PNG through, and above ImageMagick's memory policy the decode falls back to a disk-backed pixel cache
  that takes seconds to minutes while holding the directory lock. Both throw `SourceTooLargeException`
  (`RuntimeException`): the controller answers 413 without caching, the two render-time readers treat the source like
  a missing one (no height, no blur, nothing written to disk). SVG sources get the byte check only.
- The cache storage is the only owner of directory creation (race-condition-safe `!is_dir() && !mkdir() && !is_dir()`)
  and of permissions (`file_permissions`, `directory_permissions`, applied via `chmod()` after the atomic rename to fix
  the `0600` of `tempnam()`); the processor writes to the tmp path it is given and never touches either. Only on cache
  miss, zero overhead on cache hit.

#### Height semantics (Next.js-style)

`height` without `fit` is a **layout hint only** — it appears as `<img height>` for CLS prevention but does NOT affect
server-side processing. The server always resizes by `width` only, preserving source aspect ratio. This matches Next.js
`<Image>` behavior where `width`/`height` define reserved space, not processing dimensions.

`height` is used for server-side processing only when `fit` is explicitly set.

| Props                        | Server behavior                                      |
|------------------------------|------------------------------------------------------|
| `width` only                 | Resize by width, proportional height                 |
| `width` + `height`, no `fit` | Resize by width only, `height` = HTML attribute only |
| `width` + `height` + `fit`   | Resize/crop using both dimensions                    |

Same rule applies to `image_url()` Twig function — `height` without `fit` is ignored in URL generation.

Exception: `fit="scale-down"` with a source smaller than the box replaces `width`/`height` with the source dimensions
(see Fit modes).

#### Fit modes

- `cover` — fill dimensions, crop from center (`cropThumbnailImage`)
- `contain` — fit within dimensions, preserve aspect ratio (`thumbnailImage`)
- `scale-down` — like contain, but never upscales. When the source fits the box (`origW <= width` and
  `origH <= height`, or height unknown), the component and `image_url()` use the source dimensions as the effective
  width/height (`ImageMetadataReader::resolveScaleDownDimensions()`), so srcset has no candidates above the source width.

#### Lossless encoding

`lossless=true` flips the encoder into its lossless codec path via `setOption('webp:lossless', 'true')` for WebP and
`setOption('heic:lossless', 'true')` for AVIF (AVIF is written through ImageMagick's HEIF coder). For AVIF the lossless
branch *also* forces quality to 100 (`setCompressionQuality(100)` + `setImageCompressionQuality(100)`): on builds without
the AV1 lossless encoder the `heic:lossless` flag is a silent no-op, so the quality=100 floor guarantees a
"visually lossless" result instead of falling through to the lossy default. Silently no-op for JPEG (no lossless mode
exists) and PNG (always lossless). The flag is part of the HMAC signature and cache path (`_lossless` segment before
`_wm-*`), so lossy and lossless variants cache independently.

True (bit-exact) AVIF lossless requires libheif built with AV1 encoder support. On Debian/Ubuntu 24.04+ that means
`libheif-plugin-aomenc` — not installed by default.

#### Quality setters (AVIF/HEIC gotcha)

`ImagickProcessor` sets quality via **both** `setCompressionQuality()` and `setImageCompressionQuality()`. They feed
different code paths: the HEIC/AVIF delegate reads quality from the ImageInfo struct (populated by
`setCompressionQuality()`, equivalent to the CLI `-quality`), while the per-image `setImageCompressionQuality()` is what
WebP/JPEG honor. Using only the per-image setter — as the bundle did before v0.2.2 — makes AVIF ignore `quality` entirely
and emit a byte-identical encoder-default file for every quality value. Setting both makes all delegates respond.

#### Watermark

Applied after resize and the sRGB conversion, before strip/format conversion; the watermark itself goes through
`ColorProfile::normalizeToSrgb()` after its `thumbnailImage()`, so a Display P3 PNG from Figma keeps its colours.
Uses `Imagick::compositeImage()` with manually calculated x/y
position (not `setGravity()`, which has PHPStan type issues with Imagick enum constants).

Named watermark profiles configured in bundle config. `WatermarkRegistry` manages profiles, `WatermarkOptions` is the
value object per profile. Watermark Imagick object gets `clear()` in finally block.

Watermark profile name is part of the cache path (`_wm-{profile}` suffix) and HMAC signature. Same image with different
watermark profiles = different cached files.

### Twig component

`ImageComponent` is a `symfony/ux-twig-component` class component with `#[AsTwigComponent]`.

**Props:** `src`, `width`, `height` (optional), `fit` (optional), `blur` (optional bool), `quality` (optional
int|map format => int, see Quality per format), `autoDimensions` (optional bool|null), `watermark` (optional
string|false|null), `lossless` (optional bool|null)

**Width is required and must be > 0** — enforced via `InvalidArgumentException` in `postMount()`. Applies to SVG too
(uniform prop contract). Rationale: `width=0` produces `srcset="… 0w"`, which is a parse error per HTML spec (`w`
descriptor must be > 0) — browsers drop all `<source>` candidates and fall through to `<img src>`, silently disabling
AVIF. Better to fail loudly than silently degrade. The v0.1.12 "fallback width from source" in `ImagickProcessor` was
removed for the same reason (it hid the client-side breakage).

**Watermark prop resolution:**

- `watermark="profile_name"` — use this specific profile
- `:watermark="false"` — disable watermark even if `default_watermark` is set
- Omitted (null) — use `default_watermark` from config

**All other attributes** pass through to `<img>` via `{{ attributes }}` — `alt`, `class`, `id`, `loading`, `sizes`,
`data-*`, `aria-*`, etc.

**Default:** `decoding="async"` (overridable). No default `loading` attribute.

**SVG passthrough:** `.svg` files render as plain `<img src="/_image/path.svg">` — no `<picture>`, no processing, no
srcset, no blur. SVG URLs go through `route_prefix` (not raw source path), so the file is served from cache via
`try_files` (public mode) or controller. No HMAC signature — no parameters to vary. ux-twig-component evaluates every
`#[ExposeInTemplate]` method eagerly, so `getSources()` and `getFallbackSrc()` return early for SVG (`$svg` is computed
once in `postMount()`) instead of signing URLs the SVG branch of the template never reads.

**I/O during render (component `mount()`):**

- `auto_dimensions` enabled (globally or via `autoDimensions` prop) + no `height` prop: reads image metadata via
  `ImageMetadataReader` (cache hit: ~5-10 μs file read, cache miss: < 0.1 ms Imagick ping — except WebP with EXIF,
  which is decoded fully: ~6 ms at 1.2 MP, ~67 ms at 12 MP, ~133 ms at 24 MP)
- `fit="scale-down"` on a raster source (with or without `autoDimensions`): reads image metadata via
  `ImageMetadataReader::resolveScaleDownDimensions()` (same costs as above, including the WebP-with-EXIF full decode)
- `blur` enabled: reads blur data URI via `BlurPlaceholderGenerator` (cache hit: ~5-10 μs file read, cache miss: ~50-100
  ms Imagick)
- With warm cache, 50 images on a page ≈ 250-500 μs total I/O overhead
- Missing or oversized source: on a `meta.json`/`blur.txt` cache miss both services call `ImageSourceInterface::exists()` first
  (one call per uncached image — mind the cost with a remote source); when it returns false the page renders without
  `height` and blur, and the image URL returns 404 from the controller

### Blur placeholder

`BlurPlaceholderGenerator` creates a 10px-wide JPEG thumbnail, base64-encodes it as a data URI (~300-600 bytes). The
source is autorotated (`ExifOrientation::autoRotate()`) before the thumbnail, so the placeholder has the displayed
orientation, and the 10 px result is converted to sRGB through its ICC profile (`ColorProfile::normalizeToSrgb()`),
so it has the colours of the variant. Cached as `blur.txt` in the source image's cache directory
(`{cache_path}/{src}/blur.txt`), written atomically through `CacheStorageInterface::write()` (temp file + rename,
directory and permissions handled by the storage, no directory lock) and read via `getAbsolutePath()` without the
storage's TTL; an empty or corrupt `blur.txt` counts as a miss
and is rewritten. In-memory cache per request. Implements `ResetInterface` for FrankenPHP. A missing source (`exists()`
false) yields `''` (no placeholder) kept only in the in-memory cache — no `blur.txt` is written, so a source uploaded
later is picked up on the next request.

Rendered via CSS `background-image` + `filter: blur(20px)`, removed on `<img onload>`.

### Image metadata

`ImageMetadataReader` reads source image dimensions via Imagick `pingImage()` (header only), except WebP with the
VP8X EXIF flag, which is read fully because ImageMagick's WebP ping skips the EXIF chunk. Dimensions are the displayed
ones: for 90° EXIF orientations (`ExifOrientation::isQuarterTurn()`) width and height are swapped, matching
the processor's autorotation. Cached as `meta.json` in the source image's cache directory
(`{cache_path}/{src}/meta.json`), written atomically through `CacheStorageInterface::write()` (a temp file moved next
to the target and renamed; no directory lock, see Image processing) and read without the storage's TTL; an empty or corrupt file (a reader that raced a non-atomic writer used to get `null` from
`json_decode()` and a warning) counts as a miss and is rewritten. In-memory cache per request. Implements
`ResetInterface` for FrankenPHP. A missing source (`exists()` false) yields `0×0` kept only in the in-memory cache — no
`meta.json` is written, so a source uploaded later is picked up on the next request.

Used by `auto_dimensions` and `fit="scale-down"` — with `auto_dimensions`, when only `width` is provided, height is
calculated from the source aspect ratio; with `scale-down`, `resolveScaleDownDimensions()` decides whether the source
fits the box.
The `calculateHeight(string $src, int $width): ?int` method encapsulates the proportional height calculation — used by
both `ImageComponent` and `ImageUrlExtension` to avoid duplication.

### Format negotiation

`FormatNegotiator` selects the best output format based on configured formats and browser support:

- `negotiate(string $acceptHeader, string $sourceExtension)` — low-level, takes raw Accept header
- `negotiateFromRequest(Request $request, string $src)` — convenience wrapper, extracts Accept header and extension
- `formats` (priority order) is checked by `Configuration` against `OUTPUT_FORMATS` with no duplicates — `jpg`/`AVIF`
  used to build and render `<source type="application/octet-stream">` whose URLs `parse()` answers 400 — and uses
  `performNoDeepMerging()`: Symfony appends prototyped lists across config files, so a `when@prod` `[webp]` after a
  base `[avif, webp]` gave a duplicate `<source>` and could never drop a format. `device_sizes` gets the same
  treatment (no duplicates, `performNoDeepMerging()`) plus `min(1)` — a `0` breakpoint rendered `0w`, see Twig
  component.
- `getFallbackFormat(string $sourceExtension): string` — static, single source of truth for mapping source extensions to
  web-safe fallback formats (png stays png, gif/tiff/heic/bmp → jpeg, unknown → jpeg). Used by both `negotiate()` and
  `ImageComponent::getFallbackFormat()`.

### Programmatic URL generation

`ImageUrlGenerator` provides a public API for generating optimized image URLs from PHP code:

- `generate(string $src, int $width, ...)` — generate URL for a specific format
- `generateFromRequest(Request $request, string $src, int $width, ...)` — negotiate format from Accept header

Used for API responses, emails, or any context outside Twig templates. Unlike `<twig:Image>` and `image_url()`, it
does not replace a `scale-down` width/height with the source dimensions, so its URL can be a separate cache variant.
`quality` (int, map or null) is resolved through `QualityResolver` once the format is fixed.
It shares their other rules: `width` must be 1…`max_width` (the controller trusts the signature, so the cap has to sit
where URLs are made — the generator has its own `$maxWidth` argument, bound in `services.yaml`), and `height` is a
processing dimension only together with `fit` — a signed `_800_600_none_` would reach `fitExact()` and distort the
image, while `image_url()` emits `_800_auto_none_` for the same arguments.
For an `.svg` source (`SourcePath::isSvg()`, the one rule shared with the component, `image_url()` and the controller)
it returns the passthrough URL — a signed raster URL would make the controller rasterize an untrusted SVG and its
variants directory would collide with the passthrough cache file. `generate()` refuses a `$format` the controller would
not serve and, when `$format` is `null`, picks one it does — see Format resolution under `image_url()`.

### Twig function `image_url()`

`ImageUrlExtension` registers an `image_url()` Twig function for generating a single optimized image URL (as opposed to
the `<twig:Image>` component which generates a full `<picture>` tag). Useful for og tags, emails, JSON-LD, or any
context where a single URL is needed.

**Parameters:** `src`, `width`, `height`, `fit`, `quality`, `format`, `watermark`, `autoDimensions` — mirrors the
component props.

**Format resolution:**

- Explicit `format` parameter → used as is when `FormatNegotiator::isOffered()` accepts it (a configured format or the
  source's fallback), otherwise `ImageUrlGenerator::generate()` throws `InvalidArgumentException` — the controller
  would answer 404 for that URL, and a dead URL in an e-mail or a JSON response is found much later than an exception.
  The typical hit is an og:image requested as `jpeg` from a WebP/PNG source; README and skill point to `webp` (rendered
  by every major platform since late 2024, AVIF is not) instead of widening the gate — an `extra_formats` option was
  considered for 0.4.0 and deferred until a real need (JPEG for e-mails) shows up
- No format + active request → negotiate from `Accept` header (same as `ImageUrlGenerator::generateFromRequest()`)
- No format + no request (e.g. CLI) → `webp` while it is configured, else the source's fallback format — both are
  always served; the literal `webp` of 0.3 answered 404 with `formats: ['avif']`

**Watermark/quality/autoDimensions resolution** follows the same logic as `ImageComponent` — global defaults from
config, overridable per call. `quality` is handed to the generator unresolved, because the format may only be known
after negotiation there.

### Srcset generation

`SrcsetGenerator` creates srcset entries from `device_sizes` breakpoints. Only includes breakpoints ≤ the component's
`width`. Heights are proportionally calculated when `height` is provided — `ceil()` for `contain`/`scale-down`,
`round()` for `cover`, always ≥ 1 px. Bestfit shrinks to the tighter box side, so a rounded-down height would deliver a
candidate narrower than its `w` descriptor; `cover` crops to the box exactly. The main `width` is always included as
the largest entry. Default `sizes` attribute: `100vw`.

### Controller

`ImageController` is an invokable controller receiving `{path}` from the route.

**SVG flow** (path ends with `.svg`):

1. `CacheStorageInterface::has()` — cache check → hit is served without touching the source
2. Cache miss: `ImageSourceInterface::exists()` — source check → 404 if missing
3. `SourceSizeValidator::assertFits()` (`max_source_bytes`) → 413; copy source to temp file → `writeLocked()` to cache
4. `BinaryFileResponse` with `Content-Type: image/svg+xml`, `Content-Security-Policy: sandbox`,
   `X-Content-Type-Options: nosniff` and immutable cache headers

No HMAC, no Imagick processing. The two security headers cost nothing and cover controller mode and the first (uncached)
response in public mode; public-mode cache hits never reach PHP, so the web server adds the same headers there (README
"Webserver hardening for SVG" — the nginx SVG `location` must keep the `/index.php$is_args$args` fallback of the
standard Symfony vhost, a regex location wins over
the prefix one and `=404` would keep uncached SVGs from ever reaching PHP), and nginx's X-Accel-Redirect keeps only
`Content-Type`/`Cache-Control`-class headers, so the internal location adds them too. The type is set explicitly so
nothing is sniffed from the content.

**Raster flow:**

1. `CachePathResolver::parse($path)` — extract parameters from URL → 400 (`jpg` is not an accepted extension, the
   bundle generates `jpeg`)
2. `SourcePath::isSvg(src)` → 404 (SVG is served as-is, never rasterized); `FormatNegotiator::isOffered()` → 404
   unless the format is one of the configured `formats` or the source's fallback — the signature does not cover the
   format, so one signed URL would otherwise yield a variant per accepted extension (PNG is lossless: 20–30 MB for a
   3840 px photo)
3. `UrlSigner::verify()` — HMAC check → 403 if invalid
4. `CacheStorageInterface::has()` — cache check (includes TTL in controller mode) → hit is served without touching
   the source (so per-image authorization belongs in front of the route, not in a custom source's `exists()`)
5. Cache miss: `ImageSourceInterface::exists()` — source check → 404 if missing (unknown `@source/` name included)
6. `WatermarkRegistry::has()` — watermark profile check → 400 if unknown profile
7. Process via `ImagickProcessor` to temp file → `writeLocked()` to cache; `SourceTooLargeException`
   (`max_source_bytes` before the read, `max_source_pixels` from the header ping, all frames) → 413, nothing cached
8. `BinaryFileResponse` with an explicit `Content-Type` (`FormatNegotiator::getMimeType()`; guessing would need
   `symfony/mime`, which the bundle does not require) and `Cache-Control: public, max-age=31536000, immutable`

### FrankenPHP / worker mode

- `ImagickProcessor`: `clear()` in `finally` block (no `destroy()` — deprecated)
- `BlurPlaceholderGenerator`, `ImageMetadataReader`: implement `ResetInterface`, clear in-memory cache between requests
- No mutable static state anywhere
- File handles closed explicitly

## Documentation

User-facing changes — props, configuration, console commands, URL format, serve-mode behaviour — are documented in
three places that must stay in sync: `README.md` (full reference), this file (design rationale) and
`.claude/skills/image-bundle/SKILL.md`, a Claude Code skill shipped with the bundle for applications that use it (a condensed
README: component, config, sources, serve modes, security, purge). Update the skill together with the README.

## Code quality

**IMPORTANT:** Before every commit, all three checks must pass — PHP CS Fixer, PHPStan, and PHPUnit. These are enforced
by GitHub Actions and must not be skipped.

**NEVER** add `Co-Authored-By`, `Signed-off-by`, or any other trailer attributing AI to commit messages. The git user is
the sole author.

### PHP CS Fixer

- **`@Symfony` + `@Symfony:risky`** ruleset
- Config: `.php-cs-fixer.dist.php`
- Parallel execution, cache enabled
- Run: `vendor/bin/php-cs-fixer fix`

### PHPStan

- **Level 9** with bleeding edge rules and symfony extension
- Config: `phpstan.neon.dist`
- Zero errors

### PHPUnit

- Unit tests: `UrlSigner`, `FormatNegotiator`, `QualityResolver`, `CachePathResolver`, `SourceSizeValidator`,
  `ColorProfile`, `ExifInteroperability`, `LocalFilesystemSource`,
  `NamedImageSource`,
  `SourcePath`, `IdSignImageExtension` (`sources`, `formats`, `device_sizes` and `format_quality` config, tagged
  registration, compiled-container wiring — needs `symfony/yaml`), `PurgeCacheCommand`, `LocalFilesystemCacheStorage`,
  `SrcsetGenerator`,
  `ImageComponent` (per-format quality, map prop),
  `ImageUrlExtension`, `ImageUrlGenerator` (format gate, height/fit and `max_width` rules, SVG passthrough, quality
  resolved per negotiated format),
  `ImagickProcessor`
  (AVIF/WebP quality + AVIF lossless regression — skips when no AVIF encoder; + srcset breakpoint width),
  `ImageMetadataReader` (scale-down fit, EXIF orientation), `BlurPlaceholderGenerator` (EXIF orientation) —
  `ExifOrientation` is covered through these two
- Functional test: `ImageController` — cache hit/miss, invalid path (400), invalid signature (403), missing source (
  404), source above `max_source_pixels`/`max_source_bytes` (413, nothing cached), cached variant of a deleted source
  still served (cache before source, raster and SVG), avif/webp format
  generation, watermark processing, SVG passthrough (+ `sandbox`/`nosniff` headers), named sources (`@reference/` cache
  directory, unknown source 404, `@default/` 403/404, `@reference//x` 404), signed URL + `%0A` (400)
- Test fixtures: `tests/Fixtures/test.jpg` (100x75 red), `tests/Fixtures/detailed.jpg` (512x512 random noise — high
  entropy survives resize so quality has a large, encoder-version-independent effect; used by quality-sensitivity
  tests), `tests/Fixtures/rotated.jpg` (stored 40x30 with EXIF Orientation=6, displayed 30x40),
  `tests/Fixtures/rotated.webp` (same, as WebP with EXIF chunk), `tests/Fixtures/plain.webp` (40x30, no EXIF),
  `tests/Fixtures/p3.jpg` (64x48 flat rgb(100,199,80) with an embedded Display P3 profile — rgb(46,202,57) in sRGB),
  `tests/Fixtures/srgb-tagged.jpg` (same pixels with a compact sRGB profile; both profiles CC0 from
  Compact-ICC-Profiles), `tests/Fixtures/adobergb-r03.jpg` (same pixels, no profile, EXIF `InteropIndex=R03` written
  with exiftool — rgb(0,200,67) in sRGB), `tests/Fixtures/frames.gif` (two frames, 60x40 first and 1x1 last —
  `pingImage()` stops on the last frame; the all-frames `max_source_pixels` and first-frame tests),
  `tests/Fixtures/logo.svg`, `tests/Fixtures/watermark.png`

## Configuration reference

```yaml
id_sign_image:
    device_sizes: [ 320, 480, 640, 750, 828, 1080, 1200, 1920, 2048, 3840 ]  # ≥ 1, each once; later file replaces
    default_quality: 80
    format_quality:                 # format => 1-100, formats not listed use default_quality
        avif: 55
        webp: 75
    sources:                        # name => absolute path; "@name/path" in src, no prefix = default
        default: '%kernel.project_dir%/data'
        reference: '%kernel.project_dir%/assets/reference'
    formats: [ 'avif', 'webp' ]       # FormatNegotiator::OUTPUT_FORMATS, each once; a later file replaces the list
    cache:
        ttl: 2592000                    # 30 days (controller mode only)
        path: '%kernel.project_dir%/public/_image'
    blur:
        enabled: false
        size: 10
        quality: 30
    default_watermark: ~                # Profile name or null
    watermarks:
        copyright:
            path: '%kernel.project_dir%/data/watermark.png'
            position: 'bottom-right'
            opacity: 50
            size: 20
            margin: 10
    auto_dimensions: false
    lossless: false                     # true lossless encoding for webp/avif (ignored for jpeg/png)
    max_width: 4096                     # guard against memory exhaustion
    max_source_bytes: 20971520          # 20 MiB, 0 disables
    max_source_pixels: 64000000         # 64 MP, width × height from the header before decoding, 0 disables
    file_permissions: 0660              # null = use umask default
    directory_permissions: 0770
    serve_mode: 'public'                # 'public' or 'controller'
    route_prefix: '/_image'
```

Custom source implementations are services tagged `id_sign_image.source` with a `name` attribute; a custom default
re-points the `id_sign_image.source.default` alias. The pre-0.4 override of `LocalFilesystemSource`'s `$basePath`
argument still works and takes precedence over `sources.default`.

## Release process

When releasing a new version:

1. Update `version` in `composer.json`
2. Tag the release in git
