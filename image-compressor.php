<?php
/**
 * image-compressor.php — Plug-and-play image compression for Laravel & WordPress.
 *
 * Single file, zero dependencies beyond PHP's Imagick or GD extension (one of
 * which is enabled on virtually every host that can run Laravel or WordPress).
 * Works on shared hosting / cPanel where CLI tools cannot be installed.
 *
 * Features:
 *   - Built-in web interface: upload the file, visit it in a browser, and a
 *     setup wizard walks you through creating a password. No editing required.
 *   - Auto-detects Laravel (public/, storage/app/public) and WordPress (wp-content/uploads)
 *   - Compresses JPEG / PNG / WebP, downscales oversized images (default max edge 2000px)
 *   - Full timestamped backup of every touched file, one-click revert
 *   - Never lets a file grow; remembers optimized files so re-runs skip them
 *     (no generational quality loss)
 *   - Per-file and total savings summary, dry-run mode
 *   - Runs from: the browser (GUI), CLI/SSH, or cPanel cron
 *
 * Browser usage (recommended for non-technical users):
 *   1. Upload this file to the project root (next to artisan / wp-config.php).
 *   2. Visit https://your-site.com/image-compressor.php and follow the wizard.
 *   3. Delete the file when you are done.
 *
 * CLI usage:
 *   php image-compressor.php compress [--path=DIR] [--quality=N] [--max-dim=N]
 *                                     [--min-size=KB] [--limit=N] [--no-resize] [--dry-run]
 *   php image-compressor.php revert   [--path=DIR] [--backup=TIMESTAMP|latest]
 *   php image-compressor.php list-backups   [--path=DIR]
 *   php image-compressor.php delete-backup  [--path=DIR] [--backup=TIMESTAMP|latest]
 *
 * Cron / scripted URL usage (plain-text output):
 *   .../image-compressor.php?token=YOUR_PASSWORD&action=compress
 *   .../image-compressor.php?token=YOUR_PASSWORD&action=revert&backup=latest
 *   (token = the password you chose in the wizard, or ACCESS_TOKEN if set below)
 *
 * License: MIT
 */

const IC_VERSION      = '1.1.0';
const ACCESS_TOKEN    = '';                 // Optional: hardcode a secret to skip the wizard. Empty = use wizard password.
const BACKUP_DIRNAME  = '.image-compressor';
const EXCLUDED_DIRS   = [BACKUP_DIRNAME, 'node_modules', 'vendor', '.git'];
const IMAGE_EXTS      = ['jpg', 'jpeg', 'png', 'webp'];  // GIFs are left alone (animation-safe)

error_reporting(E_ALL & ~E_DEPRECATED);
@set_time_limit(0);
@ini_set('memory_limit', '512M');

if (PHP_SAPI === 'cli') {
    [$command, $opts] = parseCliArgs($_SERVER['argv']);
    runCommand($command, $opts, false);
} else {
    webEntry();
}
exit(0);

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------
function runCommand(string $command, array $opts, bool $isWeb): void
{
    $cfg = [
        'path'        => realpath((string)($opts['path'] ?? getcwd())) ?: null,
        'quality'     => max(1, min(100, (int)($opts['quality'] ?? 82))),
        'max_dim'     => max(100, (int)($opts['max_dim'] ?? 2000)),
        'min_bytes'   => max(0, (int)($opts['min_size'] ?? 10)) * 1024,
        'limit'       => (int)($opts['limit'] ?? 0),          // 0 = unlimited
        'backup'      => (string)($opts['backup'] ?? 'latest'),
        'dry_run'     => !empty($opts['dry_run']),
        'resize'      => empty($opts['no_resize']),
        'max_seconds' => (int)($opts['max_seconds'] ?? 0),    // 0 = unlimited
        'is_web'      => $isWeb,
        'started'     => microtime(true),
    ];
    if ($cfg['path'] === null) {
        fail('Path does not exist: ' . ($opts['path'] ?? getcwd()));
    }
    $cfg['backup_root'] = $cfg['path'] . '/' . BACKUP_DIRNAME . '/backups';
    $cfg['registry']    = $cfg['path'] . '/' . BACKUP_DIRNAME . '/optimized.json';

    switch ($command) {
        case 'compress':      cmdCompress($cfg); break;
        case 'revert':        cmdRevert($cfg); break;
        case 'list-backups':  cmdListBackups($cfg); break;
        case 'delete-backup': cmdDeleteBackup($cfg); break;
        case 'help': default: cmdHelp(); break;
    }
}

