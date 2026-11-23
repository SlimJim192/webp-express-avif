# Changelog

## 1.0.5 - 2026-11-23

- Serve cached AVIF files directly through Apache for varied image responses.
  Use a size-rejection marker to let WebP Express handle subsequent requests
  for sources whose AVIF would be larger, and clear cache markers when AVIF
  files are purged.
- Size-check AVIFs before moving them into the cache, so a cached AVIF can be
  served directly, and purge the AVIF cache when WebP Express's "prevent
  larger" setting is switched on.
- Record AVIF conversion failures in a `.we-avif-failure` marker (time, source,
  encoder and reason) so Apache hands later requests to WebP Express instead of
  retrying conversion in PHP. Markers are cleared on success, upload, deletion,
  bulk conversion and cache purge.
- Convert palette PNGs to truecolor before GD AVIF encoding, which libgd
  otherwise rejects.
- Build direct AVIF rewrite targets from RewriteCond captures, fixing 404s on
  servers that pass absolute paths to per-directory RewriteRule patterns.
- Replace `X-WebP-Express-AVIF-Status` (`existing`/`converted`) with
  `X-WebP-Express-AVIF`, worded like WebP Express's `X-WebP-Express` header:
  "Redirected directly to existing avif", "Served existing avif by realizer" or
  "Converted to avif by realizer".
- Set that header on directly served AVIFs from the cache directory's
  `.htaccess`, as WebP Express does for WebP. Diagnostic headers (quality,
  encoder, sizes) are only sent by the realizer on the request that converts
  the image.
- Hand requests to WebP Express when its own bigger-than-source marker exists,
  so those originals are served by Apache rather than PHP.

## 1.0.4 - 2026-11-18

- Purge cached AVIF files and refresh rewrite rules when AVIF quality settings
  change, so subsequent conversions use the selected quality.
- Add an AVIF status header and optional quality, encoder, source/output size,
  and file-savings headers.
- Add optional quality and size diagnostics to PHP-served WebP responses
  through a helper-owned wrapper, without modifying WebP Express core files.
- Add a disabled-by-default setting for additional diagnostics, while keeping
  AVIF status and WebP Express's own serving/conversion headers available.
- Prefer GD over Imagick when both AVIF encoders are active, and report the
  encoder used for new conversions.

## 1.0.3 - 2026-11-18

- Prevent duplicate or colliding AVIF rewrite and MIME blocks, while cleaning
  up blocks created by earlier versions.
- Adjust the generated uploads converter rule for image-root setups where
  Apache supplies an absolute source path, without modifying WebP Express core.
- Correct the AVIF check for WebP files larger than their source.
- Improve AVIF live-test diagnostics and validate responses on load-balanced
  sites.

## 1.0.2 - 2026-11-17

- Add AVIF live tests for cached varied responses, on-demand conversion, and the
  picture-tag realizer.

## 1.0.1 - 2026-11-17

- Integrate AVIF generation and logging with WebP Express bulk conversion and
  logging settings.
- Support AVIF content negotiation in WebP Express's "Varied image responses"
  operation mode, with WebP Express handling WebP fallback conversion.
- Refresh AVIF rewrite rules after settings saves, including forced updates.
- Remove the plugin's marked rewrite and MIME blocks on deactivation.
- Honor the configured Cache-Control header for successful AVIF responses.

## 1.0.0 - 2026-11-17

First release.

- Add AVIF sources to WebP Express picture tags, with WebP and original-image
  fallbacks.
- Support on-demand and upload-time AVIF conversion through Imagick or GD,
  using WebP Express conversion settings where applicable.
- Add independent JPEG- and PNG-source AVIF quality settings.
- Integrate AVIF derivatives with WebP Express cache purging and WordPress
  image deletion.
- Install Apache rewrite and MIME rules for on-demand conversion.
