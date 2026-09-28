# Troubleshooting

[Back to the README](../README.md)

## Collect debug logs

Open **Settings → Deadlock Guard → Troubleshooting**, check **Enable debug logging**, and reproduce the issue. Use **Download debug logs** to save `deadlock-guard-debug.txt`, containing the PHP and API traces and installed versions. Attach that file to your bug report. Disable debug logging when finished. This setting applies immediately on the installed build, including to the API adapter; no service restart is needed to toggle it.

The server files are `/var/run/deadlock-guard/debug.log` and `debug-api.log`, each with one older `.1` copy. They are root-only, rotate at 512 KiB each (2 MiB total), and disappear on reboot. The enabled/disabled preference survives reboot in `/boot/config/plugins/deadlock-guard/debug.json`. Download logs **before rebooting** if possible. To watch server events in an Unraid terminal:

```sh
tail -F /var/run/deadlock-guard/debug.log /var/run/deadlock-guard/debug-api.log
```

A file may not exist until its first event. If Settings cannot open, enable diagnostics from an Unraid terminal with:

```sh
/usr/local/emhttp/plugins/deadlock-guard/scripts/lifecycle debug-on
```

Use `debug-off` in the same command to disable it. Browser-only failures are separate: after enabling debug logging, reload the affected Unraid tab, open **Developer Tools → Console**, enable **Verbose** messages, and filter for **[Deadlock Guard]**. Copy those entries alongside the downloaded server log when reporting a UI problem.

Traces cover requests and routing, group reservations, duplicates/busy responses, worker phases, observed states, command timing/exit status, VM authorizations/events, API activation/permissions, lifecycle checks and recovery. They include diagnostic events for ordinary starts without adding handoff notifications or history entries. They deliberately omit passwords, tokens, full requests, command output, VM XML and container environment variables. Names and IDs remain useful for diagnosis; review the files before sharing. Logs are best effort: storage failure or a busy log lock never blocks a VM hook or changes a handoff result. If the plugin cannot load at all, also check Unraid's system log and the plugin installation output.

## Check Integration and Check pending jobs

These are separate tools under **Settings → Deadlock Guard → Troubleshooting**:

- **Check Integration** checks/restores VM protection and reads API activation status. It does not restart services or retry jobs.
- **Check pending jobs** reviews interrupted handoffs and retries queued jobs. It does not change integration.

Both preserve unsaved group edits. Neither is required after every reboot. Integration checks run at startup, after the array or VM service starts, and before protected handoffs involving VMs. Docker startup reviews pending jobs. There is no idle maintenance timer.

Each handoff has a supervisor that reviews its worker when it exits, then exits itself. Before accepting a new handoff, the coordinator also reviews abandoned jobs and checks the required members. Recent handoffs are written by workers; the settings page’s history refresh only reads them.

## A job remains blocked

If a worker died between commands, checking current states can release its reservations when safe. If it may have submitted an operation to Docker or libvirt, the job stays quarantined **until host reboot**. Elapsed time or a changed daemon PID cannot prove that an earlier start will not still happen. Inspect the VMs and containers, collect logs, shut down safely, then reboot. There is no force-unlock control.

If both a worker and its supervisor are killed, reservations stay in place until the next lifecycle event, handoff request or manual job check assesses them.

## API setup or activation failed

This status refers to Deadlock Guard’s integration with Unraid’s built-in API. Check the detected version and error details in Settings. API handoffs require **4.36.0 or newer** with compatible interfaces. WebUI protection remains available when API integration cannot load.

Plugin installation and updates automatically restart Unraid API after successful setup. If setup or that restart fails, Settings keeps the error details visible. Retry setup and restart together in the Unraid terminal:

```sh
/usr/local/emhttp/plugins/deadlock-guard/scripts/lifecycle api-install
```

This command also runs `unraid-api restart` and clears the saved error only after both steps succeed. Then reload Settings and confirm the API status is green. A restart briefly interrupts API connections without restarting VMs or containers. Registration copies the plugin’s bundled files and uses Unraid’s configuration-only plugin registration; it does not run npm dependency resolution.

## Installation and shutdown problems

- [Installer cannot find or parse the manifest](HOST-VALIDATION.md#installer-cannot-find-or-parse-the-manifest)
- [Update reports success but the installed version stays unchanged](HOST-VALIDATION.md#an-update-reports-success-but-the-installed-version-stays-unchanged)
- [Container times out but Unraid’s Stop button works](HOST-VALIDATION.md#a-container-times-out-but-unraids-stop-button-works)
- [One-time cleanup for early local test builds](HOST-VALIDATION.md#one-time-cleanup-for-early-local-test-builds)

If libvirt is unavailable after reboot, inspect its startup logs before testing handoffs. Repeated VM-service toggling is not the activation procedure.

An update rejected because a job is active leaves the job unchanged. If an updater exits before replacing any files, the next lifecycle event, handoff or manual integration check can clear maintenance once that process is gone. If package replacement was interrupted and files changed, maintenance stays blocked: reboot and reinstall the verified package before use.

## Report a problem

Use the [bug report form](https://github.com/ghaschel/unraid-deadlock-manager/issues/new?template=bug_report.yml). Include the plugin and Unraid versions, the steps to reproduce, the expected result and what happened. Attach `deadlock-guard-debug.txt` collected with debug logging enabled during reproduction, along with relevant handoff details. Include browser console entries for UI problems. Review VM/container names and IDs before sharing. If you cannot reproduce the problem or collect logs, explain why in the form.

For improvements, use the [feature request form](https://github.com/ghaschel/unraid-deadlock-manager/issues/new?template=feature_request.yml) and explain the problem you want to solve.
