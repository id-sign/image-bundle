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

| Prop        | Type          | Required | Description                                                   |
|-------------|---------------|----------|---------------------------------------------------------------|
| `src`       | string        | yes      | Path relative to source directory                             |
| `width`     | int           | yes      | Display width in pixels                                       |
| `height`    | int           | no       | Display height (auto-calculated if `auto_dimensions` enabled) |
| `fit`       | string        | no       | `cover` (center crop), `contain`, or `scale-down`             |
| `blur`      | bool          | no       | Inline blur placeholder                                       |
| `quality`   | int           | no       | Output quality 1-100 (default from config)                    |
| `watermark` | string\|false | no       | Profile name, `false` to disable, omit for global default     |

All other attributes pass through to `<img>`: `alt`, `class`, `id`, `loading`, `sizes`, `data-*`, `aria-*`, etc.

Default: `decoding="async"`. No default `loading`.

### Output

Generates `<picture>` with `<source>` per configured format + `<img>` fallback:

```html

<picture>
    <source type="image/avif" srcset="/_image/.../800_600_cover_80.avif 800w, ..." sizes="100vw">
    <source type="image/webp" srcset="/_image/.../800_600_cover_80.webp 800w, ..." sizes="100vw">
    <img src="/_image/.../800_600_cover_80.jpeg" width="800" height="600" alt="..." decoding="async"/>
</picture>
```

SVG files render as plain `<img>` — no processing, no `<picture>`.

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

All URLs are HMAC-signed. Tampered parameters → 403.

## Cache management

```bash
php bin/console image:purge                                    # dry-run preview
php bin/console image:purge --force                            # purge all
php bin/console image:purge uploads/photo.jpg --force          # purge specific image
php bin/console image:purge --modified-before=30 --force       # older than 30 days
php bin/console image:purge --accessed-before=14 --force       # not accessed in 14 days
```

### Programmatic URL generation

```php
// Inject ImageUrlGenerator, then:
$url = $imageUrlGenerator->generateFromRequest($request, 'uploads/photo.jpg', 800, 600, 'cover');
$url = $imageUrlGenerator->generate('uploads/photo.jpg', 800, 600, 'cover', 80, 'webp');
```

### Programmatic invalidation

```php
// Inject CacheStorageInterface, then:
$cacheStorage->deleteBySource('uploads/photo.jpg'); // deletes all variants
```
