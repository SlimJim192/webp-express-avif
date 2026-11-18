# Changelog

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
