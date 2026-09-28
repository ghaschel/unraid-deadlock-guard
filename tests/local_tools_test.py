"""Exercise local build artifacts and deployment without contacting a server."""

import hashlib
import json
import os
from pathlib import Path
import shlex
import shutil
import subprocess
import sys
import tarfile
import tempfile
import unittest
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]


class LocalToolsTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="dg local tests ")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        for name in ("scripts", "src", "packaging"):
            shutil.copytree(ROOT / name, self.root / name, ignore=shutil.ignore_patterns("__pycache__"))
        for name in ("LICENSE", "icon.svg", "deadlock-guard.plg"):
            shutil.copy2(ROOT / name, self.root / name)
        (self.root / "VERSION").write_text("2026.09.25\n")
        self.environment = dict(os.environ)
        self.environment.pop("UNRAID_HOST", None)
        self.log = self.root / "transport.jsonl"
        self.environment["TRANSPORT_LOG"] = str(self.log)
        binaries = self.root / "bin"
        binaries.mkdir()
        transport = """#!/usr/bin/env python3
import json, os, pathlib, sys
with open(os.environ['TRANSPORT_LOG'], 'a') as log:
    log.write(json.dumps(sys.argv) + '\\n')
sys.exit(23 if os.environ.get('FAIL_TRANSPORT') == pathlib.Path(sys.argv[0]).name else 0)
"""
        for name in ("ssh", "scp"):
            executable = binaries / name
            executable.write_text(transport)
            executable.chmod(0o755)
        self.environment["PATH"] = str(binaries) + os.pathsep + os.environ["PATH"]
        (self.root / ".env").write_text('UNRAID_HOST="tower.test"\n')

    def run_tool(self, script, *arguments):
        return subprocess.run(
            [sys.executable, str(self.root / "scripts" / script), *arguments],
            cwd=self.root.parent,
            env=self.environment,
            capture_output=True,
            text=True,
        )

    def build(self):
        result = self.run_tool("local_build.py")
        self.assertEqual(result.returncode, 0, result.stderr)
        return result

    def calls(self):
        return [json.loads(line) for line in self.log.read_text().splitlines()] if self.log.exists() else []

    def test_incremented_versions_reach_package_and_manifest_without_editing_release_files(self):
        original = (self.root / "deadlock-guard.plg").read_bytes()
        for expected in ("2026.09.25a1", "2026.09.25a2"):
            self.build()
            output = self.root / "dist/local" / expected
            package = output / f"deadlock-guard-{expected}-noarch-1.txz"
            self.assertEqual(ET.parse(output / "deadlock-guard.plg").getroot().get("version"), expected)
            with tarfile.open(package) as archive:
                actual = archive.extractfile("usr/local/emhttp/plugins/deadlock-guard/VERSION").read().decode().strip()
            self.assertEqual(actual, expected)
            for line in (output / "SHA256SUMS").read_text().splitlines():
                digest, name = line.split("  ")
                self.assertEqual(hashlib.sha256((output / name).read_bytes()).hexdigest(), digest)
        self.assertEqual((self.root / "VERSION").read_text(), "2026.09.25\n")
        self.assertEqual((self.root / "deadlock-guard.plg").read_bytes(), original)

    def test_counter_survives_cleaning_dist_and_new_release_gets_its_own_sequence(self):
        self.build()
        shutil.rmtree(self.root / "dist")
        self.build()
        self.assertTrue((self.root / "dist/local/2026.09.25a2").is_dir())
        (self.root / "VERSION").write_text("2026.09.26\n")
        self.build()
        self.assertTrue((self.root / "dist/local/2026.09.26a1").is_dir())

    def test_existing_numeric_suffix_increments_and_letter_releases_are_preserved(self):
        for current, expected in (("2026.09.23a3", "2026.09.23a4"), ("2026.09.25b", "2026.09.25b1")):
            (self.root / "VERSION").write_text(current)
            self.build()
            self.assertTrue((self.root / "dist/local" / expected).is_dir())

    def test_counter_increments_numerically_past_nine(self):
        (self.root / "VERSION").write_text("2026.09.25a9")
        self.build()
        self.assertTrue((self.root / "dist/local/2026.09.25a10").is_dir())

    def test_failed_build_keeps_previous_successful_build_deployable(self):
        self.build()
        state = (self.root / ".local-build/state.json").read_bytes()
        (self.root / "packaging/deadlock-guard.plg.template").write_text("not XML")
        result = self.run_tool("local_build.py")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((self.root / ".local-build/state.json").read_bytes(), state)
        self.assertFalse((self.root / "dist/local/2026.09.25a2").exists())
        self.assertEqual(self.run_tool("deploy.py").returncode, 0)

    def test_corrupt_counter_is_rejected_instead_of_reusing_versions(self):
        self.build()
        for contents in ('{"counters":', '{"counters": {}}'):
            (self.root / ".local-build/state.json").write_text(contents)
            self.assertNotEqual(self.run_tool("local_build.py").returncode, 0)
        self.assertFalse((self.root / "dist/local/2026.09.25a2").exists())

    def test_build_lock_prevents_concurrent_version_allocation(self):
        import fcntl
        directory = self.root / ".local-build"
        directory.mkdir()
        with (directory / "command.lock").open("w") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = self.run_tool("local_build.py")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("already running", result.stderr)

    def test_deployment_reads_dotenv_and_transfers_only_latest_build(self):
        self.build()
        self.build()
        (self.root / ".env").write_text("# Local server\nexport UNRAID_HOST = 'tower.test' # comment\nUNRELATED=$(touch should-not-exist)\n")
        result = self.run_tool("deploy.py")
        self.assertEqual(result.returncode, 0, result.stderr)
        calls = self.calls()
        self.assertEqual([Path(call[0]).name for call in calls], ["ssh", "scp", "ssh"])
        self.assertIn("root@tower.test", calls[0])
        copied = [Path(value).name for value in calls[1][1:-1]]
        self.assertIn("deadlock-guard-2026.09.25a2-noarch-1.txz", copied)
        self.assertNotIn("deadlock-guard-2026.09.25a1-noarch-1.txz", copied)
        self.assertIn("SHA256SUMS", copied)
        self.assertFalse((self.root / "should-not-exist").exists())

    def test_environment_override_and_ipv6_destination(self):
        self.build()
        self.environment["UNRAID_HOST"] = "2001:db8::10"
        result = self.run_tool("deploy.py")
        self.assertEqual(result.returncode, 0, result.stderr)
        calls = self.calls()
        self.assertIn("root@2001:db8::10", calls[0])
        self.assertTrue(calls[1][-1].startswith("root@[2001:db8::10]:"))

    def test_missing_invalid_or_unsafe_host_never_starts_ssh(self):
        self.build()
        for host in ("", "-oProxyCommand=evil", "root@tower.test", "https://tower.test", "tower.test;touch /tmp/oops", "$(touch /tmp/oops)", "fe80::1%$(whoami)"):
            (self.root / ".env").write_text("UNRAID_HOST=" + host)
            result = self.run_tool("deploy.py")
            self.assertNotEqual(result.returncode, 0, host)
            self.assertIn("UNRAID_HOST", result.stderr)
        self.assertEqual(self.calls(), [])

    def test_no_build_or_corrupt_artifacts_never_starts_ssh(self):
        self.assertNotEqual(self.run_tool("deploy.py").returncode, 0)
        self.build()
        package = self.root / "dist/local/2026.09.25a1/deadlock-guard-2026.09.25a1-noarch-1.txz"
        package.write_bytes(package.read_bytes() + b"corrupt")
        self.assertNotEqual(self.run_tool("deploy.py").returncode, 0)
        self.assertEqual(self.calls(), [])

    def test_transfer_failure_does_not_attempt_installation(self):
        self.build()
        self.environment["FAIL_TRANSPORT"] = "scp"
        self.assertNotEqual(self.run_tool("deploy.py").returncode, 0)
        self.assertEqual([Path(call[0]).name for call in self.calls()], ["ssh", "scp"])

    def test_help_and_unknown_flags_never_build_or_deploy(self):
        self.build()
        before = (self.root / ".local-build/state.json").read_bytes()
        for script in ("local_build.py", "deploy.py"):
            self.assertEqual(self.run_tool(script, "--help").returncode, 0)
            self.assertNotEqual(self.run_tool(script, "--dry-run").returncode, 0)
        self.assertEqual((self.root / ".local-build/state.json").read_bytes(), before)
        self.assertEqual(self.calls(), [])

    def test_remote_install_verifies_upload_and_uses_forced_absolute_manifest(self):
        self.build()
        self.assertEqual(self.run_tool("deploy.py").returncode, 0)
        command = shlex.split(self.calls()[-1][-1])
        self.assertEqual(command[:2], ["bash", "-c"])
        script, label, staging, version = command[2:]
        self.assertEqual(version, "2026.09.25a1")
        # Execute the actual remote script against disposable host paths.
        host = self.root / "host"
        cache = host / "packages"
        installed = host / "installed"
        installed.mkdir(parents=True)
        staging = host / "staging"
        shutil.copytree(self.root / "dist/local/2026.09.25a1", staging)
        script = script.replace("/boot/config/plugins/deadlock-guard/packages", str(cache))
        script = script.replace("/usr/local/emhttp/plugins/deadlock-guard", str(installed))
        plugin = self.root / "bin/plugin"
        plugin.write_text("#!/usr/bin/env python3\nimport os, pathlib, sys\nassert sys.argv[1:] == ['install', os.environ['EXPECTED_MANIFEST'], 'forced']\npathlib.Path(os.environ['INSTALLED_VERSION']).write_text(os.environ.get('WRITE_VERSION', '2026.09.25a1'))\n")
        plugin.chmod(0o755)
        self.environment["EXPECTED_MANIFEST"] = str(staging / "deadlock-guard.plg")
        self.environment["INSTALLED_VERSION"] = str(installed / "VERSION")
        args = ["bash", "-c", script, label, str(staging), version]
        result = subprocess.run(args, env=self.environment, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((installed / "VERSION").read_text(), version)
        self.assertTrue((cache / f"deadlock-guard-{version}-noarch-1.txz").is_file())
        self.assertFalse(staging.exists())
        shutil.copytree(self.root / "dist/local/2026.09.25a1", staging)
        (installed / "VERSION").unlink()
        (staging / "deadlock-guard.plg").write_text("tampered")
        result = subprocess.run(args, env=self.environment, capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((installed / "VERSION").exists())
        self.assertTrue(staging.exists())
        shutil.copyfile(self.root / "dist/local/2026.09.25a1/deadlock-guard.plg", staging / "deadlock-guard.plg")
        self.environment["WRITE_VERSION"] = "old-version"
        result = subprocess.run(args, env=self.environment, capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertTrue(staging.exists())


if __name__ == "__main__":
    unittest.main()
