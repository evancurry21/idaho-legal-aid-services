#!/usr/bin/env bash
set -euo pipefail
#
# tests/ci/dual-remote-invariant.sh — PIPE-06 invariant harness.
#
# Purpose: assert that describe_remote_status emits the correct typed status token
# AND a matching recovery command for each of the four dual-remote drift types.
# Operates entirely against ephemeral /tmp repos — never touches the real remotes.
#
# PIPE-06 invariant harness.
# Phase: 03.1-publish-pipeline-audit-hardening — Plan 03.1-07 (Wave 5).
#
# Exit contract:
#   0  — all 4 cases pass (status + recovery assertions green)
#   1  — at least one case failed; [FAIL] lines emitted to stderr naming the case
#
# Usage:
#   bash tests/ci/dual-remote-invariant.sh
#
# Cases:
#   A — local-ahead / in-sync  (normal pre-push state) — no recovery expected
#   B — github remote-ahead    — recovery: npm run git:sync-master
#   C — origin remote-ahead    — recovery: npm run git:reconcile-origin
#   D — github diverged        — recovery includes git log --left-right

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if ! REPO_ROOT="$(git -C "$SCRIPT_DIR" rev-parse --show-toplevel 2>/dev/null)"; then
  REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
fi

# --- Cleanup on exit ---
# CASE_TMPDIR is set per-case; cleanup removes it on EXIT.
CASE_TMPDIR=""
# shellcheck disable=SC2317  # cleanup is invoked via trap EXIT; shellcheck does not trace traps
cleanup() {
  if [[ -n "$CASE_TMPDIR" && -d "$CASE_TMPDIR" ]]; then
    rm -rf "$CASE_TMPDIR"
  fi
}
trap cleanup EXIT

PASS_COUNT=0
FAIL_COUNT=0

pass_case() {
  local label="$1"
  printf '[ok] Case %s\n' "$label"
  PASS_COUNT=$(( PASS_COUNT + 1 ))
}

fail_case() {
  local label="$1"
  shift
  printf '[FAIL] Case %s: %s\n' "$label" "$*" >&2
  FAIL_COUNT=$(( FAIL_COUNT + 1 ))
}

# --- Helper: initialize a fresh synthetic repo set ---
# Sets CASE_TMPDIR and populates:
#   $CASE_TMPDIR/remote-github  (bare repo)
#   $CASE_TMPDIR/remote-origin  (bare repo)
#   $CASE_TMPDIR/local          (working repo with both remotes)
# Both bare remotes start with one base commit on master.
init_repos() {
  local label="$1"

  # Remove any previous case tmpdir
  if [[ -n "$CASE_TMPDIR" && -d "$CASE_TMPDIR" ]]; then
    rm -rf "$CASE_TMPDIR"
  fi
  CASE_TMPDIR="$(mktemp -d "/tmp/dre-test-$$.XXXXXX")"

  local remote_github="$CASE_TMPDIR/remote-github"
  local remote_origin="$CASE_TMPDIR/remote-origin"
  local local_repo="$CASE_TMPDIR/local"

  git init --bare --quiet "$remote_github"
  git init --bare --quiet "$remote_origin"

  git init --quiet "$local_repo"
  git -C "$local_repo" config user.email "test@example.com"
  git -C "$local_repo" config user.name "Test"
  # Ensure we're on master (git may default to main)
  git -C "$local_repo" checkout -b master >/dev/null 2>&1 \
    || git -C "$local_repo" branch -M master >/dev/null 2>&1 \
    || true
  printf 'base commit for case %s\n' "$label" > "$local_repo/base.txt"
  git -C "$local_repo" add base.txt
  git -C "$local_repo" commit --quiet -m "base: case $label"
  git -C "$local_repo" remote add github "$remote_github"
  git -C "$local_repo" remote add origin "$remote_origin"
  git -C "$local_repo" push --quiet github master
  git -C "$local_repo" push --quiet origin master
}

