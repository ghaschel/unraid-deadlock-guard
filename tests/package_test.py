import hashlib
import io
import subprocess
import tarfile
import tempfile
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PLUGIN_DIR = "usr/local/emhttp/plugins/deadlock-guard"


class PackageTest(unittest.TestCase):
    def test_release_is_reproducible_and_manifest_matches(self):
        with tempfile.TemporaryDirectory() as first, tempfile.TemporaryDirectory() as second:
            for output in (first, second):
                subprocess.run(
                    ["python3", "scripts/build.py", "--output", output],
                    cwd=ROOT,
                    check=True,
                )

            package = next(Path(first).glob("*.txz"))
            rebuilt = Path(second) / package.name
            self.assertEqual(package.read_bytes(), rebuilt.read_bytes())

            manifest_path = Path(first) / "deadlock-guard.plg"
            manifest = ET.parse(manifest_path).getroot()
            wrapper = ET.parse(ROOT / "plugins" / "deadlock-guard.xml").getroot()
            version = (ROOT / "VERSION").read_text().strip()
            checksum = hashlib.sha256(package.read_bytes()).hexdigest()

            self.assertEqual(manifest.attrib["version"], version)
            self.assertEqual(manifest.attrib["pluginURL"], wrapper.findtext("PluginURL"))
            self.assertEqual(manifest.attrib["min"], "7.3.0")
            self.assertEqual(manifest.findtext("FILE/SHA256"), checksum)
            self.assertEqual(
                manifest.findtext("FILE/MD5"),
                hashlib.md5(package.read_bytes()).hexdigest(),
            )
            self.assertTrue(
                manifest.findtext("FILE/URL").endswith(f"/{version}/{package.name}")
            )

            for line in (Path(first) / "SHA256SUMS").read_text().splitlines():
                expected, name = line.split("  ", 1)
                artifact = Path(first) / name
                self.assertEqual(hashlib.sha256(artifact.read_bytes()).hexdigest(), expected)

            with tarfile.open(package) as archive:
                names = archive.getnames()
                self.assertIn(f"{PLUGIN_DIR}/scripts/qemu-hook", names)
                module = archive.extractfile(f"{PLUGIN_DIR}/api-plugin.tgz").read()
                # The gzip OS field must be portable. Python 3.12's one-shot
                # compressor leaks the host value here even when mtime is zero.
                self.assertEqual(
                    module[9],
                    255,
                    "Bundled API archive contains host-specific gzip metadata",
                )
                with tarfile.open(fileobj=io.BytesIO(module), mode="r:gz") as npm:
                    self.assertIn("package/LICENSE", npm.getnames())
                    for name in ("package.json", "index.mjs", "adapter.mjs", "graphql.mjs", "runtime.mjs"):
                        self.assertEqual(npm.extractfile(f"package/{name}").read(),
                                         archive.extractfile(f"{PLUGIN_DIR}/api-plugin/{name}").read())
                self.assertNotIn("boot/config/plugins/deadlock-guard/config.json", names)
                self.assertTrue(
                    all(not name.startswith("/") and ".." not in Path(name).parts for name in names)
                )
                self.assertTrue(all(entry.uid == entry.gid == 0 for entry in archive))
                self.assertEqual(archive.getmember("install/doinst.sh").mode, 0o755)
                self.assertEqual(
                    archive.extractfile(f"{PLUGIN_DIR}/VERSION").read().decode().strip(),
                    version,
                )

            self.assertTrue(ET.parse(ROOT / "ca_profile.xml").findtext("Profile").strip())


if __name__ == "__main__":
    unittest.main()
