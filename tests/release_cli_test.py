"""The command entrypoint cannot publish from the wrong branch or mint a rerun."""

import importlib.util
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
spec = importlib.util.spec_from_file_location(
    "release_cli", ROOT / "scripts/release.py"
)
cli = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cli)


class ReleaseCliTest(unittest.TestCase):
    environment = {
        "GITHUB_ACTIONS": "true",
        "GITHUB_EVENT_NAME": "workflow_dispatch",
        "GITHUB_REF": "refs/heads/main",
        "GITHUB_REPOSITORY": "owner/plugin",
        "GITHUB_RUN_ID": "42",
        "GITHUB_RUN_ATTEMPT": "1",
        "GITHUB_SHA": "a" * 40,
        "DRY_RUN": "false",
    }

    def test_ref_and_event_guards_precede_all_network_or_repository_actions(self):
        for change in [
            {"GITHUB_REF": "refs/heads/dev"},
            {"GITHUB_EVENT_NAME": "push"},
            {"GITHUB_ACTIONS": "false"},
        ]:
            with self.subTest(change=change), patch.dict(
                "os.environ", self.environment | change, clear=True
            ), patch.object(cli, "ReleasePipeline") as pipeline:
                with self.assertRaisesRegex(RuntimeError, "main|Actions"):
                    cli.run("prepare")
                pipeline.assert_not_called()

    def test_retry_without_saved_candidate_stops_before_preparation(self):
        with patch.dict(
            "os.environ", self.environment | {"GITHUB_RUN_ATTEMPT": "2"}, clear=True
        ), patch.object(cli, "GitHub") as github, patch.object(
            cli, "ReleasePipeline"
        ) as pipeline:
            github.return_value.candidate_files.return_value = None
            with self.assertRaisesRegex(RuntimeError, "candidate"):
                cli.run("prepare")
            pipeline.return_value.prepare.assert_not_called()


if __name__ == "__main__":
    unittest.main()
