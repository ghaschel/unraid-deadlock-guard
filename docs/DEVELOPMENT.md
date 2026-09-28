# Development

[Back to the README](../README.md)

Use Python 3, Node.js and PHP 8.3 (or Docker for PHP tests). The installed plugin uses Unraid’s existing runtimes and needs no extra container or database. Development dependencies do not ship in the Unraid package.

## Set up and test

```sh
npm ci --ignore-scripts
npm ci --prefix tests/api --ignore-scripts --legacy-peer-deps
npm run format:check
npm test
```

`npm test` runs PHP, native-control, API, local build/deploy, release-helper and reproducible-package checks. PHP runs locally when available, otherwise through Docker.

For browser fixtures:

```sh
npx playwright install chromium
npm run test:browser
```

Alternatively, set `DG_CHROME` to an existing Chrome executable. Browser tests exercise native controls and Settings using Unraid 7.3 fixtures. They do not replace [host validation](HOST-VALIDATION.md).

## Build and deploy locally

These commands run on your Mac or Linux development machine. They use Python 3 and your existing SSH/scp tools; they do not need a public GitHub release.

Set the host in the repository’s `.env` file. A template is available in `.env.example`:

```dotenv
UNRAID_HOST=tower.local
```

Use your server’s hostname, IP address or SSH alias, without `https://`, a username or a port. Deployment connects as **root** and uses your SSH key or prompts for your password. Configure a nonstandard port in `~/.ssh/config`. An exported `UNRAID_HOST` overrides `.env`. The file is read as data, never executed, and is excluded from Git and the plugin package.

```sh
npm run build:local
npm run deploy:local
```

**Build** uses the checked-out `VERSION` as its base. For `2026.09.25`, successive builds are `2026.09.25a1`, `2026.09.25a2`, and so on. A lettered release such as `2026.09.25b` produces `2026.09.25b1`; an existing test version such as `2026.09.23a3` continues at `2026.09.23a4`. Update your checkout to use a newer release as the base.

Each complete build is saved under `dist/local/<version>/` with its `.txz`, `.plg` and `SHA256SUMS`. The counter and latest successful version are saved in ignored `.local-build/`, so cleaning `dist/` does not reuse version numbers. Keep this directory if you want to retain the sequence. Failed builds leave the previous successful build available. The commands do not change `VERSION`, the published manifest, tags or GitHub releases.

**Deploy** installs the latest successful local build; it does not build automatically. It verifies the local files, uploads to a unique temporary directory, verifies checksums on Unraid, caches the package, and installs the absolute manifest path with `forced`. That flag avoids same-version and numeric-suffix sorting failures; the installer still enforces host compatibility and refuses updates during active jobs. Saved groups are preserved. A successful deployment checks the installed version and removes its temporary upload directory. Failed deployments retain uploaded files for diagnosis.

The plugin installer automatically runs `unraid-api restart` after successful API setup, including during local deployment. API connections are briefly interrupted; VMs and containers keep running. Reload your Unraid tabs afterward and check Settings for any setup or restart error. Reboot Unraid after first installation or if VM protection reports **Pending activation**; the installer never reboots the host or restarts VM/Docker services.

### Build with the release version

`python3 scripts/build.py` still writes reproducible artifacts under `dist/` using the exact `VERSION`, without updating the published root manifest. For manual transfers, use the [temporary host installation steps](HOST-VALIDATION.md#installation). Do not uninstall or delete groups to replace a test build.

## Repository layout

| Path | Purpose |
| --- | --- |
| `src/` | Installed filesystem, including package lifecycle scripts. |
| `packaging/deadlock-guard.plg.template` | Editable plugin metadata and install/remove commands. |
| `scripts/build.py` | Reproducible package, manifest and checksum generation. |
| `scripts/local_build.py` | Incremental test versions and local build validation. |
| `scripts/deploy.py` | `.env` host selection and SSH installation. |
| `tests/php/` | Coordinator, platform, configuration and lifecycle tests. |
| `tests/api/` | API compatibility, authorization and real Nest bootstrap fixtures. |
| `tests/js/` | Native controls and Settings browser checks. |
| `docs/branding/` | Editable logo/header assets and brand usage notes. |

Edit the manifest template rather than the generated `.plg`. Only release preparation uses `--update-manifest`. The small README inside `src/` supplies Unraid’s Plugins-tab description; keep the full documentation outside it.

## Formatting

Run `npm run format` for PHP, JavaScript, CSS, JSON and CI YAML. CI runs `npm run format:check`. Unraid `.page` headers, standalone hooks, generated manifests and upstream fixture assets retain their native formats; format those manually.

Shared PHP builders and platform doubles are in `tests/php/support/`; child-process crash and concurrency fixtures are in `tests/php/fixtures/` and are explicitly loaded by the runner.

Read [architecture](ARCHITECTURE.md) for the coordinator, hook and endpoint contracts, [troubleshooting](TROUBLESHOOTING.md) for diagnostics, and [releasing](RELEASING.md) for the maintainer workflow and Community Apps submission.
