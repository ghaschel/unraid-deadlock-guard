# Deadlock Guard implementation record

Approved scope: native Unraid 7.3.x exclusive resource groups; overlapping memberships;
normal VM, Docker and Dashboard controls; confirmed stop before start; configurable
120s VM / 30s container deadlines, native Docker Stop and opt-in VM force stop; no automatic rollback/start
on ordinary stops; asynchronous jobs; a local-only libvirt admission hook; native
settings UI, install/update/remove, reproducible beta packaging and CA metadata.

## Delivery sequence
1. Configuration, persistent reservations, idempotent jobs, coordinator and simulated platform tests.
2. Native Docker/libvirt adapters, local authorization hook, recovery and lifecycle tests.
3. Authenticated API, native WebGUI dispatch wrappers and settings, browser contract tests.
4. Reproducible package/manifest, documentation, CI and installation tests.
5. Independent review and final verification.

## Boundaries
Direct Docker CLI/Engine API, restart policies, autostart, recreation/update starts and GPU
driver rebinding are outside enforcement. Supported Unraid API actions are coordinated by
the native adapter when it is active and the group enables API handoffs. Grouped VM starts without coordinator permission
are rejected. Real Unraid host smoke tests are required before verified compatibility.

## Decisions and evidence
- The user's pasted plan is the approved specification; no additional spec approval is needed.
- Work occurs in a Codex-managed worktree on codex/deadlock-guard.
- Use PHP 8.3 CLI tests in Docker; production uses Unraid's bundled PHP and the existing Unraid API Node runtime; no additional host runtime is installed.
- Shared contract: versioned JSON configuration -> job planner -> durable RAM reservations
  -> worker -> platform adapters. Hook consumes permission records and writes lifecycle events;
  it never acquires job reservations or calls libvirt.
- For overlapping policy values, use the longest graceful deadline and require every relevant
  group to opt into VM force-stop. Containers use native Docker Stop with automatic escalation. This gives each group's protected member its full grace period.
- Uncertain in-flight operations quarantine reservations until an explicit, proven-safe recovery;
  no time-based automatic unlock. Runtime epoch changes invalidate old permissions.

## Implementation progress
- Configuration/coordinator: all four directions, overlap, grace/force, release, failure and action semantics tested with simulated platforms.
- Hooks/recovery: local-only hook, live-worker and single-use tokens, independent hook installation, reboot activation, persistent uninstall marker, conservative orphan reconciliation.
- Native/API/UI: verified native 7.3 dispatch and session/CSRF contracts; server routing, escaped settings, progress, console variants and browser fixtures.
- Packaging: deterministic tar/XZ, SHA-256 and native manifest checksums, matching CA metadata, original SVG, MIT attribution, local validation and CI artifact workflow.
- Host validation and public CA Validate/Scan remain external release gates. No Unraid host or public release was available in this task.
- Ruling: uncertain submitted operations require host reboot to clear quarantine — daemon/client termination cannot establish cancellation — cost: a conservative recovery may require downtime after a benign transport failure.
- Ruling: overlapping stop policies use the longest timeout and unanimous VM force permission — respects every group's grace and VM force choices — cost: a handoff may wait longer than one group's shorter setting.

## Final independent review
The fresh-context reviewer found three Important lifecycle defects and no confirmed Critical defects. All three were reproduced before fixes.
- Final: fixed refused maintenance interrupting active handoffs — `refused removal and upgrade leave active handoffs and admission intact` RED→GREEN; also `aborted updates recover admission only when updater is gone and payload is unchanged` RED→GREEN.
- Final: fixed stale reconciliation resurrecting removal and periodic flash writes — `checks cannot resurrect removal or rewrite persistent install intent` RED→GREEN.
- Final: fixed persistent hook rejecting unrelated VMs when current-boot payload is absent/unsupported — packaged executable-hook checks in `tests/install-smoke.php` RED→GREEN.
- Final suite: 35 PHP tests, 6 JS control tests, deterministic package/XML checks, Chromium browser fixtures, PHP/Bash syntax and disposable Linux manifest install/update/remove + actual hook execution passed. No review findings deferred. Real Unraid host tests and public CA checks remain pending.


## Event-based maintenance (2026.09.22a8)

Accepted scope: remove idle minute cron; retain lifecycle/admission checks and worker-exit recovery; split troubleshooting into Check Integration and Check pending jobs. History remains on its existing read-only three-second refresh while Settings is visible.