// ---------------------------------------------------------------------------
// Commands
// ---------------------------------------------------------------------------
function cmdCompress(array $cfg): void
{
    $engine = detectEngine();
    out(sprintf('image-compressor v%s — engine: %s (PHP %s)', IC_VERSION, $engine, PHP_VERSION));

    $scanDirs = detectScanDirs($cfg['path'], true);
    out('Scanning: ' . implode(', ', $scanDirs));
    out(sprintf(
        'Settings: quality=%d  max-dim=%dpx  min-size=%dKB  resize=%s  dry-run=%s',
        $cfg['quality'], $cfg['max_dim'], $cfg['min_bytes'] / 1024,
        $cfg['resize'] ? 'on' : 'off', $cfg['dry_run'] ? 'yes' : 'no'
    ));

    $registry = loadRegistry($cfg);
    $files = findImages($scanDirs, $cfg['min_bytes']);

    // Skip files already optimized in a previous run (same size + mtime)
    $candidates = [];
    foreach ($files as $file) {
        $rel = relPath($file, $cfg['path']);
        $entry = $registry[$rel] ?? null;
        if ($entry && $entry['s'] === filesize($file) && $entry['m'] === filemtime($file)) {
            continue;
        }
        $candidates[] = $file;
    }
    $alreadyDone = count($files) - count($candidates);

    if (!$candidates) {
        out('');
        out($alreadyDone > 0
            ? "Nothing to do — all $alreadyDone image(s) were optimized in previous runs."
            : 'No images found above ' . ($cfg['min_bytes'] / 1024) . 'KB. Nothing to do.');
        return;
    }
    out(sprintf('Found %d image(s) to process%s', count($candidates),
        $alreadyDone > 0 ? " ($alreadyDone already optimized, skipped)" : ''));

    if ($cfg['dry_run']) {
        $total = 0;
        foreach ($candidates as $file) {
            $size = filesize($file);
            $total += $size;
            out('  would process: ' . relPath($file, $cfg['path']) . ' (' . human($size) . ')');
        }
        out('');
        out('============================================================');
        out(' DRY RUN — no files were modified');
        out(sprintf(' Candidates: %d file(s), %s total', count($candidates), human($total)));
        out('============================================================');
        return;
    }

    $timestamp = date('Ymd-His');
    $backupDir = $cfg['backup_root'] . '/' . $timestamp;
    ensureStateDir($cfg['path']);
    mkdirp($backupDir . '/files');
    file_put_contents($backupDir . '/target-path.txt', $cfg['path'] . "\n");
    $manifest = fopen($backupDir . '/manifest.tsv', 'w');
    fwrite($manifest, "relative_path\toriginal_bytes\tnew_bytes\n");

    $totalBefore = 0; $totalAfter = 0; $changed = 0; $skipped = 0; $processed = 0;
    $reportLines = []; $stoppedEarly = false;

    foreach ($candidates as $file) {
        if ($cfg['limit'] > 0 && $processed >= $cfg['limit']) { $stoppedEarly = true; break; }
        if ($cfg['max_seconds'] > 0 && (microtime(true) - $cfg['started']) > $cfg['max_seconds']) {
            $stoppedEarly = true; break;
        }
        $processed++;

        $rel    = relPath($file, $cfg['path']);
        $before = filesize($file);
        $perms  = fileperms($file) & 0777;

        // --- Backup first ---
        $bkp = $backupDir . '/files/' . $rel;
        mkdirp(dirname($bkp));
        copy($file, $bkp);
        @touch($bkp, filemtime($file));
        @chmod($bkp, $perms);

        // --- Compress to a temp file next to the original ---
        $tmp = $file . '.ic-tmp';
        $okTmp = null;
        try {
            $okTmp = compressImage($engine, $file, $tmp, $cfg);
        } catch (Throwable $e) {
            out('  [warn] failed: ' . $rel . ' (' . $e->getMessage() . ')');
        }

        clearstatcache();
        if ($okTmp && is_file($tmp) && filesize($tmp) > 0 && filesize($tmp) < $before) {
            rename($tmp, $file);
            @chmod($file, $perms);
            $after = filesize($file);
            $changed++;
            fwrite($manifest, "$rel\t$before\t$after\n");
            $pct = (int)round(($before - $after) * 100 / $before);
            $reportLines[] = sprintf('%-9s %-9s %3d%%  %s', human($before), human($after), $pct, $rel);
        } else {
            // No gain (or failure) — keep original, drop its backup copy
            @unlink($tmp);
            @unlink($bkp);
            $after = $before;
            $skipped++;
        }

        clearstatcache();
        $registry[$rel] = ['s' => filesize($file), 'm' => filemtime($file)];
        if ($processed % 20 === 0) {
            saveRegistry($cfg, $registry);
            out(sprintf('  ...%d/%d processed', $processed, count($candidates)));
        }

        $totalBefore += $before;
        $totalAfter  += $after;
    }
    fclose($manifest);
    saveRegistry($cfg, $registry);

    // --- Summary ---
    $saved = $totalBefore - $totalAfter;
    $pct   = $totalBefore > 0 ? (int)round($saved * 100 / $totalBefore) : 0;
    out('');
    out('============================================================');
    out(' COMPRESSION SUMMARY');
    out('============================================================');
    if ($reportLines) {
        out(sprintf('%-9s %-9s %-5s %s', 'BEFORE', 'AFTER', 'SAVED', 'FILE'));
        foreach ($reportLines as $line) { out($line); }
        out('------------------------------------------------------------');
    }
    out(' Files compressed : ' . $changed);
    out(' Files skipped    : ' . $skipped . ' (no gain)');
    out(' Size before      : ' . human($totalBefore));
    out(' Size after       : ' . human($totalAfter));
    out(' Space saved      : ' . human($saved) . " ({$pct}%)");
    out('============================================================');

    if ($changed > 0) {
        file_put_contents($backupDir . '/summary.txt', implode("\n", [
            'date: ' . $timestamp,
            'files_compressed: ' . $changed,
            'files_skipped: ' . $skipped,
            'bytes_before: ' . $totalBefore,
            'bytes_after: ' . $totalAfter,
            'bytes_saved: ' . $saved,
        ]) . "\n");
        out(' Backup stored at: ' . $backupDir);
        out($cfg['is_web']
            ? ' To revert: use the Undo button, or ?token=...&action=revert&backup=' . $timestamp
            : " To revert: php image-compressor.php revert --path=\"{$cfg['path']}\" --backup=$timestamp");
    } else {
        rrmdir($backupDir);
        out(' No files needed compression; backup discarded.');
    }

    if ($stoppedEarly) {
        $remaining = count($candidates) - $processed;
        out('');
        out(" STOPPED EARLY (time/limit reached) — $remaining file(s) remaining.");
        out(' Run the same command again to continue; finished files are remembered.');
    }
}

