#!/usr/bin/env bash
#
# compress-images.sh — Safe image compression for Laravel / WordPress projects (Ubuntu)
#
# Features:
#   - Auto-detects Laravel (public/, storage/app/public) and WordPress (wp-content/uploads)
#   - Lossy-but-good compression: jpegoptim, pngquant, gifsicle, cwebp
#   - Optional downscaling of oversized images (default: nothing larger than 2000px)
#   - Full backup of every touched file before modification (timestamped)
#   - Revert command to restore any backup
#   - Per-file and total space-savings summary
#   - Dry-run mode
#
# Usage:
#   ./compress-images.sh compress [--path DIR] [--quality N] [--max-dim N] [--min-size KB] [--no-resize] [--dry-run]
#   ./compress-images.sh revert   [--path DIR] [--backup TIMESTAMP|latest]
#   ./compress-images.sh list-backups [--path DIR]
#   ./compress-images.sh help
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------
QUALITY=82            # JPEG/WebP quality ceiling; pngquant upper bound
MAX_DIM=2000          # Longest edge allowed; larger images are downscaled
MIN_SIZE_KB=10        # Skip files smaller than this (not worth touching)
TARGET_PATH="$(pwd)"
DRY_RUN=0
DO_RESIZE=1
BACKUP_CHOICE="latest"
BACKUP_DIRNAME=".image-compressor"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[0;33m'; BLUE='\033[0;34m'; NC='\033[0m'

usage() {
    sed -n '2,${/^#/!q;s/^# \{0,1\}//p;}' "$0"
    exit "${1:-0}"
}

info()  { echo -e "${BLUE}[info]${NC} $*"; }
ok()    { echo -e "${GREEN}[ok]${NC} $*"; }
warn()  { echo -e "${YELLOW}[warn]${NC} $*"; }
error() { echo -e "${RED}[error]${NC} $*" >&2; }

human() {
    # bytes -> human readable
    numfmt --to=iec-i --suffix=B "$1" 2>/dev/null || echo "${1}B"
}

file_size() {
    stat -c %s "$1" 2>/dev/null || stat -f %z "$1"   # GNU stat (Ubuntu), BSD fallback
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
COMMAND="${1:-help}"
[[ $# -gt 0 ]] && shift

while [[ $# -gt 0 ]]; do
    case "$1" in
        --path)      TARGET_PATH="$2"; shift 2 ;;
        --quality)   QUALITY="$2"; shift 2 ;;
        --max-dim)   MAX_DIM="$2"; shift 2 ;;
        --min-size)  MIN_SIZE_KB="$2"; shift 2 ;;
        --backup)    BACKUP_CHOICE="$2"; shift 2 ;;
        --no-resize) DO_RESIZE=0; shift ;;
        --dry-run)   DRY_RUN=1; shift ;;
        -h|--help)   usage 0 ;;
        *)           error "Unknown option: $1"; usage 1 ;;
    esac
done

TARGET_PATH="$(cd "$TARGET_PATH" && pwd)"
BACKUP_ROOT="$TARGET_PATH/$BACKUP_DIRNAME/backups"