- Recovery design: a detached per-job supervisor waits for its child and performs scoped recovery after exit. Running jobs record their worker identity; queued jobs also record supervisor identity to avoid duplicate child launches. No idle worker or daemon remains.
- Safety: invalid batches are rejected before recovery; state inspection precedes admission; uncertain operations retain reservations without time-based expiry. If both processes die, the next request/event/manual check reviews the job.
- Separation: integration endpoint touches integration only; pending endpoint reviews abandoned jobs and retries queued jobs. Both preserve unsaved form edits. Docker startup only checks pending jobs.
- Migration: installation removes only the old owned cron file and rebuilds Unraid's crontab.
- Review fixes: keep Docker-only admission independent of the VM hook; resolve accepted duplicate requests before service checks; preserve update barriers across array events and preserve an ongoing array stop across installation. Each regression was observed failing and then passing.
- Final verification: 46 PHP tests, 7 JS control tests, both browser suites (including seven settings recovery cases), PHP/Bash syntax, reproducible package/XML, SHA-256, and disposable Linux install/update/skip-detection/retry/remove checks passed. The installed detached supervisor/worker path exits after failure without a browser, legacy cron migration preserves foreign files, and the expanded Troubleshooting fixture was visually checked. No review findings remain deferred. Real Unraid lifecycle-event delivery and hardware handoffs still require host validation.


## Packaging readability (2026.09.22a9)

- Renamed `source/` to `src/` and updated development scripts, PHP/JS tests and browser fixtures. Installed filesystem paths are unchanged.
- Replaced compressed Python statements with named build steps and moved XML/Bash into `packaging/deadlock-guard.plg.template`. Moved the package description to `src/install/slack-desc`.
- Removed unreleased cron migration from normal installation/removal. The early-test cleanup is documented as a one-time action for Tower. The installer preserves the shortened messages already edited by the user.
- Preserved deterministic archives, native manifest checksums, exact-package installation, post-install version verification and configuration retention.
- Verification: 46 PHP tests, 7 JS tests, both browser suites, reproducible package/XML checks, checksums and disposable install/update/remove tests passed. Compared all 45 archive entries with the previous build: installed paths and permissions are identical, and only doinst.sh and VERSION contents changed. No runtime code changed during this refactor.


## Native notifications and stable history (2026.09.22a10)

- Replaced the custom notification popup and styles with Unraid's existing global toast API. Progress updates use a plugin-specific ID; success expires after five seconds, errors remain dismissible, and blocked consoles offer a native action. Final results fall back to Unraid's existing plain-text SweetAlert dialog if the toaster is unavailable.
- Keyed history rows by job ID and update only changed text. Read-only polling preserves open details, manual collapse and unchanged DOM nodes instead of rebuilding every row. Tests reproduced the collapse and missing native API calls before the fixes, then passed afterward.
- Formatted the remaining settings CSS and documented native UI host checks. The test-only toast double checks calls and lifetimes, not Unraid's native visual appearance.
- Host evidence: ComfyUI remained running after the 30-second graceful deadline. After the user stopped it through Unraid, inspection reported an unset StopSignal, OOMKilled=false, ExitCode=137 and RestartPolicy=no. This is consistent with a later forced stop. This originally used opt-in container force-stop. The native container Stop change below replaces that policy.
- Verification: 46 PHP tests, 9 JavaScript tests, both browser suites (including seven settings recovery cases), reproducible package/XML, PHP/Bash syntax, SHA-256 and disposable install/update/remove checks passed. Package comparison against a9 found only VERSION, integration.js, settings.js and the settings CSS changed; hooks and backend files are byte-identical. Real Unraid notification appearance and complete disposable-host validation remain pending.

## Integration status colors and external-app audit (2026.09.23a1)

- Successful initial loads and manual integration checks now render the active VM safety gate message in green. Unavailable/pending protection and failed integration requests render in red. Loading remains neutral.
- Both successful response paths share one health renderer. A failed manual integration request replaces a previously green status with a visible error.
- Audited external controls without changing enforcement: browser wrappers route starts to the coordinator, while the VM hook only authorizes or rejects startup. There is no Unraid GraphQL interception. nzb360's developer identifies its integration as an Unraid API client, so its starts do not receive handoffs. Standalone stops continue to start nothing else.
- Verification: inspected computed browser colors for initial ready/unavailable state, both manual check results and a failed request. Both browser suites, 46 PHP tests, 9 JavaScript tests, syntax checks and reproducible package/XML checks passed. The package changes only settings JavaScript, CSS and VERSION relative to a10. Real host validation remains pending.

