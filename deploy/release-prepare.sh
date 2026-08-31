#!/usr/bin/env bash
# Validate and assemble a reproducible release artifact without deploying it.
set -euo pipefail

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
VERSION="${SUITE_RELEASE_VERSION:-$(git -C "$ROOT" describe --tags --always --dirty 2>/dev/null || echo unreleased)}"
# Keep the version safe for a filename without accidentally treating letters
# from the POSIX character-class spelling as translation characters.
VERSION="$(printf '%s' "$VERSION" | sed 's#[^A-Za-z0-9._-]#-#g')"
OUT_DIR="${SUITE_RELEASE_DIR:-$ROOT/dist}"
ARCHIVE="${SUITE_RELEASE_ARCHIVE:-$OUT_DIR/typecho-suite-${VERSION}.tar.gz}"
REQUIRE_TOOLCHAIN="${REQUIRE_TOOLCHAIN:-1}"

usage() {
  cat <<'EOF'
Usage: deploy/release-prepare.sh [--check-only] [--output=/path]

Runs static checks, validates release metadata, and (unless --check-only)
creates a source artifact plus SHA-256 checksum. It never copies files to a
Typecho web root and never changes a database.
EOF
}

check_only=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --check-only) check_only=1 ;;
    --output=*) OUT_DIR="${1#*=}"; ARCHIVE="$OUT_DIR/typecho-suite-${VERSION}.tar.gz" ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 64 ;;
  esac
  shift
done

if [[ ! -f "$ROOT/CHANGELOG.md" || ! -f "$ROOT/README.md" || ! -f "$ROOT/README.zh-CN.md" ]]; then
  echo 'release metadata missing: CHANGELOG.md and both README files are required' >&2
  exit 1
fi
if ! command -v rg >/dev/null 2>&1; then
  echo 'release checks require ripgrep (rg)' >&2
  exit 1
fi
if ! rg -q '^## [0-9]{4}-[0-9]{2}-[0-9]{2}' "$ROOT/CHANGELOG.md"; then
  echo 'CHANGELOG.md has no dated release entry' >&2
  exit 1
fi

REQUIRE_TOOLCHAIN="$REQUIRE_TOOLCHAIN" CI=true "$ROOT/tests/static-check.sh"
git -C "$ROOT" diff --check

for required in \
  deploy/suite-doctor.php deploy/tag-slug-doctor.php deploy/suite-search-docs-backfill.php deploy/release-prepare.sh \
  composer.json phpunit.xml.dist tests/bootstrap.php; do
  [[ -f "$ROOT/$required" ]] || { echo "release file missing: $required" >&2; exit 1; }
done

# Refuse accidental secret/config files and unsafe permissions in the source
# tree.  Release artifacts are public, so a world-writable executable or a
# private runtime config is always a hard failure.
if git -C "$ROOT" ls-files | grep -E '(^|/)(config\.inc\.php|.*\.env|.*\.cnf)$' >/dev/null 2>&1; then
  echo 'private runtime configuration is tracked; remove it before release' >&2
  exit 1
fi
while IFS= read -r -d '' tracked; do
  file="$ROOT/$tracked"
  [[ -f "$file" ]] || continue
  mode="$(stat -c '%a' "$file" 2>/dev/null || stat -f '%Lp' "$file" 2>/dev/null || echo 0)"
  if (( (10#$mode % 10) & 2 )); then
    echo "world-writable release file: $tracked (mode $mode)" >&2
    exit 1
  fi
done < <(git -C "$ROOT" ls-files -z)

# A package must not contain working-tree dirt, credentials, or generated
# deployment state.  Operators can still build a package from a tagged commit.
if [[ -n "$(git -C "$ROOT" status --porcelain)" ]]; then
  echo 'working tree is dirty; commit or stash changes before packaging' >&2
  git -C "$ROOT" status --short >&2
  exit 1
fi

if (( check_only )); then
  echo "release checks passed for $VERSION (no artifact created)"
  exit 0
fi

mkdir -p "$OUT_DIR"
if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
  echo 'warning: release preparation is running as root; deployment artifacts should normally be built as an unprivileged user' >&2
fi
tmp_archive="${ARCHIVE}.tmp.$$"
trap 'rm -f "$tmp_archive"' EXIT

tar -czf "$tmp_archive" \
  --exclude='./.git' \
  --exclude='./.phpunit.cache' \
  --exclude='./dist' \
  --exclude='./.DS_Store' \
  --exclude='*/.DS_Store' \
  --exclude='./._*' \
  --exclude='*/._*' \
  --exclude='./config.inc.php' \
  --exclude='*/config.inc.php' \
  --exclude='./*.cnf' \
  --exclude='*.cnf' \
  --exclude='*/.env' \
  -C "$ROOT" .
mv -f "$tmp_archive" "$ARCHIVE"
if command -v sha256sum >/dev/null 2>&1; then
  sha256sum "$ARCHIVE" > "$ARCHIVE.sha256"
else
  shasum -a 256 "$ARCHIVE" > "$ARCHIVE.sha256"
fi
chmod 0644 "$ARCHIVE" "$ARCHIVE.sha256"
[[ -s "$ARCHIVE" && -s "$ARCHIVE.sha256" ]] || { echo 'release artifact is empty' >&2; exit 1; }
if tar -tzf "$ARCHIVE" | grep -E '(^|/)\.\.(/|$)|(^|/)(config\.inc\.php|[^/]*\.env|[^/]*\.cnf)$' >/dev/null; then
  echo 'release artifact contains unsafe path or private configuration' >&2
  exit 1
fi

cat <<EOF
Release artifact prepared:
  archive: $ARCHIVE
  checksum: $ARCHIVE.sha256

Before deployment, take a database/config/uploads backup and stage the archive
in a disposable Typecho instance. Deploy through an atomic current/previous
release switch; this script intentionally does not perform that operation.
EOF
