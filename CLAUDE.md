# ImageBundle — Symfony Bundle for Image Optimization

- **Composer:** `id-sign/image-bundle`
- **Namespace:** `IdSign\ImageBundle`
- **Bundle class:** `IdSign\ImageBundle\IdSignImageBundle`

## Project Overview

Symfony bundle providing a `<twig:Image>` component that generates `<picture>` tags with optimized images — automatic resize, client-side format selection (AVIF/WebP via `<source>` elements), responsive `srcset`, optional blur placeholder, named watermark profiles, HMAC-signed URLs, and aggressive HTTP cache headers.

## Requirements

- PHP 8.2+
- Symfony 7.4+ | 8.0+
- ext-imagick
- `symfony/ux-twig-component` (bundle dependency)

### Dev dependencies
- `phpstan/phpstan` + `phpstan/phpstan-symfony` (level 9)
- `phpunit/phpunit`

## Architecture

### Directory structure

```
src/
├── ImageBundle.php
├── DependencyInjection/
│   ├── IdSignImageExtension.php
│   └── Configuration.php
├── Controller/
│   └── ImageController.php
├── Service/
│   ├── ImageProcessorInterface.php
│   ├── ImagickProcessor.php
│   ├── FormatNegotiator.php
│   ├── SrcsetGenerator.php
│   ├── BlurPlaceholderGenerator.php
│   ├── ImageMetadataReader.php
│   ├── UrlSigner.php
│   ├── WatermarkOptions.php
│   └── WatermarkRegistry.php
├── Source/
│   ├── ImageSourceInterface.php
│   └── LocalFilesystemSource.php
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
```

### Key design decisions

#### Format selection via `<picture>` (not server-side content negotiation)

The component generates `<picture>` with `<source>` elements for each configured format (avif, webp) plus an `<img>` fallback in the original format. The browser selects the best supported format client-side.

This approach was chosen over server-side content negotiation (Accept header) because:
- Works with public serve mode (try_files) — each format has its own URL and file on disk
- No `Vary: Accept` needed — simpler CDN caching (one URL = one file)
- Only the format the browser actually requests gets generated — no wasted processing
- No PHP overhead on cache hit in public mode

#### HMAC-signed URLs

All image URLs are signed with HMAC-SHA256 derived from `kernel.secret`. The signature is part of the URL path. This prevents cache exhaustion attacks (arbitrary width/quality combinations) without needing a whitelist of allowed sizes.

Signature is 16 hex characters. Verification cost: ~0.4 μs per request. The key is derived via `hash_hmac('sha256', 'id_sign_image', $kernelSecret)` so compromising the image key does not compromise `kernel.secret`.

HMAC overhead per image lifecycle:
- **Public mode, cache hit:** 1× (sign in Twig component only, no PHP on image request)
- **Public mode, cache miss:** 2× (sign in component + verify in controller)
- **Controller mode:** 2× always (sign in component + verify in controller)

#### Path-based URLs

URLs are path-based (not query string) so they work with web server `try_files`:

```
/_image/{src}/{signature}_{w}_{h}_{fit}_{q}[_wm-{profile}].{format}
```

Example: `/_image/uploads/photo.jpg/a1b2c3d4e5f6g7h8_800_600_cover_80.avif`
With watermark: `/_image/uploads/photo.jpg/b2c3d4e5f6g7h8a1_800_600_cover_80_wm-copyright.avif`
SVG (no signature, no parameters): `/_image/icons/logo.svg`

The URL path doubles as the filesystem cache path. `CachePathResolver` generates paths, and can also parse parameters back from a path (`resolve()` and `parse()`).

Source path (`src`) is the first path segment — this means all cached data for a source image (variants, `meta.json`, `blur.txt`) live under the same directory prefix. `deleteBySource('uploads/photo.jpg')` = delete the `uploads/photo.jpg/` directory, which removes everything at once. This colocation is intentional — if metadata or blur cache were stored elsewhere, `deleteBySource()` would need to know about and clean up multiple locations. SVG files are cached as a single file at `{src}` (no variants directory), so `deleteBySource('icons/logo.svg')` deletes the file directly via `delete()`.

#### Image source abstraction

`ImageSourceInterface` with two methods: `exists(string $path): bool` and `getAbsolutePath(string $path): string`.

Default implementation: `LocalFilesystemSource` configured with `$basePath` (default: `%kernel.project_dir%/data`). Users override via Symfony DI to use custom sources (Flysystem, S3, etc.). No source configuration in bundle config — source path is a service argument.

#### Cache storage

`CacheStorageInterface` with methods: `has()`, `getAbsolutePath()`, `write()`, `delete()`, `deleteBySource()`, `purgeAll()`.

