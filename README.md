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
    device_sizes: [ 640, 750, 828, 1080, 1200, 1920, 2048, 3840 ]

    # Default output quality (1-100)
    default_quality: 80

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

    # Permissions for created cache files and directories
    file_permissions: 0660        # null = use umask default
    directory_permissions: 0770

    # Temporary directory for image processing (defaults to sys_get_temp_dir())
    tmp_dir: ~

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

## Component attributes

| Attribute        | Type          | Required | Description                                                                  |
|------------------|---------------|----------|------------------------------------------------------------------------------|
| `src`            | string        | yes      | Path to source image (relative to source directory)                          |
| `width`          | int           | yes      | Display width in pixels                                                      |
| `height`         | int           | no       | Display height (auto-calculated if `auto_dimensions` enabled)                |
| `fit`            | string        | no       | Resize mode: `cover`, `contain`, or `scale-down`                             |
| `blur`           | bool          | no       | Show blur placeholder (overrides global setting)                             |
| `quality`        | int           | no       | Output quality (overrides `default_quality`)                                 |
| `autoDimensions` | bool          | no       | Auto-calculate height from aspect ratio (overrides global `auto_dimensions`) |
| `watermark`      | string\|false | no       | Watermark profile name, `false` to disable, omit for global default          |

All other attributes (`alt`, `class`, `id`, `loading`, `data-*`, `aria-*`, etc.) are passed through to the `<img>` tag.

### `src`

Path to the source image relative to the configured source directory (default `data/`). The file must exist on the
filesystem. For SVG files, no processing is applied — the image is served directly.

### `width`

The display width of the image in pixels. This value is used for the `width` HTML attribute (CLS prevention), as the
main size in srcset, and as the upper bound for responsive breakpoints.

### `height`

The display height in pixels. When omitted and auto dimensions is enabled (globally via `auto_dimensions` config or
per-component via `autoDimensions` prop), the height is automatically calculated from the source image's aspect ratio.
When both `width` and `height` are provided with a `fit` mode, the server produces an image with exactly these
dimensions.

### `autoDimensions`

Overrides the global `auto_dimensions` config for this specific component instance. When `true`, height is
auto-calculated from the source aspect ratio (if `height` is not provided). When `false`, auto-calculation is disabled
even if the global config is enabled. When omitted (`null`), the global config value is used.

### `fit`

Controls how the image is resized when both `width` and `height` are specified:

- **`cover`** — fills the target dimensions, cropping any overflow. Crop is always centered. For custom crop
  positioning, omit `fit` and use CSS `object-fit` + `object-position` instead — this gives full control over the
  visible area in the browser.
- **`contain`** — fits within the target dimensions, preserving aspect ratio (may leave empty space)
- **`scale-down`** — same as `contain`, but never upscales images smaller than the target

When omitted, the image is resized to the exact dimensions, which may distort the aspect ratio.

### `blur`

Enables an inline blur placeholder. A tiny 10px-wide JPEG thumbnail is base64-encoded and rendered as a CSS
`background-image` with `filter: blur(20px)`. Once the full image loads, the placeholder is removed via `onload`. The
placeholder is generated on-demand and cached on disk.

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

### Controller mode

Every image request goes through PHP. Enables TTL-based cache invalidation -- expired variants are regenerated on the
next request. Use this when you need access control or TTL-based refresh.

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

## URL security

All image URLs are signed with HMAC-SHA256 derived from `kernel.secret`. The signature is part of the URL path, so any
modification of parameters (width, height, quality, etc.) results in a `403 Forbidden` response. This prevents cache
exhaustion attacks where an attacker generates arbitrary image variants.

## Image source

By default, source images are loaded from `%kernel.project_dir%/data`. To change this, override the service:

```yaml
# config/services.yaml
services:
    IdSign\ImageBundle\Source\LocalFilesystemSource:
        arguments:
            $basePath: '%kernel.project_dir%/public/uploads'
```

For custom sources (S3, Flysystem, etc.), implement `ImageSourceInterface` and register it:

```yaml
services:
    IdSign\ImageBundle\Source\ImageSourceInterface:
        class: App\Image\MyCustomSource
```

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
