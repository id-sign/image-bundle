# ImageBundle

Lightweight yet powerful image optimization bundle for Symfony. Provides a `<twig:Image>` component that generates
`<picture>` tags with automatic resize, client-side format negotiation (AVIF/WebP), responsive `srcset`, blur
placeholders, watermark profiles, and HMAC-signed URLs.

Built on ext-imagick with no additional dependencies — zero CLI tools, no third-party image libraries. Designed for
performance: web server serves cached images directly via `try_files` with no PHP overhead. Extensible through
interfaces for custom image sources and cache storage. Ready for FrankenPHP worker mode.

## Requirements

- PHP 8.2+
- Symfony 7.4+
- ext-imagick

## Installation

```bash
composer require id-sign/image-bundle
```

If Symfony Flex doesn't register the bundle automatically, add it to `config/bundles.php`:

```php
// config/bundles.php
return [
    // ...
    IdSign\ImageBundle\IdSignImageBundle::class => ['all' => true],
];
```

Import the bundle routes:

```yaml
# config/routes/id_sign_image.yaml
id_sign_image:
    resource: '@IdSignImageBundle/config/routes.yaml'
```

## Configuration

```yaml
# config/packages/id_sign_image.yaml
id_sign_image:
    # Breakpoints for responsive srcset generation
    device_sizes: [ 320, 480, 640, 750, 828, 1080, 1200, 1920, 2048, 3840 ]

    # Default output quality (1-100)
    default_quality: 80

    # Named image sources (name => absolute base path), referenced as "@name/path" in src.
    # Paths without a prefix use "default"; when omitted, default is %kernel.project_dir%/data.
    sources:
        default: '%kernel.project_dir%/data'
        reference: '%kernel.project_dir%/assets/reference'

    # Output formats in priority order (used for <source> elements in <picture>)
    formats: [ 'avif', 'webp' ]

    # Cache settings
    cache:
        ttl: 2592000            # 30 days in seconds (used in controller mode)
        path: '%kernel.project_dir%/public/_image'

    # Blur placeholder
    blur:
        enabled: false          # Global default, can be overridden per component
        size: 10                # Placeholder width in pixels
        quality: 30             # JPEG quality of the placeholder

    # Default watermark profile applied to all images (null = no watermark)
    default_watermark: ~

    # Named watermark profiles
    watermarks:
        copyright:
            path: '%kernel.project_dir%/data/watermark.png'  # Path to watermark image (PNG with transparency)
            position: 'bottom-right'  # top-left, top-center, top-right, center-left, center, center-right, bottom-left, bottom-center, bottom-right
            opacity: 50               # 0-100
            size: 20                  # Watermark width as percentage of output image width
            margin: 10                # Margin from edge in pixels
        # Define additional profiles as needed:
        # client_logo:
        #     path: '%kernel.project_dir%/data/client-logo.png'
        #     position: 'top-right'
        #     opacity: 30
        #     size: 15
        #     margin: 20

    # Auto-calculate height from source aspect ratio when only width is provided
    auto_dimensions: false

    # Use lossless encoding by default (WebP/AVIF only — ignored for JPEG/PNG)
    lossless: false

    # Safety limits — protect against memory exhaustion and decompression bombs
    max_width: 4096                 # Reject component width above this (pixels)
    max_source_bytes: 20971520      # Reject source files above this (bytes). 0 disables.
    max_source_pixels: 64000000     # Reject source images above this many pixels (width × height). 0 disables.

    # Permissions for created cache files and directories
    file_permissions: 0660        # null = use umask default
    directory_permissions: 0770

    # 'public' = web server serves cached files directly (try_files)
    # 'controller' = every request goes through PHP (enables TTL-based invalidation)
    serve_mode: 'public'

    # URL prefix for image routes
    route_prefix: '/_image'
```

## Usage

### Basic

```twig
<twig:Image src="uploads/photo.jpg" width="800" height="600" alt="A photo" />
```

Output:

```html

<picture>
    <source type="image/avif" srcset="/_image/.../640_480_none_80.avif 640w, /_image/.../800_600_none_80.avif 800w"
            sizes="100vw">
    <source type="image/webp" srcset="/_image/.../640_480_none_80.webp 640w, /_image/.../800_600_none_80.webp 800w"
            sizes="100vw">
    <img src="/_image/.../800_600_none_80.jpeg" width="800" height="600" alt="A photo" decoding="async"/>
</picture>
```

### With blur placeholder and fit mode

