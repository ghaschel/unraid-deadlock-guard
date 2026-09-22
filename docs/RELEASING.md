# Beta release procedure

1. Complete and record `HOST-VALIDATION.md` on disposable Unraid 7.3.x before claiming compatibility. Confirm tests and review pass; resolve all safety findings.
2. Set `VERSION` to a new date-based version (optional letter/number suffix). Run `./scripts/test.sh`, then `python3 scripts/build.py --update-manifest`. Commit the generated root manifest along with source changes. Rebuild from the committed checkout and confirm identical `.txz` and manifest hashes.
3. Push the reviewed branch and merge through the maintainer's normal review process. Tag the exact version. Publish a **GitHub prerelease**, uploading `dist/deadlock-guard-<version>-noarch-1.txz`, `dist/deadlock-guard.plg` and `dist/SHA256SUMS`. The build workflow creates downloadable artifacts; it does not publish automatically.
4. Verify the public versioned asset URL, checksum and raw main-branch manifest. The CA wrapper `PluginURL` and manifest `pluginURL` must match exactly. Retain previous versioned assets. Test installation from the public manifest.
5. Open [Community Apps submission](https://ca.unraid.net/submit/new) for `ghaschel/unraid-deadlock-manager`, run **Validate**, then **Scan**. Fix findings, repeat checks, and submit for manual plugin review. GitHub Issues is the initial support destination; do not invent a forum URL.

The build uses sorted POSIX tar entries, root ownership, fixed timestamps, normalized permissions, and XZ compression. It includes no persistent configuration in the archive. Native plugin MD5 verification is supplemented by SHA-256 verification before `upgradepkg`. Every install/update/remove path preserves `config.json`.

Publication and CA submission have not been performed by this implementation task; the versioned URLs will return errors until the matching assets are public.
