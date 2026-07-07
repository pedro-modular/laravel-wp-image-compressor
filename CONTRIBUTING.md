# Contributing

Thanks for your interest in improving Image Compressor!

## Ground rules

The project has two hard design constraints — PRs that break them won't be merged:

1. **Single files, zero dependencies.** `image-compressor.php` must remain one
   self-contained file that runs with only PHP 8.0+ and Imagick *or* GD.
   `compress-images.sh` may only depend on packages available via `apt-get` on
   a stock Ubuntu LTS. No Composer, no npm, no frameworks.
2. **Safety first.** Every code path that modifies an image must back the file
   up beforehand, must never let a file grow, and must remain fully revertible.

## Reporting bugs

Open an issue with:
- Which flavor (`image-compressor.php` or `compress-images.sh`) and version (see `CHANGELOG.md`)
- PHP version and image extension (`php -m | grep -E 'imagick|gd'`), or the tool versions for the bash script
- The exact command/URL used and the full output
- If image-specific: the image's format and dimensions (attach a sample if possible)

## Submitting changes

1. Fork and create a feature branch.
2. Keep the style of the surrounding code (the PHP file follows PSR-12-ish
   formatting; the bash script uses `set -euo pipefail` conventions).
3. Lint before pushing: `php -l image-compressor.php` and `bash -n compress-images.sh`.
4. Test the paths you touched: at minimum a compress → verify → revert cycle on a
   throwaway folder, plus the web setup/login flow if you touched the GUI.
5. Update `CHANGELOG.md` under an "Unreleased" heading.
6. Open a PR describing what changed and why. Small, focused PRs get reviewed fastest.

## Security issues

Please do **not** open public issues for vulnerabilities — see [SECURITY.md](SECURITY.md).
