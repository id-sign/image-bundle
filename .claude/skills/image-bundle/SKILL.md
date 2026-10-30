---
name: image-bundle
description: Automatic image optimization for Symfony — responsive images, format conversion (AVIF/WebP), blur placeholders, watermarks, named image sources, and caching. Use when optimizing images for web performance, generating responsive pictures, adding watermarks, or configuring image serve modes and cache in a Symfony project using ImageBundle.
---

# ImageBundle

Symfony bundle that optimizes images automatically. Generates `<picture>` tags with responsive `srcset`, modern
formats (AVIF/WebP), optional blur placeholders, and named watermark profiles. Raster URLs are HMAC-signed; SVG files
are passed through unsigned and unprocessed.

## Component

```twig
<twig:Image
    src="uploads/photo.jpg"
    width="800"
    height="600"
    fit="cover"
    blur
    quality="80"
    watermark="copyright"
    alt="Description"
    class="responsive"
    loading="lazy"
    sizes="(max-width: 768px) 100vw, 50vw"
/>
```

### Props

| Prop             | Type          | Required | Description                                                                                    |
|------------------|---------------|----------|------------------------------------------------------------------------------------------------|
| `src`            | string        | yes      | Path relative to the `default` source; `@name/path` reads from a named source (see below)      |
| `width`          | int           | yes      | Intrinsic image width in px (not display size) — see "Picking width" below                     |
| `height`         | int           | no       | HTML attribute only (CLS prevention) — NOT used for processing unless `fit` is set; ≥ 1        |
| `fit`            | string        | no       | `cover` (center crop), `contain`, or `scale-down` (anything else throws) — needed for `height` to affect processing |
| `blur`           | bool          | no       | Inline blur placeholder                                                                        |
| `quality`        | int\|map      | no       | 1-100 for every format, or `:quality="{avif: 70, webp: 90}"` for the named formats (see below) |
| `autoDimensions` | bool          | no       | Auto-calculate height from aspect ratio (overrides global config)                              |
| `watermark`      | string\|false | no       | Profile name, `false` to disable, omit for global default                                      |
| `lossless`       | bool          | no       | Use lossless encoding for WebP/AVIF output (overrides global config)                           |

All other attributes pass through to `<img>`: `alt`, `class`, `id`, `loading`, `sizes`, `data-*`, `aria-*`, etc.

Syntax: `attr="…"` passes a string (coerced for `int` props), `:attr="…"` a Twig expression (`:watermark="false"`,
`:width="image.width"`), a bare attribute `true` (`blur`, `lossless`). Props can also be a named map:
`{{ component('Image', { src: image.path, width: 800, fit: 'cover', alt: image.title }) }}`.

Default: `decoding="async"`. No default `loading`.

### Quality

Each format gets the first of: the `quality` prop (an int for all formats, a map only for the formats it names),
`format_quality` from config, `default_quality`. AVIF needs a clearly lower value than WebP/JPEG for the same look, so
tune it per format in config rather than with one int prop — an int prop overrides every format, AVIF included. Map
keys: `avif`, `webp`, `jpeg`, `png` (never `jpg`); a value outside 1-100 or an unknown key throws
`InvalidArgumentException`. Quality is part of the URL: changing it means new variants, the old ones stay until purge.

### Picking width

`width` is the **intrinsic width of the generated image file**, not the rendered size on screen. It is required and
must be > 0 — omitting it (or passing `0`) throws `InvalidArgumentException` at render time, applies to SVG as well.
Pick a value that matches the largest pixel size the image will ever render at.

Watch out for:

- **`width` smaller than smallest `device_sizes` entry → no responsive srcset.** `SrcsetGenerator` only emits
  breakpoints ≤ `width`. Default smallest breakpoint is 320, so `width=300` produces a single-candidate srcset. On
  wide viewports the image gets stretched and looks blurry.

How to pick:

- **Full-bleed image (`w-full`, `sizes="100vw"`):** use the largest realistic render size — usually the largest relevant
  `device_sizes` entry (e.g. `1920` or `2048`). Browser then picks from all breakpoints ≤ width.
