![Deadlock Guard — Stop first. Start next.](docs/branding/header.svg)

Add VMs and containers that use the same hardware to a group. When you click Start in Unraid, Deadlock Guard stops the others in that group first to prevent hardware passthrough deadlocks.

It works through Unraid’s **VMs, Docker and Dashboard** controls, and supported Unraid API clients such as nzb360. Stopping a VM or container never starts another.

## Install

**Beta · Unraid 7.3.x.**

For testing local builds, follow the [build instructions](docs/DEVELOPMENT.md) and [temporary installation guide](docs/HOST-VALIDATION.md#installation).

After the first installation, reboot Unraid when safe to activate VM protection. Then open **Settings → Deadlock Guard** and check the integration status. See the [setup guide](docs/USER-GUIDE.md#activation-and-updates) for activation and update details.

## Use it

1. Open **Settings → Deadlock Guard** and add a group.
2. Select the VMs and containers that use the same hardware.
3. Choose whether the group applies to **WebUI**, **API**, or both, then save.
4. Use your usual Start control. Deadlock Guard handles the stop/start sequence.

Progress appears when another group member needs to stop. Ordinary actions stay quiet. You can close the browser while a handoff runs and check **Recent handoffs** later.

The [user guide](docs/USER-GUIDE.md) covers timeouts, overlapping groups, console controls and API behavior.

## Know the limits

- GPU/PCI driver binding remains your responsibility. Deadlock Guard coordinates starts and stops; it cannot fix a device that fails to release.
- Containers use normal Docker Stop behavior, including forced termination after the group timeout. VM force-stop is optional and off by default.
- Direct Docker commands and automatic starts bypass handoffs. Direct starts of grouped VMs are blocked by the VM safety gate. This is not system-wide exclusivity.
- If a handoff fails, anything already stopped stays stopped.

See [coverage and API details](docs/USER-GUIDE.md#coverage) before relying on protection outside the WebUI.

## Get help

To [report a bug](https://github.com/ghaschel/unraid-deadlock-guard/issues/new?template=bug_report.yml), open **Settings → Deadlock Guard → Troubleshooting**, check **Enable debug logging**, and reproduce the problem. Click **Download debug logs** before rebooting and attach the downloaded file to the report. Disable debug logging when finished. See the [troubleshooting guide](docs/TROUBLESHOOTING.md) if you cannot collect logs.

Have an idea? [Request a feature](https://github.com/ghaschel/unraid-deadlock-guard/issues/new?template=feature_request.yml) and describe the problem it would solve.

[Development](docs/DEVELOPMENT.md) · [Architecture](docs/ARCHITECTURE.md) · [Host validation](docs/HOST-VALIDATION.md) · [Releasing](docs/RELEASING.md)

[MIT license](LICENSE) · Guilherme Haschel
