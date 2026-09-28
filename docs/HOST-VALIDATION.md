# Disposable Unraid host validation — required, not yet executed

Record exact Unraid/PHP/Docker/libvirt versions, package SHA-256, browser version and screenshots/logs with results. Use a disposable Unraid **7.3.x** host, two expendable VMs and two containers that own no important data. Snapshot configuration and existing hook names/checksums. Do not claim host compatibility until this checklist passes.

## Installation

1. Build twice with `python3 scripts/build.py`; verify matching hashes. Copy the `.plg`, `.txz` and SHA256SUMS to the test host and run `sha256sum -c SHA256SUMS` there.
2. Before public release, copy the `.txz` into `/boot/config/plugins/deadlock-guard/packages/` with its exact filename from the manifest; install the local manifest with `plugin install /path/to/deadlock-guard.plg`. Always use the full absolute manifest path: Unraid's PHP startup changes the working directory to `/usr/local/emhttp`, so `plugin install deadlock-guard.plg` can fail even after changing into the correct directory. The native installer reuses a valid cached package. When replacing a temporary build with the same version, use `plugin install /path/to/deadlock-guard.plg forced`: Unraid otherwise rejects the manifest before any package installation runs. Transfer both the rebuilt manifest and package and verify their checksums first. Test again using the public release URLs after publication.
3. Verify Settings → Deadlock Guard, permissions, plugin list, absence of a plugin cron entry and independent hook. Existing hooks must be byte-identical. Confirm the installer instructs the user to reboot after first installation and Settings requests a reboot if activation is pending. Shut down test workloads gracefully and use Unraid's normal reboot control. After the array and VM service start, reload the WebGUI and verify activation is ready. Lifecycle events check integration automatically; the Troubleshooting buttons are optional. Confirm installation runs `unraid-api restart` exactly once after successful API setup, including on updates; an unavailable or incompatible API runtime, or failed integration setup, must not trigger a restart. The plugin must not reboot the host or restart VM/Docker services. Do not toggle the VM service for activation; a retained `libvirt.img` loop attachment can prevent it from starting again. If an upgrade requires activation, repeat this planned reboot procedure.
4. Reload all open WebGUI tabs. Confirm no stock PHP/JS files changed. Add groups, save, reload, and inspect the flash JSON. Test upgrade and uninstall with jobs idle and again with an active/quarantined job. Configuration must survive; busy operations must refuse.

## Handoffs and state

- Exercise VM→container, container→VM, VM→VM and container→container. Record stop, release and start timestamps; no requested start may precede confirmed conflict release.
- Overlap two groups through a shared member. Test combined conflicts and differing timeouts/force flags. All relevant groups must be reserved.
- Delay shutdown, then prevent it beyond the timeout. For containers, verify native Docker Stop escalates after the group timeout without requiring `--init` or a force checkbox. Test failed stops and lost responses: the target must remain stopped, and uncertain commands must retain their reservations. For VMs, test timeout with force disabled, successful and failed opt-in force-stop, and failed target startup. Remaining states must be visible and stopped members must stay stopped.
- Pause/suspend/restart workloads; remove/rename a member; recreate a container with the same name before a new job, then during a job. Unknown/missing/changed identity must never be mistaken for release.
- Disable Docker or libvirt, corrupt a copy of the configuration, and restore it. No silent default configuration or substituted member is allowed.

## Concurrency and recovery

- Submit opposite starts simultaneously in two browsers/tabs. One job reserves the groups, the other gets busy. Duplicate clicks join one job.
- Close the browser immediately after acceptance. Observe the background job finishing, then reopen history.
- Kill a worker between operations: its supervisor must immediately review it without a timer or open browser. Kill it during a start command: its job must stay quarantined. Kill both supervisor and worker: reservations must persist until the next lifecycle event, protected request or **Check pending jobs** reviews them. Repeated checks and time passage must not unlock it. Reboot safely after inspecting the workloads; RAM records and old permissions must be gone.
- Attempt `virsh start` for a grouped VM without a permission, with an expired permission and after consuming a permission. All must reject. Verify normal unmanaged VMs still work. Do not call libvirt from the hook during this test.
- Reboot with configured groups and existing foreign hooks. Verify configuration persists, hook registration is correct before protected starts, managed VM autostart is rejected and routine lifecycle checks do not restart services. The manifest installation step is the only automatic API restart path.

## WebGUI and security

