# WebP Express AVIF

Adds AVIF to WebP Express picture tags and varied image responses. WebP Express
remains responsible for its existing settings and WebP fallback.

## Requirements

Install this as a separate plugin in `wp-content/plugins/webp-express-avif` and
activate it alongside WebP Express. The standard WebP Express plugin directory
must remain a sibling directory named `webp-express` so the standalone AVIF
realizer can load WebP Express's WOD classes.

- WebP Express must be active.
- WebP Express must be configured to alter HTML using picture tags or to use
  "Varied image responses".
- An active Imagick or GD converter must be enabled in WebP Express, and the
  server's corresponding PHP extension must support AVIF encoding.
- PHP 8.3 or newer is required.

## Quality settings

The WordPress Settings menu includes a WebP Express AVIF page with independent
JPEG-source and PNG-source AVIF quality controls (0-100). JPEG quality defaults
to 65 and PNG quality defaults to 70. These settings do not change WebP
Express's own quality options. Lower values generally reduce AVIF file size;
AVIF generated from PNG sources may be lossy. Saving changed quality values
clears cached AVIF files so later requests regenerate them with the new quality.
When both are active, GD is preferred over Imagick for AVIF because some
Imagick versions ignore AVIF compression-quality settings.
On multi-server installs with separate local cache directories, purge each
server's AVIF cache; CDN caches may also need to be purged separately.
The response-headers setting controls additional quality, encoder, size, and
savings diagnostics for AVIF and PHP-served WebP responses. It is disabled by
default; AVIF status and WebP Express's own serving/conversion headers remain
available when it is off.
The page also provides live checks for the existing-AVIF varied response,
source-to-converter, and picture-tag realizer routes. They require JPEG images
in the uploads scope and the corresponding WebP Express redirection settings.
The checks validate the HTTP response; a load-balanced site may create a
derivative on a different server than the one running the test.

## How it works

In picture-tag mode, the plugin adds an `image/avif` source before WebP Express's
`image/webp` source. In "Varied image responses" mode, a request for an original
JPEG or PNG from an AVIF-capable browser is served as AVIF; other browsers retain
WebP Express's existing WebP/original response. AVIF files are generated on the
first request and stored beside the equivalent WebP destination, following WebP
Express's scope, destination folder, extension, and structure settings.
For mingled destinations, the AVIF extension is appended to the WebP filename
(for example, `image.jpg.webp.avif`) to avoid overwriting existing AVIF images.

Apache serves existing varied-response AVIF cache files directly, following
WebP Express's cache-first delivery pattern. When the larger-file safeguard is
enabled, AVIFs are size-checked before they are moved into the cache, so any
cached AVIF is safe to serve. Switching the safeguard on purges the AVIF cache,
so AVIFs made without the check are regenerated.

Two markers next to the AVIF destination let later varied requests continue
through WebP Express's own rules instead of retrying AVIF conversion in PHP:
`.we-avif-too-large` when the AVIF was at least as large as its source, and
`.we-avif-failure` when conversion failed. The failure marker records the time,
source, encoder and reason. Sources that WebP Express itself has recorded as
producing larger WebPs are handed back too. A cached WebP or original can then be
served directly; a WebP cache miss may still use WebP Express's PHP converter.
Markers are removed when the source is re-uploaded or deleted, on bulk
conversion, and by any AVIF cache purge, after which conversion is retried.

