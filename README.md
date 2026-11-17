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
AVIF generated from PNG sources may be lossy. After changing either value, use
WebP Express's "Delete converted files" button so cached AVIF files are
regenerated with the new quality.
The page also provides live checks for the existing-AVIF varied response,
source-to-converter, and picture-tag realizer routes. They require JPEG images
in the uploads scope and the corresponding WebP Express redirection settings.

## How it works

In picture-tag mode, the plugin adds an `image/avif` source before WebP Express's
`image/webp` source. In "Varied image responses" mode, a request for an original
JPEG or PNG from an AVIF-capable browser is served as AVIF; other browsers retain
WebP Express's existing WebP/original response. AVIF files are generated on the
first request and stored beside the equivalent WebP destination, following WebP
Express's scope, destination folder, extension, and structure settings.
For mingled destinations, the AVIF extension is appended to the WebP filename
(for example, `image.jpg.webp.avif`) to avoid overwriting existing AVIF images.

Apache cache-miss rules route AVIF requests directly to this plugin's standalone
realizer, following WebP Express's own `.htaccess` realizer pattern. The realizer
loads WebP Express's saved WOD settings without bootstrapping WordPress, checks
the requested destination against WebP Express's image roots and scope, and
converts only the corresponding JPEG or PNG source. Picture-tag cache-miss rules
are installed in the WebP Express cache directory and, for mingled uploads, the
uploads directory. Varied-response rules are installed before WebP Express's
rules in each selected source image root, so AVIF negotiation takes precedence
only for requests advertising `image/avif`. The rules use separate marker blocks
and are refreshed from the WebP Express settings page; WebP Express can
regenerate its own rules without removing the AVIF rules. Deactivating this
plugin removes its marked rewrite and MIME blocks without deleting generated
AVIF files.

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
