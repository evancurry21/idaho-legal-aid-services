#!/usr/bin/env bash
#
# pull-live.sh — Pull database + files from Pantheon LIVE into local DDEV.
#
# Usage:
#   bash scripts/pull-live.sh [flags]
#   ddev pull-live [flags]          # if DDEV wrapper installed
#
# Flags:
#   --env=live|test|dev   Pantheon environment (default: live)
#   --no-files            Skip file download/sync
#   --no-db               Skip database download/import
#   --force               Skip confirmation prompt
#   --sanitize            Run drush sql:sanitize after import
#   --keep-local-files-backup   Tar local files/default/files before overwrite
#   --fresh-backup        Create new Pantheon backup before download
#   --help                Print usage and exit
#
# Prerequisites:
#   - terminus authenticated on the host (terminus auth:login)
#   - ddev project running (ddev start)
#

set -euo pipefail

# ─── Constants ────────────────────────────────────────────────────────────────

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
readonly DOWNLOADS_DIR="$PROJECT_ROOT/.ddev/.downloads"
readonly FILES_DIR="$PROJECT_ROOT/web/sites/default/files"
readonly STAGING_DIR="$DOWNLOADS_DIR/files_staging"
readonly VERSION="1.0.0"

# Defaults (overridable via pull-live.conf or flags)
SITE="idaho-legal-aid-services"
ENV="live"
TERMINUS="/usr/local/bin/terminus"
SKIP_FILES=false
SKIP_DB=false
FORCE=false
SANITIZE=false
KEEP_LOCAL_FILES_BACKUP=false
FRESH_BACKUP=false
MIN_DISK_MB=1024

# Tracking
CURRENT_STEP="init"
START_TIME=""
SNAPSHOT_NAME=""

# ─── Colors ───────────────────────────────────────────────────────────────────

if [[ -t 1 ]]; then
  RED='\033[0;31m'
  GREEN='\033[0;32m'
  YELLOW='\033[1;33m'
  BLUE='\033[0;34m'
  BOLD='\033[1m'
  NC='\033[0m'
else
  RED='' GREEN='' YELLOW='' BLUE='' BOLD='' NC=''
fi

# ─── Helpers ──────────────────────────────────────────────────────────────────

info()  { echo -e "${BLUE}[info]${NC}  $*"; }
ok()    { echo -e "${GREEN}[ok]${NC}    $*"; }
warn()  { echo -e "${YELLOW}[warn]${NC}  $*"; }
err()   { echo -e "${RED}[error]${NC} $*" >&2; }
step()  { echo -e "\n${BOLD}── $* ──${NC}"; }

usage() {
  cat <<'USAGE'
Usage: pull-live.sh [flags]

Flags:
  --env=live|test|dev          Pantheon environment (default: live)
  --no-files                   Skip file download/sync
  --no-db                      Skip database download/import
  --force                      Skip confirmation prompt
  --sanitize                   Run drush sql:sanitize after import
  --keep-local-files-backup    Tar local files before overwriting
  --fresh-backup               Create new Pantheon backup before download
  --help                       Print this message and exit

Examples:
  bash scripts/pull-live.sh                    # Full pull from LIVE
  bash scripts/pull-live.sh --no-files         # DB only
  bash scripts/pull-live.sh --env=test --sanitize
  ddev pull-live --force --no-files            # Via DDEV wrapper
USAGE
  exit 0
}

elapsed() {
  local end
  end=$(date +%s)
  local secs=$(( end - START_TIME ))
  printf '%dm %ds' $((secs / 60)) $((secs % 60))
}

# ─── Cleanup trap ─────────────────────────────────────────────────────────────

cleanup() {
  local exit_code=$?
  if [[ $exit_code -ne 0 ]]; then
    echo ""
    err "Failed during step: ${CURRENT_STEP}"
    if [[ -n "$SNAPSHOT_NAME" ]]; then
      echo ""
      warn "To restore your previous database:"
      echo "  ddev snapshot restore $SNAPSHOT_NAME"
    fi
    echo ""
  fi
  # Always clean up staging directory
  if [[ -d "$STAGING_DIR" ]]; then
    rm -rf "$STAGING_DIR"
  fi
  exit $exit_code
}
trap cleanup EXIT

# ─── Config loading ───────────────────────────────────────────────────────────