# ---------------------------------------------------------------------------
# Case A: github local-ahead, origin local-ahead
# Represents the normal pre-push state: local has new commits remotes haven't seen.
# Expected: github status=local-ahead (no recovery), origin status=local-ahead (no recovery)
# ---------------------------------------------------------------------------
run_case_a() {
  init_repos "A"

  local local_repo="$CASE_TMPDIR/local"

  # Add a local commit that hasn't been pushed to either remote
  printf 'local work\n' >> "$local_repo/base.txt"
  git -C "$local_repo" add base.txt
  git -C "$local_repo" commit --quiet -m "local: unpushed commit"

  # Source common.sh from the REAL repo; override REPO_ROOT to the synthetic local repo
  # shellcheck source=../git/common.sh
  source "$REPO_ROOT/scripts/git/common.sh"
  local saved_repo_root="$REPO_ROOT"
  REPO_ROOT="$local_repo"

  IFS=$'\t' read -r status _c2 _c3 recovery < <(describe_remote_status github master)

  REPO_ROOT="$saved_repo_root"

  if [[ "$status" != "local-ahead" ]]; then
    fail_case "A" "github status: expected=local-ahead actual=$status"
    return
  fi
  if [[ -n "$recovery" ]]; then
    fail_case "A" "github recovery should be empty for local-ahead, got: $recovery"
    return
  fi

  REPO_ROOT="$local_repo"
  IFS=$'\t' read -r origin_status _c2 _c3 origin_recovery < <(describe_remote_status origin master)
  REPO_ROOT="$saved_repo_root"

  if [[ "$origin_status" != "local-ahead" && "$origin_status" != "in-sync" ]]; then
    fail_case "A" "origin status: expected=local-ahead or in-sync actual=$origin_status"
    return
  fi
  if [[ -n "$origin_recovery" ]]; then
    fail_case "A" "origin recovery should be empty for $origin_status, got: $origin_recovery"
    return
  fi

  pass_case "A (github=local-ahead, origin=local-ahead, no recovery)"
}

# ---------------------------------------------------------------------------
# Case B: github remote-ahead
# Simulates: someone pushed to github that local hasn't fetched.
# Expected: status=remote-ahead, recovery="npm run git:sync-master"
# ---------------------------------------------------------------------------
run_case_b() {
  init_repos "B"

  local local_repo="$CASE_TMPDIR/local"
  local remote_github="$CASE_TMPDIR/remote-github"
  local push_work="$CASE_TMPDIR/push-work-b"

  # Push a new commit directly to the github bare remote (simulating upstream push)
  git clone --quiet "$remote_github" "$push_work"
  git -C "$push_work" config user.email "test@example.com"
  git -C "$push_work" config user.name "Test"
  printf 'upstream change\n' >> "$push_work/base.txt"
  git -C "$push_work" add base.txt
  git -C "$push_work" commit --quiet -m "upstream: commit on github"
  git -C "$push_work" push --quiet origin master

  # Fetch in local repo so it sees the new remote state
  git -C "$local_repo" fetch --quiet github

  # shellcheck source=../git/common.sh
  source "$REPO_ROOT/scripts/git/common.sh"
  local saved_repo_root="$REPO_ROOT"
  REPO_ROOT="$local_repo"

  IFS=$'\t' read -r status _c2 _c3 recovery < <(describe_remote_status github master)

  REPO_ROOT="$saved_repo_root"

  if [[ "$status" != "remote-ahead" ]]; then
    fail_case "B" "github status: expected=remote-ahead actual=$status"
    return
  fi
  if [[ "$recovery" != "npm run git:sync-master" ]]; then
    fail_case "B" "github recovery: expected='npm run git:sync-master' actual='$recovery'"
    return
  fi

  pass_case "B (github=remote-ahead, recovery=npm run git:sync-master)"
}

