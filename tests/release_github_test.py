"""Exercise GitHub transport with captured CLI requests and in-memory downloads."""

import io
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from releasing.github import GitHub


class GitHubTest(unittest.TestCase):
    def setUp(self):
        self.github = GitHub("owner/plugin")

    def test_notes_use_json_stdin_and_explicit_previous_prerelease(self):
        with patch("releasing.github.subprocess.run") as run:
            run.return_value = subprocess.CompletedProcess(
                [], 0, b'{"body":"notes"}', b""
            )
            self.assertEqual(
                self.github.generate_notes("2026.09.24", "a" * 40, "2026.09.23b"),
                "notes",
            )
        args, kwargs = run.call_args
        self.assertIn("repos/owner/plugin/releases/generate-notes", args[0])
        self.assertEqual(
            json.loads(kwargs["input"]),
            {
                "tag_name": "2026.09.24",
                "target_commitish": "a" * 40,
                "previous_tag_name": "2026.09.23b",
            },
        )
        self.assertNotIn("shell", kwargs)

    def test_release_notes_are_literal_json_not_shell_arguments(self):
        notes = 'Quotes " and $(touch /tmp/no) and `false`\nSecond line'
        with patch("releasing.github.subprocess.run") as run:
            run.return_value = subprocess.CompletedProcess([], 0, b'{"id":1}', b"")
            self.github.create_release("2026.09.24", "a" * 40, notes)
        self.assertEqual(json.loads(run.call_args.kwargs["input"])["body"], notes)
        self.assertTrue(json.loads(run.call_args.kwargs["input"])["draft"])
        self.assertTrue(json.loads(run.call_args.kwargs["input"])["prerelease"])

    def test_empty_delete_response_is_successful(self):
        with patch("releasing.github.subprocess.run") as run:
            run.return_value = subprocess.CompletedProcess([], 0, b"", b"")
            self.github.delete_asset({"id": 7})
        self.assertIn("DELETE", run.call_args.args[0])

    def archive(self, entries):
        data = io.BytesIO()
        with zipfile.ZipFile(data, "w") as archive:
            for name, value in entries.items():
                archive.writestr(name, value)
        return data.getvalue()

    def test_candidate_archive_is_read_without_extracting_paths(self):
        payload = self.archive({"state.json": b"{}", "candidate.bundle": b"git bundle"})
        artifacts = [{"id": 7, "name": "release-candidate-42", "expired": False}]
        with patch.object(self.github, "pages", return_value=artifacts), patch.object(
            self.github, "request", return_value=payload
        ):
            self.assertEqual(
                self.github.candidate_files("42"),
                {"state.json": b"{}", "candidate.bundle": b"git bundle"},
            )
        payload = self.archive(
            {"../state.json": b"{}", "candidate.bundle": b"git bundle"}
        )
        with patch.object(self.github, "pages", return_value=artifacts), patch.object(
            self.github, "request", return_value=payload
        ):
            with self.assertRaisesRegex(RuntimeError, "contents"):
                self.github.candidate_files("42")

    def test_expired_or_duplicate_candidate_cannot_be_regenerated(self):
        artifact = {"id": 7, "name": "release-candidate-42", "expired": True}
        for artifacts in [[artifact], [artifact, artifact]]:
            with self.subTest(artifacts=artifacts), patch.object(
                self.github, "pages", return_value=artifacts
            ):
                with self.assertRaisesRegex(RuntimeError, "candidate"):
                    self.github.candidate_files("42")

    def test_public_verification_has_no_authentication(self):
        response = io.BytesIO(b"package")
        with patch("releasing.github.urlopen", return_value=response) as open_url:
            self.assertEqual(
                self.github.public_asset("2026.09.24", "SHA256SUMS"), b"package"
            )
        request = open_url.call_args.args[0]
        self.assertEqual(
            request.full_url,
            "https://github.com/owner/plugin/releases/download/2026.09.24/SHA256SUMS",
        )
        self.assertFalse(request.has_header("Authorization"))

    def test_repository_name_cannot_inject_a_url_or_argument(self):
        for name in ["../repo", "-host/repo", "owner/repo?token=x", "owner/repo/extra"]:
            with self.subTest(name=name), self.assertRaises(ValueError):
                GitHub(name)


if __name__ == "__main__":
    unittest.main()