load_config() {
  local conf="$SCRIPT_DIR/pull-live.conf"
  if [[ -f "$conf" ]]; then
    info "Loading config from $conf"
    # shellcheck source=/dev/null
    source "$conf"
  fi
}

# ─── Argument parsing ─────────────────────────────────────────────────────────

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --env=*)
        ENV="${1#*=}"
        if [[ ! "$ENV" =~ ^(live|test|dev)$ ]]; then
          err "Invalid environment: $ENV (must be live, test, or dev)"
          exit 1
        fi
        ;;
      --no-files)       SKIP_FILES=true ;;
      --no-db)          SKIP_DB=true ;;
      --force)          FORCE=true ;;
      --sanitize)       SANITIZE=true ;;
      --keep-local-files-backup) KEEP_LOCAL_FILES_BACKUP=true ;;
      --fresh-backup)   FRESH_BACKUP=true ;;
      --help|-h)        usage ;;
      *)
        err "Unknown flag: $1"
        echo "Run with --help for usage."
        exit 1
        ;;
    esac
    shift
  done
}

# ─── Preflight checks ────────────────────────────────────────────────────────

preflight_checks() {
  CURRENT_STEP="preflight"
  step "Preflight checks"

  # 1. Terminus exists and is authenticated
  if [[ ! -x "$TERMINUS" ]]; then
    err "terminus not found at $TERMINUS"
    echo "  Install: https://docs.pantheon.io/terminus/install"
    exit 1
  fi

  if ! "$TERMINUS" auth:whoami &>/dev/null; then
    err "Terminus is not authenticated."
    echo "  Run: terminus auth:login --machine-token=YOUR_TOKEN"
    exit 1
  fi
  local whoami
  whoami=$("$TERMINUS" auth:whoami 2>/dev/null)
  ok "Terminus authenticated as: $whoami"

  # 2. DDEV is running
  if ! command -v ddev &>/dev/null; then
    err "ddev not found on PATH"
    exit 1
  fi

  if ! ddev status 2>/dev/null | grep -q "running"; then
    err "DDEV project is not running."
    echo "  Run: ddev start"
    exit 1
  fi
  ok "DDEV is running"

  # 3. Validate site access
  if ! "$TERMINUS" site:info "$SITE" &>/dev/null; then
    err "Cannot access Pantheon site: $SITE"
    echo "  Check site name and your Terminus permissions."
    exit 1
  fi
  ok "Pantheon site accessible: $SITE"

  # 4. MariaDB version check
  local local_db_version
  local_db_version=$(grep -A2 '^database:' "$PROJECT_ROOT/.ddev/config.yaml" 2>/dev/null \
    | grep 'version:' | sed 's/.*version:[[:space:]]*"\?\([^"]*\)"\?/\1/' || echo "unknown")
  if [[ "$local_db_version" != "10.6" ]]; then
    warn "DDEV MariaDB is $local_db_version (Pantheon uses 10.6)"
    echo "  Consider updating .ddev/config.yaml to version: \"10.6\""
  else
    ok "MariaDB version matches Pantheon: 10.6"
  fi

  # 5. Disk space
  local free_mb
  free_mb=$(df -m "$PROJECT_ROOT" | awk 'NR==2 {print $4}')
  if [[ "$free_mb" -lt "$MIN_DISK_MB" ]]; then
    err "Low disk space: ${free_mb}MB free (need at least ${MIN_DISK_MB}MB)"
    exit 1
  fi
  ok "Disk space: ${free_mb}MB free"

  # 6. Downloads directory
  mkdir -p "$DOWNLOADS_DIR"
  ok "Downloads dir: $DOWNLOADS_DIR"
}

# ─── Show plan ────────────────────────────────────────────────────────────────

show_plan() {
  step "Pull plan"
  echo "  Source:      ${BOLD}$SITE.$ENV${NC}"
  echo "  Database:    $([[ $SKIP_DB == true ]] && echo 'SKIP' || echo 'YES')"
  echo "  Files:       $([[ $SKIP_FILES == true ]] && echo 'SKIP' || echo 'YES')"
  echo "  Sanitize:    $([[ $SANITIZE == true ]] && echo 'YES' || echo 'NO')"
  echo "  Fresh backup: $([[ $FRESH_BACKUP == true ]] && echo 'YES' || echo 'NO')"

  if [[ "$FORCE" != true ]]; then
    echo ""
    read -rp "Proceed? [y/N] " confirm
    if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
      info "Aborted."
      exit 0
    fi
  fi
}