function cmdRevert(array $cfg): void
{
    $backupDir = resolveBackupDir($cfg);
    if ($backupDir === null || !is_dir($backupDir . '/files')) {
        out('Backup not found' . ($backupDir ? ': ' . $backupDir : ' (no backups exist).'));
        cmdListBackups($cfg);
        exit(1);
    }

    out('Reverting from backup: ' . basename($backupDir));
    $restored = 0;
    foreach (iterateFiles($backupDir . '/files') as $bkp) {
        $rel  = relPath($bkp, $backupDir . '/files');
        $dest = $cfg['path'] . '/' . $rel;
        mkdirp(dirname($dest));
        copy($bkp, $dest);
        @touch($dest, filemtime($bkp));
        @chmod($dest, fileperms($bkp) & 0777);
        $restored++;
        out('  restored: ' . $rel);
    }
    // Forget optimization state so the next compress run re-evaluates everything
    @unlink($cfg['registry']);
    out("Restored $restored file(s) from " . basename($backupDir));

    if (!$cfg['is_web'] && function_exists('readline')) {
        $answer = strtolower(trim((string)readline("Delete this backup now that it's restored? [y/N] ")));
        if ($answer === 'y') {
            rrmdir($backupDir);
            out('Backup deleted.');
        }
    } else {
        out('Backup kept at: ' . $backupDir . ' (delete it when no longer needed).');
    }
}

function cmdDeleteBackup(array $cfg): void
{
    $backupDir = resolveBackupDir($cfg);
    if ($backupDir === null || !is_dir($backupDir)) {
        out('Backup not found.');
        exit(1);
    }
    rrmdir($backupDir);
    out('Deleted backup ' . basename($backupDir) . '.');
}

function cmdListBackups(array $cfg): void
{
    $backups = gatherBackups($cfg);
    if (!$backups) {
        out('No backups found in ' . $cfg['backup_root']);
        return;
    }
    out('Backups in ' . $cfg['backup_root'] . ':');
    foreach ($backups as $b) {
        out('  ' . $b['name'] . " — {$b['files']} file(s), " . human($b['bytes']));
        foreach ($b['summary'] as $k => $v) {
            out("      $k: $v");
        }
    }
}

function cmdHelp(): void
{
    $src = file(__FILE__);
    foreach (array_slice($src, 2) as $line) {  // skip "<?php" and "/**"
        if (!preg_match('/^ \*/', $line)) { break; }
        out(rtrim(preg_replace('/^ \*\/?\s?/', '', $line)));
    }
}

// ---------------------------------------------------------------------------
// Compression engines
// ---------------------------------------------------------------------------
function detectEngine(): string
{
    if (extension_loaded('imagick')) { return 'imagick'; }
    if (extension_loaded('gd'))      { return 'gd'; }
    fail('Neither the Imagick nor the GD PHP extension is available. Enable one of them '
        . '(cPanel: "Select PHP Version" → Extensions → imagick or gd).');
}

/** Returns true when a candidate file was written to $tmp. */
function compressImage(string $engine, string $file, string $tmp, array $cfg): bool
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') { $ext = 'jpg'; }
    return $engine === 'imagick'
        ? compressWithImagick($file, $tmp, $ext, $cfg)
        : compressWithGd($file, $tmp, $ext, $cfg);
}

