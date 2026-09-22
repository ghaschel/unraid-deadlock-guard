# Architecture and contracts

`Config → Jobs → Coordinator → Platform` is the control path. `Gate` is independent of Platform and never calls Docker/libvirt. A single RAM registry `flock` makes multi-group reservation acquisition atomic; the planner sorts group IDs, and reservations remain job records throughout every external operation. Disjoint jobs can run concurrently; groups or touched workloads that overlap cannot. Workers are detached with `setsid`, claim queued jobs atomically, and persist command intent before starting a subprocess.

A job proceeds through validation, reserved-group shutdown, release confirmation, target action and confirmation. Reservations already exist while it is queued. All members are inspected before any stop. Immediately before starting, conflicts are inspected again. Paused, suspended, restarting, releasing and unknown states are not stopped. An active VM must produce a newer `release/end` event and be shut off. The hook writes that event after libvirt's resource cleanup. Docker must report not running/restarting with PID zero and exited/created state. Shutdown uses `docker kill --signal <configured StopSignal>` (not `docker stop`), avoiding implicit timeout-to-SIGKILL escalation. A SIGKILL StopSignal is replaced by SIGTERM unless the separate force stage is reached. VM Restart calls `virsh reboot`; Docker Restart is stop/wait/start.

Configuration identifies VMs by UUID, containers by name. Native Docker button IDs are resolved against a fresh inventory. The adapter pins the full container ID for the job and rejects mid-job recreation. All process calls use argv arrays, fixed executables and bounded output/time. There is no caller-supplied command or executable. A lost mutating command response is uncertain; client termination does not prove daemon cancellation.

## Files and lifecycle

`/etc/libvirt/hooks/qemu.d/99-deadlock-guard` is independent of the primary QEMU hook. It reads the UUID from stdin XML with network/entity loading disabled, consumes a permission once under a nonblocking per-VM lock, checks the live worker's boot/PID/start-tick identity, and records events locally. It never takes the registry lock and never calls or waits for a process that calls libvirt. Unsupported migration/restore/attach paths are rejected for grouped VMs. Reconnect events are left alone to avoid killing existing VMs on daemon restart.

Installation records the current libvirtd process identity. Hook activation is considered pending until a later daemon instance, or the first instance after an install while it was stopped. No daemon is restarted. Startup, disks-mounted and Docker/VM-start lifecycle entrypoints restore/check integration; a minute cron check catches missing hooks and orphaned jobs. A hook replaced while the daemon is already running needs activation again. Array unmounting drains admission and prevents subsequent actions. Runtime files are root-only. Uninstall retains flash configuration, removes only the plugin's hook and cron, and disables any stale hook inside a currently unmounted libvirt image through the persistent install marker.

## Internal endpoint

`POST /plugins/deadlock-guard/include/api.php` accepts JSON. Every operation requires Unraid's authenticated root session, `X-CSRF-Token` for the native prepend handler, and the same token in JSON `csrf`. The duplicate token is intentional: Unraid removes the validated header before invoking PHP. Sessions close before reading platform state. Responses are uncached JSON; failures return an HTTP error and an escaped JSON error string. Settings uses DOM text nodes for all user/workload strings.

| `op` | Payload / response |
| --- | --- |
| `snapshot` | configuration, revision, inventory, health, bounded job history |
| `inventory` | workloads and per-service errors |
| `config` | `{config, revision}`; returns saved config and new revision |
| `route` | `{native:{type,id,action}}` or `{type,bulk:true,action}`, plus `key`; returns unmanaged passthrough or a job |
| `job` | `{requests:[{workload:{type,id},action}], key}`; returns `job.id` |
| `status` | `{id}`; phase, timestamps, affected states, history, error |
| `history` | bounded jobs |
| `console` | VM workload; validated VNC/SPICE ports, no start |
| `reconcile` | integration check, abandoned-job reconciliation and queued-worker relaunch |

Actions are `start`, `restart`, `resume`, and VM-only `wake`. Keys are 8–128 alphanumeric/underscore/hyphen characters. A reused key with different content is rejected. An identical active request joins the existing job. Idempotency persists within the RAM history retention window.

## Verified upstream contracts

The browser fixture models public function signatures, not a copy of Unraid's UI. Reference branch `7.3`, inspected at commit `a68f44a5bee72c9a0d897329adb1a2536c7244b0`:

- [Docker dispatch](https://github.com/unraid/webgui/blob/7.3/emhttp/plugins/dynamix.docker.manager/javascript/docker.js): `eventControl`, `startAll`, `resumeAll`.
- [VM dispatch](https://github.com/unraid/webgui/blob/7.3/emhttp/plugins/dynamix.vm.manager/javascript/vmmanager.js): dispatch, console and Remote Viewer variants, bulk `startAll`; Dashboard reuses the dispatch functions.
- [Page injection](https://github.com/unraid/webgui/blob/7.3/emhttp/plugins/dynamix/include/DefaultPageLayout.php): `Menu="Buttons"` pages; no stock-file edits.
- [Sessions](https://github.com/unraid/webgui/blob/7.3/emhttp/auth-request.php) and [CSRF prepend](https://github.com/unraid/webgui/blob/7.3/emhttp/plugins/dynamix/include/local_prepend.php).
- [Libvirt hook lifecycle and recursion restriction](https://www.libvirt.org/hooks.html), [Docker stop escalation](https://docs.docker.com/reference/cli/docker/container/stop/).

Unraid updates may change these contracts. The package and runtime limit this beta to 7.3.x. Browser fixtures cannot establish real-host compatibility.