# ─── Local DB backup ─────────────────────────────────────────────────────────

backup_local_db() {
  CURRENT_STEP="local-db-backup"
  step "Backing up local database"

  SNAPSHOT_NAME="pre-pull-$(date +%Y%m%d-%H%M%S)"
  ddev snapshot --name="$SNAPSHOT_NAME"
  ok "Snapshot created: $SNAPSHOT_NAME"
  echo "  Restore with: ddev snapshot restore $SNAPSHOT_NAME"
}

# ─── Local files backup (optional) ───────────────────────────────────────────

backup_local_files() {
  if [[ "$KEEP_LOCAL_FILES_BACKUP" != true ]]; then
    return 0
  fi

  CURRENT_STEP="local-files-backup"
  step "Backing up local files"

  local backup_file="$DOWNLOADS_DIR/local-files-backup-$(date +%Y%m%d-%H%M%S).tar.gz"
  if [[ -d "$FILES_DIR" ]]; then
    tar -czf "$backup_file" -C "$(dirname "$FILES_DIR")" "$(basename "$FILES_DIR")"
    ok "Files backed up to: $backup_file"
  else
    warn "No local files directory found at $FILES_DIR — skipping backup"
  fi
}

# ─── Create fresh Pantheon backup (optional) ─────────────────────────────────

create_fresh_backup() {
  if [[ "$FRESH_BACKUP" != true ]]; then
    return 0
  fi

  CURRENT_STEP="create-fresh-backup"
  step "Creating fresh Pantheon backup"

  info "This may take a few minutes..."
  "$TERMINUS" backup:create "$SITE.$ENV" --keep-for=1
  ok "Fresh backup created on $SITE.$ENV"
}

# ─── Pull database ───────────────────────────────────────────────────────────

pull_db() {
  if [[ "$SKIP_DB" == true ]]; then
    info "Skipping database pull (--no-db)"
    return 0
  fi

  CURRENT_STEP="pull-db"
  step "Downloading database from $SITE.$ENV"

  local db_file="$DOWNLOADS_DIR/db.sql.gz"
  # Remove stale download
  rm -f "$db_file"

  "$TERMINUS" backup:get "$SITE.$ENV" --element=db --to="$db_file"

  if [[ ! -f "$db_file" ]]; then
    err "Database download failed — file not found at $db_file"
    exit 1
  fi

  local size
  size=$(du -h "$db_file" | cut -f1)
  ok "Downloaded: $db_file ($size)"

  CURRENT_STEP="import-db"
  step "Importing database"

  ddev import-db --file="$db_file"
  ok "Database imported"
}

# ─── Pull files ──────────────────────────────────────────────────────────────

pull_files() {
  if [[ "$SKIP_FILES" == true ]]; then
    info "Skipping files pull (--no-files)"
    return 0
  fi

  CURRENT_STEP="pull-files"
  step "Downloading files from $SITE.$ENV"

  local files_archive="$DOWNLOADS_DIR/files.tgz"
  rm -f "$files_archive"

  "$TERMINUS" backup:get "$SITE.$ENV" --element=files --to="$files_archive"

  if [[ ! -f "$files_archive" ]]; then
    err "Files download failed — file not found at $files_archive"
    exit 1
  fi

  local size
  size=$(du -h "$files_archive" | cut -f1)
  ok "Downloaded: $files_archive ($size)"

  CURRENT_STEP="extract-files"
  step "Extracting and syncing files"

  # Extract to staging directory
  rm -rf "$STAGING_DIR"
  mkdir -p "$STAGING_DIR"
  tar --strip-components=1 -C "$STAGING_DIR" -xzf "$files_archive"

  # Ensure target exists
  mkdir -p "$FILES_DIR"

  # Rsync into place
  rsync -a --delete \
    --exclude='php/' \
    --exclude='.htaccess' \
    "$STAGING_DIR/" "$FILES_DIR/"

  ok "Files synced to $FILES_DIR"

  # Clean up staging
  rm -rf "$STAGING_DIR"
}

# ─── Post-import maintenance ─────────────────────────────────────────────────

