# Release workflow and API compatibility

Approved plan: implement in `codex/release-workflow` from main. Preserve Unraid 7.3.x, native authorization and handoff behavior, private repository visibility, and the published manifest URL. Do not publish a release or redesign the README.

## Tasks

1. Fix API eligibility to accept semantic versions from 4.36.0, including build metadata. Check native schema and providers before atomically installing wrappers. Test installation recovery and runtime diagnostics.
2. Implement a main-only manual release with automatic UTC date versions, PR-generated notes, reproducible artifacts, draft/upload/verify/publish ordering, and manifest promotion last. Persist candidates for safe reruns. Dry runs never write remote state.
3. Update release documentation and CI; exercise publication failures, concurrency, API and browser regressions, package reproducibility, and install/update/remove. Perform a fresh final review.

## Acceptance

API 4.37.4+ad268301 activates when its interfaces match. Future versions are eligible by capabilities, not certified by number alone. Releases retain immutable tags/assets and never advertise unavailable packages. Existing source changes on main survive metadata promotion. Tower validation remains a required maintainer step.

## Implementation verification

Completed locally: 67 PHP tests, 9 native JavaScript tests, 44 API tests (also run on Node 22), and 28 release tests; both browser suites; package/XML and reproducibility checks; install/update/remove smoke; formatter and workflow lint. Linux Python 3.12 builds match local artifacts byte-for-byte. The published root manifest remains unchanged.

The independent branch review found an interrupted-upload recovery gap. A regression reproduced it, and recovery now removes only empty `starter` placeholders in a verified owned draft. Uploaded and published assets remain immutable. The full suite passed after the fix.

Tower behavior remains unverified, as required by the plan; skipping host validation could miss platform-specific failures. GitHub's first-release notes boundary with hypothetical preexisting unpublished tags was not certified; this repository has no such tags, and the maintainer must inspect the first dry run's notes before publishing to avoid omitted history. Later releases explicitly identify the previous published tag.