function compressWithImagick(string $file, string $tmp, string $ext, array $cfg): bool
{
    $im = new Imagick($file);
    if ($im->getNumberImages() > 1) { $im->destroy(); return false; }  // animated — leave alone

    // Honor EXIF orientation before stripping metadata
    if (method_exists($im, 'autoOrient')) {
        @$im->autoOrient();
    } else {
        imagickManualOrient($im);
    }

    if ($cfg['resize']) {
        $w = $im->getImageWidth(); $h = $im->getImageHeight();
        if ($w > $cfg['max_dim'] || $h > $cfg['max_dim']) {
            $im->resizeImage($cfg['max_dim'], $cfg['max_dim'], Imagick::FILTER_LANCZOS, 1, true);
        }
    }

    switch ($ext) {
        case 'jpg':
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality($cfg['quality']);
            $im->setInterlaceScheme(Imagick::INTERLACE_PLANE);  // progressive
            $im->stripImage();
            break;
        case 'png':
            $im->setImageFormat('png');
            $im->stripImage();
            // Lossy palette quantization — same idea as pngquant. The caller's
            // keep-if-smaller rule discards this if it doesn't actually help.
            $colorspace = $im->getImageAlphaChannel()
                ? Imagick::COLORSPACE_TRANSPARENT : Imagick::COLORSPACE_RGB;
            @$im->quantizeImage(256, $colorspace, 0, true, false);
            $im->setOption('png:compression-level', '9');
            break;
        case 'webp':
            $im->setImageFormat('webp');
            $im->setImageCompressionQuality($cfg['quality']);
            break;
        default:
            $im->destroy();
            return false;
    }

    $ok = $im->writeImage($tmp);
    $im->destroy();
    return (bool)$ok;
}

function imagickManualOrient(Imagick $im): void
{
    switch ($im->getImageOrientation()) {
        case Imagick::ORIENTATION_BOTTOMRIGHT: $im->rotateImage('#000', 180); break;
        case Imagick::ORIENTATION_RIGHTTOP:    $im->rotateImage('#000', 90);  break;
        case Imagick::ORIENTATION_LEFTBOTTOM:  $im->rotateImage('#000', -90); break;
        default: return;
    }
    $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
}

function compressWithGd(string $file, string $tmp, string $ext, array $cfg): bool
{
    switch ($ext) {
        case 'jpg':  $img = @imagecreatefromjpeg($file); break;
        case 'png':  $img = @imagecreatefrompng($file);  break;
        case 'webp': $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false; break;
        default:     return false;
    }
    if (!$img) { return false; }

    if ($ext === 'jpg') { $img = gdAutoOrient($img, $file); }

    if ($cfg['resize']) {
        $w = imagesx($img); $h = imagesy($img);
        if ($w > $cfg['max_dim'] || $h > $cfg['max_dim']) {
            $scale = min($cfg['max_dim'] / $w, $cfg['max_dim'] / $h);
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $resized = imagecreatetruecolor($nw, $nh);
            if ($ext === 'png' || $ext === 'webp') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefill($resized, 0, 0, $transparent);
            }
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $resized;
        }
    }

    switch ($ext) {
        case 'jpg':
            imageinterlace($img, true);  // progressive
            $ok = imagejpeg($img, $tmp, $cfg['quality']);
            break;
        case 'png':
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $ok = imagepng($img, $tmp, 9);  // lossless recompression only (GD can't quantize well)
            break;
        case 'webp':
            $ok = function_exists('imagewebp') && imagewebp($img, $tmp, $cfg['quality']);
            break;
        default:
            $ok = false;
    }
    imagedestroy($img);
    return (bool)$ok;
}

function gdAutoOrient(GdImage $img, string $file): GdImage
{
    if (!function_exists('exif_read_data')) { return $img; }
    $exif = @exif_read_data($file);
    $angle = match ((int)($exif['Orientation'] ?? 1)) {
        3 => 180, 6 => -90, 8 => 90, default => 0,
    };
    if ($angle !== 0) {
        $rotated = imagerotate($img, $angle, 0);
        if ($rotated) {
            imagedestroy($img);
            return $rotated;
        }
    }
    return $img;
}

// ---------------------------------------------------------------------------
// Project detection & file discovery
// ---------------------------------------------------------------------------
function detectProjectType(string $path): string
{
    if (is_file("$path/artisan")) { return 'Laravel'; }
    if (is_file("$path/wp-config.php") || is_dir("$path/wp-content")) { return 'WordPress'; }
    return 'generic';
}

function detectScanDirs(string $path, bool $announce = false): array
{
    $type = detectProjectType($path);
    $dirs = [];
    if ($type === 'Laravel') {
        if ($announce) { out('Detected Laravel project'); }
        foreach (["$path/public", "$path/storage/app/public"] as $d) {
            if (is_dir($d)) { $dirs[] = $d; }
        }
    } elseif ($type === 'WordPress') {
        if ($announce) { out('Detected WordPress project'); }
        if (is_dir("$path/wp-content/uploads")) { $dirs[] = "$path/wp-content/uploads"; }
    } else {
        if ($announce) { out('No Laravel/WordPress markers found — scanning the whole path'); }
        $dirs[] = $path;
    }
    if (!$dirs) { fail("No image directories found under $path"); }
    return $dirs;
}

function findImages(array $dirs, int $minBytes): array
{
    $files = [];
    foreach ($dirs as $dir) {
        foreach (iterateFiles($dir) as $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, IMAGE_EXTS, true) && filesize($path) > $minBytes) {
                $files[] = $path;
            }
        }
    }
    sort($files);
    return $files;
}

/** Recursively yields file paths, pruning excluded directories. */
function iterateFiles(string $dir): Generator
{
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            fn (SplFileInfo $f) => !($f->isDir() && in_array($f->getFilename(), EXCLUDED_DIRS, true))
        )
    );
    foreach ($it as $f) {
        if ($f->isFile()) { yield $f->getPathname(); }
    }
}

