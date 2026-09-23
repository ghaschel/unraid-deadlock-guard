"""Release behavior against disposable Git repositories and a simulated GitHub service."""

import copy
import datetime as dt
import hashlib
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
from releasing.versions import next_version
from releasing.pipeline import ReleasePipeline


def git(directory, *args):
    return subprocess.check_output(
        ["git", "-C", str(directory), *args], stderr=subprocess.PIPE, text=True
    ).strip()


class FakeGitHub:
    """Only the external network boundary is simulated; Git and builds are real."""

    repository = "owner/plugin"

    def __init__(self):
        self.public = True
        self.releases = []
        self.assets = {}
        self.asset_states = {}
        self.candidate = None
        self.calls = []
        self.fail_upload = None
        self.fail_download = False
        self.on_verify = lambda: None

    def is_public(self):
        return self.public

    def candidate_files(self, run_id):
        return self.candidate

    def list_releases(self):
        return copy.deepcopy(self.releases)

    def generate_notes(self, version, source, previous):
        self.calls.append(("notes", version, source, previous))
        return "## What changed\n\n* Support metadata & newer APIs (#7)\n* Literal `$(touch SHOULD_NOT_EXIST)` and `]]>` and `@EXAMPLE@`.\n"

    def create_release(self, version, commit, body):
        release = {
            "id": len(self.releases) + 1,
            "tag_name": version,
            "target_commitish": commit,
            "body": body,
            "draft": True,
            "prerelease": True,
        }
        self.releases.append(release)
        self.calls.append(("draft", version))
        return copy.deepcopy(release)

    def list_assets(self, release_id):
        return [
            {
                "id": (release_id, name),
                "name": name,
                "state": self.asset_states.get((release_id, name), "uploaded"),
                "size": len(self.assets[release_id, name]),
            }
            for rid, name in self.assets
            if rid == release_id
        ]

    def upload_asset(self, release_id, artifact):
        self.calls.append(("upload", artifact.name))
        if self.fail_upload == artifact.name:
            raise RuntimeError("Simulated upload interruption")
        self.assets[release_id, artifact.name] = artifact.read_bytes()

    def delete_asset(self, asset):
        self.calls.append(("delete", asset["name"]))
        del self.assets[asset["id"]]
        self.asset_states.pop(asset["id"], None)

    def download_asset(self, asset):
        return self.assets[asset["id"]]

    def publish_release(self, release_id):
        self.calls.append(("publish", release_id))
        next(r for r in self.releases if r["id"] == release_id)["draft"] = False

    def public_asset(self, version, name):
        self.calls.append(("public-download", name))
        self.on_verify()
        if self.fail_download:
            raise RuntimeError("Simulated unavailable public asset")
        release = next(
            r for r in self.releases if r["tag_name"] == version and not r["draft"]
        )
        return self.assets[release["id"], name]


class VersionTest(unittest.TestCase):
    def test_date_and_suffix_order_matches_unraid_string_comparison(self):
        today = dt.date(2026, 9, 23)
        for existing, expected in [
            ([], "2026.09.23"),
            (["2026.09.22a10"], "2026.09.23"),
            (["2026.09.23"], "2026.09.23a"),
            (["2026.09.23a3"], "2026.09.23b"),
            (["2026.09.23a9", "2026.09.23a10"], "2026.09.23b"),
            (["2026.09.23z"], "2026.09.23za"),
            (["2026.09.23zz"], "2026.09.23zza"),
        ]:
            with self.subTest(existing=existing):
                actual = next_version(today, existing)
                self.assertEqual(actual, expected)
                self.assertTrue(all(actual > version for version in existing))
        self.assertEqual(
            next_version(dt.date(2027, 1, 1), ["2026.12.31z"]), "2027.01.01"
        )

    def test_clock_behind_previous_release_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "date"):
            next_version(dt.date(2026, 9, 23), ["2026.09.24"])


class ReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.remote = self.root / "remote.git"
        self.repo = self.root / "checkout"
        subprocess.run(
            ["git", "init", "--bare", "--initial-branch=main", str(self.remote)],
            check=True,
            capture_output=True,
        )
        subprocess.run(
            ["git", "clone", str(self.remote), str(self.repo)],
            check=True,
            capture_output=True,
        )
        git(self.repo, "config", "user.name", "Test Maintainer")
        git(self.repo, "config", "user.email", "test@example.invalid")
        # Copy real package inputs, not generated mock package bytes.
        import shutil

        for name in ["src", "scripts", "packaging", "plugins"]:
            shutil.copytree(
                ROOT / name,
                self.repo / name,
                ignore=shutil.ignore_patterns("__pycache__"),
            )
        for name in [
            "LICENSE",
            "icon.svg",
            "VERSION",
            "deadlock-guard.plg",
            "ca_profile.xml",
            "CHANGELOG.md",
        ]:
            if (ROOT / name).exists():
                shutil.copy2(ROOT / name, self.repo / name)
        # Keep this fixture independent of the version being released by CI.
        (self.repo / "VERSION").write_text("2026.09.23a3\n")
        (self.repo / "CHANGELOG.md").write_text("# Changelog\n")
        (self.repo / ".gitignore").write_text("/.release/\n/dist/\n__pycache__/\n")
        git(self.repo, "add", ".")
        git(self.repo, "commit", "-m", "Initial tested source")
        git(self.repo, "push", "origin", "main")
        self.source = git(self.repo, "rev-parse", "HEAD")
        self.github = FakeGitHub()
        self.pipeline = ReleasePipeline(
            self.repo, self.github, "42", self.source, dt.date(2026, 9, 24)
        )
        self.old_manifest = (self.repo / "deadlock-guard.plg").read_bytes()

    def prepare(self, dry_run=False):
        state = self.pipeline.prepare(dry_run=dry_run)
        self.state = state
        # Simulate Actions' durable artifact transport, not pipeline behavior.
        self.github.candidate = {
            name: (self.repo / ".release" / name).read_bytes()
            for name in ["state.json", "candidate.bundle"]
        }
        return state

    def published_main(self, name):
        return subprocess.check_output(
            ["git", "--git-dir", str(self.remote), "show", "main:" + name]
        )

    def test_publish_creates_exact_tag_assets_notes_and_manifest_last(self):
        state = self.prepare()
        self.assertEqual(state["version"], "2026.09.24")
        self.assertEqual(self.published_main("deadlock-guard.plg"), self.old_manifest)
        self.github.on_verify = lambda: self.assertEqual(
            self.published_main("deadlock-guard.plg"), self.old_manifest
        )
        self.pipeline.publish()
        self.assertEqual(
            git(self.repo, "ls-remote", "origin", "refs/tags/2026.09.24^{}").split()[0],
            state["commit"],
        )
        self.assertEqual(self.published_main("VERSION"), b"2026.09.24\n")
        self.assertEqual(
            self.published_main("deadlock-guard.plg"),
            (self.repo / "dist/deadlock-guard.plg").read_bytes(),
        )
        changes = (
            ET.fromstring(self.published_main("deadlock-guard.plg"))
            .findtext("CHANGES")
            .strip()
        )
        self.assertEqual(changes, f'### {state["version"]}\n{state["notes"]}')
        self.assertFalse(self.github.releases[0]["draft"])
        self.assertTrue(self.github.releases[0]["prerelease"])
        self.assertIn("Support metadata", self.github.releases[0]["body"])
        self.assertIn("Support metadata", self.published_main("CHANGELOG.md").decode())
        self.assertFalse((self.repo / "SHOULD_NOT_EXIST").exists())

    def test_private_publishing_fails_before_tag_or_release_creation(self):
        self.github.public = False
        with self.assertRaisesRegex(RuntimeError, "public"):
            self.pipeline.prepare()
        self.assertEqual(git(self.repo, "ls-remote", "--tags", "origin"), "")
        self.assertEqual(self.github.releases, [])

    def test_private_dry_run_builds_without_any_remote_writes(self):
        self.github.public = False
        state = self.prepare(dry_run=True)
        self.pipeline.publish()
        self.assertTrue(state["dry_run"])
        self.assertTrue((self.repo / "dist/SHA256SUMS").exists())
        self.assertEqual(git(self.repo, "ls-remote", "--tags", "origin"), "")
        self.assertEqual(self.github.releases, [])
        self.assertEqual(self.published_main("deadlock-guard.plg"), self.old_manifest)

    def test_rerun_resumes_same_candidate_after_partial_upload(self):
        state = self.prepare()
        self.github.fail_upload = "deadlock-guard.plg"
        with self.assertRaisesRegex(RuntimeError, "interruption"):
            self.pipeline.publish()
        self.assertEqual(self.published_main("deadlock-guard.plg"), self.old_manifest)
        self.github.fail_upload = None
        self.pipeline = ReleasePipeline(
            self.repo, self.github, "42", self.source, dt.date(2026, 9, 25)
        )
        self.assertEqual(self.pipeline.prepare(), state)
        self.pipeline.publish()
        self.assertEqual(len(self.github.releases), 1)
        package = "deadlock-guard-2026.09.24-noarch-1.txz"
        self.assertEqual(self.github.calls.count(("upload", package)), 1)
        before = git(self.remote, "rev-parse", "main")
        self.pipeline.publish()
        self.assertEqual(git(self.remote, "rev-parse", "main"), before)

    def test_mismatched_existing_asset_is_never_overwritten(self):
        self.prepare()
        self.github.fail_upload = "deadlock-guard.plg"
        with self.assertRaises(RuntimeError):
            self.pipeline.publish()
        self.github.assets[1, "deadlock-guard-2026.09.24-noarch-1.txz"] = b"corrupt"
        self.github.fail_upload = None
        with self.assertRaisesRegex(RuntimeError, "checksum"):
            self.pipeline.publish()
        self.assertEqual(
            self.github.assets[1, "deadlock-guard-2026.09.24-noarch-1.txz"], b"corrupt"
        )
        self.assertEqual(self.published_main("deadlock-guard.plg"), self.old_manifest)

    def test_rerun_recovers_empty_starter_asset_in_its_owned_draft(self):
        self.prepare()
        self.github.fail_upload = "deadlock-guard.plg"
        with self.assertRaises(RuntimeError):
            self.pipeline.publish()
        self.github.assets[1, "deadlock-guard.plg"] = b""
        self.github.asset_states[1, "deadlock-guard.plg"] = "starter"
        self.github.fail_upload = None
        self.assertEqual(self.pipeline.prepare(), self.state)
        self.pipeline.publish()
        self.assertIn(("delete", "deadlock-guard.plg"), self.github.calls)
        self.assertEqual(self.published_main("VERSION"), b"2026.09.24\n")

    def test_published_starter_placeholder_is_never_removed(self):
        self.prepare()
        self.github.fail_upload = "deadlock-guard.plg"
        with self.assertRaises(RuntimeError):
            self.pipeline.publish()
        self.github.assets[1, "deadlock-guard.plg"] = b""
        self.github.asset_states[1, "deadlock-guard.plg"] = "starter"
        self.github.releases[0]["draft"] = False
        self.github.fail_upload = None
        with self.assertRaisesRegex(RuntimeError, "checksum"):
            self.pipeline.publish()
        self.assertNotIn(("delete", "deadlock-guard.plg"), self.github.calls)
        self.assertEqual(self.published_main("deadlock-guard.plg"), self.old_manifest)

    def test_unavailable_public_download_never_promotes_manifest(self):
        self.prepare()
        self.github.fail_download = True
        with self.assertRaisesRegex(RuntimeError, "unavailable"):
            self.pipeline.publish()
        self.assertFalse(self.github.releases[0]["draft"])
        self.assertEqual(self.published_main("deadlock-guard.plg"), self.old_manifest)
        self.github.fail_download = False
        self.pipeline.publish()
        self.assertEqual(self.published_main("VERSION"), b"2026.09.24\n")

    def advance_main(self, name, content):
        other = self.root / "other"
        subprocess.run(
            ["git", "clone", str(self.remote), str(other)],
            check=True,
            capture_output=True,
        )
        git(other, "config", "user.name", "Other maintainer")
        git(other, "config", "user.email", "other@example.invalid")
        (other / name).write_text(content)
        git(other, "add", name)
        git(other, "commit", "-m", "Concurrent change")
        git(other, "push", "origin", "main")

    def test_concurrent_source_commit_survives_promotion(self):
        self.prepare()
        self.advance_main("next-feature.txt", "Unreleased source\n")
        self.pipeline.publish()
        self.assertEqual(
            self.published_main("next-feature.txt"), b"Unreleased source\n"
        )
        self.assertEqual(self.published_main("VERSION"), b"2026.09.24\n")

    def test_concurrent_release_metadata_change_stops_promotion(self):
        self.prepare()
        self.advance_main("VERSION", "2026.09.25\n")
        with self.assertRaisesRegex(RuntimeError, "metadata"):
            self.pipeline.publish()
        self.assertEqual(self.published_main("VERSION"), b"2026.09.25\n")
        self.assertEqual(self.published_main("deadlock-guard.plg"), self.old_manifest)

    def test_foreign_tag_is_never_moved(self):
        self.prepare()
        git(self.repo, "tag", "2026.09.24", self.source)
        git(self.repo, "push", "origin", "refs/tags/2026.09.24")
        with self.assertRaisesRegex(RuntimeError, "tag"):
            self.pipeline.publish()
        self.assertEqual(
            git(self.repo, "ls-remote", "origin", "refs/tags/2026.09.24").split()[0],
            self.source,
        )
        self.assertEqual(self.github.releases, [])

    def test_previous_published_prerelease_is_used_for_notes(self):
        self.github.releases.append(
            {
                "id": 1,
                "tag_name": "2026.09.23a",
                "draft": False,
                "prerelease": True,
                "body": "Previous beta",
            }
        )
        self.prepare()
        self.assertEqual(
            self.github.calls[0], ("notes", "2026.09.24", self.source, "2026.09.23a")
        )

    def test_a_new_run_cannot_skip_an_unfinished_publication(self):
        self.prepare()
        self.github.fail_download = True
        with self.assertRaises(RuntimeError):
            self.pipeline.publish()
        self.github.candidate = None
        other_run = ReleasePipeline(
            self.repo, self.github, "43", self.source, dt.date(2026, 9, 25)
        )
        with self.assertRaisesRegex(RuntimeError, "unpromoted"):
            other_run.prepare()

    def test_candidate_restore_rejects_changed_identity_or_dry_run(self):
        self.prepare()
        with self.assertRaisesRegex(RuntimeError, "Dry run"):
            self.pipeline.prepare(dry_run=True)
        other_run = ReleasePipeline(
            self.repo, self.github, "43", self.source, dt.date(2026, 9, 25)
        )
        with self.assertRaisesRegex(RuntimeError, "workflow run"):
            other_run.prepare()

    def test_a_failed_tag_push_can_retry_its_owned_local_tag(self):
        self.prepare()
        original_git = self.pipeline.repo.git

        def fail_tag_push(*args, **kwargs):
            if args[:2] == ("push", "origin") and args[2].startswith("refs/tags/"):
                raise RuntimeError("Simulated tag push interruption")
            return original_git(*args, **kwargs)

        with patch.object(self.pipeline.repo, "git", side_effect=fail_tag_push):
            with self.assertRaisesRegex(RuntimeError, "interruption"):
                self.pipeline.publish()
        self.pipeline.publish()
        self.assertEqual(self.published_main("VERSION"), b"2026.09.24\n")

    def test_concurrent_push_during_promotion_retries_without_losing_source(self):
        self.prepare()
        original_git = self.pipeline.repo.git
        advanced = False

        def racing_git(*args, **kwargs):
            nonlocal advanced
            if (
                args[:2] == ("push", "origin")
                and args[2].endswith(":refs/heads/main")
                and not advanced
            ):
                advanced = True
                self.advance_main("racing-feature.txt", "Keep this too\n")
            return original_git(*args, **kwargs)

        with patch.object(self.pipeline.repo, "git", side_effect=racing_git):
            self.pipeline.publish()
        self.assertTrue(advanced)
        self.assertEqual(self.published_main("racing-feature.txt"), b"Keep this too\n")

    def test_changed_candidate_payload_cannot_be_published(self):
        self.prepare()
        (self.repo / "src/install/slack-desc").write_text("Altered after validation")
        with self.assertRaisesRegex(RuntimeError, "candidate|changes"):
            self.pipeline.publish()
        self.assertEqual(self.github.releases, [])


if __name__ == "__main__":
    unittest.main()
