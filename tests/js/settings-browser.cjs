const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs'),
  path = require('node:path'),
  assert = require('node:assert/strict');
const html = execFileSync(
  'docker',
  [
    'run',
    '--rm',
    '-v',
    process.cwd() + ':/app:ro',
    '-w',
    '/app',
    'php:8.3-cli',
    'php',
    'tests/render-settings.php',
  ],
  { encoding: 'utf8' },
);
(async () => {
  const browser = await chromium.launch(
    process.env.DG_CHROME ? { executablePath: process.env.DG_CHROME } : {},
  );
  try {
    for (const failure of [
      'stale-script',
      'api-absent',
      'server-error',
      'invalid-json',
      'timeout',
      'missing-api',
      'missing-settings',
      'broken-settings',
    ]) {
      const page = await browser.newPage({ viewport: { width: 1280, height: 1050 } });
      page.setDefaultTimeout(3000);
      await page.clock.install({ time: new Date('2026-09-22T12:00:00Z') });
      await page.clock.pauseAt(new Date('2026-09-22T12:00:00Z'));
      let mode = failure,
        writes = 0,
        snapshots = 0;
      let finishInitialLoad;
      const initialLoad =
        failure === 'stale-script'
          ? new Promise((resolve) => {
              finishInitialLoad = resolve;
            })
          : Promise.resolve();
      const vm = { type: 'vm', id: '11111111-1111-1111-1111-111111111111' };
      const snapshot = {
        config: {
          version: 1,
          groups: [
            {
              id: 'gpu',
              name: 'Saved GPU group',
              enabled: true,
              members: [vm, { type: 'docker', id: 'FileFlows' }],
              vmTimeout: 120,
              containerTimeout: 30,
              forceVm: false,
              forceContainer: false,
            },
          ],
        },
        revision: 'saved',
        health: { ready: true, message: 'VM safety gate installed and activated' },
        apiHealth: { ready: true, message: 'API handoffs installed and activated' },
        inventory: {
          workloads: [
            { ...vm, name: 'lava-lamp', status: 'stopped' },
            { type: 'docker', id: 'FileFlows', name: 'FileFlows', status: 'running' },
          ],
          errors: {},
        },
        jobs: [],
      };
      if (failure === 'api-absent')
        snapshot.apiHealth = {
          ready: false,
          message: 'API setup failed. See Troubleshooting.',
          details: 'Unraid API is not installed',
        };
      await page.route('http://fixture/**', async (route) => {
        const url = new URL(route.request().url());
        if (url.pathname === '/Settings/DeadlockGuard')
          return route.fulfill({ contentType: 'text/html', body: html });
        if (url.pathname.endsWith('/api.php')) {
          const body = route.request().postDataJSON();
          assert.equal(body.csrf, 'fixture');
          if (body.op === 'debug') return route.fulfill({ json: { enabled: false } });
          if (body.op === 'config') {
            writes++;
            assert.equal(Object.hasOwn(body.config.groups[0], 'forceContainer'), false);
            assert.equal(body.config.groups[0].forceVm, true);
            assert.equal(body.config.groups[0].containerTimeout, 42);
            return route.fulfill({ json: snapshot });
          }
          if (body.op === 'snapshot') {
            snapshots++;
            if (mode === 'stale-script') await initialLoad;
            if (mode === 'server-error')
              return route.fulfill({ status: 503, json: { error: 'VM inventory unavailable' } });
            if (mode === 'invalid-json')
              return route.fulfill({ contentType: 'text/html', body: '<html>Sign in</html>' });
            if (mode === 'timeout') return;
            return route.fulfill({ json: snapshot });
          }
          return route.fulfill({
            json: { jobs: [], health: snapshot.health, apiHealth: snapshot.apiHealth },
          });
        }
        if (url.pathname.endsWith('/integration.js') && mode === 'missing-api')
          return route.fulfill({ status: 404, body: '' });
        if (url.pathname.endsWith('/settings.js')) {
          if (mode === 'missing-settings') return route.fulfill({ status: 404, body: '' });
          if (mode === 'broken-settings')
            return route.fulfill({
              contentType: 'application/javascript',
              body: 'throw new Error("Broken settings script");',
            });
          // Simulate a cached pre-upgrade script that expects the removed Reload button.
          if (mode === 'stale-script' && !url.search)
            return route.fulfill({
              contentType: 'application/javascript',
              body: 'document.getElementById("dg-reload").onclick=function(){};',
            });
        }
        return route.fulfill({ path: path.resolve('src/usr/local/emhttp' + url.pathname) });
      });
      await page.goto('http://fixture/Settings/DeadlockGuard');
      if (failure === 'stale-script' || failure === 'api-absent') {
        if (finishInitialLoad) {
          assert.equal(await page.locator('#dg-message').innerText(), 'Loading configuration…');
          assert.equal(await page.locator('#dg-save').isDisabled(), true);
          finishInitialLoad();
        }
        await page.locator('[data-field="name"]').waitFor();
        assert.equal(await page.locator('#dg-message').isVisible(), false);
        assert.equal(
          (await page.locator('#deadlock-guard h2').innerText()).replace(/\s+/g, ' ').trim(),
          'Deadlock Guard - Beta - ' + fs.readFileSync('VERSION', 'utf8').trim(),
        );
        if (failure === 'api-absent') {
          assert.equal(
            await page.locator('#dg-api-health').innerText(),
            snapshot.apiHealth.message,
          );
          assert.equal(await page.locator('#dg-save').isEnabled(), true);
          assert.equal(await page.locator('[data-field="webui"]').isChecked(), true);
        }
        assert.equal(await page.locator('[data-field="name"]').inputValue(), 'Saved GPU group');
        await page.screenshot({ path: 'dist/settings-fixture.png', fullPage: true });
        // Failed reloads must retain unsaved groups and offer recovery.
        await page.locator('[data-field="name"]').fill('Unsaved edits');
        mode = 'server-error';
        await page.locator('#dg-discard').click();
        await page
          .locator('#dg-health')
          .filter({ hasText: /unable|could not|cannot|can't/i })
          .waitFor();
        assert.equal(await page.locator('[data-field="name"]').inputValue(), 'Unsaved edits');
      } else {
        if (failure === 'timeout' || failure === 'broken-settings') await page.clock.runFor(31000);
        await page
          .locator('#dg-health')
          .filter({ hasText: /unable|could not|cannot|can't/i })
          .waitFor();
        assert.equal(await page.locator('#dg-save').isDisabled(), true);
        assert.equal(await page.locator('#dg-add').isDisabled(), true);
        assert.ok(
          (await page.locator('#dg-message').innerText()).length > 0,
          'Load failure has no explanation',
        );
        if (failure === 'server-error')
          await page.screenshot({ path: 'dist/settings-error-fixture.png', fullPage: true });
      }
      mode = 'healthy';
      await page.getByRole('button', { name: 'Retry', exact: true }).click();
      await page.locator('#dg-retry').waitFor({ state: 'hidden' });
      await page.locator('[data-field="name"]').waitFor();
      assert.equal(await page.locator('[data-field="name"]').inputValue(), 'Saved GPU group');
      assert.equal(await page.locator('#dg-save').isEnabled(), true);
      assert.equal(await page.locator('#dg-retry').isVisible(), false);
      assert.equal(await page.locator('#dg-message').isVisible(), false);
      assert.equal(writes, 0, 'Recovery overwrote saved configuration');
      assert.ok(snapshots > 0);
      // Detailed errors belong inside Troubleshooting, rendered as text.
      const apiDetails = 'npm warn dependency\nnpm error ENOTCACHED <img src=x onerror=alert(1)>';
      snapshot.apiHealth = {
        ready: false,
        message: 'API setup failed. See Troubleshooting.',
        details: apiDetails,
      };
      await page.reload();
      await page
        .locator('#dg-api-health')
        .filter({ hasText: snapshot.apiHealth.message })
        .waitFor();
      assert.equal(await page.locator('#dg-api-health').innerText(), snapshot.apiHealth.message);
      await page.locator('#dg-troubleshooting > summary').click();
      assert.equal(await page.locator('#dg-api-details').innerText(), apiDetails);
      assert.equal(await page.locator('#dg-api-details img').count(), 0);
      snapshot.apiHealth = { ready: true, message: 'API handoffs installed and activated' };
      await page.getByRole('button', { name: 'Check Integration', exact: true }).click();
      await page
        .locator('#dg-api-health')
        .filter({ hasText: snapshot.apiHealth.message })
        .waitFor();
      assert.equal(await page.locator('#dg-api-details').isVisible(), false);
      if (failure === 'stale-script') {
        assert.equal(await page.locator('[data-field="forceContainer"]').count(), 0);
        assert.equal(await page.locator('[data-field="forceVm"]').count(), 1);
        await page.locator('[data-field="forceVm"]').check();
        await page.locator('[data-field="containerTimeout"]').fill('42');
        await page.getByRole('button', { name: 'Save groups', exact: true }).click();
        await page.waitForFunction(() =>
          document.getElementById('dg-message').textContent.includes('saved'),
        );
        assert.equal(writes, 1);
      }
      await page.close();
      console.log('PASS settings recovery: ' + failure);
    }
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
