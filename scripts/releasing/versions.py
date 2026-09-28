"""Date versions ordered by Unraid's lexical plugin-version comparison."""

import datetime as dt
import re

VERSION_PATTERN = re.compile(r"([0-9]{4}\.[0-9]{2}\.[0-9]{2})([a-z]*)([0-9]*)\Z")


def valid_version(version: str) -> bool:
    match = VERSION_PATTERN.fullmatch(version)
    if not match or (match[3] and not match[2]):
        return False
    try:
        dt.datetime.strptime(match[1], "%Y.%m.%d")
    except ValueError:
        return False
    return True


def next_version(today: dt.date, existing: list[str]) -> str:
    latest = max(
        (version for version in existing if valid_version(version)), default=""
    )
    date = today.strftime("%Y.%m.%d")
    if date > latest:
        return date
    if date < latest[:10]:
        raise ValueError("UTC date is earlier than the latest release date")
    suffix = VERSION_PATTERN.fullmatch(latest)[2]
    if not suffix:
        return date + "a"
    if suffix[-1] == "z":
        return date + suffix + "a"
    return date + suffix[:-1] + chr(ord(suffix[-1]) + 1)