```twig
<twig:Image
    src="uploads/hero.jpg"
    width="1200"
    height="600"
    fit="cover"
    blur
    alt="Hero image"
    class="w-full rounded-lg"
    loading="lazy"
    sizes="100vw"
/>
```

### With watermark

```twig
{# Apply a specific watermark profile #}
<twig:Image src="uploads/photo.jpg" width="800" height="600" watermark="copyright" alt="Photo" />

{# Apply a different profile #}
<twig:Image src="uploads/photo.jpg" width="800" height="600" watermark="client_logo" alt="Photo" />

{# Disable watermark even if default_watermark is set globally #}
<twig:Image src="uploads/photo.jpg" width="800" height="600" :watermark="false" alt="Photo" />

{# Uses default_watermark from config (null = no watermark by default) #}
<twig:Image src="uploads/photo.jpg" width="800" height="600" alt="Photo" />
```

### Custom quality

```twig
<twig:Image src="uploads/avatar.jpg" width="64" height="64" quality="90" alt="Avatar" class="rounded-full" />
```

### SVG passthrough

SVG files are served without any image processing — no resize, no format conversion, no srcset. The file is copied to cache on first request and served via `try_files` (public mode) or controller. No HMAC signature is needed.

```twig
<twig:Image src="icons/logo.svg" width="120" height="40" alt="Logo" />
```

Output:

```html
<img src="/_image/icons/logo.svg" width="120" height="40" alt="Logo" decoding="async"/>
```

### Colour profiles and metadata

Every variant is written in sRGB without metadata. A source with an embedded ICC profile — Display P3 (every iPhone
photo), Adobe RGB, a monitor profile in a screenshot, a CMYK press profile — is converted to sRGB through that profile
before it is stripped, so the colours stay as intended; a source without a profile is taken as sRGB — except a camera
JPEG shot in Adobe RGB mode, which carries no profile but says so in EXIF (InteroperabilityIndex `R03`) and is
converted as Adobe RGB. A watermark image with a profile is converted the same way before it is composited. EXIF
orientation is applied before the EXIF block is dropped. The conversion runs on the resized output (about 40 ms for 800 × 600) and only when a variant is
generated; a source tagged with one of the sRGB profile variants (camera and Photoshop JPEGs) is recognised in about
2 ms and not converted. A profile that cannot be converted (truncated, or not matching the image) is logged as a
warning and stripped without conversion. The sRGB target is the ICC's `sRGB2014.icc`, bundled in `resources/`; the
bundle refuses to build the container when that file is missing.

Not handled, and taken as sRGB like browsers do: PNG files that declare their primaries only through `cHRM`/`gAMA`
chunks, and AVIF/HEIC files whose colour space is given only by an `nclx` box without an ICC profile (`avifenc`,
Pixelmator, Affinity; iPhone HEIC embeds a profile and is converted).

## Component attributes

