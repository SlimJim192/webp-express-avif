# Changelog

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
