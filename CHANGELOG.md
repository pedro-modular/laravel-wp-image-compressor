# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-07-07

### Added
- Built-in web GUI in `image-compressor.php`: first-visit setup wizard (choose a
  password, no file editing), dashboard with preview/compress buttons, quality and
  max-size dropdowns, live streaming progress, per-backup Undo and Delete buttons.
- Automatic continuation on large sites: browser runs pause before the PHP time
  limit and resume themselves until every image is processed.
- `delete-backup` command (CLI and API).
- Web hardening: bcrypt-hashed password, session regeneration on login,
  CSRF-protected mutations, login throttling, and an auto-written `.htaccess`
  denying direct web access to the `.image-compressor/` state directory.

### Fixed
- `help` command printed nothing due to an off-by-one in the doc-block reader.

## [1.0.0] - 2026-07-07

### Added
- `image-compressor.php`: single-file PHP compressor (Imagick or GD) for shared
  hosting — token-protected browser endpoint, cron and CLI modes, optimization
  registry so re-runs never re-encode finished files.
- `compress-images.sh`: bash compressor for Ubuntu servers using `jpegoptim`,
  `pngquant`, `gifsicle`, `cwebp`, and ImageMagick.
- Shared feature set: Laravel/WordPress auto-detection, downscaling of oversized
  images with EXIF-orientation handling, full timestamped backups with manifest,
  one-command revert, never-grow-a-file guarantee, dry-run mode, and per-file plus
  total savings summaries.

[1.1.0]: https://github.com/OWNER/REPO/releases/tag/v1.1.0
[1.0.0]: https://github.com/OWNER/REPO/releases/tag/v1.0.0