- **Fixed-size image in a column:** set `width` to the max CSS width the image can reach (times DPR if you want crisp
  retina — typically 2×). Add `sizes` matching the layout, e.g. `sizes="(min-width: 1024px) 500px, 100vw"`.
- **Unknown source dimensions:** pick width based on layout anyway; `autoDimensions="true"` fills `height` from source
  aspect ratio. Width still has to come from you.
- **SVG:** width is still required (uniform prop contract) but only affects the `<img width="…">` HTML attribute. Pick
  the layout size you want reserved for CLS — no server-side processing happens for SVG.

Rule of thumb: `width` ≥ largest rendered pixel size the image will reach across breakpoints. Undersized `width` =
blurry image, oversized `width` = wasted bandwidth on mobile (but srcset mitigates this).

### Picking sizes

`sizes` tells the browser how wide the image will render at each viewport size — the browser multiplies that against
DPR and picks the smallest srcset candidate ≥ that target. Without a correct `sizes`, responsive `srcset` is wasted
bandwidth: the default `sizes="100vw"` makes every browser download the largest candidate regardless of actual render
size.

Always set `sizes` to match the real CSS layout. Common patterns:

- **Full-bleed (edge-to-edge):** `sizes="100vw"` (bundle default — only correct if the image truly spans the viewport).
- **Fixed max-width container with full-bleed mobile:** `sizes="(min-width: 1280px) 1280px, 100vw"`.
- **Grid column, breakpoint-dependent:** `sizes="(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw"`.
- **Fixed pixel size regardless of viewport:** `sizes="400px"`.

Must align with `width`:

- `sizes` tells the browser the target render width → browser picks candidate from srcset
- `width` caps the top of srcset → the largest candidate the browser has to pick from
- Mismatch example: `sizes="100vw"` + `width="500"` on a 1920px desktop → browser wants 1920w but srcset max is 500w →
  stretched. Fix: raise `width` to match the largest viewport the image will reach, OR narrow `sizes` to reflect the
  real layout (e.g. `sizes="500px"` if the image is actually capped at 500px CSS).

Pass `sizes` as any other attribute — it propagates to every `<source>` and `<img>` via `{{ attributes }}`:

```twig
<twig:Image src="uploads/hero.jpg" width="1920" sizes="(min-width: 1280px) 1280px, 100vw" alt="…" class="w-full h-auto" />
```

### Height semantics (Next.js-style)

`height` without `fit` is a **layout hint only** — the server resizes by `width` only, preserving source aspect ratio.
`height` affects server-side processing only when `fit` is explicitly set.

| Props                        | Server behavior                                      |
|------------------------------|------------------------------------------------------|
| `width` only                 | Resize by width, proportional height                 |
| `width` + `height`, no `fit` | Resize by width only, `height` = HTML attribute only |
| `width` + `height` + `fit`   | Resize/crop using both dimensions                    |

### Output

Generates `<picture>` with `<source>` per configured format + `<img>` fallback:

```html
<picture>
    <source type="image/avif" srcset="/_image/.../800_600_cover_80.avif 800w, ..." sizes="100vw">
    <source type="image/webp" srcset="/_image/.../800_600_cover_80.webp 800w, ..." sizes="100vw">
    <img src="/_image/.../800_600_cover_80.jpeg" width="800" height="600" alt="..." decoding="async"/>
</picture>
```

The fallback `<img>` keeps the source format: JPEG → JPEG, PNG → PNG, WebP → WebP, AVIF → AVIF; GIF, TIFF, HEIC, BMP and
unknown extensions → JPEG. Converting a PNG source to WebP therefore also changes the fallback. Every variant is
converted to sRGB through its embedded ICC profile (Display P3 from iPhones, Adobe RGB, CMYK; no profile = sRGB,
except camera JPEGs in Adobe RGB mode, recognised by EXIF `R03`) and then stripped of all metadata — EXIF
(orientation is applied first), ICC profile, comments.

