# Using Deadlock Guard

[Back to the README](../README.md)

## Activation and updates

Deadlock Guard is a beta for **Unraid 7.3.x**. The full [host validation checklist](HOST-VALIDATION.md) remains a release requirement; passing automated tests does not certify host compatibility.

**Reboot Unraid after first installation** to activate VM protection. Shut down VMs and containers gracefully and use Unraid’s normal reboot control. The plugin never reboots the host or restarts the VM or Docker services automatically. After reboot, reload existing WebGUI tabs and open **Settings → Deadlock Guard**. Confirm the VM safety gate is activated before testing handoffs.

Ordinary updates do not automatically require a reboot. Reboot if Settings reports **Pending activation**. Do not toggle the VM service to activate the plugin: a retained `libvirt.img` loop attachment can prevent it from starting again.

### API handoffs

Deadlock Guard sets up its integration with Unraid’s built-in API during plugin installation and updates. The installer runs **`unraid-api restart`** after the module is registered and archived. This briefly interrupts API connections; running VMs and containers keep running. Reload Settings and confirm **API handoffs installed and activated** is green once the API finishes starting.

Check that status after a reboot or an Unraid update too. If integration setup or activation fails, Settings shows the error and WebUI protection remains available. See [API troubleshooting](TROUBLESHOOTING.md#api-setup-or-activation-failed) for recovery steps.

An enabled group’s **API** checkbox controls which starts trigger handoffs. The integration must also be active: until it loads, Docker API starts bypass handoffs and the VM safety gate rejects grouped VM starts.

## Groups

Open **Settings → Deadlock Guard**, add a group, and select its VMs and containers. Members can belong to multiple groups; starting one must satisfy every group enabled for that source.

**WebUI** and **API** are enabled by default. At least one must be selected to save a group. Unchecking a source allows starts from that source without that group’s handoffs; other groups enabled for the same source still apply.

Saving applies changes to future starts and does not start or stop anything. **Discard changes** restores the saved configuration. Group changes cannot be saved while any job is active or quarantined.

VMs are identified by UUID and containers by exact name. Normal container recreation preserves a mapping, but renamed or missing members need repair. A container recreated during a handoff causes that attempt to fail.

## Starts, stops and timeouts

Use Unraid’s normal **VMs**, **Docker** or **Dashboard** controls. Start, Start with Console, Restart and Resume are supported. VM Restart remains a guest reboot. Container Restart stops the container before starting it again. Supported API clients, including nzb360, use the same coordinator once API integration is active.

A handoff stops conflicting members, confirms their shutdown and release events, then starts the selection. VM-to-container, container-to-VM, VM-to-VM and container-to-container handoffs are supported. Stopping a member alone never starts another.

| Setting | Default | What happens at the timeout |
| --- | --- | --- |
| VM shutdown | 120 seconds | Abort unless VM force-stop is enabled. |
| Container shutdown | 30 seconds | Native Docker Stop forcibly terminates the container. |

Force-stop can lose unsaved data. For overlapping groups, the longest timeout applies. VM force-stop requires every relevant group to allow it. A paused, suspended, restarting or unknown state does not count as stopped.

Bulk starts are rejected before stopping anything if the batch requests multiple members of one exclusive group. Choose one member instead. Duplicate clicks join the existing job; competing starts receive a busy response.

## Progress and history

Progress notifications and **Recent handoffs** appear only when another group member needs to stop. Ordinary starts, stops and restarts stay quiet. Failed ordinary actions show an error; accepted-job errors remain under **Troubleshooting → Action errors**.

Closing the browser does not interrupt an accepted job. Success notifications close after five seconds; errors remain until dismissed. Expand a handoff to see its stages, remaining states and job ID. History refreshes every three seconds while Settings is visible, preserving expanded details. This only reads history; it does not run maintenance checks.

Completed history is kept in RAM, limited to 200 jobs with 100 transitions each, and clears on reboot. Failed handoffs leave stopped members stopped; there is no automatic rollback.

## Coverage

Deadlock Guard does not provide system-wide exclusivity or manage GPU/PCI driver binding. Correct IOMMU configuration, device release behavior and guest drivers remain the administrator’s responsibility.

Direct Docker CLI/Engine API starts, restart policies, Unraid autostart and update/recreation starts bypass handoffs. Disable automatic starts for grouped containers when relying on exclusive use.

The independent QEMU hook rejects grouped VM starts without a coordinator authorization, including terminal commands, scripts and VM autostart. Disable autostart for grouped VMs. Managed-save restore, migration and external attach are also rejected for grouped VMs in this beta. Shut the VM down normally and use Start. Resume from pause or guest suspend is supported through native controls.

API handoffs cover Docker start/restart/unpause and VM start/resume/reboot. The caller needs update permission for every VM/container resource type affected. Ordinary Stop stays native and never starts another member.

Grouped VM API reboots use a guest reboot even when API handoffs are unchecked. Grouped VM Reset is rejected before stopping the VM; use Reboot or Stop then Start. Ungrouped API actions retain their native behavior. Unchecked grouped VM starts receive a single-use authorization without stopping conflicts.

## Configuration and removal

Groups are saved in `/boot/config/plugins/deadlock-guard/config.json`. Upgrades and ordinary uninstall preserve configuration. Jobs, reservations and authorizations live in `/var/run/deadlock-guard/` until reboot.

Updates and removal are refused while jobs are active or quarantined. The plugin removes only its own files and hooks and does not patch Unraid source files. Remove the plugin before upgrading beyond its supported Unraid versions; a persistent VM hook can keep grouped starts blocked if the plugin cannot load.

For interrupted jobs, failed updates or unavailable services, see [troubleshooting](TROUBLESHOOTING.md).
