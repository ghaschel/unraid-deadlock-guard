"""Prepare once, verify the candidate, publish assets, then advertise the update."""

import datetime as dt
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile

from .repository import Repository, METADATA
from .versions import next_version, valid_version


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def release_marker(state: dict) -> str:
    return f'<!-- deadlock-guard-release run={state["run_id"]} source={state["source"]} commit={state["commit"]} -->'


class ReleasePipeline:
    def __init__(self, root: Path, github, run_id: str, source: str, today: dt.date):
        if not re.fullmatch(r"[0-9]+", run_id) or not re.fullmatch(
            r"[0-9a-f]{40}", source
        ):
            raise ValueError("Expected a workflow run ID and a full source commit SHA")
        self.root = root.resolve()
        self.github = github
        self.run_id = run_id
        self.source = source
        self.today = today
        self.repo = Repository(self.root)
        self.directory = self.root / ".release"
        self.new_candidate = False

    def build(self, output: Path, update_manifest=False):
        args = [sys.executable, "scripts/build.py", "--output", str(output)]
        if update_manifest:
            args.append("--update-manifest")
        subprocess.run(args, cwd=self.root, check=True, stdout=subprocess.DEVNULL)

    def prepare(self, dry_run=False) -> dict:
        self.repo.assert_clean()
        if not dry_run and not self.github.is_public():
            raise RuntimeError(
                "Publishing requires a public repository. Use Dry run while it is private."
            )
        saved = self.github.candidate_files(self.run_id)
        self.directory.mkdir(exist_ok=True)
        if saved:
            return self.restore_candidate(saved, dry_run)

        self.repo.git("checkout", "--detach", self.source)
        current = (self.root / "VERSION").read_text().strip()
        if not valid_version(current):
            raise RuntimeError("Current VERSION is invalid")
        tags = self.repo.remote_tags()
        versions = [tag for tag in tags if valid_version(tag)]
        if any(version > current for version in versions):
            raise RuntimeError(
                "An unpromoted release tag exists. Finish its original workflow run first."
            )
        releases = self.github.list_releases()
        if any(
            release["draft"]
            and "deadlock-guard-release run=" in (release["body"] or "")
            for release in releases
        ):
            raise RuntimeError(
                "An unfinished release exists. Rerun its original workflow first."
            )
        published = [
            release["tag_name"]
            for release in releases
            if not release["draft"] and valid_version(release["tag_name"])
        ]
        version = next_version(self.today, [current, *versions, *published])
        previous = max(published, default=None)
        notes = self.github.generate_notes(version, self.source, previous).strip()
        if not notes:
            raise RuntimeError("GitHub did not generate release notes")
        self.write_metadata(version, notes)
        self.build(self.root / "dist", update_manifest=True)
        commit = self.repo.commit_candidate(version)
        state = {
            "schema": 1,
            "repository": self.github.repository,
            "run_id": self.run_id,
            "source": self.source,
            "commit": commit,
            "version": version,
            "previous_tag": previous,
            "notes": notes,
            "dry_run": dry_run,
            "artifacts": {
                name: digest(self.root / "dist" / name)
                for name in self.artifact_names(version)
            },
        }
        (self.directory / "state.json").write_text(json.dumps(state, indent=2) + "\n")
        self.repo.git(
            "bundle", "create", str(self.directory / "candidate.bundle"), "HEAD"
        )
        self.new_candidate = True
        return state

    def write_metadata(self, version: str, notes: str):
        (self.root / "VERSION").write_text(version + "\n")
        changelog = self.root / "CHANGELOG.md"
        existing = changelog.read_text() if changelog.exists() else "# Changelog\n"
        older = existing.removeprefix("# Changelog").lstrip()
        changelog.write_text(
            f"# Changelog\n\n## {version}\n\n{notes}\n\n{older}".rstrip() + "\n"
        )

    @staticmethod
    def artifact_names(version):
        return [
            f"deadlock-guard-{version}-noarch-1.txz",
            "deadlock-guard.plg",
            "SHA256SUMS",
        ]

    def validate_state(self, state: dict):
        expected = {
            "schema": 1,
            "repository": self.github.repository,
            "run_id": self.run_id,
            "source": self.source,
        }
        if any(state.get(key) != value for key, value in expected.items()):
            raise RuntimeError(
                "Saved candidate does not belong to this workflow run and source"
            )
        if (
            not isinstance(state.get("dry_run"), bool)
            or not isinstance(state.get("notes"), str)
            or not re.fullmatch(r"[0-9a-f]{40}", state.get("commit", ""))
            or not valid_version(state.get("version", ""))
        ):
            raise RuntimeError("Invalid saved release candidate")
        artifacts = state.get("artifacts", {})
        if set(artifacts) != set(self.artifact_names(state["version"])) or any(
            not re.fullmatch(r"[0-9a-f]{64}", checksum)
            for checksum in artifacts.values()
        ):
            raise RuntimeError("Invalid saved candidate checksums")

    def restore_candidate(self, saved: dict[str, bytes], dry_run: bool) -> dict:
        if set(saved) != {"state.json", "candidate.bundle"}:
            raise RuntimeError("Invalid candidate artifact contents")
        state = json.loads(saved["state.json"])
        self.validate_state(state)
        if state["dry_run"] != dry_run:
            raise RuntimeError("A rerun must keep the original Dry run setting")
        for name, data in saved.items():
            (self.directory / name).write_bytes(data)
        bundle = str(self.directory / "candidate.bundle")
        self.repo.git("bundle", "verify", bundle)
        self.repo.git("fetch", bundle, "HEAD")
        if self.repo.git("rev-parse", "FETCH_HEAD") != state["commit"]:
            raise RuntimeError("Saved candidate bundle has a different commit")
        self.repo.git("checkout", "--detach", state["commit"])
        self.verify_candidate(state)
        return state

    def verify_candidate(self, state: dict):
        self.validate_state(state)
        self.repo.assert_clean()
        if self.repo.git("rev-parse", "HEAD") != state["commit"]:
            raise RuntimeError("Checkout does not match the release candidate")
        if self.repo.git("rev-parse", "HEAD^") != state["source"]:
            raise RuntimeError("Candidate parent does not match its frozen source")
        changed = self.repo.git(
            "diff", "--name-only", state["source"], state["commit"]
        ).splitlines()
        if set(changed) != set(METADATA):
            raise RuntimeError(
                "Candidate must change only the three release metadata files"
            )
        # Rebuild from the frozen commit, not whichever files a previous step left in dist/.
        with tempfile.TemporaryDirectory() as temporary:
            output = Path(temporary)
            self.build(output)
            for name, checksum in state["artifacts"].items():
                if digest(output / name) != checksum:
                    raise RuntimeError(f"Candidate checksum changed: {name}")
            if (output / "deadlock-guard.plg").read_bytes() != (
                self.root / "deadlock-guard.plg"
            ).read_bytes():
                raise RuntimeError("Candidate manifest does not match its package")
            destination = self.root / "dist"
            destination.mkdir(exist_ok=True)
            for name in state["artifacts"]:
                (destination / name).write_bytes((output / name).read_bytes())

    def publish(self):
        state = json.loads((self.directory / "state.json").read_text())
        self.verify_candidate(state)
        if state["dry_run"]:
            return
        if not self.github.is_public():
            raise RuntimeError("Publishing requires a public repository")
        self.repo.ensure_tag(state)
        release = self.find_or_create_release(state)
        self.upload_and_verify(release, state)
        if release["draft"]:
            self.github.publish_release(release["id"])
        for name, checksum in state["artifacts"].items():
            downloaded = self.github.public_asset(state["version"], name)
            if hashlib.sha256(downloaded).hexdigest() != checksum:
                raise RuntimeError(f"Public asset checksum mismatch: {name}")
        self.repo.promote(state)

    def find_or_create_release(self, state):
        matches = [
            r for r in self.github.list_releases() if r["tag_name"] == state["version"]
        ]
        if matches:
            release = matches[0]
            if (
                release_marker(state) not in (release["body"] or "")
                or not release["prerelease"]
            ):
                raise RuntimeError("Existing release belongs to a different candidate")
            return release
        body = f'{state["notes"]}\n\nBeta for Unraid 7.3.x. API handoffs require API 4.36.0 or newer with compatible interfaces.\n\n{release_marker(state)}'
        return self.github.create_release(state["version"], state["commit"], body)

    def upload_and_verify(self, release, state):
        for name, checksum in state["artifacts"].items():
            matches = [
                asset
                for asset in self.github.list_assets(release["id"])
                if asset["name"] == name
            ]
            if (
                release["draft"]
                and len(matches) == 1
                and matches[0].get("state") == "starter"
                and matches[0].get("size") == 0
            ):
                # GitHub can leave this empty placeholder after an upload 502.
                # Ownership was verified above; completed/published assets stay intact.
                self.github.delete_asset(matches[0])
                matches = []
            if not matches:
                if not release["draft"]:
                    raise RuntimeError(
                        f"Published release is missing {name}; refusing to modify it"
                    )
                self.github.upload_asset(release["id"], self.root / "dist" / name)
                matches = [
                    asset
                    for asset in self.github.list_assets(release["id"])
                    if asset["name"] == name
                ]
            if len(matches) != 1:
                raise RuntimeError(f"Expected exactly one release asset: {name}")
            downloaded = self.github.download_asset(matches[0])
            if hashlib.sha256(downloaded).hexdigest() != checksum:
                raise RuntimeError(
                    f"Existing asset checksum mismatch; refusing overwrite: {name}"
                )