SVG files render as plain `<img>` — no processing, no `<picture>`. Served via `route_prefix` (copied to cache), no HMAC;
`image_url()` and `ImageUrlGenerator` return the same passthrough URL for `.svg`.

## Configuration

```yaml
# config/packages/id_sign_image.yaml
id_sign_image:
    # breakpoints ≥ 1, each once; a later config file replaces the list
    device_sizes: [ 320, 480, 640, 750, 828, 1080, 1200, 1920, 2048, 3840 ]
    default_quality: 80
    format_quality:                 # example, default none: format => 1-100; unlisted formats use default_quality
        avif: 55
        webp: 75
    sources:                        # name => absolute path; "@name/path" in src, no prefix = default
        default: '%kernel.project_dir%/data'
        reference: '%kernel.project_dir%/assets/reference'
    formats: [ 'avif', 'webp' ]     # avif, webp, jpeg, png, each once; a later config file replaces the list
    cache:
        ttl: 2592000                # seconds, controller mode only; 0 = re-encode every request (debugging)
        path: ~                     # absolute; default: %kernel.project_dir%/public + route_prefix
    blur:
        enabled: false
        size: 10                    # 1-64
        quality: 30                 # 1-100
    default_watermark: ~            # a profile name from watermarks, or null
    watermarks:                     # names: letters, digits, "_", "-" — kept verbatim, used in URLs
        copyright:
            path: '%kernel.project_dir%/data/watermark.png'   # absolute
            position: 'bottom-right'    # 9 positions: top-left, top-center, top-right, center-left, center, center-right, bottom-left, bottom-center, bottom-right
            opacity: 50
            size: 20                    # % of output image width, 1-100
            margin: 10                  # pixels from edge, ≥ 0
    auto_dimensions: false
    lossless: false                 # true lossless encoding (webp/avif only, ignored for jpeg/png)
    max_width: 4096                 # reject component width above this
    max_source_bytes: 20971520      # 20 MiB; 0 disables the check
    max_source_pixels: 64000000     # 64 MP (width × height from the header); 0 disables the check
    file_permissions: 0o660         # owner rw (null = umask); YAML reads 0660 as a string; from env decimal (432)
    directory_permissions: 0o770    # cache directories, owner rwx
    serve_mode: 'public'
    route_prefix: '/_image'         # leading "/", no trailing "/"; the route and default cache.path follow it
```

### Image sources

Sources are named. `src` without a prefix reads from the `default` source (`%kernel.project_dir%/data` unless
configured); `@name/path` reads from the source `name`:

```yaml
# config/packages/id_sign_image.yaml
id_sign_image:
    sources:
        default: '%kernel.project_dir%/public/uploads'
        reference: '%kernel.project_dir%/assets/reference'
```

```twig
<twig:Image src="uploads/photo.jpg" width="800" alt="From the default source" />
<twig:Image src="@reference/screenshot.png" width="1200" alt="From the reference source" />
{{ image_url('@reference/screenshot.png', 1200, format='webp') }}
```

Rules:

- Names start with a letter, may contain letters, digits, `_` and `-`, and are case-sensitive. Paths must be absolute
  (a `%parameter%` counts).
- The prefix stays in the URL (`/_image/%40reference/…`), in the HMAC signature and in the cache directory
  (`public/_image/@reference/screenshot.png/`), so `@reference/logo.png` and `logo.png` never share a cache entry.
  `@default/photo.jpg` and `/photo.jpg` are normalized to `photo.jpg` (a leading slash would otherwise be signed and
  yield `/_image//…` URLs that answer 403 behind nginx).
- An unknown source name behaves like a missing file: the page renders without `height`/blur, the image URL returns 404.
- A leading `@` in `src` is reserved. A directory of the default source whose name starts with `@` must be renamed or
  registered as a source of that name (`sources: { scope: '%kernel.project_dir%/data/@scope' }` keeps URLs and cache
  entries valid).
- Every configured source is a public asset tree — see Security model.