# ---------------------------------------------------------------------------
# Dependency check
# ---------------------------------------------------------------------------
check_dependencies() {
    local missing=()
    for cmd in jpegoptim pngquant gifsicle cwebp identify mogrify file; do
        command -v "$cmd" >/dev/null 2>&1 || missing+=("$cmd")
    done
    if [[ ${#missing[@]} -gt 0 ]]; then
        error "Missing tools: ${missing[*]}"
        echo "Install them with:"
        echo "  sudo apt-get update && sudo apt-get install -y jpegoptim pngquant gifsicle webp imagemagick"
        exit 1
    fi
}

# ---------------------------------------------------------------------------
# Project detection — returns the directories to scan
# ---------------------------------------------------------------------------
detect_scan_dirs() {
    local dirs=()
    if [[ -f "$TARGET_PATH/artisan" ]]; then
        info "Detected Laravel project" >&2
        [[ -d "$TARGET_PATH/public" ]] && dirs+=("$TARGET_PATH/public")
        [[ -d "$TARGET_PATH/storage/app/public" ]] && dirs+=("$TARGET_PATH/storage/app/public")
    elif [[ -f "$TARGET_PATH/wp-config.php" || -d "$TARGET_PATH/wp-content" ]]; then
        info "Detected WordPress project" >&2
        [[ -d "$TARGET_PATH/wp-content/uploads" ]] && dirs+=("$TARGET_PATH/wp-content/uploads")
    else
        info "No Laravel/WordPress markers found — scanning the whole path" >&2
        dirs+=("$TARGET_PATH")
    fi
    if [[ ${#dirs[@]} -eq 0 ]]; then
        error "No image directories found under $TARGET_PATH"
        exit 1
    fi
    printf '%s\n' "${dirs[@]}"
}

# ---------------------------------------------------------------------------
# Compression per format (operates in place; backup already taken)
# ---------------------------------------------------------------------------

# Security gate: confirm the file's ACTUAL content matches its extension before
# handing it to ImageMagick. `file --mime-type` sniffs magic bytes and never
# invokes an image delegate, so a malicious MVG/MSL/SVG/PS payload disguised as
# .jpg is rejected here and never reaches identify/mogrify — closing the
# ImageTragick / delegate-abuse class (CVE-2016-3714), which matters most since
# this script may run as root.
verify_image_content() {
    local file="$1" ext="$2" mime expected
    mime=$(file -b --mime-type -- "$file" 2>/dev/null) || return 1
    case "$ext" in
        jpg|jpeg) expected="image/jpeg" ;;
        png)      expected="image/png"  ;;
        gif)      expected="image/gif"  ;;
        webp)     expected="image/webp" ;;
        *)        return 1 ;;
    esac
    [[ "$mime" == "$expected" ]]
}

resize_if_needed() {
    local file="$1" coder="$2"
    [[ $DO_RESIZE -eq 1 ]] || return 0
    local dims w h
    # Pin the input coder (e.g. "JPG:file") so ImageMagick cannot be steered into
    # a different, delegate-backed decoder by the file's content.
    dims=$(identify -format '%w %h' "${coder}:${file}[0]" 2>/dev/null) || return 0
    read -r w h <<< "$dims"
    if [[ "$w" -gt "$MAX_DIM" || "$h" -gt "$MAX_DIM" ]]; then
        mogrify -auto-orient -resize "${MAX_DIM}x${MAX_DIM}>" "${coder}:${file}"
        echo "resized ${w}x${h}"
    fi
}

compress_file() {
    local file="$1" ext="$2"
    if ! verify_image_content "$file" "$ext"; then
        warn "Skipping (content does not match .$ext extension): ${file#"$TARGET_PATH"/}"
        return 1
    fi
    case "$ext" in
        jpg|jpeg)
            resize_if_needed "$file" JPG >/dev/null
            jpegoptim --max="$QUALITY" --strip-all --all-progressive --quiet "$file"
            ;;
        png)
            resize_if_needed "$file" PNG >/dev/null
            pngquant --quality="60-$QUALITY" --speed 1 --skip-if-larger --force --ext .png "$file" 2>/dev/null || true
            ;;
        gif)
            # No resize for GIFs (animated frames); just optimize
            gifsicle -O3 --batch "$file" 2>/dev/null || true
            ;;
        webp)
            resize_if_needed "$file" WEBP >/dev/null
            local tmp="${file}.tmp.webp"
            if cwebp -quiet -q "$QUALITY" "$file" -o "$tmp" 2>/dev/null; then
                if [[ $(file_size "$tmp") -lt $(file_size "$file") ]]; then
                    mv "$tmp" "$file"
                else
                    rm -f "$tmp"
                fi
            fi
            rm -f "$tmp" 2>/dev/null || true
            ;;
    esac
}