// ---------------------------------------------------------------------------
// State: backups, optimized-file registry, config
// ---------------------------------------------------------------------------
function ensureStateDir(string $path): string
{
    $dir = $path . '/' . BACKUP_DIRNAME;
    mkdirp($dir);
    // Block direct web access to backups/config (Apache/LiteSpeed honor this)
    if (!is_file("$dir/.htaccess")) {
        file_put_contents("$dir/.htaccess",
            "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    if (!is_file("$dir/index.html")) {
        file_put_contents("$dir/index.html", '');
    }
    return $dir;
}

function resolveBackupDir(array $cfg): ?string
{
    if ($cfg['backup'] === 'latest') {
        $dirs = glob($cfg['backup_root'] . '/*', GLOB_ONLYDIR) ?: [];
        sort($dirs);
        return $dirs ? end($dirs) : null;
    }
    if (!preg_match('/^[0-9]{8}-[0-9]{6}$/', $cfg['backup'])) {
        fail('Invalid backup name: ' . $cfg['backup'] . ' (expected e.g. 20260707-153012 or "latest")');
    }
    return $cfg['backup_root'] . '/' . $cfg['backup'];
}

/** @return array<int, array{dir:string,name:string,files:int,bytes:int,summary:array<string,string>}> */
function gatherBackups(array $cfg): array
{
    if (!is_dir($cfg['backup_root'])) { return []; }
    $result = [];
    $dirs = glob($cfg['backup_root'] . '/*', GLOB_ONLYDIR) ?: [];
    sort($dirs);
    foreach ($dirs as $dir) {
        $count = 0; $bytes = 0;
        foreach (iterateFiles($dir) as $f) { $count++; $bytes += filesize($f); }
        $summary = [];
        if (is_file($dir . '/summary.txt')) {
            foreach (file($dir . '/summary.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_contains($line, ':')) {
                    [$k, $v] = array_map('trim', explode(':', $line, 2));
                    $summary[$k] = $v;
                }
            }
        }
        $result[] = ['dir' => $dir, 'name' => basename($dir), 'files' => $count, 'bytes' => $bytes, 'summary' => $summary];
    }
    return $result;
}

function loadRegistry(array $cfg): array
{
    if (!is_file($cfg['registry'])) { return []; }
    $data = json_decode((string)file_get_contents($cfg['registry']), true);
    return is_array($data) ? $data : [];
}

function saveRegistry(array $cfg, array $registry): void
{
    ensureStateDir($cfg['path']);
    file_put_contents($cfg['registry'], json_encode($registry), LOCK_EX);
}

function configFilePath(): string
{
    return __DIR__ . '/' . BACKUP_DIRNAME . '/config.json';
}

function loadWebConfig(): array
{
    if (!is_file(configFilePath())) { return []; }
    $data = json_decode((string)file_get_contents(configFilePath()), true);
    return is_array($data) ? $data : [];
}

function saveWebConfig(array $config): void
{
    ensureStateDir(__DIR__);
    file_put_contents(configFilePath(), json_encode($config), LOCK_EX);
}

// ---------------------------------------------------------------------------
// Web: auth
// ---------------------------------------------------------------------------
function authConfigured(): bool
{
    return ACCESS_TOKEN !== '' || isset(loadWebConfig()['password_hash']);
}

function verifySecret(string $secret): bool
{
    if ($secret === '') { return false; }
    if (ACCESS_TOKEN !== '') { return hash_equals(ACCESS_TOKEN, $secret); }
    $hash = loadWebConfig()['password_hash'] ?? null;
    return $hash !== null && password_verify($secret, $hash);
}

function sessionAuthed(array $req): bool
{
    return !empty($_SESSION['auth'])
        && hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($req['csrf'] ?? ''));
}

// ---------------------------------------------------------------------------
// Web: entry point & routing
// ---------------------------------------------------------------------------
function webEntry(): void
{
    session_name('imgcompressor');
    @session_start();
    $req = array_merge($_GET, $_POST);
    $action = (string)($req['action'] ?? '');
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

    if ($action === 'logout') {
        session_destroy();
        header('Location: ' . selfUrl());
        exit;
    }
    if ($isPost && ($action === 'setup' || $action === 'login')) {
        handleAuthPost($action, $req);
        exit;
    }

    // Plain-text API (used by the GUI's JavaScript, cron jobs, and scripts)
    if (in_array($action, ['compress', 'revert', 'list-backups', 'delete-backup'], true)) {
        if (!authConfigured()) {
            http_response_code(403);
            exit("Not set up yet. Open this script in a browser and complete the setup wizard first.\n");
        }
        $tokenOk = verifySecret((string)($req['token'] ?? ''));
        if (!$tokenOk && !sessionAuthed($req)) {
            http_response_code(403);
            exit("Access denied. Pass ?token=YOUR_PASSWORD or log in through the browser.\n");
        }
        session_write_close();  // don't block other tabs during long runs
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) { ob_end_flush(); }

        runCommand($action, [
            'path'        => __DIR__,  // web mode always operates on the folder the script sits in
            'quality'     => $req['quality']  ?? null,
            'max_dim'     => $req['max_dim']  ?? null,
            'min_size'    => $req['min_size'] ?? null,
            'limit'       => $req['limit']    ?? null,
            'backup'      => $req['backup']   ?? null,
            'dry_run'     => !empty($req['dry_run']),
            'no_resize'   => !empty($req['no_resize']),
            'max_seconds' => $req['max_seconds'] ?? 45,  // stop before the host kills us
        ], true);
        exit;
    }

    // HTML GUI
    if (!authConfigured()) {
        renderPage('setup');
    } elseif (empty($_SESSION['auth'])) {
        renderPage('login');
    } else {
        renderPage('dashboard');
    }
}

