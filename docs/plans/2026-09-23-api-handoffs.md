# API and WebUI group controls

Approved behavior: both source checkboxes default on, at least one is required to save, and unchecked sources permit starts without that group's handoff rules. Start requests still obey other overlapping groups enabled for their source. Ordinary stops start nothing else. Target Unraid 7.3.x and the user's API 4.37.4+ad268301 (official commit ad268301ca78da1fa47fd3bb87e60fcedc458c5b).

## Implementation sequence

1. Source-aware configuration, routing and jobs.
   - Default WebUI/API to true. Filter group plans by trusted source, persist source on jobs and include it in duplicate identity.
   - Browser endpoints assign WebUI; local API bridge assigns API. Neither trusts a caller-supplied source.
   - VM starts with no enabled handoff group still need a short-lived authorization through a job, with no conflict shutdown. Docker bypass stays native.
   - Test defaults, invalid selections, overlaps, bulk conflicts, unchecked-source VM starts and concurrent requests.
2. Authenticated API extension and local PHP bridge.
   - Load a packaged Nest API module using Unraid's plugin mechanism. Keep existing GraphQL resolver authentication/authorization intact.
   - Carry GraphQL context through original resolvers into wrappers around the verified Docker/VM service methods. Perform handoffs only after the original guards allow the operation.
   - Check native UPDATE_ANY permissions for every affected Docker/VM resource type, and recheck allowed types against the actual plan under the registry lock.
   - Validate all selected start-like fields in an API operation before the first mutation. Account for aliases, variables and fragments.
   - Use argv-array PHP execution and bounded JSON. Accepted jobs continue independently of client connections; preserve native API result shapes without submitting a second start.
   - Test actual GraphQL routing and authorization with simulated platform operations, failures, bypass, duplicate/concurrent clients and stale integration.
3. Installation, activation and status.
   - Package the API module locally; install it offline with the API's existing Node/npm environment, register via plugin CLI without restarting services, and archive native dependencies for reboot.
   - Track live API adapter identity and code hash in RAM. Report inactive/stale API coverage separately from VM gate status.
   - Preserve configuration and other API plugins through update/remove; unregister only this module. Loaded copies become inert after uninstall.
   - No idle maintenance timer and no automatic service restart. API extension changes require an API restart; unchanged UI updates do not.
4. Documentation, packaging and verification.
   - Update setup boundaries, API activation instructions and host checks; produce next reproducible beta package.
   - Run PHP/JS/browser/package/install verification, then one independent safety review and fix confirmed findings.

## Review focus

Authorization before side effects; cross-resource permissions; source spoofing; configuration changes between permission checks and job admission; overlapping source policies; native bypass permission consumption; multi-field API batches; worker/API crashes; plugin/module activation gaps; persistence across reboot and removal; no hidden Docker force-kill.

## Progress

Steps 1–3 implemented. The user's clarification supersedes the initial API=false draft: both default true, unchecked means bypass.

- Task 1 complete: source/configuration/VM permit tests pass, including overlaps, strict validation, permissions and concurrency.
- Task 2 complete: 9 GraphQL tests and a real Nest bootstrap fixture pass. Review caught native API reboot shutdown/create; fixed with guest reboot routing and pre-mutation grouped Reset rejection, with RED→GREEN regression evidence.
- Task 3 complete for beta installation/status: offline npm install/update verified in a disposable directory, 63 PHP tests pass including repair/removal. Important limitation: node_modules archive does not preserve API root peer metadata; install/startup restores it but cannot guarantee it precedes API startup. Manual API restart may be required after boot. Pending activation is visible. Host boot order and native built-service testing remain publication gates.
- Task 4 complete for local beta: built 2026.09.23a2. Verified 63 PHP tests, 9 browser-integration unit tests, 9 GraphQL tests plus real Nest bootstrap, both Playwright browser suites, reproducible nested archives/XML, actual bundled tarball offline npm install/remove, and disposable Linux manifest install/update/remove. Visually inspected settings. Host/API boot tests remain pending. One independent safety review completed. No other Important/Critical finding in authorization, reservations, source filtering or stale module handling. No changes committed or published.
