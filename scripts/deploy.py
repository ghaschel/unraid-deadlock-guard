#!/usr/bin/env python3
"""Install the latest local build on the Unraid host configured in .env."""

import argparse
import ipaddress
import os
from pathlib import Path
import re
import secrets
import shlex
import subprocess
import sys
import xml.etree.ElementTree as ET

import local_build

ROOT = Path(__file__).resolve().parents[1]

# Passed to bash as a separate, shell-quoted argument. Values use positional
# parameters; neither .env contents nor local paths become executable shell text.
INSTALL_SCRIPT = r'''
set -euo pipefail
staging=$1
expected_version=$2
package="deadlock-guard-${expected_version}-noarch-1.txz"
cache='/boot/config/plugins/deadlock-guard/packages'
plugin_dir='/usr/local/emhttp/plugins/deadlock-guard'

cd "$staging"
sha256sum -c SHA256SUMS
mkdir -p "$cache"
cp -- "$package" "$cache/$package"

# Force only bypasses version comparisons; the plugin's host and active-job
# checks still apply. Numeric test suffixes do not sort naturally in Unraid.
TERM=xterm plugin install "$staging/deadlock-guard.plg" forced
installed_version=$(cat "$plugin_dir/VERSION")
if [[ "$installed_version" != "$expected_version" ]]; then
  printf 'Installation failed: expected %s, found %s.\n' "$expected_version" "$installed_version" >&2
  exit 1
fi

rm -- "$staging/$package" "$staging/deadlock-guard.plg" "$staging/SHA256SUMS"
rmdir -- "$staging"
printf 'Installed Deadlock Guard %s.\n' "$installed_version"
'''.strip()


def read_host() -> str:
    value = os.environ.get("UNRAID_HOST")
    if value is None:
        dotenv = ROOT / ".env"
        if dotenv.exists():
            for line in dotenv.read_text(encoding="utf-8").splitlines():
                match = re.match(r"^\s*(?:export\s+)?UNRAID_HOST\s*=\s*(.*)$", line)
                if not match:
                    continue
                # Parse quotes and comments without sourcing .env or expanding variables.
                try:
                    tokens = shlex.split(match[1], comments=True)
                except ValueError:
                    raise RuntimeError("Invalid quoted UNRAID_HOST in .env") from None
                if len(tokens) != 1:
                    raise RuntimeError(
                        "Set one hostname or IP address as UNRAID_HOST in .env"
                    )
                value = tokens[0]
    if not value:
        raise RuntimeError("Set UNRAID_HOST in .env (see .env.example) before deploying")
    host = value.strip()
    if host.startswith("[") and host.endswith("]"):
        host = host[1:-1]
    if ":" in host and re.fullmatch(r"[0-9A-Fa-f:.]+", host):
        try:
            return str(ipaddress.IPv6Address(host))
        except ValueError:
            pass
    elif len(host) <= 253 and re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]*", host):
        return host
    raise RuntimeError(
        "UNRAID_HOST must be a hostname, SSH alias or IP address, without user, URL or port"
    )


def run(command: list[str]) -> None:
    # Inherit the terminal so SSH/scp can use keys or prompt for a password.
    subprocess.run(command, check=True)


def deploy() -> None:
    host = read_host()
    with local_build.command_lock():
        version, artifacts = local_build.latest_build()
        destination = f"root@{host}"
        copy_destination = f"root@[{host}]" if ":" in host else destination
        staging = f"/tmp/deadlock-guard-test-{version}-{secrets.token_hex(4)}"
        print(f"Deploying local build {version} to {host}…", flush=True)
        try:
            run(["ssh", destination, shlex.join(["mkdir", "-m", "700", "--", staging])])
            run(["scp", *map(str, artifacts), f"{copy_destination}:{staging}/"])
            command = shlex.join(
                ["bash", "-c", INSTALL_SCRIPT, "deadlock-guard-deploy", staging, version]
            )
            run(["ssh", destination, command])
        except (OSError, subprocess.CalledProcessError):
            print(f"Deployment stopped. Any uploaded files remain at {host}:{staging}", file=sys.stderr)
            raise
        print("Reload your Unraid tabs and check Settings → Deadlock Guard.")
        print("The installer handles API setup and restart; Settings shows any API errors.")
        print("Reboot only after first installation or if VM protection reports Pending activation.")


if __name__ == "__main__":
    argparse.ArgumentParser(description=__doc__).parse_args()
    try:
        deploy()
    except (OSError, RuntimeError, ValueError, ET.ParseError, subprocess.CalledProcessError) as error:
        print(f"Deployment failed: {error}", file=sys.stderr)
        sys.exit(1)