Cache misses route to this plugin's standalone realizer, following WebP
Express's own `.htaccess` realizer pattern. The realizer loads WebP Express's
saved WOD settings without bootstrapping WordPress, checks the requested
destination against WebP Express's image roots and scope, and converts only the
corresponding JPEG or PNG source. Picture-tag cache-miss rules are installed in
the WebP Express cache directory and, for mingled uploads, the uploads
directory. Varied-response rules are installed before WebP Express's rules in
each selected source image root, so AVIF negotiation takes precedence only for
requests advertising `image/avif`. The rules use separate marker blocks and are
refreshed from the WebP Express settings page; WebP Express can regenerate its
own rules without removing the AVIF rules. Deactivating this plugin removes its
marked rewrite and MIME blocks without deleting generated AVIF files.
For uploads using image-roots, the helper also adjusts WebP Express's generated
on-demand rule when Apache passes an absolute source path, so the converter
receives a path relative to the uploads root without requiring a core-plugin
edit.
AVIF responses include an `X-WebP-Express-AVIF` header worded like WebP
Express's own `X-WebP-Express` header:

- `Redirected directly to existing avif`: served by Apache from the cache.
- `Served existing avif by realizer`: a cached AVIF served by the realizer.
- `Converted to avif by realizer`: converted and served on this request.

When additional diagnostic headers are enabled, realizer responses also include
`X-WebP-Express-AVIF-Quality`, source/output byte counts, and a size-savings
percentage, and newly converted responses include `X-WebP-Express-AVIF-Encoder`
(`gd` or `imagick`). These record what the encoder actually produced, so they
are not sent on responses Apache serves directly.

WebP responses also receive diagnostics from the helper plugin. The helper
routes WebP Express's PHP realizer requests through its own response wrapper,
which calls the existing WebP Express request handlers without changing core
plugin files. When additional diagnostic headers are enabled, PHP-served WebPs
include `X-WebP-Express-WebP-Quality`, source/output byte counts, and savings;
PNG responses also include `X-WebP-Express-WebP-Alpha-Quality`. WebP Express's own
`X-WebP-Express` and `X-WebP-Convert-Log` headers indicate its serving or
conversion path regardless of this option. Quality and size metrics are
unavailable for WebPs served directly from the cache. A quality value of
`auto` reports WebP Express's configured mode, not a per-image quality chosen
internally by a converter.

After upgrading from a version that generated AVIF files without public-read
permissions, use WebP Express's "Delete converted files" button once so the
files can be regenerated with the corrected permissions.

When WebP Express's convert-on-upload setting is enabled, this plugin also
generates AVIF files for eligible uploaded images and intermediate sizes.
Otherwise, AVIF files are generated only when requested. Existing derivatives
are removed when WordPress deletes their source image. WebP Express's cache
purge action also removes generated AVIF derivatives, and the purge result
reports WebP and AVIF deletion/failure counts separately.
The WebP Express bulk-convert action also generates AVIF for each eligible
image it converts. When WebP Express logging is enabled, AVIF results are
appended to the corresponding conversion log.

AVIF encoding follows WebP Express's active converter order, but only Imagick
and GD are supported because the bundled WebP converters do not produce AVIF.
The WebP Express metadata setting and the option that prevents using converted
images larger than their source are also applied to AVIF. The configured
Cache-Control header is applied to successful AVIF responses. If the configured
PHP encoder cannot create AVIF, picture-tag requests fall back to their WebP
equivalent, while varied-response requests use WebP Express's own WebP serving
path. As with WebP Express, some CDNs may not handle varied responses correctly;
see its FAQ.

The plugin installs Apache mod_rewrite and `image/avif` MIME rules alongside
the realizer route. Apache with mod_rewrite and permission to update the
relevant `.htaccess` files is required for on-demand cache-miss conversion. On
servers that do not use Apache, equivalent rewrite and MIME rules must be
configured manually; the standalone realizer is not automatically wired into
Nginx.

Picture-tag source additions require WebP Express's picture-tag replacement
mode. Varied-response negotiation works independently of HTML alteration when
that operation mode is selected. Both features are disabled in no-conversion
mode.

WebP Express settings saves, including forced rewrite-rule updates, refresh this
plugin's separate Apache rewrite blocks without replacing WebP Express's rules.
The WebP Express cache purge and AVIF quality-change purge also remove the
helper's AVIF cache-validation and size-rejection markers.
