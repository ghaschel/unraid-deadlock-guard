"""GitHub transport. Authenticated requests use gh; public checks use no credentials."""

import io
import json
from pathlib import Path
import re
import subprocess
import time
from urllib.error import URLError
from urllib.parse import quote
from urllib.request import Request, urlopen
import zipfile


class GitHub:
    def __init__(self, repository: str):
        if not re.fullmatch(
            r"[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*", repository
        ):
            raise ValueError("Expected a GitHub owner/repository name")
        self.repository = repository
        self.base = f"repos/{repository}"

    def request(
        self, path, method="GET", payload=None, file: Path | None = None, binary=False
    ):
        accept = "application/octet-stream" if binary else "application/vnd.github+json"
        command = [
            "gh",
            "api",
            path,
            "--method",
            method,
            "-H",
            f"Accept: {accept}",
            "-H",
            "X-GitHub-Api-Version: 2022-11-28",
        ]
        data = None
        if payload is not None:
            command.extend(["--input", "-"])
            data = json.dumps(payload).encode()
        if file is not None:
            command.extend(
                ["--input", str(file), "-H", "Content-Type: application/octet-stream"]
            )
        result = subprocess.run(command, input=data, capture_output=True, timeout=180)
        if result.returncode:
            raise RuntimeError(
                f"GitHub {method} {path} failed: {result.stderr.decode().strip()}"
            )
        if binary:
            return result.stdout
        return json.loads(result.stdout) if result.stdout else None

    def pages(self, path, key=None):
        items = []
        for page in range(1, 1001):
            separator = "&" if "?" in path else "?"
            response = self.request(f"{path}{separator}per_page=100&page={page}")
            batch = response[key] if key else response
            items.extend(batch)
            if len(batch) < 100:
                return items
        raise RuntimeError(f"GitHub pagination limit exceeded: {path}")

    def is_public(self):
        return self.request(self.base)["visibility"] == "public"

    def list_releases(self):
        return self.pages(f"{self.base}/releases")

    def generate_notes(self, version, source, previous):
        payload = {"tag_name": version, "target_commitish": source}
        if previous:
            payload["previous_tag_name"] = previous
        return self.request(f"{self.base}/releases/generate-notes", "POST", payload)[
            "body"
        ]

    def create_release(self, version, commit, body):
        return self.request(
            f"{self.base}/releases",
            "POST",
            {
                "tag_name": version,
                "target_commitish": commit,
                "name": f"{version} beta",
                "body": body,
                "draft": True,
                "prerelease": True,
                "make_latest": "false",
            },
        )

    def list_assets(self, release_id):
        return self.pages(f"{self.base}/releases/{release_id}/assets")

    def upload_asset(self, release_id, file):
        path = (
            f"https://uploads.github.com/{self.base}/releases/{release_id}/assets"
            f'?name={quote(file.name, safe="")}'
        )
        return self.request(path, "POST", file=file)

    def delete_asset(self, asset):
        self.request(f'{self.base}/releases/assets/{asset["id"]}', "DELETE")

    def download_asset(self, asset):
        return self.request(f'{self.base}/releases/assets/{asset["id"]}', binary=True)

    def publish_release(self, release_id):
        return self.request(
            f"{self.base}/releases/{release_id}",
            "PATCH",
            {
                "draft": False,
                "prerelease": True,
                "make_latest": "false",
            },
        )

    def candidate_files(self, run_id):
        artifacts = self.pages(
            f"{self.base}/actions/runs/{run_id}/artifacts", "artifacts"
        )
        matches = [
            item for item in artifacts if item["name"] == f"release-candidate-{run_id}"
        ]
        if not matches:
            return None
        if len(matches) != 1 or matches[0]["expired"]:
            raise RuntimeError(
                "Saved candidate artifact is duplicated or expired; refusing to regenerate it"
            )
        data = self.request(
            f'{self.base}/actions/artifacts/{matches[0]["id"]}/zip', binary=True
        )
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            if sorted(archive.namelist()) != ["candidate.bundle", "state.json"]:
                raise RuntimeError("Invalid candidate artifact contents")
            return {name: archive.read(name) for name in archive.namelist()}

    def public_asset(self, version, name):
        url = (
            f"https://github.com/{self.repository}/releases/download/"
            f'{quote(version, safe="")}/{quote(name, safe="")}'
        )
        # GitHub's public download edge can take a moment to expose a new release.
        for attempt in range(5):
            try:
                request = Request(
                    url, headers={"User-Agent": "Deadlock-Guard-release-verification"}
                )
                with urlopen(request, timeout=30) as response:
                    return response.read()
            except (URLError, TimeoutError) as error:
                if attempt == 4:
                    raise RuntimeError(
                        f"Public download unavailable: {name}: {error}"
                    ) from error
                time.sleep(2**attempt)
