# Deadlock Guard

A native **Unraid 7.3.x beta** plugin for handing shared resources between VMs and Docker containers. Configure an exclusive group in **Settings → Deadlock Guard**, then use the normal **VMs, Docker, or Dashboard** Start controls or a supported Unraid API client. Deadlock Guard stops conflicting members, confirms release, and starts the requested member. Ordinary Stop never starts something else.

**Host compatibility is not yet verified.** This implementation has simulated platform tests and browser fixtures based on Unraid 7.3. Run the [disposable-host validation checklist](docs/HOST-VALIDATION.md) before publishing or using it with important workloads. Minimum declared version: **7.3.0**; this beta deliberately does not support older versions or 7.4+.

## Behavior

- Each group has **WebUI** and **API** checkboxes, both enabled by default, including existing groups. At least one must be selected to save. Unchecked sources allow actions without that group’s handoffs; other groups enabled for the source still apply.
- VM ↔ container, VM ↔ VM and container ↔ container handoffs; membership in multiple groups.
- Start, Start with Console (browser or Remote Viewer), Restart and Resume. VM Restart remains a guest reboot. Container Restart uses the same graceful stop policy before starting again.
- Bulk starts are rejected before any stop if they request two members of the same exclusive group. Choose an individual workload instead.
- VMs are identified by UUID; containers by exact name, with fresh IDs pinned during each job. Renamed or missing members need repair. A container recreated during a handoff causes that handoff to fail.
- Default grace periods: **120 seconds for VMs**, **30 seconds for containers**. Each group has separate force-stop switches, off by default. For overlapping policies, the longest timeout applies and every relevant group must permit force.
- Accepted jobs survive browser closure. Duplicate clicks join the active job; competing requests receive a busy error. Failure leaves stopped workloads stopped.
- Discard unsaved group changes with **Discard changes**. **Troubleshooting** has separate **Check Integration** and **Check pending jobs** buttons. There is no idle maintenance timer.
- Progress and save confirmations use Unraid's native notifications, with names and VM/Docker labels. Group saves explain when changes apply. Success notifications close after five seconds; errors remain until dismissed. Expanded handoff details stay open during history refreshes, with job IDs available there for troubleshooting.

## Coverage boundaries

This plugin **does not provide system-wide exclusivity**. Direct Docker CLI/Engine API starts, restart policies, Unraid autostart, and update/recreation-triggered starts bypass its controls. Disable automatic starts and restart policies for grouped containers.

An independently named libvirt QEMU hook rejects grouped VM starts without a short-lived, single-use coordinator authorization. This includes terminal commands, scripts and VM autostart. Disable autostart for grouped VMs. Managed-save restore, migration and external attach are also rejected for grouped VMs in this beta; shut the VM down normally and use Start. Resume from pause or guest suspend is supported through native controls.

The API adapter requires **Unraid API 4.36.0 or newer** with compatible interfaces, including versions such as `4.37.4+ad268301`. Once Settings shows **API handoffs installed and activated**, clients such as nzb360 use the same coordinator for Docker start/restart/unpause and VM start/resume/reboot. The caller needs update permission for every VM/container resource type affected by the handoff. Ordinary stops stay native and start nothing else.

Grouped VM API reboots use a guest reboot, including when API handoffs are unchecked. The native API's shutdown/create sequence cannot pass the VM gate without coordination. Grouped VM **Reset** is rejected before it destroys the VM; use Reboot or Stop then Start. Ungrouped API actions retain their native behavior. Unchecked grouped VM starts get a single-use authorization without stopping conflicts.

**An enabled API checkbox does not activate the adapter.** Until the adapter loads, Docker API starts bypass handoffs and grouped VM starts are rejected by the active gate. See activation below.

GPU/PCI binding, device release behavior and guest drivers remain the administrator's responsibility. Force-stop can lose unsaved data. A hook does not replace correct IOMMU/device configuration.

## Build and install for testing

Development tools are PHP 8.3 (or Docker for the tests), Node.js and Python 3. The installed plugin uses Unraid's PHP, Bash, Docker CLI, libvirt tools, and the Unraid API's existing Node/npm runtime; it requires no additional container, database or runtime.

```sh
npm ci --prefix tests/api --ignore-scripts --legacy-peer-deps
./scripts/test.sh
python3 scripts/build.py
```

The repository separates the installed files from the build tooling:

- `src/` mirrors the filesystem installed on Unraid, including `install/doinst.sh` and the Slackware package description.
- `packaging/deadlock-guard.plg.template` contains the plugin metadata and readable installation/removal commands.
- `scripts/build.py` packages `src/`, fills the template's release values, and writes checksums. Edit the template rather than the generated `.plg`.

This produces a reproducible `.txz`, `deadlock-guard.plg`, and `SHA256SUMS` under `dist/`. The generated manifest in `dist/` uses the new package checksum; the published repository manifest stays unchanged. The `.plg` references a versioned GitHub release asset; those URLs only work **after publishing the matching release**. For pre-release host validation, transfer the three artifacts to the disposable host, put the `.txz` at the package path in the manifest, verify its SHA-256, and install the local `.plg` using Unraid's `plugin install /path/to/deadlock-guard.plg` command. See the [host checklist](docs/HOST-VALIDATION.md).

