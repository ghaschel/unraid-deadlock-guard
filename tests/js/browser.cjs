const { chromium } = require('playwright');
const fs = require('node:fs'),
  path = require('node:path'),
  assert = require('node:assert/strict');
(async () => {
  const browser = await chromium.launch(
    process.env.DG_CHROME ? { executablePath: process.env.DG_CHROME } : {},
  );
  try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } }),
      errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.clock.install({ time: new Date('2026-09-22T12:00:00Z') });
    await page.clock.pauseAt(new Date('2026-09-22T12:00:00Z'));
    const a = { type: 'vm', id: '11111111-1111-1111-1111-111111111111' },
      b = { type: 'docker', id: 'FileFlows' };
    const workloads = [
      { type: 'vm', name: 'lava-lamp' },
      { type: 'docker', name: 'docker-container-xyz' },
    ];
    let config = {
      version: 1,
      groups: [
        {
          id: 'gpu',
          name: 'Shared GPU <img src=x onerror=alert(1)>',
          enabled: true,
          members: [a, b],
          vmTimeout: 120,
          containerTimeout: 30,
          forceVm: false,
          forceContainer: false,
        },
      ],
    };
    let complete = false,
      saveGate = null,
      failSave = false,
      saveRequests = 0,
      integrationChecks = 0,
      pendingChecks = 0,
      historyReads = 0,
      historyJobs = [];
    const job = () => ({
      id: 'e877ebc296836427af78c4682c010491',
      createdAt: 1790078400,
      updatedAt: 1790078400,
      status: complete ? 'succeeded' : 'running',
      phase: complete ? 'Handoff complete' : 'Waiting for resources to be released',
      progress: { workloads: complete ? [] : workloads },
      history: [],
      states: {},
      inFlight: null,
      error: null,
    });
    await page.route('http://fixture/**', async (route) => {
      const url = new URL(route.request().url());
      if (url.pathname.endsWith('/api.php')) {
        const req = route.request().postDataJSON();
        let data = {};
        assert.equal(req.csrf, 'fixture');
        if (req.op === 'snapshot')
          data = {
            config,
            revision: 'rev',
            health: { ready: true, message: 'VM safety gate installed and activated' },
            apiHealth: { ready: true, message: 'API handoffs installed and activated' },
            inventory: {
              workloads: [
                { ...a, name: 'lava-lamp', status: 'running' },
                { ...b, name: 'FileFlows', status: 'stopped' },
              ],
              errors: {},
            },
            jobs: [],
          };
        if (req.op === 'integration') {
          integrationChecks++;
          data = {
            health: { ready: true, message: 'VM integration checked' },
            apiHealth: { ready: true, message: 'API handoffs installed and activated' },
          };
        }
        if (req.op === 'pending') {
          pendingChecks++;
          data = { jobs: [] };
        }
        if (req.op === 'config') {
          saveRequests++;
          if (saveGate) await saveGate;
          if (failSave)
            return route.fulfill({
              status: 409,
              json: { error: 'Active handoffs prevent configuration changes.' },
            });
          config = req.config;
          data = { config, revision: 'next' };
        }
        if (req.op === 'history') {
          historyReads++;
          data = { jobs: historyJobs };
        }
        if (req.op === 'route')
          data = {
            managed: true,
            requests: [{ workload: req.native.type === 'vm' ? a : b, action: 'start' }],
            job: job(),
          };
        if (req.op === 'status') data = { job: job() };
        if (req.op === 'console') data = { protocol: 'vnc', websocket: 5700, name: 'lava-lamp' };
        return route.fulfill({ json: data });
      }
      const file = ['/Docker', '/Dashboard', '/VMs'].includes(url.pathname)
        ? 'tests/fixtures/unraid-7.3.html'
        : url.pathname.slice(1);
      return route.fulfill({ path: path.resolve(file) });
    });
    for (const tab of ['/Docker', '/Dashboard', '/VMs']) {
      complete = false;
      await page.goto('http://fixture' + tab);
      await page.locator('#start-container').click();
      const toast = page.locator('[data-test-toast-id="deadlock-guard"]');
      await toast.filter({ hasText: 'docker-container-xyz' }).waitFor();
      assert.match(await toast.innerText(), /lava-lamp/);
      assert.match(await toast.innerText(), /VM/);
      assert.match(await toast.innerText(), /Docker/);
      assert.equal(await toast.locator('img').count(), 0);
      assert.ok(!(await toast.innerText()).includes('e877ebc296836427af78c4682c010491'));
      assert.equal(await page.locator('#dg-progress').count(), 0, 'A custom popup was created');
      assert.equal(await page.evaluate(() => window.nativeToastCalls.at(-1).kind), 'loading');
      complete = true;
      await page.clock.runFor(1000);
      await toast.filter({ hasText: 'Handoff complete' }).waitFor();
      assert.deepEqual(await page.evaluate(() => nativeCalls), []);
      await page.clock.runFor(5100);
      assert.equal(await toast.isVisible(), false, 'Completed handoff notification stayed visible');
    }
    await page.locator('#native-fixture').evaluate((node) => node.remove());
    const markup = fs
      .readFileSync('src/usr/local/emhttp/plugins/deadlock-guard/DeadlockGuard.page', 'utf8')
      .split('---\n')[1]
      .replace(/<script[^>]*>[\s\S]*?<\/script>/g, '');
    await page
      .locator('#settings-fixture')
      .evaluate((node, html) => (node.innerHTML = html), markup);
    await page.addScriptTag({
      path: 'src/usr/local/emhttp/plugins/deadlock-guard/javascript/settings.js',
    });
    await page.locator('#dg-message').filter({ hasText: 'Configuration loaded' }).waitFor();
    assert.equal(await page.locator('.dg-group img').count(), 0);
    const webuiSource = page.locator('[data-field="webui"]');
    const apiSource = page.locator('[data-field="api"]');
    assert.equal(await webuiSource.isChecked(), true, 'Existing groups lost WebUI access');
    assert.equal(await apiSource.isChecked(), true, 'API handoffs did not default on');
    await apiSource.uncheck();
    await webuiSource.uncheck();
    await page.locator('#dg-save').click();
    await page.locator('#dg-message').filter({ hasText: 'Select WebUI, API, or both' }).waitFor();
    assert.equal(saveRequests, 0, 'A group without a source was submitted');
    await page.getByRole('button', { name: 'Close notification' }).click();
    await webuiSource.check();
    await apiSource.check();
    historyJobs = [
      {
        ...job(),
        status: 'failed',
        phase: 'Handoff failed',
        error: 'Shutdown timeout for ComfyUI-Nvidia-Docker; requested VM was not started',
      },
    ];
    const refreshHistory = async () => {
      const response = page.waitForResponse(
        (r) => r.url().endsWith('/api.php') && r.request().postDataJSON()?.op === 'history',
      );
      await page.clock.runFor(3100);
      await response;
    };
    await refreshHistory();
    const historyRow = page.locator('#dg-history details').first();
    await historyRow.locator('summary').click();
    await historyRow.evaluate((node) => (window.originalHistoryRow = node));
    await refreshHistory();
    assert.equal(
      await historyRow.evaluate((node) => node.open),
      true,
      'Refreshing history collapsed the handoff being read',
    );
    assert.equal(
      await historyRow.evaluate((node) => node === window.originalHistoryRow),
      true,
      'Refreshing history replaced the open row',
    );
    historyJobs.unshift({ ...historyJobs[0], id: 'a'.repeat(32), createdAt: 1790078410 });
    await refreshHistory();
    assert.equal(
      await page.evaluate(
        () => window.originalHistoryRow.open && window.originalHistoryRow.isConnected,
      ),
      true,
      'A new handoff collapsed the previous entry',
    );
    await page.evaluate(() => (window.originalHistoryRow.open = false));
    await refreshHistory();
    assert.equal(
      await page.evaluate(() => window.originalHistoryRow.open),
      false,
      'Refresh reopened a manually collapsed entry',
    );
    assert.equal(
      await page.getByRole('button', { name: 'Discard changes', includeHidden: true }).count(),
      1,
      'Discard changes control is missing',
    );
    assert.equal(await page.getByRole('button', { name: 'Discard changes' }).isVisible(), false);
    await page.locator('[data-field="name"]').fill('Shared GPU');
    await apiSource.check();
    assert.equal(await page.getByRole('button', { name: 'Discard changes' }).isVisible(), true);
    let releaseSave;
    saveGate = new Promise((resolve) => (releaseSave = resolve));
    await page.locator('#dg-save').click();
    assert.equal(await page.locator('#dg-save').isDisabled(), true);
    assert.equal(await page.getByRole('button', { name: 'Discard changes' }).isDisabled(), true);
    assert.equal(await page.locator('[data-field="name"]').isDisabled(), true);
    assert.equal(
      await page.locator('[data-test-toast-id="deadlock-guard"]').isVisible(),
      false,
      'Save confirmed before server accepted changes',
    );
    releaseSave();
    await page
      .locator('[data-test-toast-id="deadlock-guard"]')
      .filter({ hasText: 'Groups saved' })
      .waitFor();
    saveGate = null;
    assert.equal(config.groups[0].webui, true);
    assert.equal(config.groups[0].api, true);
    assert.equal(config.groups[0].name, 'Shared GPU');
    assert.equal(await page.locator('#dg-save').isEnabled(), true);
    assert.match(await page.locator('#dg-message').innerText(), /does not start or stop/);
    await page.screenshot({ path: 'dist/settings-fixture.png', fullPage: true });
    await page.clock.runFor(5100);
    assert.equal(await page.locator('[data-test-toast-id="deadlock-guard"]').isVisible(), false);
    assert.equal(await page.getByRole('button', { name: 'Discard changes' }).isVisible(), false);
    await page.locator('#dg-add').click();
    await page.locator('.dg-group').first().getByRole('button', { name: 'Remove group' }).click();
    await page.locator('[data-field="name"]').fill('Temporary group');
    await page.getByRole('button', { name: 'Discard changes' }).click();
    await page.locator('#dg-message').filter({ hasText: 'Unsaved changes discarded' }).waitFor();
    assert.equal(await page.locator('.dg-group').count(), 1);
    assert.equal(await page.locator('[data-field="name"]').inputValue(), 'Shared GPU');
    assert.equal(await page.locator('[data-field="vmTimeout"]').inputValue(), '120');
    assert.equal(await page.locator('[data-member]:checked').count(), 2);
    assert.equal(saveRequests, 1, 'Discard changes wrote configuration');
    assert.equal(await page.getByRole('button', { name: 'Discard changes' }).isVisible(), false);
    await page.locator('[data-field="name"]').fill('Unsaved edits');
    assert.equal(
      await page.getByRole('button', { name: 'Check Integration', exact: true }).isVisible(),
      false,
    );
    await page.getByText('Troubleshooting', { exact: true }).click();
    await page.getByRole('button', { name: 'Check Integration', exact: true }).click();
    await page.locator('#dg-message').filter({ hasText: 'Integration checked' }).waitFor();
    assert.equal(integrationChecks, 1);
    assert.equal(pendingChecks, 0, 'Integration check reviewed pending jobs');
    assert.equal(await page.locator('#dg-health').innerText(), 'VM integration checked');
    await page.getByRole('button', { name: 'Check pending jobs', exact: true }).click();
    await page.locator('#dg-message').filter({ hasText: 'Pending jobs checked' }).waitFor();
    assert.equal(pendingChecks, 1);
    assert.equal(integrationChecks, 1, 'Pending jobs check changed integration');
    assert.equal(await page.locator('#dg-health').innerText(), 'VM integration checked');
    assert.equal(
      await page.locator('[data-field="name"]').inputValue(),
      'Unsaved edits',
      'Troubleshooting discarded edits',
    );
    assert.equal(saveRequests, 1, 'Troubleshooting saved configuration');
    const previousHistoryReads = historyReads;
    const refreshed = page.waitForResponse(
      (r) => r.url().endsWith('/api.php') && r.request().postDataJSON()?.op === 'history',
    );
    await page.clock.runFor(3100);
    await refreshed;
    assert.ok(
      historyReads > previousHistoryReads,
      'Recent handoffs stopped refreshing automatically',
    );
    assert.equal(integrationChecks, 1);
    assert.equal(pendingChecks, 1, 'History polling ran maintenance');
    await page.screenshot({ path: 'dist/troubleshooting-fixture.png', fullPage: true });
    failSave = true;
    await page.locator('[data-field="name"]').fill('Unsaved edits');
    await page.locator('#dg-save').click();
    await page
      .locator('[data-test-toast-id="deadlock-guard"]')
      .filter({ hasText: 'Active handoffs' })
      .waitFor();
    await page.clock.runFor(6000);
    assert.equal(
      await page.locator('[data-test-toast-id="deadlock-guard"]').isVisible(),
      true,
      'Save failure disappeared before dismissal',
    );
    assert.equal(await page.locator('[data-field="name"]').inputValue(), 'Unsaved edits');
    assert.equal(config.groups[0].name, 'Shared GPU');
    await page.getByRole('button', { name: 'Close notification' }).click();
    assert.equal(await page.locator('[data-test-toast-id="deadlock-guard"]').isVisible(), false);
    await page.evaluate(() =>
      DeadlockGuard.report('Waiting for resources to be released', {
        workloads: [{ type: 'vm', name: '<img src=x onerror=alert(1)>' }],
      }),
    );
    assert.equal(await page.locator('[data-test-toast-id="deadlock-guard"] img').count(), 0);
    assert.match(
      await page.locator('[data-test-toast-id="deadlock-guard"]').innerText(),
      /<img src=x onerror=alert\(1\)>/,
    );
    // A completed notification must never hide a newer active handoff.
    await page.evaluate(() => DeadlockGuard.report('Saved', { kind: 'success' }));
    await page.clock.runFor(3000);
    await page.evaluate(() =>
      DeadlockGuard.report('Waiting for resources to be released', {
        workloads: [{ type: 'vm', name: 'lava-lamp' }],
      }),
    );
    await page.clock.runFor(6000);
    assert.equal(await page.locator('[data-test-toast-id="deadlock-guard"]').isVisible(), true);
    // A browser-blocked console gets a persistent native toast action after completion.
    await page.evaluate(() => {
      window.open = () => null;
      ajaxVMDispatchconsole(
        { action: 'domain-start-console', uuid: '11111111-1111-1111-1111-111111111111' },
        'loadlist',
      );
    });
    await page.getByRole('button', { name: 'Open VM console', exact: true }).waitFor();
    await page.clock.runFor(6000);
    assert.equal(
      await page.getByRole('button', { name: 'Open VM console', exact: true }).isVisible(),
      true,
    );
    assert.deepEqual(errors, []);
    console.log(
      'PASS browser fixtures: native tabs, readable escaped progress, native notification calls/timers, stable history, save success/failure, discard changes, troubleshooting, retained edits and console fallback',
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
