# Deadlock Guard

A native **Unraid 7.3.x beta** plugin for handing shared resources between VMs and Docker containers. Configure an exclusive group in **Settings → Deadlock Guard**, then use the normal **VMs, Docker, or Dashboard** Start controls. Deadlock Guard stops conflicting members, confirms release, and starts the requested member. Ordinary Stop never starts something else.

**Host compatibility is not yet verified.** This implementation has simulated platform tests and browser fixtures based on Unraid 7.3. Run the [disposable-host validation checklist](docs/HOST-VALIDATION.md) before publishing or using it with important workloads. Minimum declared version: **7.3.0**; this beta deliberately does not support older versions or 7.4+.

## Behavior

- VM ↔ container, VM ↔ VM and container ↔ container handoffs; membership in multiple groups.
- Start, Start with Console (browser or Remote Viewer), Restart and Resume. VM Restart remains a guest reboot. Container Restart uses the same graceful stop policy before starting again.
- Bulk starts are rejected before any stop if they request two members of the same exclusive group. Choose an individual workload instead.
- VMs are identified by UUID; containers by exact name, with fresh IDs pinned during each job. Renamed or missing members need repair. A container recreated during a handoff causes that handoff to fail.
- Default grace periods: **120 seconds for VMs**, **30 seconds for containers**. Each group has separate force-stop switches, off by default. For overlapping policies, the longest timeout applies and every relevant group must permit force.
- Accepted jobs survive browser closure. Duplicate clicks join the active job; competing requests receive a busy error. Failure leaves stopped workloads stopped.

## Coverage boundaries

This plugin **does not provide system-wide exclusivity**. Docker CLI/API starts, restart policies, Unraid autostart, and update/recreation-triggered starts bypass its controls. Disable automatic starts and restart policies for grouped containers. Settings highlights those configurations.

An independently named libvirt QEMU hook rejects grouped VM starts without a short-lived, single-use coordinator authorization. This includes terminal commands, scripts and VM autostart. Disable autostart for grouped VMs. Managed-save restore, migration and external attach are also rejected for grouped VMs in this beta; shut the VM down normally and use Start. Resume from pause or guest suspend is supported through native controls.

GPU/PCI binding, device release behavior and guest drivers remain the administrator's responsibility. Force-stop can lose unsaved data. A hook does not replace correct IOMMU/device configuration.

## Build and install for testing

Development tools are PHP 8.3 (or Docker for the tests), Node.js and Python 3. The installed plugin uses only Unraid's PHP, Bash, Docker CLI and libvirt tools; it requires no additional container, database or runtime.

```sh
./scripts/test.sh
python3 scripts/build.py --update-manifest
```

This produces a reproducible `.txz`, `deadlock-guard.plg`, and `SHA256SUMS` under `dist/`. The repository manifest is regenerated with the package checksum. The `.plg` references a versioned GitHub release asset; those URLs only work **after publishing the matching release**. For pre-release host validation, transfer the three artifacts to the disposable host, put the `.txz` at the package path in the manifest, verify its SHA-256, and install the local `.plg` using Unraid's `plugin install /path/to/deadlock-guard.plg` command. See the [host checklist](docs/HOST-VALIDATION.md).

Open **Settings → Deadlock Guard** and check activation. A newly installed hook on a running VM service requires a safe VM-service stop/start; the plugin never restarts that service automatically. Reload every existing WebGUI tab after installation or upgrade. Add a group with at least two members and save.

## Storage and recovery

- Flash: `/boot/config/plugins/deadlock-guard/config.json` (version 1 JSON), install marker, cron entry, downloaded packages.
- RAM: `/var/run/deadlock-guard/` contains jobs, reservations, single-use permissions and VM lifecycle events. Completed history is limited to 200 jobs with 100 transitions each and clears on reboot.
- Upgrades and ordinary uninstall preserve configuration. Installation refuses to overwrite a foreign hook of the same name and does not edit stock Unraid files. An uninstall marker makes a copy of our hook in an unmounted libvirt image harmless after removal. If a later boot cannot load the plugin (including an unsupported OS upgrade), the self-contained hook keeps grouped starts blocked and allows unrelated VMs. Remove the plugin before upgrading beyond 7.3.x.
- Configuration changes, updates and removal are refused while jobs are active or quarantined. Configuration saves use an atomic rename and revision check.

Use **Check integration and reconcile** after an interrupted worker. If a worker died between commands, reconciliation refreshes actual states and releases its reservations. If it may have submitted an operation to a daemon, the job stays quarantined **until host reboot**. Neither elapsed time nor a daemon PID change proves that a previously accepted start cannot still happen. Inspect workloads and shut down safely before rebooting. There is no unsafe force-unlock button.

An update rejected because a job is active leaves the job and admission unchanged. An updater that exits before changing its payload is reconciled automatically once its process is gone. If package replacement was interrupted and files changed, maintenance remains blocked: reboot and reinstall the verified package before use. Periodic integration checks never recreate an uninstalled marker and do not rewrite installation intent to flash.

## Development and release

See [architecture and API](docs/ARCHITECTURE.md), [validation](docs/HOST-VALIDATION.md), and [release procedure](docs/RELEASING.md). Browser checks use `tests/js/browser.cjs` with Playwright installed as a development dependency; they do not ship to the host.

Support: [GitHub Issues](https://github.com/ghaschel/unraid-deadlock-manager/issues). MIT © 2026 Guilherme Haschel.