Default implementation: `LocalFilesystemCacheStorage` using native PHP filesystem functions. Cache is always local (no remote storage for cached variants). `write()` uses `rename()` from temp file for atomicity, then `chmod()` to set configured file permissions (needed because `tempnam()` creates files with `0600` and `rename()` preserves them).

#### Two serve modes

- **`public` (default):** Cache path matches URL path. Web server serves files directly via `try_files`. PHP only called on first request per variant. TTL config has no effect.
- **`controller`:** Every request goes through PHP. Enables TTL-based invalidation — `has()` checks `filemtime()` against configured TTL. Use for access-controlled images.

### Image processing

- **ext-imagick directly** — thin wrapper, no third-party libraries
- `ImagickProcessor` calls `$imagick->clear()` in `finally` block after every operation — critical for FrankenPHP worker mode. `Imagick::destroy()` is not used (deprecated).
- Processing writes to a temp file, then `CacheStorageInterface::write()` moves it to the cache location
- Temp directory is configurable (`tmp_dir` in bundle config)
- Auto-rotation from EXIF orientation data
- Directory creation uses race-condition-safe pattern: `!is_dir() && !mkdir() && !is_dir()`
- File and directory permissions are configurable (`file_permissions`, `directory_permissions`). Applied via `chmod()` after file creation — fixes `0600` permissions from `tempnam()`/`rename()`. Only called on cache miss, zero overhead on cache hit.

#### Fit modes

- `cover` — fill dimensions, crop from center (`cropThumbnailImage`)
- `contain` — fit within dimensions, preserve aspect ratio (`thumbnailImage`)
- `scale-down` — like contain, but never upscales
- No fit — resize to exact dimensions (may distort)

#### Watermark

Applied after resize, before strip/format conversion. Uses `Imagick::compositeImage()` with manually calculated x/y position (not `setGravity()`, which has PHPStan type issues with Imagick enum constants).

Named watermark profiles configured in bundle config. `WatermarkRegistry` manages profiles, `WatermarkOptions` is the value object per profile. Watermark Imagick object gets `clear()` in finally block.

Watermark profile name is part of the cache path (`_wm-{profile}` suffix) and HMAC signature. Same image with different watermark profiles = different cached files.

### Twig component

`ImageComponent` is a `symfony/ux-twig-component` class component with `#[AsTwigComponent]`.

**Props:** `src`, `width`, `height` (optional), `fit` (optional), `blur` (optional bool), `quality` (optional int), `autoDimensions` (optional bool|null), `watermark` (optional string|false|null)

**Watermark prop resolution:**
- `watermark="profile_name"` — use this specific profile
- `:watermark="false"` — disable watermark even if `default_watermark` is set
- Omitted (null) — use `default_watermark` from config

**All other attributes** pass through to `<img>` via `{{ attributes }}` — `alt`, `class`, `id`, `loading`, `sizes`, `data-*`, `aria-*`, etc.

**Default:** `decoding="async"` (overridable). No default `loading` attribute.

**SVG passthrough:** `.svg` files render as plain `<img src="/_image/path.svg">` — no `<picture>`, no processing, no srcset, no blur. SVG URLs go through `route_prefix` (not raw source path), so the file is served from cache via `try_files` (public mode) or controller. No HMAC signature — no parameters to vary.

**I/O during render (component `mount()`):**
- `auto_dimensions` enabled (globally or via `autoDimensions` prop) + no `height` prop: reads image metadata via `ImageMetadataReader` (cache hit: ~5-10 μs file read, cache miss: ~10-30 ms Imagick)
- `blur` enabled: reads blur data URI via `BlurPlaceholderGenerator` (cache hit: ~5-10 μs file read, cache miss: ~50-100 ms Imagick)
- With warm cache, 50 images on a page ≈ 250-500 μs total I/O overhead

### Blur placeholder

`BlurPlaceholderGenerator` creates a 10px-wide JPEG thumbnail, base64-encodes it as a data URI (~300-600 bytes). Cached as `blur.txt` in the source image's cache directory (`{cache_path}/{src}/blur.txt`). In-memory cache per request. Implements `ResetInterface` for FrankenPHP.

Rendered via CSS `background-image` + `filter: blur(20px)`, removed on `<img onload>`.

### Image metadata

`ImageMetadataReader` reads source image dimensions via Imagick. Cached as `meta.json` in the source image's cache directory (`{cache_path}/{src}/meta.json`). In-memory cache per request. Implements `ResetInterface` for FrankenPHP.

Used by `auto_dimensions` feature — when only `width` is provided, height is calculated from the source aspect ratio. The `calculateHeight(string $src, int $width): ?int` method encapsulates the proportional height calculation — used by both `ImageComponent` and `ImageUrlExtension` to avoid duplication.

### Format negotiation

