# GitGuardian CI

This repo now has a dedicated GitHub Actions workflow for GitGuardian secret
scanning:

- workflow name: `GitGuardian CI`
- required check name to use in branch protection: `GitGuardian CI Scan`
- workflow file: `.github/workflows/gitguardian-ci.yml`

The scan is intentionally isolated from the existing `Quality Gate` and
Pantheon deploy scripts. It does not modify local hooks, `publish.sh`,
`finish.sh`, or any Promptfoo logic.

## Required GitHub Secret

Configure this secret in GitHub before expecting the workflow to pass on
same-repo pull requests or protected-branch pushes:

- `GITGUARDIAN_API_KEY`

Preferred credential source:

1. GitGuardian service account token if the workspace is on the Business plan
2. GitGuardian personal access token otherwise

Where to get it:

- Service accounts: GitGuardian dashboard -> API -> Service accounts
- Personal access tokens: GitGuardian dashboard -> API -> Personal access tokens

Keep this token in GitHub Secrets only. Do not place it in Pantheon runtime
secrets, local hook scripts, or committed files.

## Trigger Events

The workflow runs on:

- pull requests targeting `master`
- pull requests targeting `release/**`
- pushes to `master`
- pushes to `release/**`

It does not trigger on `main` because the GitHub repository currently uses
`master` only.

## Failure Behavior

- If `ggshield` detects a new secret in a same-repo PR or protected push, the
  `GitGuardian CI Scan` job fails.
- For fork-origin pull requests, the job emits a notice and exits cleanly
  because GitHub does not expose repository secrets to `pull_request`
  workflows from forks.

This workflow is commit-range based. It is meant to block newly introduced
secrets, not to retroactively fail the repo for historical alert debt.

## Interaction With Native GitGuardian Monitoring

Keep GitGuardian repository monitoring enabled.

Recommended split of responsibilities:

- `GitGuardian CI Scan`: repo-owned GitHub Actions enforcement
- `GitGuardian Security Checks`: informational monitoring signal, dashboard
  visibility, and fork-PR visibility

Operational guidance:

- require `GitGuardian CI Scan` in GitHub branch protection after the workflow
  has produced a successful run
- do not require `GitGuardian Security Checks`
- if you want the native GitGuardian check to be fully informational, set its
  GitHub check-run conclusion to `Neutral` in GitGuardian settings

## Safe Test Procedure

Use GitGuardian's documented test token format on a temporary same-repo branch.
Do not store a detector-valid test token in repo-tracked documentation. Pull the
current example token from GitGuardian's detector docs when you run the test:

- https://docs.gitguardian.com/secrets-detection/secrets-detection-engine/detectors/specifics/gitguardian_test_token_checked

Recommended validation flow:

1. Create a temporary branch in this repo.
2. Add a disposable file containing a GitGuardian-documented test token.
3. Open a draft PR to `master`.
4. Confirm `GitGuardian CI Scan` fails.
5. Remove the token in a follow-up commit.
6. Confirm `GitGuardian CI Scan` passes.
7. Merge a clean PR and confirm the workflow also passes on the resulting
   `master` push.

Do not test a secret-bearing direct push to `master`.

## Post-Implementation Follow-Up

After the first successful run:

1. Add `GitGuardian CI Scan` to `master` branch protection required checks.
2. Leave `GitGuardian Security Checks` non-required.
3. If release branches later become protected, add the same required check
   there.