Custom source (S3, Flysystem): implement `ImageSourceInterface` and tag the service with `id_sign_image.source` and a
`name` (`#[AutoconfigureTag('id_sign_image.source', ['name' => 'reference'])]`). A custom **default** source re-points
the `id_sign_image.source.default` alias. The pre-0.4 override of the `$basePath` argument of `LocalFilesystemSource`
still works and takes precedence over `sources.default`.

### Routes

```yaml
# config/routes/id_sign_image.yaml
id_sign_image:
    resource: '@IdSignImageBundle/config/routes.yaml'
```

Import **without** `prefix:` — the route path comes from `route_prefix`. A custom prefix needs only
`route_prefix: '/media/images'`; the default `cache.path` follows it. Replace `/_image` in web server snippets and
`access_control` with the prefix.

## Serve modes

**Public (default):** Web server serves cached files via `try_files`. PHP only on first request per variant.

```nginx
location /_image/ { try_files $uri /index.php$is_args$args; }
```

FrankenPHP: `php_server` serves existing files from the web root before handing a request to PHP (its built-in
`try_files {path} {path}/index.php index.php`), and `@` in the URL (`%40`) works, so nothing has to be configured.
Caddy with PHP-FPM: `handle /_image/* { try_files {path}; php_fastcgi … }`.

**Controller:** Every request through PHP. Enables TTL-based invalidation and access control — with `cache.path` outside
the document root (`%kernel.project_dir%/var/image-cache`): under `public/` the web server's `try_files` serves cached
variants without PHP and the firewall never sees them. Works with
`X-Accel-Redirect`/`X-Sendfile` out of the box: set `framework.trust_x_sendfile_type_header` and the web server mapping
(README has nginx, FrankenPHP and Apache configs) — PHP then sends only headers and the web server streams the file.

In both modes a cached variant is served without consulting the source, so deleting a source file does not remove its
variants. Call `deleteBySource()` or `image:purge <src> --force` when a source is deleted; in controller mode the
variants also expire with `cache.ttl`.

## URL security

Raster URLs are HMAC-signed. Tampered parameters → 403. The format is not signed, so the controller generates only the
configured `formats` plus the source's fallback format; other extensions → 404. The fallback follows the source
extension: `jpg`/`jpeg` → `jpeg`, `png` → `png`, `webp` → `webp`, `avif` → `avif`, anything else (`gif`, `tiff`, `heic`,
`bmp`, …) → `jpeg`. SVG URLs have no signature (no parameters to vary). With different qualities per format a signed
quality is reachable in every offered format (up to formats × distinct qualities variants per width) — bounded, by
design.

URL format: `/_image/{src}/{signature}_{w}_{h}_{fit}_{q}[_lossless][_wm-{profile}].{format}`

## Security model

Every configured source directory (`sources`, default `%kernel.project_dir%/data`) is treated as **publicly
accessible**. Any file under any of them is readable by anyone who knows (or guesses) the path — adding a directory to
`sources` publishes it. For access control, use `serve_mode: controller` with `cache.path` outside the document root +
Symfony firewall in front of the bundle route; `try_files` bypasses Symfony security in public mode, and in controller
mode whenever the cache sits under `public/`.