| Attribute        | Type          | Required | Description                                                                  |
|------------------|---------------|----------|------------------------------------------------------------------------------|
| `src`            | string        | yes      | Source path, optionally `@source/`-prefixed (see [Image sources](#image-sources)) |
| `width`          | int           | yes      | Intrinsic image width in px (not display size) — required, see below         |
| `height`         | int           | no       | Display height (auto-calculated if `auto_dimensions` enabled)                |
| `fit`            | string        | no       | Resize mode: `cover`, `contain`, or `scale-down`                             |
| `blur`           | bool          | no       | Show blur placeholder (overrides global setting)                             |
| `quality`        | int           | no       | Output quality (overrides `default_quality`)                                 |
| `autoDimensions` | bool          | no       | Auto-calculate height from aspect ratio (overrides global `auto_dimensions`) |
| `watermark`      | string\|false | no       | Watermark profile name, `false` to disable, omit for global default          |
| `lossless`       | bool          | no       | Use lossless encoding (WebP/AVIF only, overrides global config)              |

All other attributes (`alt`, `class`, `id`, `loading`, `data-*`, `aria-*`, etc.) are passed through to the `<img>` tag.

### `src`

Path to the source image relative to the `default` source directory (`data/` unless configured). Prefix it with
`@name/` to read from another configured source, e.g. `@reference/logo.png` — see [Image sources](#image-sources).
`@default/photo.jpg` means the same as `photo.jpg`. The file must exist on the filesystem. For SVG files, no processing
is applied — the image is served directly.

### `width`

The **intrinsic width of the generated image file** in pixels — not the rendered CSS size. Used as the `width` HTML
attribute (CLS prevention), as the main size in srcset, and as the upper bound for responsive breakpoints
(`SrcsetGenerator` only emits `device_sizes` breakpoints ≤ `width`).

`width` is required and must be > 0 — omitting it (or passing `0`) throws `InvalidArgumentException` at render time,
applies to SVG as well. Pick a value that matches the largest pixel size the image will ever render at.

**Picking the right value:**

- **Full-bleed image** (`class="w-full"`, `sizes="100vw"`): use the largest realistic render size, typically the largest
  relevant `device_sizes` entry (e.g. `1920` or `2048`). The browser picks from all breakpoints ≤ width.
- **Fixed-size image in a column:** set `width` to the max CSS width the image can reach, optionally 2× for retina.
  Pair with a matching `sizes`, e.g. `sizes="(min-width: 1024px) 500px, 100vw"`.
- **`width` smaller than smallest `device_sizes` entry (default `320`)** produces a single-candidate srcset — on wide
  viewports the image gets stretched and looks blurry. Raise `width`, or narrow `sizes` to reflect the real layout.
- **SVG:** `width` only affects the `<img width="…">` HTML attribute — no server-side processing happens. Pick the
  layout size you want reserved for CLS.

Mismatch between `width` and `sizes` wastes bandwidth or causes blurry rendering — see [Picking sizes](#picking-sizes)
below.

### Picking sizes

The `sizes` attribute tells the browser how wide the image will render at each viewport size. The browser multiplies
that against DPR and picks the smallest srcset candidate ≥ the target. Without a correct `sizes`, responsive srcset is
wasted bandwidth: the bundle default `sizes="100vw"` makes every browser download the largest candidate regardless of
actual render size.

Set `sizes` to match the real CSS layout. Common patterns:

- **Full-bleed (edge-to-edge):** `sizes="100vw"` (bundle default — only correct if the image truly spans the viewport).
- **Fixed max-width container with full-bleed mobile:** `sizes="(min-width: 1280px) 1280px, 100vw"`.
- **Grid column, breakpoint-dependent:** `sizes="(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw"`.
- **Fixed pixel size:** `sizes="400px"`.

Pass `sizes` as any other attribute — it propagates to every `<source>` and `<img>`:

```twig
<twig:Image src="uploads/hero.jpg" width="1920" sizes="(min-width: 1280px) 1280px, 100vw" alt="Hero" class="w-full h-auto" />
```

### `height`

The display height in pixels. When omitted and auto dimensions is enabled (globally via `auto_dimensions` config or
per-component via `autoDimensions` prop), the height is automatically calculated from the source image's aspect ratio.
When both `width` and `height` are provided with a `fit` mode, the server produces an image with exactly these
dimensions.

### `autoDimensions`

Overrides the global `auto_dimensions` config for this specific component instance. When `true`, height is
auto-calculated from the source aspect ratio (if `height` is not provided). When `false`, auto-calculation is disabled
even if the global config is enabled. When omitted (`null`), the global config value is used. When the source image does
not exist, no `height` is rendered and the page still renders — the image itself returns 404.

### `fit`

Controls how the image is resized when both `width` and `height` are specified:

- **`cover`** — fills the target dimensions, cropping any overflow. Crop is always centered. For custom crop
  positioning, omit `fit` and use CSS `object-fit` + `object-position` instead — this gives full control over the
  visible area in the browser.
- **`contain`** — fits within the target dimensions, preserving aspect ratio (may leave empty space)
- **`scale-down`** — same as `contain`, but never upscales images smaller than the target. When the source is
  smaller, the rendered `width`/`height` attributes, `srcset` and URL reflect the actual source size.

When omitted, the image is resized to the exact dimensions, which may distort the aspect ratio.

### `blur`

Enables an inline blur placeholder. A tiny 10px-wide JPEG thumbnail is base64-encoded and rendered as a CSS
`background-image` with `filter: blur(20px)`. Once the full image loads, the placeholder is removed via `onload`. The
placeholder is generated on-demand and cached on disk. When the source image does not exist, no placeholder is rendered
and the image itself returns 404.

Can be set globally via `blur.enabled` in bundle configuration. The component attribute overrides the global setting.

### `quality`

Output compression quality (1-100). Overrides the global `default_quality` setting. Lower values produce smaller files
with more compression artifacts. Typical values: 60-80 for photos, 80-90 for detailed images.

### `watermark`

Selects a named watermark profile to apply. Profiles are defined in `watermarks` in the bundle configuration, each with
its own image, position, opacity, size, and margin.

- **`watermark="copyright"`** — applies the `copyright` profile
- **`:watermark="false"`** — disables watermark even if `default_watermark` is set globally
- **Omitted** — uses the `default_watermark` profile from config (null = no watermark)

Each watermark profile produces a separate cached file with a distinct URL, so the same image can exist with different
watermarks or without one.

### `lossless`

Switches the encoder into its lossless mode for formats that support it. Useful for screenshots, diagrams, UI assets,
pixel art, and anything where `quality=100` still visibly softens edges or introduces banding.

```twig
{# Single lossless asset #}
<twig:Image src="docs/screenshot.png" :width="1200" lossless />

{# With watermark #}
<twig:Image src="icons/diagram.png" :width="600" lossless watermark="copyright" />

{# Disable even if set globally #}
<twig:Image src="photos/hero.jpg" :width="1920" :lossless="false" />
```

**Format matrix:**

| Output format | `lossless=true` behaviour |
|---|---|
| `webp` | True lossless WebP (separate codec path, not `quality=100`) |
| `avif` | True lossless AVIF via libheif where the AV1 encoder plugin is present (see below); otherwise falls back to `quality=100` ("visually lossless") rather than silently dropping to the lossy default |
| `jpeg` / `jpg` | Silently ignored — JPEG has no lossless mode. `quality` applies normally |
| `png` | Silently no-op — PNG is always lossless |

Lossless encoding is **5-50× slower** and produces **2-10× larger files** than lossy at `quality=80`. For photographic
content the visual difference is imperceptible; use lossless only where bit-exact pixels actually matter.

**AVIF runtime requirement:** libheif on Debian/Ubuntu 24.04+ ships without an AV1 encoder by default. Install
`libheif-plugin-aomenc` on the host where PHP runs. Without it, AVIF lossless (and even lossy) encoding fails with
`no encode delegate for image format AVIF`.

```bash
# Debian / Ubuntu 24.04+
apt-get install libheif-plugin-aomenc
```

`lossless` can be set globally via the `lossless` config key and overridden per-component via the prop. The global
default is `false`.

## Serve modes

### Public mode (default)

Cached images are stored at a path matching the URL. The web server serves them directly via `try_files`, PHP is only
called on the first request per variant.

Nginx example:

```nginx
location /_image/ {
    try_files $uri @symfony;
}
```

Caddy example:

```
handle /_image/* {
    try_files {path}
    php_fastcgi unix//run/php-fpm.sock
}
```

FrankenPHP needs no extra configuration: `php_server` serves existing files from the web root before handing the
request to PHP (its built-in `try_files {path} {path}/index.php index.php`), and `@` in the URL (`%40`) works.

### Controller mode

Every image request goes through PHP. Enables TTL-based cache invalidation -- expired variants are regenerated on the
next request. Use this when you need access control or TTL-based refresh.

In both modes a cached variant is served without consulting the source, so deleting a source file does not remove its
variants: call `deleteBySource()` or `image:purge <src> --force` when a source is deleted (see
[Cache management](#cache-management)). In controller mode the variants then expire with `cache.ttl` at the latest.

```yaml
id_sign_image:
    serve_mode: 'controller'
    cache:
        ttl: 86400  # Re-generate after 24 hours
```

### Custom route prefix

Import the bundle routes with a custom prefix to change the URL base path:

```yaml
# config/routes/id_sign_image.yaml
id_sign_image:
    resource: '@IdSignImageBundle/config/routes.yaml'
    prefix: /media/images
```

Update the bundle config to match:

```yaml
id_sign_image:
    route_prefix: '/media/images'
```

### Restricting access to authenticated users

Use controller mode with Symfony security to require authentication for image access:

```yaml
# config/packages/id_sign_image.yaml
id_sign_image:
    serve_mode: 'controller'
```

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/_image, roles: ROLE_USER }
```

In public mode, the web server serves cached files directly and bypasses PHP — Symfony's security firewall is not
involved. Use controller mode when access control is required.

### Handing cached files to the web server (X-Accel-Redirect / X-Sendfile)

In controller mode PHP streams every cached file through Symfony's `BinaryFileResponse`. With `X-Accel-Redirect`
(nginx, FrankenPHP) or `X-Sendfile` (Apache `mod_xsendfile`) PHP answers with a header only and the web server sends
the file itself: `sendfile`, range requests and slow clients stop being PHP's business, while the firewall and the
controller still run for every request, so access control is unchanged. With nginx + PHP-FPM the worker is already
released once nginx has buffered the response, so the gain there is the CPU and memory of copying the file through PHP
and FastCGI; with FrankenPHP, where PHP writes straight to the client connection, it also frees the PHP thread from slow
clients. The bundle needs no configuration for this — it is the standard `BinaryFileResponse` support.

1. Keep `cache.path` outside the document root (recommended for controller mode anyway) and let Symfony trust the
   `X-Sendfile-Type` request header:

   ```yaml
   # config/packages/framework.yaml
   framework:
       trust_x_sendfile_type_header: true   # Symfony 7.2+: or SYMFONY_TRUST_X_SENDFILE_TYPE_HEADER=1
   ```

2. Tell the web server to pass the type and the path mapping to PHP and to serve the cache directory from an internal
   location. nginx:

   ```nginx
   location ~ ^/index\.php(/|$) {
       # ... fastcgi_pass etc.
       fastcgi_param HTTP_X_SENDFILE_TYPE X-Accel-Redirect;
       fastcgi_param HTTP_X_ACCEL_MAPPING /var/www/app/var/image-cache/=/_image_internal/;
   }

   location /_image_internal/ {
       internal;
       alias /var/www/app/var/image-cache/;
   }
   ```

   nginx keeps `Content-Type` and `Cache-Control` from the PHP response, so the `immutable` caching headers survive.

   FrankenPHP (from its [X-Sendfile documentation](https://frankenphp.dev/docs/x-sendfile/), paths adapted to a cache
   in `var/image-cache`):

   ```caddyfile
   root public/

   request_header X-Sendfile-Type x-accel-redirect
   request_header X-Accel-Mapping ../var/image-cache=/image-cache

   intercept {
       @accel header X-Accel-Redirect *
       handle_response @accel {
           root ../var/image-cache/
           rewrite * {resp.header.X-Accel-Redirect}
           method * GET
           header -X-Accel-Redirect
           file_server
       }
   }

   php_server
   ```

   Apache with `mod_xsendfile`:

   ```apache
   XSendFile On
   XSendFilePath /var/www/app/var/image-cache
   RequestHeader set X-Sendfile-Type X-Sendfile
   ```

## URL security

All **raster** image URLs are signed with HMAC-SHA256 derived from `kernel.secret`. The signature is part of the URL
path, so any modification of parameters (width, height, quality, etc.) results in a `403 Forbidden` response. This
prevents cache exhaustion attacks where an attacker generates arbitrary image variants.

SVG URLs are **not** signed — SVG has no parameter variations, so HMAC would add no protection against cache
exhaustion. See [Security model](#security-model) below for the implications.

## Security model

The bundle's threat model treats every configured source directory (`sources`, default `%kernel.project_dir%/data`) as
a **publicly accessible asset tree**. Any file under any of them can be fetched by anyone who knows or guesses the path:

- **Raster images** — attacker needs a valid HMAC signature (generated by your application) to access any specific
  `(src, width, height, …)` combination. They cannot forge arbitrary sizes or qualities. But the source file itself is
  public as soon as you render it anywhere with `<twig:Image>` or `image_url()`.
- **SVG files** — no signature is required. Any `.svg` file under any source directory is readable by anyone who knows
  or guesses the path (`/_image/@reference/anything.svg`).

### What this means for your project

**Do not store access-controlled or non-public files under any source directory.** Adding a directory to `sources`
publishes it. The bundle does not enforce
authorization. If you need:

- **Authorization per user/role:** use `serve_mode: controller` and add a Symfony firewall / `#[IsGranted]` in front of
  the bundle route. In public mode the web server serves cached files directly and bypasses Symfony security.
- **Private storage with controlled access:** implement a custom `ImageSourceInterface` pointing at a non-public
  location, and use `serve_mode: controller` with access control.

### Path traversal and symlinks

Input paths (`src` values) are checked for `..` and empty segments and rejected with `400`/`403` at the controller /
cache layer. **Symlinks are followed transparently** — the bundle assumes any symlink inside the source directory was
placed there intentionally by the administrator. If you don't want symlinks followed, audit your source tree (e.g.
`find data/ -type l`) and remove unexpected ones before relying on the boundary.

### Decompression bombs

`max_source_bytes` (default 20 MiB) rejects oversized source files, and `max_source_pixels` (default 64 million
pixels, 64 MP) rejects images whose header announces more pixels than that. The byte limit alone is not enough: a
highly compressed 100 MP JPEG is about 15 MB and a flat 30 000 × 30 000 PNG a few hundred kB, yet decoding either
needs over a gigabyte — above ImageMagick's memory policy it falls back to a disk-backed pixel cache that takes
seconds to minutes per variant. Both checks run before Imagick decodes anything (the pixel check reads only the
header) and the image URL answers `413`; a page that renders such an image gets no `height` and no blur placeholder,
like for a missing source. Raise `max_source_pixels` for medium-format photography (100 MP and more) or set it to `0`
to disable the check. Also configure ImageMagick's `policy.xml` to restrict memory/width/height limits and disable
dangerous coders (`MVG`, `MSL`, `PDF`, `EPHEMERAL`, `URL`). The bundle cannot substitute for a properly configured
ImageMagick policy.

### SVG and XSS

SVG files can contain `<script>` and event handlers that execute in your origin when the SVG is loaded as a top-level
document. The bundle serves SVGs as-is — it does not strip scripts. **If your source directory can receive
user-uploaded SVGs, you must either sanitize before storage, or harden the served response** (see
[Webserver hardening for SVG](#webserver-hardening-for-svg) below).

## Webserver hardening for SVG

In public mode the web server serves cached SVG files directly via `try_files` — PHP is not involved, so the bundle
cannot set response headers on those requests. To block script execution in served SVGs, add a `Content-Security-Policy:
sandbox` header in the web server configuration:

### Nginx

```nginx
location /_image/ {
    try_files $uri @symfony;
}

location ~* ^/_image/.+\.svg$ {
    add_header Content-Security-Policy "sandbox" always;
    add_header X-Content-Type-Options "nosniff" always;
    try_files $uri =404;
}
```

### Apache

```apache
<LocationMatch "^/_image/.+\.svg$">
    Header always set Content-Security-Policy "sandbox"
    Header always set X-Content-Type-Options "nosniff"
</LocationMatch>
```

### Caddy

```
@image_svg path_regexp ^/_image/.+\.svg$
header @image_svg Content-Security-Policy "sandbox"
header @image_svg X-Content-Type-Options "nosniff"
```

`Content-Security-Policy: sandbox` (with no directive value) applies the strictest sandbox: blocks scripts, forms,
popups, plugins, and same-origin access. The SVG still renders as an image. This is cheap (single header in response),
effective against `<script>` and `on*` handlers, and independent of the bundle.

If you cannot configure the web server and SVG hardening is a requirement, sanitize SVGs before they land in the source
directory — e.g. using [`enshrined/svg-sanitize`](https://github.com/darylldoyle/svg-sanitize) on upload.

## Validating uploaded images

The bundle does not validate uploaded files — that is the host project's job, best done with Symfony's built-in
[`Assert\Image`](https://symfony.com/doc/current/reference/constraints/Image.html) constraint, which already covers size,
dimensions, aspect ratio, MIME type, and corruption detection:

```php
use Symfony\Component\Validator\Constraints as Assert;

class Product
{
    #[Assert\Image(
        maxSize: '5M',
        maxWidth: 4000,
        maxHeight: 4000,
        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        detectCorrupted: true,
    )]
    public ?File $image = null;
}
```

The bundle's `max_source_bytes` and `max_width` are runtime safety nets, not replacements for proper upload validation.

## Image sources

Source images are read from named sources configured under `sources`. A path without a prefix uses the `default`
source (`%kernel.project_dir%/data` unless configured); `@name/path` reads from the source `name`:

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

The prefix stays part of the path everywhere: in URLs (`/_image/%40reference/screenshot.png/…`), in the HMAC signature
and in the cache directory (`public/_image/@reference/screenshot.png/`), so variants of `@reference/logo.png` and
`logo.png` never collide, and `image:purge '@reference/screenshot.png'` deletes exactly that source's variants.
`@default/photo.jpg` is normalized to `photo.jpg` on input, so both spellings share one URL and one cache directory.

Source names start with a letter, may contain letters, digits, `_` and `-`, and are case-sensitive. Source paths must
be absolute (a `%parameter%` counts). A path with an unknown source name behaves like a missing file: the page renders
without `height` and blur, the image URL returns 404. Every configured source is a public asset tree — see
[Security model](#security-model).

### Custom source implementations

Named sources are services implementing `ImageSourceInterface`; configured paths become `LocalFilesystemSource`
instances. To plug in a custom source (S3, Flysystem, …), tag your service with `id_sign_image.source` and a `name`:

```php
use IdSign\ImageBundle\Source\ImageSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('id_sign_image.source', ['name' => 'reference'])]
final class S3ReferenceSource implements ImageSourceInterface
{
    // ...
}
```

To use a custom implementation as the **default** source, re-point the `id_sign_image.source.default` alias:

```yaml
# config/services.yaml
services:
    id_sign_image.source.default: '@App\Image\S3Source'
```

Two pre-0.4 ways of configuring the source keep working: overriding the `$basePath` argument of
`IdSign\ImageBundle\Source\LocalFilesystemSource` still changes the default directory (and takes precedence over
`sources.default`), and replacing the `ImageSourceInterface` alias with your own service still works but bypasses
named sources — your implementation then receives the raw `@name/…` string.

## Cache management

By default, all commands run in dry-run mode (showing what would be deleted). Add `--force` to actually delete files.

### Purge all cached images

```bash
# Preview
php bin/console image:purge

# Delete
php bin/console image:purge --force
```

### Purge a specific source image

```bash
php bin/console image:purge uploads/photo.jpg --force

# Variants of an image from a named source
php bin/console image:purge @reference/logo.png --force

# Everything cached below a directory prefix or a whole source
php bin/console image:purge uploads/2024 --force
php bin/console image:purge @reference --force
```

### Purge by age

```bash
# Files not modified in 30 days
php bin/console image:purge --modified-before=30 --force

# Files not accessed in 14 days
php bin/console image:purge --accessed-before=14 --force

# Combine both
php bin/console image:purge --modified-before=30 --accessed-before=14 --force
```

### Upgrading from v0.3.0 or earlier

A leading `@` in `src` now selects a named source. A file of the default source whose first path segment starts with
`@` (`data/@scope/hero.jpg`, rendered as `src="@scope/hero.jpg"`) is read as a reference to a source named `scope` and
returns 404. Either rename the directory, or register it as that source —
`sources: { scope: '%kernel.project_dir%/data/@scope' }` — which keeps the existing URLs and cache entries valid
because `src`, signature and cache directory stay the same.

The default `device_sizes` gained `320` and `480`. Images rendered with a larger `width` get up to two new srcset
candidates per format; browsers on narrow viewports request them and they are generated on first request like any
other variant, so expect a small, one-off wave of cache misses. Set `device_sizes` explicitly to the previous list
(`[640, 750, 828, 1080, 1200, 1920, 2048, 3840]`) to keep the old markup.

Variant generation now locks the variant's directory instead of a `.lock` file next to each variant. `*.lock` files
left behind by earlier versions are unused; the next `image:purge --force` removes them. Large JPEG sources are decoded
at a reduced size when they are much larger than the output; dimensions are unchanged, so cached variants stay valid.

If you construct `ImagickProcessor`, `ImageMetadataReader` or `BlurPlaceholderGenerator` yourself rather than through
the container, their cache-directory and permission arguments are gone: the two readers take a `CacheStorageInterface`
and write `meta.json`/`blur.txt` atomically through it, the processor writes to the path it is given.

In controller mode the controller now checks the cache before the source, so a cache hit costs no source lookup. A
cached variant of a deleted source is served until `cache.ttl` expires or the cache is purged — the same behaviour
public mode has always had with `try_files`. Call `deleteBySource()` when you delete a source image.

A new `max_source_pixels` limit (default 64 million pixels) rejects source images above 64 MP before they are
decoded. Raise it or set it to `0` if you serve larger sources (medium-format photography, 200 MP phone modes). A
source above `max_source_bytes` or `max_source_pixels` now answers `413` instead of a server error, and a page that
renders it is no longer broken: it renders without `height` and blur, like for a missing source.

Sources with an embedded non-sRGB ICC profile — Display P3 (every iPhone photo), Adobe RGB, CMYK — and camera JPEGs
in Adobe RGB mode (EXIF `R03`, no profile) are now converted to sRGB before the metadata is stripped. Earlier
versions stripped the profile without converting, so their variants and blur placeholders of such sources have
washed-out colours. The URLs are unchanged, so purge them:
`image:purge <src> --force` per source, or `image:purge --force` for everything.

### Upgrading from v0.2.4 or earlier

Cached dimensions (`meta.json`) and blur placeholders (`blur.txt`) now follow EXIF orientation. Entries cached by an
older version keep the stored (unrotated) size of rotated photos, so purge once after upgrading — everything with
`php bin/console image:purge --force`, or only the affected sources with `image:purge <src> --force`.

Srcset breakpoints for `fit="contain"` and `fit="scale-down"` now round the box height up, so every candidate is
exactly as wide as its `w` descriptor. Their URLs changed; the old variants stay in the cache until `image:purge`.

### Programmatic cache invalidation

Inject `CacheStorageInterface` to invalidate cache from your application code:

```php
use IdSign\ImageBundle\Cache\CacheStorageInterface;

class ImageUploadHandler
{
    public function __construct(
        private readonly CacheStorageInterface $cacheStorage,
    ) {}

    public function onImageUpdated(string $src): void
    {
        $this->cacheStorage->deleteBySource($src); // e.g. 'uploads/photo.jpg'
    }
}
```

This deletes all cached variants (all sizes, formats, watermark combinations) for the given source image.

### Programmatic URL generation

Generate optimized image URLs from PHP code (e.g. for API responses, emails):

```php
use IdSign\ImageBundle\Service\ImageUrlGenerator;

class ImageApiController
{
    public function __construct(
        private readonly ImageUrlGenerator $imageUrlGenerator,
    ) {}

    public function getImageUrl(Request $request): JsonResponse
    {
        // Negotiate format from Accept header (AVIF > WebP > original)
        $url = $this->imageUrlGenerator->generateFromRequest($request, 'uploads/photo.jpg', 800, 600, 'cover');

        // Or specify format explicitly
        $url = $this->imageUrlGenerator->generate('uploads/photo.jpg', 800, 600, 'cover', 80, 'webp');

        return new JsonResponse(['image' => $url]);
    }
}
```

### Twig function `image_url()`

For contexts where you need a single image URL instead of a full `<picture>` tag — og tags, emails, JSON-LD, etc.:

```twig
{# Format negotiated from current request (AVIF > WebP > original) #}
{{ image_url('uploads/photo.jpg', 800) }}

{# Explicit format (best for og tags, emails — no content negotiation) #}
{{ image_url('uploads/photo.jpg', 800, format='webp') }}

{# Full control #}
{{ image_url('uploads/photo.jpg', 800, height=600, fit='cover', quality=90, format='avif', watermark='copyright') }}

{# Auto-calculate height from aspect ratio #}
{{ image_url('uploads/photo.jpg', 800, autoDimensions=true, format='webp') }}
```

| Parameter        | Type          | Required | Description                                                       |
|------------------|---------------|----------|-------------------------------------------------------------------|
| `src`            | string        | yes      | Source path, optionally `@source/`-prefixed                       |
| `width`          | int           | yes      | Output width in pixels                                            |
| `height`         | int           | no       | Output height (auto-calculated if `autoDimensions` enabled)       |
| `fit`            | string        | no       | `cover`, `contain`, or `scale-down`                               |
| `quality`        | int           | no       | Output quality 1-100 (default from config)                        |
| `format`         | string        | no       | Output format (`avif`, `webp`, `jpeg`, `png`). If omitted, negotiated from request |
| `watermark`      | string\|false | no       | Profile name, `false` to disable, omit for global default         |
| `autoDimensions` | bool          | no       | Auto-calculate height from aspect ratio (overrides global config) |
| `lossless`       | bool          | no       | Lossless encoding for WebP/AVIF output (overrides global config)  |

## Development

```bash
# Install dependencies
composer install

# Run tests
vendor/bin/phpunit

# Static analysis
vendor/bin/phpstan analyse

# Code style fix
vendor/bin/php-cs-fixer fix
```

### Docker test matrix

Run tests across all supported PHP versions using Docker:

```bash
make test-matrix           # Run tests on PHP 8.2, 8.3, 8.4, 8.5
make test                  # Run tests on PHP 8.4 (default)
make test PHP_VERSION=8.2  # Run tests on a specific PHP version
make phpstan               # Run PHPStan analysis
make cs-fix                # Run PHP CS Fixer (dry-run)
```

## License

MIT

`resources/sRGB2014.icc` is the International Color Consortium's sRGB profile (Copyright International Color
Consortium, 2015), distributed under the ICC's terms: it may be copied, distributed, embedded, made, used, and sold
without restriction; altered versions must have the original identification and copyright information removed.
`resources/AdobeCompat-v2.icc` (an Adobe RGB-compatible profile) and the ICC profiles embedded in the test fixtures
`tests/Fixtures/p3.jpg` and `tests/Fixtures/srgb-tagged.jpg` come from
[Compact-ICC-Profiles](https://github.com/saucecontrol/Compact-ICC-Profiles) (CC0 1.0).
