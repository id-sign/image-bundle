# Upgrading

What an application has to check or change when it upgrades `id-sign/image-bundle`, newest version first. Each
section covers an upgrade from the named version or an earlier one — read every section between your version and the
one you upgrade to.

## From v0.4.0 or earlier

### Security

A number with a leading zero in a signed URL (`_0800_` for `_800_`) verified like the original and was encoded and
cached as another file, so a single public image URL could fill the disk. Such URLs — the bundle never generates them
— now answer 400, as does a width, height or quality of `0`, which earlier versions put into a URL when `0` was passed.
Variants already created that way stay on disk until the next `image:purge`.

### Configuration values that now fail the container build

These used to pass and break only when an image was rendered or requested:

- `default_quality`, `format_quality` or `blur.quality` outside 1-100.
- `formats` with anything but `avif`, `webp`, `jpeg` and `png`, or with a format twice — `jpg`, `AVIF` or `gif`
  rendered a `<source>` whose URLs answered 400.
- `device_sizes` with a breakpoint below 1 or listed twice — a `0` rendered `0w`, which makes browsers drop the whole
  srcset.
- A `default_watermark` that names no profile in `watermarks` — every image URL answered 400.
- A watermark profile name with characters other than letters, digits, `_` and `-`, a relative watermark `path`, a
  watermark `size` outside 1-100 or a negative `margin`.
- `blur.size` outside 1-64.
- A negative `cache.ttl`. `0` stays valid: in controller mode it re-encodes on every request (debugging only).
- A relative or empty `cache.path` — under PHP-FPM it resolved against `public/`, so a cache meant to be private was
  served without the firewall.
- `file_permissions` or `directory_permissions` that are negative or do not give the owner read/write (files) or
  read/write/execute (directories). Write `0o660`: `660` is decimal, and the `0660` of earlier READMEs is a string in
  YAML and never built.
- A `route_prefix` that does not start with `/`, ends with one, has a `.` or `..` segment or characters other than
  letters, digits and `._~-`.
- A cache directory (`cache.path`, or the new default `public/<route_prefix>`) that is a source directory or contains
  one — `image:purge` empties the cache directory and would delete the source images.

### Configuration merging and environment variables

A `formats` or `device_sizes` list in a later config file (e.g. `when@prod`) now replaces the earlier one. It used to
be appended (`[avif, webp]` + `[webp]` gave `[avif, webp, webp]`), so a file that relied on appending must list every
value it wants.

Watermark profile names are kept verbatim. Symfony used to turn `brand-logo` into `brand_logo`, so
`default_watermark: brand-logo` and `watermark="brand-logo"` answered 400 while `brand_logo` worked. If your templates
use the underscore form of a dashed profile name, rename the profile to that form or change the templates — an unknown
name in `default_watermark` fails the build, an unknown `watermark` attribute still answers 400 per image. A profile
defined with a dash and partially overridden in another config file under its underscore form (`brand-logo` in the
base file, `brand_logo` in `when@prod`) no longer merges into one profile: the override is a new profile, which fails
the build for its missing `path`. Use the same spelling in every file.

Options set through `%env()%` build — as in v0.4, and now also `sources` and `max_width`, which did not; their values
are checked only when written literally. A permission mode from `%env(int:…)%` is decimal: `432` for 0o660, `504` for
0o770. The exception is `route_prefix`: it is now the path of the bundle route and of the default cache directory,
both fixed when the container is built, so an `%env()%` value fails the build with that explanation.

### Route and cache directory

The image route now takes its path from `route_prefix`. The custom-prefix recipe of earlier READMEs — importing the
routes with `prefix: /media/images` — produced the route `/media/images/_image/{path}` while the URLs were
`/media/images/…`, so every uncached image answered 404. Drop the `prefix:` from the import and keep `route_prefix`.
If you worked around it with `route_prefix: '/media/images/_image'` and the import prefix, drop the import prefix and
keep that `route_prefix`.

