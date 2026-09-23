"""Git operations for immutable release candidates and metadata-only promotion."""

import os
from pathlib import Path
import subprocess
import tempfile

METADATA = ("VERSION", "CHANGELOG.md", "deadlock-guard.plg")
BOT_NAME = "github-actions[bot]"
BOT_EMAIL = "41898282+github-actions[bot]@users.noreply.github.com"


class Repository:
    def __init__(self, root: Path):
        self.root = root

    def git(self, *args: str, data: str | None = None, env=None) -> str:
        command = [
            "git",
            "-c",
            f"user.name={BOT_NAME}",
            "-c",
            f"user.email={BOT_EMAIL}",
            "-c",
            "commit.gpgSign=false",
            "-c",
            "tag.gpgSign=false",
            *args,
        ]
        result = subprocess.run(
            command,
            cwd=self.root,
            input=data,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=env,
        )
        if result.returncode:
            raise RuntimeError(f"Git {args[0]} failed: {result.stderr.strip()}")
        return result.stdout.strip()

    def assert_clean(self):
        if self.git("status", "--porcelain", "--untracked-files=no"):
            raise RuntimeError("Tracked changes would alter the release candidate")

    def remote_tags(self) -> dict[str, str]:
        refs = self.git("ls-remote", "--tags", "origin")
        return {
            ref.removeprefix("refs/tags/"): sha
            for sha, ref in (line.split() for line in refs.splitlines())
            if not ref.endswith("^{}")
        }

    def blob(self, commit: str, name: str) -> str | None:
        result = subprocess.run(
            ["git", "rev-parse", "--verify", f"{commit}:{name}"],
            cwd=self.root,
            text=True,
            capture_output=True,
        )
        return result.stdout.strip() if result.returncode == 0 else None

    def commit_candidate(self, version: str) -> str:
        self.git("add", "--", *METADATA)
        self.git("commit", "-m", f"Release {version}")
        return self.git("rev-parse", "HEAD")

    def ensure_tag(self, state: dict):
        version, commit = state["version"], state["commit"]
        annotation = f'Deadlock Guard release {version}\nRun: {state["run_id"]}\nSource: {state["source"]}'

        def verify(ref):
            if self.git("cat-file", "-t", ref) != "tag":
                raise RuntimeError(
                    f"Existing tag {version} is not an annotated release tag"
                )
            message = self.git("cat-file", "-p", ref).partition("\n\n")[2].strip()
            if (
                self.git("rev-parse", f"{ref}^{{commit}}") != commit
                or message != annotation
            ):
                raise RuntimeError(
                    f"Existing tag {version} belongs to a different candidate"
                )

        if version in self.remote_tags():
            self.git("fetch", "--no-tags", "origin", f"refs/tags/{version}")
            verify("FETCH_HEAD")
            return
        local = f"refs/tags/{version}"
        if self.git("tag", "--list", version):
            verify(local)
        else:
            self.git("tag", "-a", version, commit, "-m", annotation)
        self.git("push", "origin", local)

    def promote(self, state: dict):
        """Append only release metadata; a normal push rejects concurrent ref changes."""
        last_error = None
        for _ in range(3):
            self.git("fetch", "origin", "refs/heads/main")
            current = self.git("rev-parse", "FETCH_HEAD")
            released = {name: self.blob(state["commit"], name) for name in METADATA}
            present = {name: self.blob(current, name) for name in METADATA}
            if present == released:
                return
            baseline = {name: self.blob(state["source"], name) for name in METADATA}
            if any(
                present[name] not in (baseline[name], released[name])
                for name in METADATA
            ):
                raise RuntimeError(
                    "Release metadata on main changed; resolve the conflict and rerun this release"
                )
            with tempfile.TemporaryDirectory() as temporary:
                environment = dict(
                    os.environ, GIT_INDEX_FILE=str(Path(temporary) / "index")
                )
                self.git("read-tree", current, env=environment)
                for name, blob in released.items():
                    self.git(
                        "update-index",
                        "--add",
                        "--cacheinfo",
                        "100644",
                        blob,
                        name,
                        env=environment,
                    )
                tree = self.git("write-tree", env=environment)
                commit = self.git(
                    "commit-tree",
                    tree,
                    "-p",
                    current,
                    data=f'Publish release {state["version"]}\n',
                )
            try:
                self.git("push", "origin", f"{commit}:refs/heads/main")
                return
            except RuntimeError as error:
                last_error = error
        raise RuntimeError(
            f"Main promotion failed; rerun this release without replacing its assets: {last_error}"
        )