post_import() {
  CURRENT_STEP="post-import"
  step "Post-import maintenance"

  # Run database updates (non-fatal — some Pantheon hooks fail locally)
  info "Running database updates..."
  if ddev drush updatedb -y 2>&1; then
    ok "Database updates complete"
  else
    warn "drush updatedb had warnings/errors (non-fatal, continuing)"
  fi

  info "Rebuilding cache..."
  ddev drush cache:rebuild
  ok "Cache rebuilt"

  if [[ "$SANITIZE" == true ]]; then
    CURRENT_STEP="sanitize"
    info "Sanitizing database..."
    ddev drush sql:sanitize -y
    ok "Database sanitized (user emails/passwords replaced)"
  fi
}

# ─── Validation ───────────────────────────────────────────────────────────────

validate() {
  CURRENT_STEP="validate"
  step "Validating content counts"

  # PHP snippet to get entity counts as "type:count" lines
  read -r -d '' COUNT_PHP <<'PHPEOF' || true
$types = \Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple();
foreach ($types as $type) {
  $count = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
    ->accessCheck(FALSE)->condition('type', $type->id())->count()->execute();
  echo $type->id() . ':' . $count . "\n";
}
$user_count = \Drupal::entityTypeManager()->getStorage('user')->getQuery()
  ->accessCheck(FALSE)->count()->execute();
echo 'users:' . $user_count . "\n";
PHPEOF

  info "Fetching content counts from $SITE.$ENV..."
  local live_counts
  live_counts=$("$TERMINUS" drush "$SITE.$ENV" -- ev "$COUNT_PHP" 2>/dev/null || echo "")

  info "Fetching local content counts..."
  local local_counts
  local_counts=$(ddev drush ev "$COUNT_PHP" 2>/dev/null || echo "")

  if [[ -z "$live_counts" || -z "$local_counts" ]]; then
    warn "Could not retrieve counts for comparison — skipping validation"
    return 0
  fi

  # Print comparison table
  printf "\n  ${BOLD}%-35s %8s %8s %-10s${NC}\n" "Content Type" "REMOTE" "LOCAL" "Status"
  printf "  %-35s %8s %8s %-10s\n" "-----------------------------------" "--------" "--------" "----------"

  local all_ok=true
  while IFS=: read -r type live_count; do
    [[ -z "$type" ]] && continue
    local local_count
    local_count=$(echo "$local_counts" | grep "^${type}:" | cut -d: -f2 | tr -d '[:space:]')
    local_count="${local_count:-0}"
    live_count=$(echo "$live_count" | tr -d '[:space:]')

    local status
    if [[ "$live_count" == "$local_count" ]]; then
      status="${GREEN}OK${NC}"
    else
      status="${YELLOW}MISMATCH${NC}"
      all_ok=false
    fi
    printf "  %-35s %8s %8s " "$type" "$live_count" "$local_count"
    echo -e "$status"
  done <<< "$live_counts"

  echo ""
  if [[ "$all_ok" == true ]]; then
    ok "All content counts match"
  else
    warn "Some counts differ — this may be expected if content changed during pull"
  fi
}

# ─── Summary ──────────────────────────────────────────────────────────────────

print_summary() {
  step "Done"

  local duration
  duration=$(elapsed)

  echo ""
  echo -e "  ${GREEN}Pull complete in ${duration}${NC}"
  echo ""
  echo "  Source:    $SITE.$ENV"
  [[ "$SKIP_DB" != true ]]    && echo "  Database:  imported"
  [[ "$SKIP_FILES" != true ]] && echo "  Files:     synced"
  [[ "$SANITIZE" == true ]]   && echo "  Sanitized: yes"
  echo ""
  echo "  Restore DB:  ddev snapshot restore $SNAPSHOT_NAME"
  echo "  Local site:  $(ddev describe -j 2>/dev/null | python3 -c "import sys,json; print(json.load(sys.stdin)['raw']['primary_url'])" 2>/dev/null || echo "https://ilas-pantheon.ddev.site")"
  echo ""
}

# ─── Main ─────────────────────────────────────────────────────────────────────

main() {
  echo -e "${BOLD}pull-live.sh v${VERSION}${NC} — Pantheon → DDEV sync"
  echo ""

  load_config
  parse_args "$@"

  START_TIME=$(date +%s)

  preflight_checks
  show_plan
  backup_local_db
  backup_local_files
  create_fresh_backup
  pull_db
  pull_files
  post_import
  validate
  print_summary
}

main "$@"
