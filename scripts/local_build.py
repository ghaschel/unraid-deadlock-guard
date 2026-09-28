#!/usr/bin/env python3
"""Build incrementally numbered local test packages without changing release metadata."""

import argparse
from contextlib import contextmanager
import fcntl
import hashlib
import json
from pathlib import Path
import re
import sys
import tempfile
import xml.etree.ElementTree as ET

import build

ROOT = Path(__file__).resolve().parents[1]


@contextmanager
def command_lock():
    directory = ROOT / ".local-build"
    directory.mkdir(exist_ok=True)
    with (directory / "command.lock").open("a") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise RuntimeError(
                "Another local build or deployment is already running"
            ) from None
        yield


def read_state() -> dict:
    path = ROOT / ".local-build/state.json"
    if not path.exists():
        return {"counters": {}, "latest": None}
    try:
        state = json.loads(path.read_text(encoding="utf-8"))
        if (
            not isinstance(state, dict)
            or set(state) != {"counters", "latest"}
            or not isinstance(state["counters"], dict)
        ):
            raise ValueError()
        for prefix, number in state["counters"].items():
            if not re.fullmatch(r"[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]+", prefix):
                raise ValueError()
            if type(number) is not int or number < 1:
                raise ValueError()
        latest = state.get("latest")
        if latest is not None and (
            not isinstance(latest, str)
            or not re.fullmatch(r"[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]+[1-9][0-9]*", latest)
        ):
            raise ValueError()
        return state
    except (ValueError, TypeError):
        raise RuntimeError(
            "Invalid .local-build/state.json; restore it before building to avoid reusing versions"
        ) from None


def save_state(state: dict) -> None:
    directory = ROOT / ".local-build"
    with tempfile.NamedTemporaryFile(mode="w", dir=directory, delete=False) as temporary:
        path = Path(temporary.name)
        json.dump(state, temporary, indent=2)
        temporary.write("\n")
    try:
        path.replace(directory / "state.json")
    finally:
        path.unlink(missing_ok=True)


def verify_artifacts(directory: Path, version: str) -> list[Path]:
    """Reject incomplete or mismatched builds before any transfer or install."""
    package_name = f"deadlock-guard-{version}-noarch-1.txz"
    filenames = [package_name, "deadlock-guard.plg"]
    checksums = {}
    for line in (directory / "SHA256SUMS").read_text(encoding="utf-8").splitlines():
        match = re.fullmatch(r"([0-9a-f]{64})  (.+)", line)
        if not match or match[2] in checksums:
            raise RuntimeError("Invalid local SHA256SUMS; run npm run build:local again")
        checksums[match[2]] = match[1]
    if set(checksums) != set(filenames):
        raise RuntimeError("Local checksums do not match the expected build files")
    for name in filenames:
        digest = hashlib.sha256((directory / name).read_bytes()).hexdigest()
        if digest != checksums[name]:
            raise RuntimeError(f"Checksum mismatch for {name}; run npm run build:local again")

    manifest = ET.parse(directory / "deadlock-guard.plg").getroot()
    package_path = f"/boot/config/plugins/deadlock-guard/packages/{package_name}"
    entry = manifest.find(f"FILE[@Name='{package_path}']")
    if (
        manifest.tag != "PLUGIN"
        or manifest.get("name") != "deadlock-guard"
        or manifest.get("version") != version
        or entry is None
        or entry.findtext("SHA256") != checksums[package_name]
    ):
        raise RuntimeError("Local manifest does not match the test package")
    return [directory / name for name in [*filenames, "SHA256SUMS"]]


def latest_build() -> tuple[str, list[Path]]:
    version = read_state()["latest"]
    if version is None:
        raise RuntimeError("No local build found. Run npm run build:local first")
    directory = ROOT / "dist/local" / version
    if not directory.is_dir():
        raise RuntimeError(f"Local build {version} is missing. Run npm run build:local again")
    return version, verify_artifacts(directory, version)


def build_local() -> str:
    with command_lock():
        base = build.read_version()
        # Preserve published letter revisions; continue older numeric test versions.
        match = re.fullmatch(r"([0-9]{4}\.[0-9]{2}\.[0-9]{2})([a-z]*)([0-9]*)", base)
        prefix = match[1] + (match[2] or "a")
        state = read_state()
        number = max(state["counters"].get(prefix, 0), int(match[3] or 0)) + 1
        output = ROOT / "dist/local"
        output.mkdir(parents=True, exist_ok=True)
        # A crash after writing artifacts but before saving state must not overwrite them.
        while (output / f"{prefix}{number}").exists():
            number += 1
        version = f"{prefix}{number}"
        with tempfile.TemporaryDirectory(prefix=".building-", dir=output) as temporary:
            staging = Path(temporary)
            build.build(staging, version=version)
            verify_artifacts(staging, version)
            staging.rename(output / version)
        state["counters"][prefix] = number
        state["latest"] = version
        save_state(state)
        print(f"Local test build: {version}\nArtifacts: {output / version}")
        print("Deploy with npm run deploy:local. Release files are unchanged.")
        return version


if __name__ == "__main__":
    argparse.ArgumentParser(description=__doc__).parse_args()
    try:
        build_local()
    except (OSError, RuntimeError, ValueError, ET.ParseError) as error:
        print(f"Local build failed: {error}", file=sys.stderr)
        sys.exit(1)
