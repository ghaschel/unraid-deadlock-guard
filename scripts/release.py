#!/usr/bin/env python3
"""Entrypoint for the main-only Release workflow. Never invoke it to build locally."""
import argparse
import datetime as dt
import json
import os
from pathlib import Path
import sys

from releasing.github import GitHub
from releasing.pipeline import ReleasePipeline

ROOT = Path(__file__).resolve().parents[1]


def run(command):
    if (
        os.environ.get("GITHUB_ACTIONS") != "true"
        or os.environ.get("GITHUB_EVENT_NAME") != "workflow_dispatch"
        or os.environ.get("GITHUB_REF") != "refs/heads/main"
    ):
        raise RuntimeError(
            "Release must run through GitHub Actions workflow_dispatch on main"
        )
    github = GitHub(os.environ["GITHUB_REPOSITORY"])
    run_id = os.environ["GITHUB_RUN_ID"]
    pipeline = ReleasePipeline(
        ROOT,
        github,
        run_id,
        os.environ["GITHUB_SHA"],
        dt.datetime.now(dt.timezone.utc).date(),
    )
    if command == "prepare":
        if int(os.environ["GITHUB_RUN_ATTEMPT"]) > 1 and not github.candidate_files(
            run_id
        ):
            raise RuntimeError(
                "This rerun has no saved candidate. Do not regenerate or replace a published release. "
                "If no tag or release was created, start a new workflow run."
            )
        dry_run = os.environ["DRY_RUN"]
        if dry_run not in ("true", "false"):
            raise RuntimeError("Dry run must be true or false")
        state = pipeline.prepare(dry_run=dry_run == "true")
        with open(os.environ["GITHUB_OUTPUT"], "a") as output:
            output.write(f'version={state["version"]}\n')
            output.write(f"new_candidate={str(pipeline.new_candidate).lower()}\n")
        print(f'Prepared {state["version"]} from {state["source"]}')
    else:
        pipeline.publish()
        state = json.loads((pipeline.directory / "state.json").read_text())
        with open(os.environ["GITHUB_STEP_SUMMARY"], "a") as summary:
            if state["dry_run"]:
                summary.write(
                    f'Validated **{state["version"]}** (dry run). No remote refs or releases changed.\n'
                )
            else:
                url = f'https://github.com/{github.repository}/releases/tag/{state["version"]}'
                summary.write(
                    f'Published [{state["version"]} beta]({url}); main now advertises the verified assets.\n'
                )


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=["prepare", "publish"])
    try:
        run(parser.parse_args().command)
    except (RuntimeError, ValueError, KeyError) as error:
        print(f"Release stopped: {error}", file=sys.stderr)
        sys.exit(1)