`FormatNegotiator` selects the best output format based on configured formats and browser support:
- `negotiate(string $acceptHeader, string $sourceExtension)` — low-level, takes raw Accept header
- `negotiateFromRequest(Request $request, string $src)` — convenience wrapper, extracts Accept header and extension
- `getFallbackFormat(string $sourceExtension): string` — static, single source of truth for mapping source extensions to web-safe fallback formats (png stays png, gif/tiff/heic/bmp → jpeg, unknown → jpeg). Used by both `negotiate()` and `ImageComponent::getFallbackFormat()`.

### Programmatic URL generation

`ImageUrlGenerator` provides a public API for generating optimized image URLs from PHP code:
- `generate(string $src, int $width, ...)` — generate URL for a specific format
- `generateFromRequest(Request $request, string $src, int $width, ...)` — negotiate format from Accept header

Used for API responses, emails, or any context outside Twig templates.

### Twig function `image_url()`

`ImageUrlExtension` registers an `image_url()` Twig function for generating a single optimized image URL (as opposed to the `<twig:Image>` component which generates a full `<picture>` tag). Useful for og tags, emails, JSON-LD, or any context where a single URL is needed.

**Parameters:** `src`, `width`, `height`, `fit`, `quality`, `format`, `watermark`, `autoDimensions` — mirrors the component props.

**Format resolution:**
- Explicit `format` parameter → use that format directly
- No format + active request → negotiate from `Accept` header (same as `ImageUrlGenerator::generateFromRequest()`)
- No format + no request (e.g. CLI) → fallback to `webp`

**Watermark/quality/autoDimensions resolution** follows the same logic as `ImageComponent` — global defaults from config, overridable per call.

### Srcset generation

`SrcsetGenerator` creates srcset entries from `device_sizes` breakpoints. Only includes breakpoints ≤ the component's `width`. Heights are proportionally calculated when `height` is provided. The main `width` is always included as the largest entry. Default `sizes` attribute: `100vw`.

### Controller

`ImageController` is an invokable controller receiving `{path}` from the route.

**SVG flow** (path ends with `.svg`):
1. `ImageSourceInterface::exists()` — source check → 404 if missing
2. `CacheStorageInterface::has()` — cache check
3. Cache miss: copy source to temp file → `write()` to cache
4. `BinaryFileResponse` with immutable cache headers

No HMAC, no Imagick processing.

**Raster flow:**
1. `CachePathResolver::parse($path)` — extract parameters from URL
2. `UrlSigner::verify()` — HMAC check → 403 if invalid
3. `ImageSourceInterface::exists()` — source check → 404 if missing
4. `WatermarkRegistry::has()` — watermark profile check → 400 if unknown profile
5. `CacheStorageInterface::has()` — cache check (includes TTL in controller mode)
6. Cache miss: process via `ImagickProcessor` to temp file → `write()` to cache
7. `BinaryFileResponse` with `Cache-Control: public, max-age=31536000, immutable`

### FrankenPHP / worker mode

- `ImagickProcessor`: `clear()` in `finally` block (no `destroy()` — deprecated)
- `BlurPlaceholderGenerator`, `ImageMetadataReader`: implement `ResetInterface`, clear in-memory cache between requests
- No mutable static state anywhere
- File handles closed explicitly

## Code quality

**IMPORTANT:** Before every commit, all three checks must pass — PHP CS Fixer, PHPStan, and PHPUnit. These are enforced by GitHub Actions and must not be skipped.

**NEVER** add `Co-Authored-By`, `Signed-off-by`, or any other trailer attributing AI to commit messages. The git user is the sole author.

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
- Unit tests: `UrlSigner`, `FormatNegotiator`, `CachePathResolver`, `LocalFilesystemSource`, `LocalFilesystemCacheStorage`, `SrcsetGenerator`, `ImageComponent`, `ImageUrlExtension`
- Functional test: `ImageController` — cache hit/miss, invalid path (400), invalid signature (403), missing source (404), avif/webp format generation, watermark processing, SVG passthrough
- Test fixtures: `tests/Fixtures/test.jpg` (100x75 red), `tests/Fixtures/logo.svg`, `tests/Fixtures/watermark.png`

## Configuration reference

```yaml
id_sign_image:
    device_sizes: [ 640, 750, 828, 1080, 1200, 1920, 2048, 3840 ]
    default_quality: 80
    formats: [ 'avif', 'webp' ]
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
    file_permissions: 0660              # null = use umask default
    directory_permissions: 0770
    tmp_dir: ~                          # defaults to sys_get_temp_dir()
    serve_mode: 'public'                # 'public' or 'controller'
    route_prefix: '/_image'
```

Image source path is configured as a service argument, not in bundle config:

```yaml
# config/services.yaml
IdSign\ImageBundle\Source\LocalFilesystemSource:
    arguments:
        $basePath: '%kernel.project_dir%/data'
```

## Release process

When releasing a new version:
1. Update `version` in `composer.json`
2. Tag the release in git
