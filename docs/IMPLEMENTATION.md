# Deadlock Guard implementation record

Approved scope: native Unraid 7.3.x exclusive resource groups; overlapping memberships;
normal VM, Docker and Dashboard controls; graceful stop before start; configurable
120s VM / 30s container deadlines and opt-in force stop; no automatic rollback/start
on ordinary stops; asynchronous jobs; a local-only libvirt admission hook; native
settings UI, install/update/remove, reproducible beta packaging and CA metadata.

## Delivery sequence
1. Configuration, persistent reservations, idempotent jobs, coordinator and simulated platform tests.
2. Native Docker/libvirt adapters, local authorization hook, recovery and lifecycle tests.
3. Authenticated API, native WebGUI dispatch wrappers and settings, browser contract tests.
4. Reproducible package/manifest, documentation, CI and installation tests.
5. Independent review and final verification.

## Boundaries
Docker CLI/API, restart policies, autostart, recreation/update starts and GPU driver
rebinding are outside enforcement. Grouped VM starts without coordinator permission
are rejected. Real Unraid host smoke tests are required before verified compatibility.

## Decisions and evidence
- The user's pasted plan is the approved specification; no additional spec approval is needed.
- Work occurs in a Codex-managed worktree on codex/deadlock-guard.
- Use PHP 8.3 CLI tests in Docker; production uses Unraid's bundled PHP only.
- Shared contract: versioned JSON configuration -> job planner -> durable RAM reservations
  -> worker -> platform adapters. Hook consumes permission records and writes lifecycle events;
  it never acquires job reservations or calls libvirt.
- For overlapping policy values, use the longest graceful deadline and require every relevant
  group to opt into force-stop. This gives each group's protected member its full grace period.
- Uncertain in-flight operations quarantine reservations until an explicit, proven-safe recovery;
  no time-based automatic unlock. Runtime epoch changes invalidate old permissions.

## Implementation progress
- Configuration/coordinator: all four directions, overlap, grace/force, release, failure and action semantics tested with simulated platforms.
- Hooks/recovery: local-only hook, live-worker and single-use tokens, independent hook installation, reboot activation, persistent uninstall marker, conservative orphan reconciliation.
- Native/API/UI: verified native 7.3 dispatch and session/CSRF contracts; server routing, escaped settings, progress, console variants and browser fixtures.
- Packaging: deterministic tar/XZ, SHA-256 and native manifest checksums, matching CA metadata, original SVG, MIT attribution, local validation and CI artifact workflow.
- Host validation and public CA Validate/Scan remain external release gates. No Unraid host or public release was available in this task.
- Ruling: uncertain submitted operations require host reboot to clear quarantine — daemon/client termination cannot establish cancellation — cost: a conservative recovery may require downtime after a benign transport failure.
- Ruling: overlapping stop policies use the longest timeout and unanimous force permission — respects every group's grace and force choices — cost: a handoff may wait longer than one group's shorter setting.

## Final independent review
The fresh-context reviewer found three Important lifecycle defects and no confirmed Critical defects. All three were reproduced before fixes.
- Final: fixed refused maintenance interrupting active handoffs — `refused removal and upgrade leave active handoffs and admission intact` RED→GREEN; also `aborted updates recover admission only when updater is gone and payload is unchanged` RED→GREEN.
- Final: fixed stale reconciliation resurrecting removal and periodic flash writes — `checks cannot resurrect removal or rewrite persistent install intent` RED→GREEN.
- Final: fixed persistent hook rejecting unrelated VMs when current-boot payload is absent/unsupported — packaged executable-hook checks in `tests/install-smoke.php` RED→GREEN.
- Final suite: 35 PHP tests, 6 JS control tests, deterministic package/XML checks, Chromium browser fixtures, PHP/Bash syntax and disposable Linux manifest install/update/remove + actual hook execution passed. No review findings deferred. Real Unraid host tests and public CA checks remain pending.