# ---------------------------------------------------------------------------
# COMPRESS command
# ---------------------------------------------------------------------------
cmd_compress() {
    check_dependencies

    local timestamp backup_dir manifest
    timestamp="$(date +%Y%m%d-%H%M%S)"
    backup_dir="$BACKUP_ROOT/$timestamp"
    manifest="$backup_dir/manifest.tsv"

    mapfile -t scan_dirs < <(detect_scan_dirs)
    info "Scanning: ${scan_dirs[*]}"
    info "Settings: quality=$QUALITY  max-dim=${MAX_DIM}px  min-size=${MIN_SIZE_KB}KB  resize=$([[ $DO_RESIZE -eq 1 ]] && echo on || echo off)  dry-run=$([[ $DRY_RUN -eq 1 ]] && echo yes || echo no)"

    # Collect candidate files (skip our own backup dir, vendor, node_modules, git)
    local files=()
    while IFS= read -r -d '' f; do
        files+=("$f")
    done < <(find "${scan_dirs[@]}" \
                \( -path "*/$BACKUP_DIRNAME/*" -o -path '*/node_modules/*' -o -path '*/vendor/*' -o -path '*/.git/*' \) -prune -o \
                -type f \( -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.png' -o -iname '*.gif' -o -iname '*.webp' \) \
                -size +"${MIN_SIZE_KB}"k -print0)

    if [[ ${#files[@]} -eq 0 ]]; then
        warn "No images found above ${MIN_SIZE_KB}KB. Nothing to do."
        exit 0
    fi
    info "Found ${#files[@]} image(s) to process"

    if [[ $DRY_RUN -eq 0 ]]; then
        mkdir -p "$backup_dir/files"
        echo "$TARGET_PATH" > "$backup_dir/target-path.txt"
        printf 'relative_path\toriginal_bytes\tnew_bytes\n' > "$manifest"
    fi

    local total_before=0 total_after=0 changed=0 skipped=0
    local report_lines=()

    for file in "${files[@]}"; do
        local rel="${file#"$TARGET_PATH"/}"
        local ext="${file##*.}"
        ext="${ext,,}"
        local before after
        before=$(file_size "$file")

        if [[ $DRY_RUN -eq 1 ]]; then
            echo "  would process: $rel ($(human "$before"))"
            total_before=$((total_before + before))
            continue
        fi

        # --- Backup (preserve relative path, permissions, timestamps) ---
        local bkp="$backup_dir/files/$rel"
        mkdir -p "$(dirname "$bkp")"
        cp -p "$file" "$bkp"

        # --- Compress ---
        if ! compress_file "$file" "$ext"; then
            warn "Failed to process $rel — restoring original"
            cp -p "$bkp" "$file"
            skipped=$((skipped + 1))
            continue
        fi

        after=$(file_size "$file")

        # Keep whichever is smaller — never let a file grow
        if [[ "$after" -ge "$before" ]]; then
            cp -p "$bkp" "$file"
            rm -f "$bkp"
            after=$before
            skipped=$((skipped + 1))
        else
            changed=$((changed + 1))
            printf '%s\t%s\t%s\n' "$rel" "$before" "$after" >> "$manifest"
            local pct=$(( (before - after) * 100 / before ))
            report_lines+=("$(printf '%-8s %-8s %3d%%  %s' "$(human "$before")" "$(human "$after")" "$pct" "$rel")")
        fi

        total_before=$((total_before + before))
        total_after=$((total_after + after))
    done

    # --- Summary ---
    echo
    echo "============================================================"
    if [[ $DRY_RUN -eq 1 ]]; then
        echo " DRY RUN — no files were modified"
        echo " Candidates: ${#files[@]} file(s), $(human "$total_before") total"
        echo "============================================================"
        return
    fi

    echo " COMPRESSION SUMMARY"
    echo "============================================================"
    if [[ ${#report_lines[@]} -gt 0 ]]; then
        printf '%-8s %-8s %-5s %s\n' "BEFORE" "AFTER" "SAVED" "FILE"
        printf '%s\n' "${report_lines[@]}"
        echo "------------------------------------------------------------"
    fi
    local saved=$((total_before - total_after))
    local total_pct=0
    [[ $total_before -gt 0 ]] && total_pct=$(( saved * 100 / total_before ))
    echo " Files compressed : $changed"
    echo " Files skipped    : $skipped (no gain or too risky)"
    echo " Size before      : $(human "$total_before")"
    echo " Size after       : $(human "$total_after")"
    echo " Space saved      : $(human "$saved") (${total_pct}%)"
    echo "============================================================"
    if [[ $changed -gt 0 ]]; then
        ok "Backup stored at: $backup_dir"
        echo " To revert: $(basename "$0") revert --path \"$TARGET_PATH\" --backup $timestamp"
        # Persist summary alongside the backup
        {
            echo "date: $timestamp"
            echo "files_compressed: $changed"
            echo "files_skipped: $skipped"
            echo "bytes_before: $total_before"
            echo "bytes_after: $total_after"
            echo "bytes_saved: $saved"
        } > "$backup_dir/summary.txt"
    else
        # Nothing changed — remove the empty backup
        rm -rf "$backup_dir"
        info "No files needed compression; backup discarded."
    fi
}

# ---------------------------------------------------------------------------
# REVERT command
# ---------------------------------------------------------------------------
cmd_revert() {
    local backup_dir
    if [[ "$BACKUP_CHOICE" == "latest" ]]; then
        backup_dir=$(ls -1d "$BACKUP_ROOT"/*/ 2>/dev/null | sort | tail -n1 | sed 's:/$::') || true
        if [[ -z "${backup_dir:-}" ]]; then
            error "No backups found in $BACKUP_ROOT"
            exit 1
        fi
    else
        backup_dir="$BACKUP_ROOT/$BACKUP_CHOICE"
    fi

    if [[ ! -d "$backup_dir/files" ]]; then
        error "Backup not found or empty: $backup_dir"
        cmd_list_backups
        exit 1
    fi

    info "Reverting from backup: $(basename "$backup_dir")"
    local restored=0
    while IFS= read -r -d '' bkp; do
        local rel="${bkp#"$backup_dir"/files/}"
        local dest="$TARGET_PATH/$rel"
        mkdir -p "$(dirname "$dest")"
        cp -p "$bkp" "$dest"
        restored=$((restored + 1))
        echo "  restored: $rel"
    done < <(find "$backup_dir/files" -type f -print0)

    ok "Restored $restored file(s) from $(basename "$backup_dir")"
    read -r -p "Delete this backup now that it's restored? [y/N] " answer
    if [[ "${answer,,}" == "y" ]]; then
        rm -rf "$backup_dir"
        ok "Backup deleted."
    fi
}

# ---------------------------------------------------------------------------
# LIST-BACKUPS command
# ---------------------------------------------------------------------------
cmd_list_backups() {
    if [[ ! -d "$BACKUP_ROOT" ]] || [[ -z "$(ls -A "$BACKUP_ROOT" 2>/dev/null)" ]]; then
        info "No backups found in $BACKUP_ROOT"
        return
    fi
    echo "Backups in $BACKUP_ROOT:"
    for d in "$BACKUP_ROOT"/*/; do
        local name count size
        name=$(basename "$d")
        count=$(find "$d/files" -type f 2>/dev/null | wc -l | tr -d ' ')
        size=$(du -sh "$d" 2>/dev/null | cut -f1)
        echo "  $name — $count file(s), $size"
        [[ -f "$d/summary.txt" ]] && sed 's/^/      /' "$d/summary.txt"
    done
}

# ---------------------------------------------------------------------------
# Dispatch
# ---------------------------------------------------------------------------
case "$COMMAND" in
    compress)     cmd_compress ;;
    revert)       cmd_revert ;;
    list-backups) cmd_list_backups ;;
    help|-h|--help) usage 0 ;;
    *) error "Unknown command: $COMMAND"; usage 1 ;;
esac