# ---------------------------------------------------------------------------
# Case C: origin remote-ahead
# Simulates: Pantheon received a commit (e.g. via dashboard) that local lacks.
# Expected: status=remote-ahead, recovery="npm run git:reconcile-origin"
# ---------------------------------------------------------------------------
run_case_c() {
  init_repos "C"

  local local_repo="$CASE_TMPDIR/local"
  local remote_origin="$CASE_TMPDIR/remote-origin"
  local push_work="$CASE_TMPDIR/push-work-c"

  # Push a new commit directly to the origin bare remote (simulating Pantheon code movement)
  git clone --quiet "$remote_origin" "$push_work"
  git -C "$push_work" config user.email "test@example.com"
  git -C "$push_work" config user.name "Test"
  printf 'pantheon change\n' >> "$push_work/base.txt"
  git -C "$push_work" add base.txt
  git -C "$push_work" commit --quiet -m "upstream: commit on origin/Pantheon"
  git -C "$push_work" push --quiet origin master

  # Fetch in local repo so it sees the new remote state
  git -C "$local_repo" fetch --quiet origin

  # shellcheck source=../git/common.sh
  source "$REPO_ROOT/scripts/git/common.sh"
  local saved_repo_root="$REPO_ROOT"
  REPO_ROOT="$local_repo"

  IFS=$'\t' read -r status _c2 _c3 recovery < <(describe_remote_status origin master)

  REPO_ROOT="$saved_repo_root"

  if [[ "$status" != "remote-ahead" ]]; then
    fail_case "C" "origin status: expected=remote-ahead actual=$status"
    return
  fi
  if [[ "$recovery" != "npm run git:reconcile-origin" ]]; then
    fail_case "C" "origin recovery: expected='npm run git:reconcile-origin' actual='$recovery'"
    return
  fi

  pass_case "C (origin=remote-ahead, recovery=npm run git:reconcile-origin)"
}

# ---------------------------------------------------------------------------
# Case D: github diverged from local
# Simulates: local and github/master have diverged (both have commits the other lacks).
# Expected: status=diverged, recovery includes "git log --left-right" and "git:sync-master"
# ---------------------------------------------------------------------------
run_case_d() {
  init_repos "D"

  local local_repo="$CASE_TMPDIR/local"
  local remote_github="$CASE_TMPDIR/remote-github"
  local push_work="$CASE_TMPDIR/push-work-d"

  # Push a commit to github that local won't have
  git clone --quiet "$remote_github" "$push_work"
  git -C "$push_work" config user.email "test@example.com"
  git -C "$push_work" config user.name "Test"
  printf 'github-only change\n' >> "$push_work/base.txt"
  git -C "$push_work" add base.txt
  git -C "$push_work" commit --quiet -m "diverge: commit on github"
  git -C "$push_work" push --quiet origin master

  # Add a local-only commit (diverges from github)
  printf 'local-only change\n' > "$local_repo/local-only.txt"
  git -C "$local_repo" add local-only.txt
  git -C "$local_repo" commit --quiet -m "diverge: local-only commit"

  # Fetch github to make the remote ref visible in local (but not merged)
  git -C "$local_repo" fetch --quiet github

  # shellcheck source=../git/common.sh
  source "$REPO_ROOT/scripts/git/common.sh"
  local saved_repo_root="$REPO_ROOT"
  REPO_ROOT="$local_repo"

  IFS=$'\t' read -r status _c2 _c3 recovery < <(describe_remote_status github master)

  REPO_ROOT="$saved_repo_root"

  if [[ "$status" != "diverged" ]]; then
    fail_case "D" "github status: expected=diverged actual=$status"
    return
  fi
  if [[ "$recovery" != *"git log --left-right"* ]]; then
    fail_case "D" "github recovery should include 'git log --left-right'; got: $recovery"
    return
  fi
  if [[ "$recovery" != *"git:sync-master"* ]]; then
    fail_case "D" "github recovery should include 'git:sync-master'; got: $recovery"
    return
  fi

  pass_case "D (github=diverged, recovery includes git log --left-right + git:sync-master)"
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
main() {
  printf '[info] PIPE-06 dual-remote invariant harness — 4 drift cases\n'
  printf '[info] REPO_ROOT=%s\n' "$REPO_ROOT"

  run_case_a
  run_case_b
  run_case_c
  run_case_d

  printf '\n[info] Results: %d passed, %d failed\n' "$PASS_COUNT" "$FAIL_COUNT"

  if (( FAIL_COUNT > 0 )); then
    exit 1
  fi
  exit 0
}

main "$@"