## Per-group WebUI/API controls — 2026.09.23a2

- User clarified that unchecked sources bypass handoffs and both default on. Existing groups normalize both fields to true; browser/server require at least one selection. Source-filtered plans preserve overlapping group checks, reservations and permissions.
- Added a native Nest module for Unraid API 4.37.4 (ad268301), preserving GraphQL guards and cross-resource permissions. A root-only PHP bridge feeds the existing coordinator and never repeats the native start on success. Native result shapes, ordinary stops and ungrouped actions remain intact.
- Grouped API reboot uses guest reboot even when API is unchecked, avoiding the upstream shutdown/create gate failure. Grouped destructive Reset rejects before mutation. Meaningful regressions failed before these fixes and passed afterward.
- API installation copies the bundled dependency-free module and registers it through the native config-only plugin CLI without npm. The final plugin installation step then runs `unraid-api restart` once after setup and archiving succeed. Native dependencies, lockfiles and other plugins are preserved through install/update/remove. Live process/code proof drives a separate green/red API status. Partial module copies can be repaired and unregistered copies removed.
- Independent safety review confirmed the reboot issue (fixed) and boot-order limitation (visible and documented). Native archives do not preserve root package metadata, so API boot before lifecycle restoration requires manual API restart. Actual host boot/API update tests are required before publication; automatic activation is not claimed.
- Local verification includes PHP coordination/configuration/installer tests, GraphQL concurrency/authorization/routing tests, real Nest DI/bootstrap with simulated host files/services, both browser suites, reproducible package/XML checks and Linux install/update/remove smoke tests. See the plan for final counts/results.

## Repository cleanup — 2026.09.23a3

- Removed the unread VM permit nonce, unused API test counter/dependency, and inspection fields left behind by the removed status table. VM console metadata now includes the domain name; its regression test failed before the fix and passed afterward.
- Split coordination into validation, conflict shutdown, target execution, confirmation and failure recording. Extracted lifecycle hook/configuration/activation/maintenance helpers while preserving registry locks, operation ordering, repeated validation and unresolved reservations.
- Made platform inspection, HTTP/CLI dispatch, settings editor sections and native control wrappers readable. Named limits and command arguments; retained the documented inventory and job endpoints. Kept standalone hook and Unraid event entrypoints unchanged.
- Moved shared PHP test support and process fixtures into explicit support/fixture directories. Removed starter-era conditional loads and silent import failures. Added pinned development-only Prettier/PHP tooling, EditorConfig and CI formatting validation. Pinned Playwright 1.55.1 in place of the old ad-hoc 1.55.0 download dependency.
- Kept all existing uncommitted feature work. Reviewed cleanup against a pre-cleanup file snapshot. The independent review found no Critical, Important or Minor issues; live Unraid boot ordering/API activation/hardware release still require host validation.
- Verification: 64 PHP tests, 9 native-control tests, 10 API tests, both browser suites including all seven settings recovery cases, PHP/Bash syntax, formatting, deterministic package/XML/checksums and disposable Linux install/update/remove/foreign-hook/config preservation passed. The settings fixture was visually inspected.
- Update effect: QEMU hook bytes are identical, so an already activated VM safety gate needs no reboot for this cleanup. The API module hash changes; the installer restarts Unraid API after setup. Reload WebGUI tabs afterward. Nothing is published by this cleanup.


## Native container Stop

- User approved replacing the container force-stop checkbox with Unraid’s normal Docker Stop behavior for every handoff. Docker honors the container’s configured signal and escalates after the group’s container timeout; overlapping groups use the longest timeout. Existing configurations retain their groups and VM force choice while discarding the obsolete container force field.
- The background worker waits for Docker Stop with a bounded completion allowance, then confirms the container is stopped before starting the requested VM or container. Lost responses retain reservations. VM shutdown, optional VM force-stop, and ordinary Stop controls retain their existing behavior.
- Keep the current version and release metadata unchanged while further requested changes are collected. This change still needs a ComfyUI-to-Windows handoff test on Tower without `--init`.