Path segments `..`, `.`, empty and any containing `\` are rejected; symlinks inside the source tree ARE followed
(admin's decision). Audit `find data/ -type l` if you don't want that. Per-image authorization belongs in front of the
bundle route (firewall, `IsGranted`, request listener) — a custom `ImageSourceInterface` cannot enforce it, cached
variants are served without consulting the source.

### Decompression bombs

`max_source_bytes` (default 20 MiB) and `max_source_pixels` (default 64 MP, width × height read from the header) reject
oversized sources before Imagick decodes anything. The image URL answers `413`; a page rendering such an image gets no
`height` and no blur placeholder, like for a missing source. Raise `max_source_pixels` (or set `0`) for medium-format
photography. Still configure ImageMagick's `policy.xml` — the bundle is not a substitute for it.

### SVG XSS

The controller sends SVG as `image/svg+xml` with `Content-Security-Policy: sandbox` and
`X-Content-Type-Options: nosniff`, so controller mode is covered (X-Accel-Redirect drops them — add them in the internal
location, README shows how). In public mode cache hits bypass PHP: add the same headers on the web server, matching
case-insensitively (`logo.SVG` is served as SVG too). nginx — a regex `location` wins over the `/_image/` prefix one, so
keep the `/index.php` fallback or uncached SVGs never reach PHP:

```nginx
location ~* ^/_image/.+\.svg$ {
    add_header Content-Security-Policy "sandbox" always;
    add_header X-Content-Type-Options "nosniff" always;
    try_files $uri /index.php$is_args$args;
}
```

## Lossless encoding

`lossless=true` switches WebP/AVIF to their true lossless codecs (not `quality=100`, which is still lossy). Silently
ignored for JPEG (no lossless) and PNG (already lossless).

```twig
<twig:Image src="docs/screenshot.png" :width="1200" lossless />
```

5-50× slower, 2-10× larger files. Use for screenshots/diagrams/pixel art where edges matter.

**AVIF runtime requirement:** libheif built with AV1 encoder. On Debian/Ubuntu 24.04+ install `libheif-plugin-aomenc`
(not in base image). Missing encoder → `ImagickException: no encode delegate for AVIF`.

## Cache management

```bash
php bin/console image:purge                                    # dry-run preview
php bin/console image:purge --force                            # purge all
php bin/console image:purge uploads/photo.jpg --force          # purge specific image
php bin/console image:purge @reference/logo.png --force        # image from a named source
php bin/console image:purge uploads/2024 --force               # everything below a directory prefix
php bin/console image:purge @reference --force                 # whole named source
php bin/console image:purge --modified-before=30 --force       # older than 30 days
php bin/console image:purge --accessed-before=14 --force       # not accessed in 14 days
```

The cache directory (`cache.path`, default `public` + `route_prefix`) belongs to the bundle: purge and
`deleteBySource()` delete everything below it, files of your own included. Pick a `route_prefix` whose directory
nothing else uses; a cache directory that is a source directory or contains one fails the build.

### Twig function `image_url()`

For single URL generation in Twig (og tags, emails, JSON-LD):

```twig
{# Negotiate format from request #}
{{ image_url('uploads/photo.jpg', 800) }}

{# Explicit format #}
{{ image_url('uploads/photo.jpg', 800, format='webp') }}

{# og:image / twitter:image — WebP whatever the source format #}
{{ image_url(article.image, 1200, 630, 'cover', format='webp') }}

{# All options #}
{{ image_url('uploads/photo.jpg', 800, height=600, fit='cover', quality=90, format='avif', watermark='copyright', autoDimensions=true, lossless=true) }}
```

`src` and `width` are positional and required; the rest is optional and passed by name in any order (as in the examples)
or positionally in the order `height, fit, quality, format, watermark, autoDimensions, lossless`. Parameters mirror
component props + `format` (string, optional — if omitted, negotiated from the request Accept header;
without a request `webp`, or the source's fallback format when `webp` is not configured). `format` must be one of the
configured `formats` or the source's fallback format (`jpeg`/`png`), anything else throws `InvalidArgumentException` —
the controller would answer 404 for it.

Share images (`og:image`, `twitter:image`): use `format='webp'` — as of late 2024 every major platform renders WebP
previews (Facebook, X, LinkedIn, Slack, Discord, WhatsApp, iMessage, Threads, Bluesky); AVIF is dropped by X, LinkedIn,
Slack, Discord and iMessage. `format='jpeg'` throws for a WebP or PNG source (the format gate); if a platform really
needs JPEG, add `jpeg` to `formats` at the cost of a third `<source>` in every `<picture>`.

### Programmatic URL generation (PHP)

```php
// Inject ImageUrlGenerator, then:
$url = $imageUrlGenerator->generateFromRequest($request, 'uploads/photo.jpg', 800, 600, 'cover');
// format: one of the configured formats or the source's fallback, anything else throws
$url = $imageUrlGenerator->generate('uploads/photo.jpg', 800, 600, 'cover', 80, 'webp');
```

Same rules as the component: `width` must be 1…`max_width`, `height` is used only together with `fit` (without `fit`
the server resizes by `width` and keeps the aspect ratio), an unservable `format` throws, `quality` takes an int or a
map and, omitted, is the configured quality of the resulting format. `scale-down` keeps the requested `width` here (the
component replaces it with the source dimensions).

### Programmatic invalidation

```php
// Inject CacheStorageInterface, then:
$cacheStorage->deleteBySource('uploads/photo.jpg');   // all variants + meta.json/blur.txt
$cacheStorage->deleteBySource('icons/logo.svg');      // cached SVG file
$cacheStorage->deleteBySource('@reference/logo.png'); // image from a named source
$cacheStorage->deleteBySource('uploads/2024');        // everything below a directory prefix
```

Call it whenever a source image is replaced or deleted — cached variants are served without consulting the source.

## Upgrading from v0.4.0 or earlier

See "From v0.4.0 or earlier" in the bundle's UPGRADE.md. In short: a `height` below 1 throws like an unknown `fit`; with
a custom `route_prefix` the default cache directory is `public/<route_prefix>`, which purge empties — it must hold
nothing else; a `fit` other than `cover`/`contain`/`scale-down` throws (it used to render URLs answering 400); a signed
URL with a leading zero in a number (`_0800_`) no longer verifies (it used to yield unlimited variants per URL) and
answers 400; `ImageUrlGenerator` without `quality` now uses the configured quality of the format instead of a hard-coded
`80` (URLs change only when `default_quality` is not 80 or `format_quality` is set), a quality outside 1-100 — in
config, prop, `image_url()` or the generator — throws, and `formats` accepts only `avif`/`webp`/`jpeg`/`png` once each
and `device_sizes` only breakpoints ≥ 1 once each, with a later config file replacing either list instead of appending
to it. Values that used to break at runtime fail the build: unknown `default_watermark`, watermark names outside
`[A-Za-z0-9_-]`, relative watermark paths, watermark `size` outside 1-100, negative `margin`, `blur.size` outside 1-64,
negative `cache.ttl`, relative or empty `cache.path`, permission modes without owner rw/rwx (write `0o660`, not `660` or
`0660`), `route_prefix` without a leading or with a trailing `/`, with `.`/`..` segments or characters outside
`[A-Za-z0-9._~-]`. `%env()%` values build as before (checked only when literal), except `route_prefix`, which can no
longer come from env. Watermark names are kept verbatim — `brand-logo` was registered as `brand_logo` before. The route
path now comes from `route_prefix`: drop any `prefix:` on the routes import (the old README recipe gave
`/media/images/_image/{path}` against URLs `/media/images/…`, i.e. 404); without an explicit `cache.path` a custom
prefix caches under `public/<route_prefix>`.

## Upgrading from v0.3.0 or earlier

See "From v0.3.0 or earlier" in the bundle's UPGRADE.md. In short: a leading `@` in `src` now selects a named source
(rename a `data/@dir` directory or register it as a source of that name), default `device_sizes` gained `320` and `480`
(a one-off wave of new variants), oversized sources answer `413` instead of a server error, and `*.lock` files left by
older versions are unused — the next `image:purge --force` removes them. The controller serves only the configured
`formats` plus the source's fallback (other extensions 404); `image_url(format=…)` and `ImageUrlGenerator::generate()`
throw for any other format (typical hit: an og:image as `jpeg` from a WebP/PNG source — use `webp`) and, without a
request, fall back to the source format when `webp` is not configured. SVG
responses from the controller carry `Content-Security-Policy: sandbox` and `X-Content-Type-Options: nosniff`.
`ImageUrlGenerator` follows the component's rules: a `height` without `fit` is dropped from the URL (a new cache
variant; the old one was distorted) and a `width` above `max_width` throws. A leading slash in `src` is normalized
away like `@default/`. Variants of sources with a Display P3 or Adobe RGB profile generated by older versions have
washed-out colours (the profile was stripped without conversion); their URLs are unchanged, so purge those sources.
