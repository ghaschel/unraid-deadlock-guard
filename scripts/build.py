#!/usr/bin/env python3
"""Build the Unraid package, plugin manifest, and checksum file.

The installable filesystem lives in src/. Plugin metadata and installation
commands live in packaging/deadlock-guard.plg.template.
"""

import argparse
import gzip
import datetime as dt
import hashlib
import io
import lzma
import re
import tarfile
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Optional


ROOT = Path(__file__).resolve().parents[1]
SOURCE_DIR = ROOT / "src"
MANIFEST_TEMPLATE = ROOT / "packaging" / "deadlock-guard.plg.template"
PLUGIN_DIR = "usr/local/emhttp/plugins/deadlock-guard"
MANIFEST_NAME = "deadlock-guard.plg"


def validate_version(version: str) -> str:
    if not re.fullmatch(r"[0-9]{4}\.[0-9]{2}\.[0-9]{2}(?:[a-z]+[0-9]*)?", version):
        raise ValueError(f"Invalid release version: {version!r}")
    dt.datetime.strptime(version[:10], "%Y.%m.%d")
    return version


def read_version() -> str:
    return validate_version((ROOT / "VERSION").read_text(encoding="utf-8").strip())


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
    files[f"{PLUGIN_DIR}/api-plugin.tgz"] = (gzip_bytes(tar_bytes(module_files)), 0o644)
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


def gzip_bytes(contents: bytes) -> bytes:
    """Use a portable gzip header across Python versions and operating systems."""
    buffer = io.BytesIO()
    # gzip.compress(..., mtime=0) leaks zlib's OS byte on Python 3.11/3.12.
    # GzipFile writes a portable header and omits the original filename.
    with gzip.GzipFile(
        fileobj=buffer,
        mode="wb",
        filename="",
        compresslevel=9,
        mtime=0,
    ) as archive:
        archive.write(contents)
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


def release_notes(version: str) -> str:
    changelog = ROOT / "CHANGELOG.md"
    if changelog.exists():
        heading = re.search(
            r"^## " + re.escape(version) + r"$", changelog.read_text(), re.M
        )
        if heading:
            remaining = changelog.read_text()[heading.end() :]
            return re.split(
                r"^## [0-9]{4}\.[0-9]{2}\.[0-9]{2}", remaining, maxsplit=1, flags=re.M
            )[0].strip()
    return "Development build. See GitHub Releases for published changes."


def write_manifest(path: Path, package: Path, version: str) -> None:
    """Fill the release values without escaping or rewriting the Bash commands."""
    payload = package.read_bytes()
    values = {
        "VERSION": version,
        "CHANGES": release_notes(version).replace("]]>", "]]]]><![CDATA[>"),
        "PACKAGE_NAME": package.name,
        "SHA256": hashlib.sha256(payload).hexdigest(),
        # Unraid's plugin manager also consumes the legacy MD5 field.
        "MD5": hashlib.md5(payload).hexdigest(),
    }

    manifest = MANIFEST_TEMPLATE.read_text(encoding="utf-8")
    placeholders = set(re.findall(r"@([A-Z_0-9]+)@", manifest))
    if placeholders - values.keys():
        raise ValueError("Unresolved release value in the plugin manifest")
    # One pass prevents PR text containing @TOKENS@ from becoming template input.
    manifest = re.sub(r"@([A-Z_0-9]+)@", lambda match: values[match[1]], manifest)
    ET.fromstring(manifest)
    path.write_text(manifest, encoding="utf-8")


def write_checksums(output: Path, artifacts: list[Path]) -> None:
    lines = []
    for artifact in artifacts:
        checksum = hashlib.sha256(artifact.read_bytes()).hexdigest()
        lines.append(f"{checksum}  {artifact.name}\n")
    (output / "SHA256SUMS").write_text("".join(lines), encoding="utf-8")


def build(output: Path, version: Optional[str] = None) -> None:
    version = read_version() if version is None else validate_version(version)
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
