#!/usr/bin/env python3
"""Build the Unraid package, plugin manifest, and checksum file.

The installable filesystem lives in src/. Plugin metadata and installation
commands live in packaging/deadlock-guard.plg.template.
"""

import argparse
import gzip
import hashlib
import io
import lzma
import re
import tarfile
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SOURCE_DIR = ROOT / "src"
MANIFEST_TEMPLATE = ROOT / "packaging" / "deadlock-guard.plg.template"
PLUGIN_DIR = "usr/local/emhttp/plugins/deadlock-guard"
MANIFEST_NAME = "deadlock-guard.plg"


def read_version() -> str:
    version = (ROOT / "VERSION").read_text(encoding="utf-8").strip()
    if not re.fullmatch(r"\d{4}\.\d{2}\.\d{2}(?:[a-z]\d+)?", version):
        raise ValueError(f"Invalid release version: {version!r}")
    return version


def collect_package_files(version: str) -> dict[str, tuple[bytes, int]]:
    """Map each installed path to its contents and Unix permissions."""
    files = {}
    for path in SOURCE_DIR.rglob("*"):
        if path.is_file():
            name = path.relative_to(SOURCE_DIR).as_posix()
            mode = 0o755 if path.stat().st_mode & 0o111 else 0o644
            files[name] = (path.read_bytes(), mode)

    for name in ("LICENSE", "icon.svg"):
        files[f"{PLUGIN_DIR}/{name}"] = ((ROOT / name).read_bytes(), 0o644)

    files[f"{PLUGIN_DIR}/VERSION"] = (f"{version}\n".encode("utf-8"), 0o644)
    module_files = {
        "package/" + name.removeprefix(f"{PLUGIN_DIR}/api-plugin/"): payload
        for name, payload in files.items()
        if name.startswith(f"{PLUGIN_DIR}/api-plugin/")
    }
    module_files["package/LICENSE"] = ((ROOT / "LICENSE").read_bytes(), 0o644)
    files[f"{PLUGIN_DIR}/api-plugin.tgz"] = (
        gzip.compress(tar_bytes(module_files), mtime=0), 0o644
    )
    return files


def tar_bytes(files: dict[str, tuple[bytes, int]]) -> bytes:
    """Create a tar archive with normalized ownership, order and dates."""
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w", format=tarfile.USTAR_FORMAT) as archive:
        for name, (contents, mode) in sorted(files.items()):
            entry = tarfile.TarInfo(name)
            entry.size = len(contents)
            entry.mode = mode
            entry.uid = entry.gid = 0
            entry.uname = entry.gname = "root"
            entry.mtime = 0
            archive.addfile(entry, io.BytesIO(contents))

    return buffer.getvalue()


def write_package(path: Path, files: dict[str, tuple[bytes, int]]) -> None:
    """Compress the reproducible tar archive for the Unraid package manager."""
    compressed = lzma.compress(
        tar_bytes(files),
        format=lzma.FORMAT_XZ,
        preset=9,
        check=lzma.CHECK_CRC64,
    )
    path.write_bytes(compressed)


def write_manifest(path: Path, package: Path, version: str) -> None:
    """Fill the release values without escaping or rewriting the Bash commands."""
    payload = package.read_bytes()
    values = {
        "VERSION": version,
        "PACKAGE_NAME": package.name,
        "SHA256": hashlib.sha256(payload).hexdigest(),
        # Unraid's plugin manager also consumes the legacy MD5 field.
        "MD5": hashlib.md5(payload).hexdigest(),
    }

    manifest = MANIFEST_TEMPLATE.read_text(encoding="utf-8")
    for name, value in values.items():
        manifest = manifest.replace(f"@{name}@", value)

    if re.search(r"@[A-Z_0-9]+@", manifest):
        raise ValueError("Unresolved release value in the plugin manifest")
    ET.fromstring(manifest)
    path.write_text(manifest, encoding="utf-8")


def write_checksums(output: Path, artifacts: list[Path]) -> None:
    lines = []
    for artifact in artifacts:
        checksum = hashlib.sha256(artifact.read_bytes()).hexdigest()
        lines.append(f"{checksum}  {artifact.name}\n")
    (output / "SHA256SUMS").write_text("".join(lines), encoding="utf-8")


def build(output: Path) -> None:
    version = read_version()
    output.mkdir(parents=True, exist_ok=True)

    package = output / f"deadlock-guard-{version}-noarch-1.txz"
    manifest = output / MANIFEST_NAME

    write_package(package, collect_package_files(version))
    write_manifest(manifest, package, version)
    write_checksums(output, [package, manifest])
    print(f"Built {package.name}")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--output",
        type=Path,
        default=ROOT / "dist",
        help="Artifact directory (default: dist/)",
    )
    parser.add_argument(
        "--update-manifest",
        action="store_true",
        help="Also update the repository's generated deadlock-guard.plg",
    )
    args = parser.parse_args()

    build(args.output)
    if args.update_manifest:
        (ROOT / MANIFEST_NAME).write_bytes((args.output / MANIFEST_NAME).read_bytes())


if __name__ == "__main__":
    main()
