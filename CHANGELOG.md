# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-07-07

### Security
- **Config file no longer leaks on nginx.** The stored bcrypt password hash and
  API token now live in a self-guarding `config.php` that returns `403 Forbidden`
  on any direct web request, instead of a world-readable `config.json`. A
  `web.config` (IIS) is written alongside the existing `.htaccess`.
- **Dedicated API token for automation.** Cron/scripts now authenticate with a
  separate randomly-generated token (not the login password), sent via an
  `Authorization: Bearer` header so it stays out of server access logs. The
  dashboard shows a ready-to-copy cron command. `?token=` in the URL still works
  but is discouraged.
- **Session & CSRF hardening.** Session cookies are now `HttpOnly`,
  `SameSite=Strict`, and `Secure` on HTTPS; logout is CSRF-guarded so a forged
  link cannot force a sign-out.
- **Symlink-escape protection.** The scanner no longer follows symlinks, and each
  file's real path is confirmed to be inside the scanned tree before it is
  backed up or overwritten. Temp files use `tempnam()` instead of a predictable
  name.
- **Bash `--backup` validation.** `compress-images.sh revert` now rejects any
  `--backup` value that is not a `latest`/timestamp, closing a path-traversal gap.

## [1.2.0] - 2026-07-07

### Security
- **Proof-of-ownership setup gate (fixes installer-claim race).** First-time web
  setup now requires the operator to create a sentinel file
  (`image-compressor-ALLOW-SETUP.txt`) next to the script before any password can
  be set. Creating a file requires filesystem access, which a remote visitor
  scanning the URL does not have — so the first anonymous visitor can no longer
  claim the tool. The sentinel is deleted automatically once setup completes.
- **Content-based image validation (fixes ImageTragick / delegate-abuse class).**
  Both the PHP and bash tools now decide what to decode from a file's actual
  magic bytes (`getimagesize()` / `file --mime-type`), never its extension. A
  malicious MVG/MSL/SVG/PostScript payload disguised as `.jpg` is rejected before
  it ever reaches Imagick/ImageMagick. Extension/content mismatches are skipped.
- **Decompression-bomb guard.** Images above `MAX_MEGAPIXELS` (100 MP) are
  skipped, and Imagick decodes run under explicit memory/area/disk resource
  limits. The ImageMagick input coder is pinned to the validated format so it
  cannot be steered into a delegate-backed decoder.

### Fixed
- The web dashboard no longer errors out on a project whose expected image
  folder (e.g. Laravel `public/`) does not exist yet; it now renders with a
  "no image folder found here yet" note.

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

## 1.0.0 - 2026-07-07 (pre-release, unpublished)

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

[1.3.0]: https://github.com/pedro-modular/laravel-wp-image-compressor/releases/tag/v1.3.0
[1.2.0]: https://github.com/pedro-modular/laravel-wp-image-compressor/releases/tag/v1.2.0
[1.1.0]: https://github.com/pedro-modular/laravel-wp-image-compressor/releases/tag/v1.1.0