- On native VMs, Docker and Dashboard, exercise Start, Start with Console, Remote Viewer, Restart and Resume/wake. VM Restart must be a guest reboot. Check custom-network Docker host routes after start.
- Request bulk starts containing two members of one group: reject before stopping anything. Test valid batches and ordinary Stop/Pause/Delete on unmanaged workloads. Test failed API requests; protected actions must not fall through to native startup.
- Verify existing browser tabs are reloaded after install/update, integration failure is visible, and post-handoff console windows open correctly. Keep the browser cache enabled during an upgrade: settings and integration scripts must use changed URLs when their contents change. Block a script or settings API request, then verify a clear error and Retry control replace the loading status, saving stays disabled until groups load, and retry restores the saved groups without writing configuration. Confirm the Plugins tab shows only the short installed description.
- Verify a group save shows confirmation only after acceptance and explains that saving does not start or stop workloads. Failed saves retain edits and show a dismissible error. Notifications must use Unraid's native theme and configured position. Waiting notifications list names with VM/Docker labels without job IDs. Success notifications disappear after five seconds, while active progress, errors and the blocked-popup console action stay visible. The action must open the console. Start a new handoff while an earlier success notice is visible; the old timer must not hide the new progress. Other plugins' notifications must remain untouched.
- Test unauthenticated requests, missing/wrong CSRF, invalid actions/IDs, shell metacharacters, HTML in names, stale configuration revisions and edits during jobs. No execution/injection or active-config overwrite.
- Confirm the group selectors retain state and missing-member indicators without a separate status table or warning column. Service errors remain visible and coverage limitations are available in Troubleshooting. Deliberately demonstrate a direct Docker start bypass on disposable workloads, then disable automatic starts again.
- Edit, add and remove groups; **Discard changes** must restore saved settings without writing configuration. It appears only while edits are unsaved and is disabled during a save. Both **Check Integration** and **Check pending jobs** under **Troubleshooting** must preserve unsaved edits. The first must not retry jobs; the second must not repair hooks. Recent handoffs must still load with Settings and refresh every three seconds while visible, without running either maintenance check. Open a handoff, select diagnostic text, and let several refreshes pass: unchanged content must retain expansion and selection. Adding a new handoff must not collapse existing details, and manually collapsed details must stay collapsed.
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

## An update reports success but the installed version stays unchanged

The original `2026.09.22` package sorts above `2026.09.22a5` in PHP version comparisons: `a` means alpha, not a newer revision. Earlier manifests let `upgradepkg` skip this replacement and still printed success.

Use the corrected manifest and its matching package. It passes `--reinstall` for the plugin-owned package and verifies the installed `VERSION` before reporting success. This package flag does not override Unraid's earlier manifest version check: same-version test rebuilds also require the trailing `forced` argument on `plugin install`. No uninstall or configuration removal is needed. Compare the installed `VERSION` with the manifest version after the update; the manifest version alone does not prove the package was replaced. Verify an upgrade from the original date-only version and a retry after a skipped update.

## Event-based checks

- Confirm that a fresh install does not create or manage any cron entries. For an early local test installation, perform the one-time cleanup below before upgrading.
- Confirm startup, array-start, VM-start and Docker-start events invoke their intended checks. Docker startup must not rewrite the VM hook. Confirm array shutdown blocks new handoffs before services stop. These event deliveries require a real 7.3.x host.
- With Settings closed and no handoffs running, confirm no Deadlock Guard maintenance processes remain. With Settings open, only read-only history requests should repeat.


## One-time cleanup for early local test builds

Local test builds through `2026.09.22a7` installed a minute cron on the test host. They were never published. Build `a8` removed it automatically; subsequent builds keep this development cleanup out of the installer.

If the file is still present on Tower, run this once in the Unraid terminal before updating:

```bash
if [ -f /boot/config/plugins/deadlock-guard/deadlock-guard.cron ]; then
  rm /boot/config/plugins/deadlock-guard/deadlock-guard.cron
  /usr/local/sbin/update_cron
fi
```

This removes only the old test timer and rebuilds Unraid's generated crontab. It preserves groups, packages and other plugins' cron entries. No action is needed if the file is already absent.

## A container times out but Unraid's Stop button works