If you installed an early local test build with the minute cron, follow the [one-time test cleanup](docs/HOST-VALIDATION.md#one-time-cleanup-for-early-local-test-builds). Normal installation and removal do not carry unreleased test migrations.

**Reboot Unraid after first installation to activate VM protection.** Plan downtime, shut down workloads gracefully, and use Unraid's normal reboot control. The plugin never reboots the host or restarts services automatically. After reboot, reload every existing WebGUI tab and open **Settings → Deadlock Guard**. Confirm the VM safety gate is activated before testing handoffs. Add a group with at least two members and save.

After an upgrade, reboot again if Settings reports **Pending activation**. Manual VM-service toggling is not the supported activation procedure: stopping and re-enabling it can leave `libvirt.img` attached to a loop device and prevent the service from starting. If libvirt remains unavailable after reboot, inspect its startup logs before testing handoffs.

API installation bundles its module locally and registers it without restarting services. After this update, run `unraid-api restart` in the Unraid terminal, then reload Settings and confirm the API status is green. This briefly interrupts API connections, but does not restart VMs or containers. API code updates require this reload; unchanged UI-only updates do not.

**Check API activation after reboot or an Unraid API update.** Unraid's dependency archive preserves the module files, but not the root package metadata used to discover plugins. Deadlock Guard restores registration at install/startup; if the API has already started, another `unraid-api restart` is needed. Automatic API activation across boot order has not been verified on a real host. The beta must pass this check before publication. For a setup error, retry `/usr/local/emhttp/plugins/deadlock-guard/scripts/lifecycle api-install` before restarting the API.

## Storage and recovery

- Flash: `/boot/config/plugins/deadlock-guard/config.json` (version 1 JSON), install marker and downloaded packages.
- RAM: `/var/run/deadlock-guard/` contains jobs, reservations, single-use permissions and VM lifecycle events. Completed history is limited to 200 jobs with 100 transitions each and clears on reboot.
- Upgrades and ordinary uninstall preserve configuration. Installation refuses to overwrite a foreign hook of the same name and does not patch Unraid source files. API dependency metadata is managed through npm and Unraid’s plugin CLI. An uninstall marker makes a copy of our hook in an unmounted libvirt image harmless after removal. If a later boot cannot load the plugin (including an unsupported OS upgrade), the self-contained hook keeps grouped starts blocked and allows unrelated VMs. Remove the plugin before upgrading beyond 7.3.x.
- Configuration changes, updates and removal are refused while jobs are active or quarantined. Configuration saves use an atomic rename and revision check.

Integration checks run at startup, after the array or VM service starts, and before protected handoffs involving VMs. Docker startup reviews pending jobs. Each handoff has a supervisor that waits for its worker to exit and immediately reviews any interrupted job; the supervisor then exits too. Before a new handoff, abandoned jobs are reviewed and the required VMs/containers are checked. **Check Integration** checks/restores VM protection and reads API activation status; **Check pending jobs** reviews interrupted handoffs and retries queued jobs without changing integration. If a worker died between commands, checking its current states releases its reservations when safe. If it may have submitted an operation to a daemon, the job stays quarantined **until host reboot**. Neither elapsed time nor a daemon PID change proves that a previously accepted start cannot still happen. Inspect workloads and shut down safely before rebooting. There is no unsafe force-unlock button.

If both a worker and its supervisor are killed, its reservations remain until the next lifecycle event, handoff request or manual job check can assess it. Elapsed time alone never releases them. Recent handoffs are written by workers and read when Settings opens, then every three seconds while the page is visible. History polling does not run integration or recovery checks.

An update rejected because a job is active leaves the job and admission unchanged. An updater that exits before changing its payload can be cleared by the next lifecycle, handoff or manual integration check once its process is gone. If package replacement was interrupted and files changed, maintenance remains blocked: reboot and reinstall the verified package before use. Integration checks never recreate an uninstalled marker and do not rewrite installation intent to flash.

## Development and release

See [architecture and API](docs/ARCHITECTURE.md), [validation](docs/HOST-VALIDATION.md), and [release procedure](docs/RELEASING.md). Browser checks use `tests/js/browser.cjs` and `tests/js/settings-browser.cjs` with Playwright installed as a development dependency; they do not ship to the host.

Support: [GitHub Issues](https://github.com/ghaschel/unraid-deadlock-manager/issues). MIT © 2026 Guilherme Haschel.

## Development and formatting

Use `npm ci --ignore-scripts` for the pinned formatter and browser tools, then
`npm ci --prefix tests/api --ignore-scripts --legacy-peer-deps` for API fixtures.
These dependencies are development-only and never enter the Unraid package.

Run `npm run format` to format PHP, JavaScript, CSS, JSON and CI YAML;
`npm run format:check` checks the same files without writing. CI runs the check.
Unraid `.page` headers, the standalone QEMU hook, generated manifests and upstream
fixture assets retain their native format. Format those manually when editing them.

Run `npm test` for PHP, native-control, API and reproducible-package checks.
PHP can run locally or through Docker. For browser verification, run
`npx playwright install chromium` and `npm run test:browser` (or set `DG_CHROME`
to an existing Chrome executable). Shared PHP builders/platform doubles are in
`tests/php/support/`; child-process crash/concurrency fixtures are in
`tests/php/fixtures/` and are explicitly loaded by the test runner.