function handleAuthPost(string $action, array $req): void
{
    $password = (string)($req['password'] ?? '');

    if ($action === 'setup') {
        if (authConfigured()) {
            flash('This tool is already set up.');
        } elseif (strlen($password) < 8) {
            flash('Please choose a password of at least 8 characters.');
        } else {
            saveWebConfig(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'created' => date('c')]);
            loginSession();
            flash('Setup complete! Keep your password safe — you will need it to log in and for cron jobs.');
        }
    }

    if ($action === 'login') {
        if (verifySecret($password)) {
            loginSession();
        } else {
            sleep(1);  // slow down brute force
            flash('Wrong password.');
        }
    }

    header('Location: ' . selfUrl());
}

function loginSession(): void
{
    session_regenerate_id(true);
    $_SESSION['auth'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function flash(string $msg): void  { $_SESSION['flash'] = $msg; }
function takeFlash(): string       { $m = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']); return $m; }
function selfUrl(): string         { return strtok($_SERVER['REQUEST_URI'], '?') ?: basename(__FILE__); }
function e(string $s): string      { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ---------------------------------------------------------------------------
// Web: HTML pages
// ---------------------------------------------------------------------------
function renderPage(string $page): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    echo guiHead();
    $flashMsg = takeFlash();
    if ($flashMsg !== '') {
        echo '<div class="flash">' . e($flashMsg) . '</div>';
    }
    match ($page) {
        'setup'     => renderSetup(),
        'login'     => renderLogin(),
        'dashboard' => renderDashboard(),
    };
    echo "</main></body></html>\n";
}

function guiHead(): string
{
    $css = <<<'CSS'
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
         background:#f0f4f2;color:#1c2b25;line-height:1.55;padding:24px 16px}
    main{max-width:760px;margin:0 auto}
    .card{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:24px;margin-bottom:20px}
    h1{font-size:1.5rem;margin-bottom:4px}
    h2{font-size:1.1rem;margin-bottom:12px}
    p{margin-bottom:10px}
    .muted{color:#5c6f66;font-size:.92rem}
    .flash{background:#e8f6ee;border:1px solid #b3dfc5;color:#14532d;border-radius:10px;padding:12px 16px;margin-bottom:16px}
    .warnbox{background:#fdf6e3;border:1px solid #eadfb8;color:#6b5a12;border-radius:10px;padding:12px 16px;margin-top:14px;font-size:.92rem}
    input[type=password],select{width:100%;padding:11px 12px;border:1px solid #c6d4cd;border-radius:8px;font-size:1rem;background:#fff}
    label{display:block;font-weight:600;font-size:.9rem;margin:14px 0 5px}
    .btn{display:inline-block;border:0;border-radius:8px;padding:12px 22px;font-size:1rem;font-weight:600;
         cursor:pointer;text-decoration:none;text-align:center}
    .btn-primary{background:#16794c;color:#fff}
    .btn-primary:hover{background:#12603c}
    .btn-secondary{background:#e5ede9;color:#1c2b25}
    .btn-secondary:hover{background:#d5e2db}
    .btn-small{padding:7px 14px;font-size:.88rem}
    .btn-danger{background:#fbeaea;color:#8f2020}
    .btn:disabled{opacity:.5;cursor:wait}
    .row{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}
    .row .btn{flex:1;min-width:180px}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    @media(max-width:560px){.grid2{grid-template-columns:1fr}}
    #log{display:none;background:#10201a;color:#c9e8d6;border-radius:10px;padding:14px;margin-top:16px;
         font:12px/1.5 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;max-height:340px;overflow-y:auto}
    #banner{display:none;background:#e8f6ee;border:1px solid #b3dfc5;color:#14532d;border-radius:10px;
            padding:14px 16px;margin-top:16px;font-weight:600}
    table{width:100%;border-collapse:collapse;font-size:.92rem}
    th{text-align:left;color:#5c6f66;font-weight:600;padding:6px 8px;border-bottom:1px solid #e2eae6}
    td{padding:9px 8px;border-bottom:1px solid #eef3f0;vertical-align:middle}
    .topbar{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:18px}
    .topbar a{color:#16794c;font-size:.9rem}
    .badge{display:inline-block;background:#e5ede9;border-radius:6px;padding:2px 9px;font-size:.85rem;font-weight:600}
CSS;
    return "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"utf-8\">"
        . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
        . "<meta name=\"robots\" content=\"noindex,nofollow\">"
        . "<title>Image Compressor</title><style>$css</style></head><body><main>";
}

function renderSetup(): void
{
    $url = e(selfUrl());
    echo <<<HTML
    <div class="card">
      <h1>🖼️ Image Compressor</h1>
      <p class="muted">First-time setup — takes 10 seconds.</p>
      <p>This tool shrinks the images on this website to make it faster and use less disk space.
         Every change is backed up first and can be undone with one click.</p>
      <form method="post" action="$url">
        <input type="hidden" name="action" value="setup">
        <label for="pw">Choose a password to protect this tool</label>
        <input type="password" id="pw" name="password" minlength="8" required
               placeholder="At least 8 characters" autofocus autocomplete="new-password">
        <div class="row"><button class="btn btn-primary" type="submit">Save password &amp; continue</button></div>
      </form>
      <div class="warnbox">⚠️ Complete this setup right after uploading the file, so nobody else can claim it.
      Delete the file from the server when you are finished.</div>
    </div>
    HTML;
}

function renderLogin(): void
{
    $url = e(selfUrl());
    echo <<<HTML
    <div class="card">
      <h1>🖼️ Image Compressor</h1>
      <p class="muted">Enter your password to continue.</p>
      <form method="post" action="$url">
        <input type="hidden" name="action" value="login">
        <label for="pw">Password</label>
        <input type="password" id="pw" name="password" required autofocus autocomplete="current-password">
        <div class="row"><button class="btn btn-primary" type="submit">Log in</button></div>
      </form>
    </div>
    HTML;
}

function renderDashboard(): void
{
    $path   = __DIR__;
    $type   = detectProjectType($path);
    $dirs   = detectScanDirs($path);
    $engine = extension_loaded('imagick') ? 'Imagick' : (extension_loaded('gd') ? 'GD' : 'none');
    $csrf   = json_encode($_SESSION['csrf'] ?? '');
    $url    = e(selfUrl());

    $dirList = implode(', ', array_map(fn ($d) => e(relPath($d, $path) ?: '.'), $dirs));
    $typeLabel = $type === 'generic' ? 'Website folder' : e($type) . ' website';

    echo <<<HTML
    <div class="topbar"><h1>🖼️ Image Compressor</h1><a href="$url?action=logout">Log out</a></div>

    <div class="card">
      <h2>$typeLabel <span class="badge">engine: $engine</span></h2>
      <p class="muted">Images will be processed inside: <strong>$dirList</strong></p>
      <div class="grid2">
        <div>
          <label for="quality">Image quality</label>
          <select id="quality">
            <option value="82" selected>Recommended (82) — looks identical, big savings</option>
            <option value="90">Higher quality (90) — smaller savings</option>
            <option value="75">Smaller files (75) — slight quality trade-off</option>
            <option value="65">Smallest files (65) — visible on close inspection</option>
          </select>
        </div>
        <div>
          <label for="maxdim">Maximum image size</label>
          <select id="maxdim">
            <option value="2000" selected>2000 px — recommended for websites</option>
            <option value="2560">2560 px — large screens</option>
            <option value="1600">1600 px — smaller &amp; faster</option>
            <option value="1200">1200 px — blogs / thumbnails</option>
            <option value="0">Keep original size (compress only)</option>
          </select>
        </div>
      </div>
      <div class="row">
        <button class="btn btn-secondary" id="btn-preview" onclick="preview()">1&#65039;&#8283; Preview — changes nothing</button>
        <button class="btn btn-primary" id="btn-compress" onclick="compressNow()">2&#65039;&#8283; Compress images</button>
      </div>
      <p class="muted" style="margin-top:10px">Every file is backed up before it is changed. You can undo any run below.</p>
      <div id="banner"></div>
      <pre id="log"></pre>
    </div>

    <div class="card">
      <h2>Backups &amp; undo</h2>
    HTML;

    $cfg = ['backup_root' => $path . '/' . BACKUP_DIRNAME . '/backups'];
    $backups = gatherBackups($cfg);
    if (!$backups) {
        echo '<p class="muted">No backups yet. They will appear here after your first compression run.</p>';
    } else {
        echo '<table><tr><th>Date</th><th>Files</th><th>Saved</th><th></th></tr>';
        foreach (array_reverse($backups) as $b) {
            $dt = DateTime::createFromFormat('Ymd-His', $b['name']);
            $when = $dt ? $dt->format('j M Y, H:i') : $b['name'];
            $saved = isset($b['summary']['bytes_saved']) ? human((int)$b['summary']['bytes_saved']) : '—';
            $name = e($b['name']);
            echo '<tr><td>' . e($when) . '</td><td>' . (int)$b['files'] . '</td><td>' . e($saved) . '</td>'
               . '<td style="text-align:right;white-space:nowrap">'
               . '<button class="btn btn-secondary btn-small" onclick="undo(\'' . $name . '\')">↩ Undo</button> '
               . '<button class="btn btn-danger btn-small" onclick="delBackup(\'' . $name . '\')">Delete</button>'
               . '</td></tr>';
        }
        echo '</table><p class="muted" style="margin-top:10px">Backups hold the full-size originals — delete them once you are happy with the results to free up space.</p>';
    }

    echo '</div><p class="muted" style="text-align:center">image-compressor v' . IC_VERSION
       . ' · open source (MIT) · also works from the command line and cron</p>';

    echo "<script>const CSRF = $csrf;</script>";
    echo <<<'JS'
    <script>
    const logEl = document.getElementById('log');
    const bannerEl = document.getElementById('banner');

    function setBusy(busy) {
      document.querySelectorAll('.btn').forEach(b => b.disabled = busy);
    }
    function settings() {
      const maxdim = document.getElementById('maxdim').value;
      const p = { quality: document.getElementById('quality').value };
      if (maxdim === '0') { p.no_resize = '1'; } else { p.max_dim = maxdim; }
      return p;
    }
    async function streamInto(url) {
      const res = await fetch(url, { method: 'POST' });
      const reader = res.body.getReader();
      const dec = new TextDecoder();
      let full = '';
      for (;;) {
        const { done, value } = await reader.read();
        if (done) break;
        const t = dec.decode(value, { stream: true });
        full += t;
        logEl.textContent += t;
        logEl.scrollTop = logEl.scrollHeight;
      }
      return full;
    }
    async function runAction(action, params, autoContinue) {
      bannerEl.style.display = 'none';
      logEl.style.display = 'block';
      logEl.textContent = '';
      setBusy(true);
      let full = '', rounds = 0;
      try {
        for (;;) {
          const qs = new URLSearchParams(Object.assign({ action, csrf: CSRF }, params));
          const text = await streamInto('?' + qs.toString());
          full += text;
          if (!(autoContinue && text.includes('STOPPED EARLY') && ++rounds < 500)) break;
          logEl.textContent += '\n— continuing automatically… —\n\n';
        }
      } catch (err) {
        logEl.textContent += '\n[error] ' + err;
      }
      setBusy(false);
      return full;
    }
    function showBanner(msg) {
      bannerEl.textContent = msg;
      bannerEl.style.display = 'block';
      bannerEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    async function preview() {
      const out = await runAction('compress', Object.assign({ dry_run: '1' }, settings()), false);
      const m = out.match(/Candidates:\s*(\d+) file\(s\), ([^\n]+) total/);
      if (m) showBanner('🔍 Preview: ' + m[1] + ' image(s) would be processed (' + m[2].trim() + '). Nothing was changed.');
      else if (out.includes('Nothing to do')) showBanner('✅ Nothing to do — your images are already optimized.');
    }
    async function compressNow() {
      const out = await runAction('compress', settings(), true);
      const m = out.match(/Space saved\s*:\s*([^\n(]+)\(([^)]+)\)/);
      if (m) {
        showBanner('🎉 Done! You saved ' + m[1].trim() + ' — your images are now ' + m[2] + ' smaller.');
        setTimeout(() => location.reload(), 3500);
      } else if (out.includes('Nothing to do')) {
        showBanner('✅ Nothing to do — your images are already optimized.');
      }
    }
    async function undo(name) {
      if (!confirm('Restore all images from the backup of ' + name + '?')) return;
      await runAction('revert', { backup: name }, false);
      showBanner('↩ Restored. Reloading…');
      setTimeout(() => location.reload(), 1800);
    }
    async function delBackup(name) {
      if (!confirm('Permanently delete backup ' + name + '? You will no longer be able to undo that run.')) return;
      await runAction('delete-backup', { backup: name }, false);
      location.reload();
    }
    </script>
    JS;
}

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------
function parseCliArgs(array $argv): array
{
    $command = $argv[1] ?? 'help';
    $opts = [];
    for ($i = 2, $n = count($argv); $i < $n; $i++) {
        $arg = $argv[$i];
        if ($arg === '--dry-run')        { $opts['dry_run'] = true; }
        elseif ($arg === '--no-resize')  { $opts['no_resize'] = true; }
        elseif (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
            $opts[str_replace('-', '_', $m[1])] = $m[2];
        } elseif (preg_match('/^--([a-z-]+)$/', $arg, $m) && isset($argv[$i + 1])) {
            $opts[str_replace('-', '_', $m[1])] = $argv[++$i];
        } else {
            fail("Unknown option: $arg (use --key=value)");
        }
    }
    return [$command, $opts];
}

function out(string $msg): void
{
    echo $msg, "\n";
    if (PHP_SAPI !== 'cli') { @flush(); }
}

function fail(string $msg): never
{
    if (PHP_SAPI !== 'cli') { http_response_code(500); }
    fwrite(PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'w'), "[error] $msg\n");
    exit(1);
}

function human(int $bytes): string
{
    foreach (['B', 'KiB', 'MiB', 'GiB'] as $i => $unit) {
        if ($bytes < 1024 ** ($i + 1) || $unit === 'GiB') {
            return round($bytes / (1024 ** $i), $i > 0 ? 1 : 0) . $unit;
        }
    }
    return $bytes . 'B';
}

function relPath(string $file, string $base): string
{
    return ltrim(substr($file, strlen(rtrim($base, '/'))), '/');
}

function mkdirp(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fail("Cannot create directory: $dir");
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}
