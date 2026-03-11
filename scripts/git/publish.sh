#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=./common.sh
source "$SCRIPT_DIR/common.sh"

BRANCH=""
DO_FETCH=true
DRY_RUN=false
TARGET_MODE="both"
NO_VERIFY_ORIGIN=false
NO_VERIFY_GITHUB=false
REMOTES=("${DEFAULT_REMOTES[@]}")

usage() {
  cat <<'USAGE'
Usage:
  publish.sh [--branch <branch>] [--origin-only|--github-only] [--no-fetch]
             [--no-verify-origin] [--no-verify-github] [--dry-run]

Defaults to pushing github first, then origin, to avoid advancing Pantheon when
GitHub is the stricter remote and would fail later.

Examples:
  bash scripts/git/publish.sh
  bash scripts/git/publish.sh --origin-only --no-verify-origin
  bash scripts/git/publish.sh --github-only --dry-run
USAGE
}

parse_args() {
  while (($# > 0)); do
    case "$1" in
      --branch)
        if (($# < 2)); then
          err "Missing value for --branch"
          usage
          exit 1
        fi
        BRANCH="$2"
        shift 2
        ;;
      --branch=*)
        BRANCH="${1#*=}"
        shift
        ;;
      --origin-only)
        TARGET_MODE="origin"
        shift
        ;;
      --github-only)
        TARGET_MODE="github"
        shift
        ;;
      --no-fetch)
        DO_FETCH=false
        shift
        ;;
      --no-verify-origin)
        NO_VERIFY_ORIGIN=true
        shift
        ;;
      --no-verify-github)
        NO_VERIFY_GITHUB=true
        shift
        ;;
      --dry-run)
        DRY_RUN=true
        shift
        ;;
      -h|--help)
        usage
        exit 0
        ;;
      *)
        err "Unknown option: $1"
        usage
        exit 1
        ;;
    esac
  done
}

push_branch() {
  local remote="$1"
  local branch="$2"
  local no_verify="$3"
  local args=(git -C "$REPO_ROOT" push "$remote" "$branch:$branch")

  if [[ "$no_verify" == "true" ]]; then
    args=(git -C "$REPO_ROOT" push --no-verify "$remote" "$branch:$branch")
  fi

  if "$DRY_RUN"; then
    print_cmd "${args[@]}"
    return 0
  fi

  "${args[@]}"
}

main() {
  local branch=""
  local remote=""
  local status=""
  local remote_only=""
  local local_only=""
  local no_verify=false
  local targets=()
  declare -A remote_status=()

  parse_args "$@"

  branch="$(ensure_named_branch "$BRANCH")"
  require_local_branch "$branch"

  info "Publishing branch '$branch' from $REPO_ROOT"
  warn_if_worktree_dirty

  if "$DO_FETCH"; then
    for remote in "${REMOTES[@]}"; do
      fetch_remote "$remote"
    done
  fi

  for remote in "${REMOTES[@]}"; do
    IFS=$'\t' read -r status remote_only local_only < <(describe_remote_status "$remote" "$branch")
    remote_status["$remote"]="$status"
    print_remote_status "$remote" "$branch" "$status" "$remote_only" "$local_only"
  done

  case "$TARGET_MODE" in
    both)
      targets=(github origin)
      ;;
    origin)
      targets=(origin)
      ;;
    github)
      targets=(github)
      ;;
    *)
      err "Unknown target mode: $TARGET_MODE"
      exit 1
      ;;
  esac

  for remote in "${targets[@]}"; do
    status="${remote_status[$remote]}"
    if [[ "$status" == "remote-ahead" || "$status" == "diverged" ]]; then
      err "Refusing to push $remote/$branch while status is '$status'."
      err "Inspect with: git log --left-right --cherry-pick --oneline $remote/$branch...$branch"
      exit 1
    fi
  done

  if [[ "$TARGET_MODE" == "origin" && "${remote_status[github]}" != "in-sync" ]]; then
    warn "github/$branch will remain '${remote_status[github]}' after this origin-only publish."
  fi

  if [[ "$TARGET_MODE" == "github" && "${remote_status[origin]}" != "in-sync" ]]; then
    warn "origin/$branch will remain '${remote_status[origin]}' after this github-only publish."
  fi

  for remote in "${targets[@]}"; do
    status="${remote_status[$remote]}"

    if [[ "$status" == "in-sync" ]]; then
      info "Skipping $remote/$branch; already in sync."
      continue
    fi

    no_verify=false
    if [[ "$remote" == "origin" && "$NO_VERIFY_ORIGIN" == "true" ]]; then
      no_verify=true
      warn "Bypassing pre-push hooks for origin/$branch."
    elif [[ "$remote" == "github" && "$NO_VERIFY_GITHUB" == "true" ]]; then
      no_verify=true
      warn "Bypassing pre-push hooks for github/$branch."
    fi

    info "Pushing $branch to $remote/$branch..."
    push_branch "$remote" "$branch" "$no_verify"
  done

  if "$DRY_RUN"; then
    ok "Dry-run publish plan complete."
  else
    ok "Publish sequence complete."
  fi
}

main "$@"