Deadlock Guard uses native Docker Stop with the group's container timeout. Docker sends the configured stop signal (SIGTERM when unset) and forcibly terminates the container if that timeout expires; see [Docker's stop behavior](https://docs.docker.com/reference/cli/docker/container/stop/). A successful Stop response is followed by a state check before the target can start. The plugin does not require `--init`, although it can help containers handle shutdown signals. Existing groups use this behavior automatically; the old container force-stop checkbox no longer applies.

Inspect the exact container ID for its StopSignal, OOMKilled, ExitCode and RestartPolicy. Exit code 137 is consistent with SIGKILL; OOMKilled=false means Docker did not record an OOM kill, but these fields alone do not identify who sent the signal. Forced termination can interrupt active work. Increase the container timeout if it needs longer to finish, or investigate the image’s shutdown handling. If the container remains active even after native Stop, collect Docker events, daemon logs and process states; the plugin must keep the target stopped.

A handoff reaching the shutdown stage and reporting a shutdown timeout is not evidence that an update needs a reboot. Reboot after first installation or when integration reports **Pending activation**; ordinary updates that leave the hook unchanged do not require one.


## API and source controls — release gate

Use Unraid API **4.37.4+ad268301** and disposable groups for the reported regression. Confirm the installed module activates, the full version appears in Settings, and a previous version-rejection error clears after successful registration. Repeat the API checks on **4.36.0** when available; eligibility begins at 4.36.0 and requires matching interfaces. These checks are still required on Tower; local Nest/GraphQL fixtures do not certify host compatibility.

- Upgrade the local test package, reload Settings, and confirm saved groups remain. Both source checkboxes default on for old groups and new groups. Neither selected must fail in the browser and server, preserving the last saved config.
- Confirm the installer automatically runs `unraid-api restart` after module setup and that **API handoffs installed and activated** becomes green once startup completes. Simulate a failed restart; installation must preserve WebUI protection and show the API error with a manual retry command in Troubleshooting. Stop the API or load mismatched adapter files in a disposable test environment and confirm the status becomes red. A loaded stale adapter must reject protected requests without falling through to native starts.
- In nzb360 (and native GraphQL), start each direction with API selected. Confirm one handoff, actual conflict shutdown, and one target start. Test Docker restart/unpause and VM start/resume/guest reboot. Ordinary Stop must never start another member. Grouped VM Reset must reject before stopping the VM.
- Uncheck API: Docker starts and grouped VM starts/reboots must skip that group's conflict shutdown. Uncheck WebUI: native tabs/Dashboard must skip it too. Keep another overlapping group selected for that source and verify its rules still apply. Use disposable hardware-free workloads for bypass tests because simultaneous members are intentional.
- Denied/expired credentials must produce no handoff or native mutation. A Docker-only update key must not stop a conflicting VM; require VMS update access too, and vice versa. Test mixed-resource batches, aliases, variables and fragments; conflicting start batches must fail before any handoff stops a member.
- Make duplicate and opposing requests from API and browser simultaneously. Confirm joins/busy responses preserve reservations. Disconnect the client or restart API after admission: the worker must continue, and its result remains in Recent handoffs.
- Verify `unraid-api plugins list` retains other plugins before/after installation and removal. Check groups/configuration and foreign VM hooks are preserved. An already-loaded adapter becomes inert after uninstall.
- **Reboot and API replacement:** test both with and without Unraid Connect installed. Confirm startup restores the module's peer dependency, native plugin configuration and module files. If API boot preceded restoration, status must remain red until a manual `unraid-api restart`; never accept silent Docker starts as protected. Record actual ordering and activation behavior before publishing. This beta does not claim automatic API activation across all boot orders.

## Debug logging

- With logging off, reproduce an ordinary start and a handoff; no new debug entries should appear. Enable **Troubleshooting → Enable debug logging**, reload the native tab, and repeat with disposable members. Verify routing, state, job ID, command results and VM release events in **Download debug logs**. Ordinary starts must remain absent from Recent handoffs and handoff notifications even while debug is enabled.
- Trigger an API action and confirm its authorization/routing/job events appear in `debug-api.log`. Toggle logging without restarting the API and verify new writes stop/resume. Test a failed start or expired permit and a worker recovery case; confirm the diagnostic file identifies the affected job/stage without altering safety decisions.
- Open Developer Tools → Console, enable Verbose output, filter `[Deadlock Guard]`, and test a failed request. Confirm no CSRF token, password, GraphQL variables, environment, VM XML, command output or raw error body enters console/server traces. Review VM/container names and IDs before sharing logs.
- Verify download works with debug off and when inventory/configuration fails. Debug changes must preserve unsaved group edits. Download before reboot; RAM files clear, while the debug preference survives. If Settings is unavailable, exercise `scripts/lifecycle debug-on` and `debug-off` from the installed plugin directory.
