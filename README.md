# Image Compressor for Laravel & WordPress

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777bb4)
![Ubuntu](https://img.shields.io/badge/Ubuntu-20.04%2B-e95420)

Open-source, plug-and-play image compression for Laravel and WordPress projects. Every file is backed up before it is touched, results are summarized, and any run can be fully reverted. Works on shared hosting (cPanel) through a built-in web GUI, and on your own servers through the CLI.

Two interchangeable flavors — same commands, same backup format, same safety rules:

| | `image-compressor.php` | `compress-images.sh` |
|---|---|---|
| Best for | **Shared hosting / cPanel** (and anywhere PHP runs) | VPS / dedicated Ubuntu servers with root |
| Requirements | PHP 8.0+ with Imagick **or** GD (present on any Laravel/WP host) | `apt-get install jpegoptim pngquant gifsicle webp imagemagick` |
| Runs from | Browser URL, cPanel cron, SSH/CLI | SSH/CLI, cron |
| JPEG / WebP | Excellent (Imagick) / very good (GD) | Excellent (`jpegoptim`, `cwebp`) |
| PNG | Very good with Imagick (lossy quantization), modest with GD | Best-in-class (`pngquant`) |
| GIF | Left untouched (animation-safe) | Optimized losslessly (`gifsicle`) |
| Re-run safety | Remembers optimized files — **zero generational quality loss** | Idempotent via quality caps |

**Rule of thumb:** on cPanel/shared hosting use the PHP version; on your own Ubuntu server use the bash version (or the PHP one — it's nearly as good with Imagick).

## What both do

- **Auto-detect the project type**: Laravel → `public/` + `storage/app/public/`; WordPress → `wp-content/uploads/`; anything else → the given path recursively.
- **Compress** JPEG (progressive, metadata stripped, quality-capped at 82), PNG (lossy palette quantization), WebP (re-encode).
- **Downscale oversized images** — anything with an edge longer than 2000px (`--max-dim`) is resized down, preserving aspect ratio and EXIF orientation. Never upscaled.
- **Never make a file bigger** — if compression doesn't help, the original is kept untouched.
- **Back up every modified file** to `<project>/.image-compressor/backups/<timestamp>/` with a manifest, before changing anything.
- **Revert** any backup with one command (verified byte-identical restore).
- **Summarize** per-file and total savings at the end of every run.
- Always exclude `vendor/`, `node_modules/`, `.git/` and the backup folder itself.

---

## PHP version — cPanel / shared hosting / anywhere

### Option A: from the browser — built-in GUI (for everyone, including non-technical users)

1. Upload `image-compressor.php` to the project root (next to `artisan` or `wp-config.php`) using cPanel **File Manager**.
2. Visit `https://your-site.com/image-compressor.php`. The setup wizard first asks you to prove you own the site by creating an empty file named `image-compressor-ALLOW-SETUP.txt` in the same folder (File Manager → **+ File**). This one-time step stops anyone else on the internet from claiming the tool before you do; the file is deleted automatically once setup finishes.
3. Reload, choose a password, and you land on a dashboard with plain-language controls: **Preview** (changes nothing), **Compress images**, quality and max-size dropdowns, live progress, and an **Undo** button next to every backup.
4. Big sites are handled automatically — the run pauses before the PHP time limit and continues itself until everything is done.
5. **Delete the file from the server when you are finished.**

Power users can skip the wizard by hardcoding `const ACCESS_TOKEN = '...'` in the file, and can call the plain-text API directly:
`...?token=YOUR_PASSWORD&action=compress|revert|list-backups|delete-backup` with optional `dry_run=1`, `no_resize=1`, `quality=82`, `max_dim=2000`, `min_size=10`, `limit=200`, `backup=latest|20260707-153012`.

### Option B: cPanel cron job or SSH

```bash
php /home/youruser/public_html/image-compressor.php compress --path=/home/youruser/public_html
```

Or as a URL-based cron (cPanel "cron job" with wget/curl), using the password you chose in the wizard:

```bash
wget -qO- "https://your-site.com/image-compressor.php?token=YOUR_PASSWORD&action=compress"
```

### CLI usage

```bash
php image-compressor.php compress --path=/var/www/site --dry-run   # preview
php image-compressor.php compress --path=/var/www/site             # compress
php image-compressor.php compress --quality=75 --max-dim=1600      # more aggressive
php image-compressor.php compress --no-resize                      # never change dimensions
php image-compressor.php list-backups
php image-compressor.php revert --backup=latest
```

`--path` defaults to the current directory (CLI) or the folder the script sits in (browser).

---

## Bash version — Ubuntu servers with root

```bash
sudo apt-get update && sudo apt-get install -y jpegoptim pngquant gifsicle webp imagemagick

./compress-images.sh compress --path /var/www/my-project --dry-run
./compress-images.sh compress --path /var/www/my-project
./compress-images.sh compress --path /var/www/my-project --quality 75 --max-dim 1600
./compress-images.sh list-backups --path /var/www/my-project
./compress-images.sh revert --path /var/www/my-project --backup latest
```

## Options (both versions)

| Option | Default | Description |
|---|---|---|
| `--path DIR` | current dir | Project root (or any folder of images) |
| `--quality N` | `82` | JPEG/WebP quality ceiling; PNG quantization bound |
| `--max-dim N` | `2000` | Longest allowed edge in pixels; larger images are downscaled |
| `--min-size KB` | `10` | Skip files smaller than this |
| `--no-resize` | off | Compress only, never change dimensions |
| `--dry-run` | off | Show what would be done without touching anything |
| `--limit N` (PHP only) | unlimited | Max files per run (chunking for big sites) |
| `--backup TS\|latest` | `latest` | Which backup to restore (revert command) |

## Backup layout

```
<project>/.image-compressor/
├── optimized.json                    # PHP version: remembers finished files
└── backups/20260707-153012/
    ├── files/                        # exact copies of originals, mirroring project paths
    ├── manifest.tsv                  # relative_path, original_bytes, new_bytes
    ├── summary.txt                   # totals for the run
    └── target-path.txt               # project root the backup belongs to
```

Backups live inside the project so they travel with it. Add `.image-compressor/` to your `.gitignore`, and delete old backups once you're happy — they hold full-size originals.

## Notes & recommendations

- Compression is **lossy by design** (that's where the big wins are), but at quality 82 the difference is invisible for web use. Raise `--quality` to 90+ for pixel-perfect needs.
- WordPress generates multiple thumbnail sizes per upload; all get compressed. If you set `--max-dim` below your theme's largest registered size, regenerate thumbnails afterwards (`wp media regenerate`).
- Safe to run repeatedly. The PHP version keeps a registry of optimized files and skips them on re-runs, so JPEGs are never re-encoded twice.
- Animated GIFs and animated WebPs are detected and left untouched by the PHP version.
- Browser mode is protected by a password (stored as a bcrypt hash, never in plain text), CSRF-protected sessions, a proof-of-ownership file gate on first-time setup, and an `.htaccess` that blocks direct web access to backups and config. Uploaded images are validated by their real content (not their extension) before processing, so disguised payloads and decompression bombs are skipped. Still: remove the script when finished.

## Contributing & security

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report vulnerabilities privately as described in [SECURITY.md](SECURITY.md). Release history lives in [CHANGELOG.md](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE).
