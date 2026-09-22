# Disposable Unraid host validation — required, not yet executed

Record exact Unraid/PHP/Docker/libvirt versions, package SHA-256, browser version and screenshots/logs with results. Use a disposable Unraid **7.3.x** host, two expendable VMs and two containers that own no important data. Snapshot configuration and existing hook names/checksums. Do not claim host compatibility until this checklist passes.

## Installation

1. Build twice with `python3 scripts/build.py --update-manifest`; verify matching hashes. Copy the `.plg`, `.txz` and SHA256SUMS to the test host and run `sha256sum -c SHA256SUMS` there.
2. Before public release, copy the `.txz` into `/boot/config/plugins/deadlock-guard/packages/` with its exact filename from the manifest; install the local manifest with `plugin install /path/to/deadlock-guard.plg`. Always use the full absolute manifest path: Unraid's PHP startup changes the working directory to `/usr/local/emhttp`, so `plugin install deadlock-guard.plg` can fail even after changing into the correct directory. The native installer reuses a valid cached package. Test again using the public release URLs after publication.
3. Verify Settings → Deadlock Guard, permissions, plugin list, cron entry and independent hook. Existing hooks must be byte-identical. Confirm activation is pending if the VM service was already running. Stop/start it manually when safe; activation should then be ready.
4. Reload all open WebGUI tabs. Confirm no stock PHP/JS files changed. Add groups, save, reload, and inspect the flash JSON. Test upgrade and uninstall with jobs idle and again with an active/quarantined job. Configuration must survive; busy operations must refuse.

## Handoffs and state

- Exercise VM→container, container→VM, VM→VM and container→container. Record stop, release and start timestamps; no requested start may precede confirmed conflict release.
- Overlap two groups through a shared member. Test combined conflicts and differing timeouts/force flags. All relevant groups must be reserved.
- Delay graceful shutdown; then prevent shutdown beyond timeout. Target stays stopped without force. Opt into force separately for VM/container; test successful and failed force-stop, plus failed target startup. Remaining states must be visible and stopped workloads must stay stopped.
- Pause/suspend/restart workloads; remove/rename a member; recreate a container with the same name before a new job, then during a job. Unknown/missing/changed identity must never be mistaken for release.
- Disable Docker or libvirt, corrupt a copy of the configuration, and restore it. No silent default configuration or substituted member is allowed.

## Concurrency and recovery

- Submit opposite starts simultaneously in two browsers/tabs. One job reserves the groups, the other gets busy. Duplicate clicks join one job.
- Close the browser immediately after acceptance. Observe the background job finishing, then reopen history.
- Kill a worker between operations and reconcile. Kill it during a start command and reconcile: its job must stay quarantined. Repeated checks and time passage must not unlock it. Reboot safely after inspecting the workloads; RAM records and old permissions must be gone.
- Attempt `virsh start` for a grouped VM without a permission, with an expired permission and after consuming a permission. All must reject. Verify normal unmanaged VMs still work. Do not call libvirt from the hook during this test.
- Reboot with configured groups and existing foreign hooks. Verify configuration persists, hook registration is correct before protected starts, managed VM autostart is rejected and no services are restarted by the plugin.

## WebGUI and security

- On native VMs, Docker and Dashboard, exercise Start, Start with Console, Remote Viewer, Restart and Resume/wake. VM Restart must be a guest reboot. Check custom-network Docker host routes after start.
- Request bulk starts containing two members of one group: reject before stopping anything. Test valid batches and ordinary Stop/Pause/Delete on unmanaged workloads. Test failed API requests; protected actions must not fall through to native startup.
- Verify existing browser tabs are reloaded after install/update, integration failure is visible, and post-handoff console windows open correctly.
- Test unauthenticated requests, missing/wrong CSRF, invalid actions/IDs, shell metacharacters, HTML in names, stale configuration revisions and edits during jobs. No execution/injection or active-config overwrite.
- Confirm settings display Docker restart-policy/autostart warnings and coverage limitations. Deliberately demonstrate a direct Docker start bypass on disposable workloads, then disable automatic starts again.
- Interrupt an update before download/installation and during package extraction. Unchanged payload should recover admission only after its updater is gone; partial replacement must remain blocked. A refused update/remove during an active handoff must leave that handoff unaffected.
- Simulate missing plugin payload and an unsupported 7.4 boot with the persistent hook present. Grouped VMs must fail with a useful error; unrelated VMs remain usable. Uninstall before an actual unsupported OS upgrade.
- Uninstall while the libvirt image is mounted and unmounted. Foreign hooks/configuration remain; stale plugin hooks in an unmounted image must become harmless after uninstall.

Attach completed results to a GitHub issue or release. Local automated checks are not a substitute for these tests.

## Installer cannot find or parse the manifest

`XML file doesn't exist or xml parse error` is a combined missing-file/XML error. Check the actual path on the Unraid host and query the manifest version before installation:

```sh
ls -l /tmp/deadlock-guard-test/deadlock-guard.plg
plugin version /tmp/deadlock-guard-test/deadlock-guard.plg
```

Use your actual absolute path if different. The version query is read-only and should print the build's `VERSION`. If that succeeds, install that same absolute path. If it fails despite the file existing, compare its SHA-256 with the transferred `SHA256SUMS` and check that the file is raw XML rather than a downloaded HTML page.

The accompanying `unexpected EOF while looking for matching` quote errors can be secondary failures in Unraid's post-hook invocation: the plugin manager passes its `doesn't` error text into a shell command without escaping it. Reproduced using the official 7.3 plugin-manager source with a missing relative path; the same generated manifest parsed correctly through the absolute-path version query.
