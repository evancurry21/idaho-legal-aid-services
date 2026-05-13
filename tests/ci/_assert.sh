#!/usr/bin/env bash
# tests/ci/_assert.sh — sourceable assertion helper library for Wave 0+ CI test harnesses.
#
# Purpose: provide assert_eq / assert_command_succeeds / assert_grep / assert_not_grep with
# the same `[ok] ...` / `[FAIL] ...` logger shape as scripts/git/common.sh:11-25 (info/ok/warn/err).
#
# Phase: 03.1-publish-pipeline-audit-hardening — Plan 03.1-01 (Wave 0)
# Anti-centralization (PIPE-08): TEST-ONLY surface. Never sourced by scripts/ci/* or scripts/git/*
# (plan-check greps for accidental imports). Re-sourcing is idempotent via the guard below.
#
# Usage:
#   source "${REPO_ROOT}/tests/ci/_assert.sh"
#   assert_eq "expected" "actual" "label"
#   assert_command_succeeds "label" cmd arg1 arg2 ...
#   assert_grep "pattern" "/path/to/file" "label"
#   assert_not_grep "pattern" "/path/to/file" "label"
#
# On failure each function prints `[FAIL] <label>: <detail>` to stderr and exits 1.
# On success each function prints `[ok] <label>` to stdout.
# No `set -e` is set here — callers control their own shell options.

# --- Idempotent sourceable guard (mirrors scripts/ci/publish-gates.lib.sh:22-25) ---
if [[ -n "${ILAS_TESTS_ASSERT_LIB_SOURCED:-}" ]]; then
  return 0
fi
ILAS_TESTS_ASSERT_LIB_SOURCED=1

# --- assert_eq expected actual [label] ---
assert_eq() {
  local expected="$1"
  local actual="$2"
  local label="${3:-assertion}"
  if [[ "$expected" != "$actual" ]]; then
    printf '[FAIL] %s: expected=%s actual=%s\n' "$label" "$expected" "$actual" >&2
    exit 1
  fi
  printf '[ok] %s\n' "$label"
}

# --- assert_command_succeeds label cmd... ---
assert_command_succeeds() {
  local label="$1"
  shift
  if ! "$@"; then
    printf '[FAIL] %s: command exited non-zero: %s\n' "$label" "$*" >&2
    exit 1
  fi
  printf '[ok] %s\n' "$label"
}

# --- assert_grep pattern file [label] ---
assert_grep() {
  local pattern="$1"
  local file="$2"
  local label="${3:-assert_grep}"
  if [[ ! -f "$file" ]]; then
    printf '[FAIL] %s: file not found: %s\n' "$label" "$file" >&2
    exit 1
  fi
  if ! grep -qE "$pattern" "$file"; then
    printf '[FAIL] %s: pattern not found in %s: %s\n' "$label" "$file" "$pattern" >&2
    exit 1
  fi
  printf '[ok] %s\n' "$label"
}

# --- assert_not_grep pattern file [label] ---
assert_not_grep() {
  local pattern="$1"
  local file="$2"
  local label="${3:-assert_not_grep}"
  if [[ ! -f "$file" ]]; then
    printf '[FAIL] %s: file not found: %s\n' "$label" "$file" >&2
    exit 1
  fi
  if grep -qE "$pattern" "$file"; then
    printf '[FAIL] %s: pattern unexpectedly found in %s: %s\n' "$label" "$file" "$pattern" >&2
    exit 1
  fi
  printf '[ok] %s\n' "$label"
}
