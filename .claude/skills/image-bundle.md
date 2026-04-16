---
name: image-bundle
description: Automatic image optimization for Symfony — responsive images, format conversion (AVIF/WebP), blur placeholders, watermarks, and caching. Use when optimizing images for web performance, generating responsive pictures, adding watermarks, or configuring image serve modes and cache in a Symfony project using ImageBundle.
---

# ImageBundle

Symfony bundle that optimizes images automatically. Generates `<picture>` tags with responsive `srcset`, modern
formats (AVIF/WebP), optional blur placeholders, and named watermark profiles. All URLs are HMAC-signed for security.

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
| `src`            | string        | yes      | Path relative to source directory                                                              |
| `width`          | int           | yes      | Intrinsic image width in px (not display size) — see "Picking width" below                     |
| `height`         | int           | no       | HTML attribute only (CLS prevention) — NOT used for processing unless `fit` is set             |
| `fit`            | string        | no       | `cover` (center crop), `contain`, or `scale-down` — required for `height` to affect processing |
| `blur`           | bool          | no       | Inline blur placeholder                                                                        |
| `quality`        | int           | no       | Output quality 1-100 (default from config)                                                     |
| `autoDimensions` | bool          | no       | Auto-calculate height from aspect ratio (overrides global config)                              |
| `watermark`      | string\|false | no       | Profile name, `false` to disable, omit for global default                                      |

All other attributes pass through to `<img>`: `alt`, `class`, `id`, `loading`, `sizes`, `data-*`, `aria-*`, etc.

Default: `decoding="async"`. No default `loading`.

### Picking width

`width` is the **intrinsic width of the generated image file**, not the rendered size on screen. It is required and
must be > 0 — omitting it (or passing `0`) throws `InvalidArgumentException` at render time, applies to SVG as well.
Pick a value that matches the largest pixel size the image will ever render at.

Watch out for:

- **`width` smaller than smallest `device_sizes` entry → no responsive srcset.** `SrcsetGenerator` only emits
  breakpoints ≤ `width`. Default smallest breakpoint is 640, so `width=500` produces a single-candidate srcset. On
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

SVG files render as plain `<img>` — no processing, no `<picture>`. Served via `route_prefix` (copied to cache), no HMAC.

## Configuration

```yaml
# config/packages/id_sign_image.yaml
id_sign_image:
    device_sizes: [ 640, 750, 828, 1080, 1200, 1920, 2048, 3840 ]
    default_quality: 80
    formats: [ 'avif', 'webp' ]
    cache:
        ttl: 2592000
        path: '%kernel.project_dir%/public/_image'
    blur:
        enabled: false
        size: 10
        quality: 30
    default_watermark: ~
    watermarks:
        copyright:
            path: '%kernel.project_dir%/data/watermark.png'
            position: 'bottom-right'    # 9 positions: top-left, top-center, top-right, center-left, center, center-right, bottom-left, bottom-center, bottom-right
            opacity: 50
            size: 20                    # % of output image width
            margin: 10                  # pixels from edge
    auto_dimensions: false
    file_permissions: 0660          # permissions for cache files (null = umask default)
    directory_permissions: 0770     # permissions for cache directories
    tmp_dir: ~
    serve_mode: 'public'
    route_prefix: '/_image'
```

### Image source

Default source directory: `%kernel.project_dir%/data`. Override:

```yaml
# config/services.yaml
IdSign\ImageBundle\Source\LocalFilesystemSource:
    arguments:
        $basePath: '%kernel.project_dir%/public/uploads'
```

Custom source (S3, Flysystem): implement `ImageSourceInterface` and register as alias.

### Routes

```yaml
# config/routes/id_sign_image.yaml
id_sign_image:
    resource: '@IdSignImageBundle/config/routes.yaml'
```

## Serve modes

**Public (default):** Web server serves cached files via `try_files`. PHP only on first request per variant.

```nginx
location /_image/ { try_files $uri @symfony; }
```

**Controller:** Every request through PHP. Enables TTL-based invalidation and access control.

## URL security

Raster URLs are HMAC-signed. Tampered parameters → 403. SVG URLs have no signature (no parameters to vary).

## Cache management

```bash
php bin/console image:purge                                    # dry-run preview
php bin/console image:purge --force                            # purge all
php bin/console image:purge uploads/photo.jpg --force          # purge specific image
php bin/console image:purge --modified-before=30 --force       # older than 30 days
php bin/console image:purge --accessed-before=14 --force       # not accessed in 14 days
```

### Twig function `image_url()`

For single URL generation in Twig (og tags, emails, JSON-LD):

```twig
{# Negotiate format from request #}
{{ image_url('uploads/photo.jpg', 800) }}

{# Explicit format #}
{{ image_url('uploads/photo.jpg', 800, format='webp') }}

{# All options #}
{{ image_url('uploads/photo.jpg', 800, height=600, fit='cover', quality=90, format='avif', watermark='copyright', autoDimensions=true) }}
```

Parameters mirror component props + `format` (string, optional — if omitted, negotiated from request Accept header).

### Programmatic URL generation (PHP)

```php
// Inject ImageUrlGenerator, then:
$url = $imageUrlGenerator->generateFromRequest($request, 'uploads/photo.jpg', 800, 600, 'cover');
$url = $imageUrlGenerator->generate('uploads/photo.jpg', 800, 600, 'cover', 80, 'webp');
```

### Programmatic invalidation

```php
// Inject CacheStorageInterface, then:
$cacheStorage->deleteBySource('uploads/photo.jpg'); // deletes all raster variants
$cacheStorage->deleteBySource('icons/logo.svg');    // deletes cached SVG file
```