Without an explicit `cache.path`, a custom prefix now caches under `public/<route_prefix>` (where `try_files` looks)
instead of `public/_image`; the old directory can be deleted. That directory belongs to the bundle: `image:purge`
empties it, files of your own included — with `route_prefix: /images` over a `public/images/` that holds your static
images, move either the images or the prefix.

### Templates, `image_url()` and `ImageUrlGenerator`

A `fit` other than `cover`, `contain` or `scale-down`, or a `height` below 1, now throws an `InvalidArgumentException`
in the component, `image_url()` and `ImageUrlGenerator`. It used to render URLs that answered 400 (`fit="none"`: 403),
so every `<source>` of the image was broken without an error anywhere. A height calculated from the aspect ratio is at
least 1 px (a 1200×1 separator at 400 px rounded to 0).

A `quality` outside 1-100 passed to the component, `image_url()` or `ImageUrlGenerator` throws an
`InvalidArgumentException` instead of producing a URL.

`ImageUrlGenerator::generate()` and `generateFromRequest()` without a `quality` argument used a hard-coded `80`; they
now use the configured quality of the format (`format_quality`, then `default_quality`), like `<twig:Image>` and
`image_url()`. With `default_quality: 80` and no `format_quality` nothing changes; otherwise those URLs change once.

### PHP API

`ImageComponent` and `ImageUrlExtension` take a `QualityResolver` in place of `int $defaultQuality`, and
`ImageUrlGenerator` takes one after the `FormatNegotiator`. This matters only if you construct them yourself or
override their service definitions: a definition that binds `$defaultQuality` no longer compiles — drop the binding
and set `default_quality` / `format_quality` in the bundle configuration instead.

## From v0.3.0 or earlier

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

Formats, URLs and responses:

- The controller generates only the configured `formats` plus the source's fallback format; a URL with another
  extension answers `404` (it used to produce the variant), and `.jpg` is no longer accepted as a variant extension
  (the bundle always generated `.jpeg`).
- `image_url(format=…)` and `ImageUrlGenerator::generate()` throw an `InvalidArgumentException` for a format the
  controller would not serve — the typical hit is an `og:image` requested as `jpeg` from a WebP or PNG source, switch
  it to `webp` (see [`image_url()`](README.md#twig-function-image_url)) — and without a request they return the
  source's fallback format when `webp` is not configured (previously always `.webp`).
- A multi-frame source (GIF, APNG, animated WebP, multi-page TIFF) is now processed from its first frame — it used to
  be the last — and all frames count towards `max_source_pixels`.
- `image_url()` and `ImageUrlGenerator` return the passthrough URL for an `.svg` source instead of a signed raster URL.
- Responses carry an explicit `Content-Type` (previously guessed through `symfony/mime`, which the bundle never
  required), and SVG responses from the controller add `Content-Security-Policy: sandbox` and
  `X-Content-Type-Options: nosniff`.
- `ImageUrlGenerator::generate()` and `generateFromRequest()` now follow the component's rules: a `height` without
  `fit` is no longer part of the URL (such URLs used to resize to the exact box and distort the image — the new URL is
  a new cache variant), and a `width` above `max_width` or below 1 throws.
- A leading slash in `src` is dropped like `@default/`, so `/photo.jpg` and `photo.jpg` share one URL; a `src` with a
  leading slash used to produce `/_image//…` URLs that answer 403 behind nginx.
- If a custom `ImageSourceInterface` enforced per-image authorization in `exists()`, move it in front of the route —
  see [Security model](README.md#security-model).

## From v0.2.4 or earlier

Cached dimensions (`meta.json`) and blur placeholders (`blur.txt`) now follow EXIF orientation. Entries cached by an
older version keep the stored (unrotated) size of rotated photos, so purge once after upgrading — everything with
`php bin/console image:purge --force`, or only the affected sources with `image:purge <src> --force`.

Srcset breakpoints for `fit="contain"` and `fit="scale-down"` now round the box height up, so every candidate is
exactly as wide as its `w` descriptor. Their URLs changed; the old variants stay in the cache until `image:purge`.
