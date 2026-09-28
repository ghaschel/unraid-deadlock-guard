const { test } = require('node:test');
const assert = require('node:assert/strict');
const api = require('../../src/usr/local/emhttp/plugins/deadlock-guard/javascript/integration.js');

function fixture() {
  const calls = [];
  const dialogs = [];
  const win = {
    swal(options) {
      dialogs.push(options);
    },
    eventControl(...args) {
      calls.push(['docker', ...args]);
    },
    ajaxVMDispatch(...args) {
      calls.push(['vm', ...args]);
    },
    ajaxVMDispatchconsole(...args) {
      calls.push(['console', ...args]);
    },
    ajaxVMDispatchconsoleRV(...args) {
      calls.push(['rv', ...args]);
    },
    startAll() {
      calls.push(['bulk']);
    },
    resumeAll() {
      calls.push(['resumeAll']);
    },
    location: { pathname: '/Docker' },
    loadlist() {
      calls.push(['refresh']);
    },
  };
  return { win, calls, dialogs };
}
test('managed native start waits for job; unmanaged actions preserve original arguments', async () => {
  assert.ok(api, 'Integration module missing');
  const { win, calls } = fixture();
  const seen = [];
  api.install(
    win,
    async (b) => {
      seen.push(b);
      return { managed: true, job: { id: 'job', status: 'succeeded' } };
    },
    () => {},
    async () => {},
  );
  await win.eventControl({ action: 'start', container: 'abc' }, 'loadlist');
  assert.equal(calls.filter((x) => x[0] === 'docker').length, 0);
  assert.equal(seen[0].native.id, 'abc');
  win.eventControl({ action: 'stop', container: 'abc' }, 'loadlist');
  assert.deepEqual(calls.at(-1), ['docker', { action: 'stop', container: 'abc' }, 'loadlist']);
});
test('unmanaged start goes to original dispatch and API errors never fall through', async () => {
  const { win, calls } = fixture();
  api.install(
    win,
    async () => ({ managed: false }),
    () => {},
    async () => {},
  );
  await win.ajaxVMDispatch({ action: 'domain-start', uuid: 'uuid' }, 'loadlist');
  assert.equal(calls[0][0], 'vm');
  const second = fixture();
  const errors = [];
  api.install(
    second.win,
    async () => {
      throw Error('offline');
    },
    (message, options) => errors.push(message + ' ' + (options?.detail || '')),
    async () => {},
  );
  await second.win.eventControl({ action: 'start', container: 'abc' });
  assert.equal(second.calls.length, 0);
  assert.deepEqual(errors, []);
  assert.match(second.dialogs.at(-1).text, /offline/);
});
test('VM console variants use completed job and never issue a second native start', async () => {
  for (const name of ['ajaxVMDispatchconsole', 'ajaxVMDispatchconsoleRV']) {
    const { win, calls } = fixture();
    const consoles = [];
    api.install(
      win,
      async () => ({
        managed: true,
        requests: [{ workload: { type: 'vm', id: 'uuid' } }],
        job: { id: 'job', status: 'succeeded' },
      }),
      () => {},
      async (m, mode) => consoles.push([m, mode]),
    );
    await win[name](
      {
        action: name.endsWith('RV') ? 'domain-start-consoleRV' : 'domain-start-console',
        uuid: 'uuid',
      },
      'loadlist',
    );
    assert.equal(calls.filter((x) => ['console', 'rv'].includes(x[0])).length, 0);
    assert.equal(consoles.length, 1);
  }
});
test('VM restart remains restart, resume and bulk route through coordinator', async () => {
  const { win } = fixture();
  const seen = [];
  api.install(
    win,
    async (b) => {
      seen.push(b);
      return { managed: false };
    },
    () => {},
    async () => {},
  );
  await win.ajaxVMDispatch({ action: 'domain-restart', uuid: 'uuid' });
  await win.eventControl({ action: 'resume', container: 'abc' });
  await win.startAll();
  await win.resumeAll();
  assert.deepEqual(
    seen.map((x) => x.native.action),
    ['restart', 'resume', 'start', 'resume'],
  );
  assert.equal(seen[2].native.bulk, true);
});
test('Dashboard shares dispatch wrappers and installer is idempotent', async () => {
  const { win, calls } = fixture();
  win.location.pathname = '/Dashboard';
  let n = 0;
  const send = async () => {
    n++;
    return { managed: false };
  };
  api.install(
    win,
    send,
    () => {},
    async () => {},
  );
  api.install(
    win,
    send,
    () => {},
    async () => {},
  );
  await win.eventControl({ action: 'restart', container: 'abc' });
  assert.equal(n, 1);
  assert.equal(calls.length, 1);
});
test('duplicate clicks share one accepted handoff', async () => {
  const { win } = fixture();
  let finish;
  let count = 0;
  api.install(
    win,
    () => {
      count++;
      return new Promise((r) => (finish = r));
    },
    () => {},
    async () => {},
  );
  const a = win.eventControl({ action: 'start', container: 'abc' });
  const b = win.eventControl({ action: 'start', container: 'abc' });
  assert.equal(a, b);
  finish({ managed: true, job: { id: 'one', status: 'succeeded' } });
  await a;
  assert.equal(count, 1);
});
test('native progress uses workload details without a job ID and marks completion as success', async () => {
  const { win } = fixture(),
    reports = [],
    workloads = [
      { type: 'vm', name: 'lava-lamp' },
      { type: 'docker', name: 'docker-container-xyz' },
    ];
  api.install(
    win,
    async (b) => ({
      managed: true,
      job:
        b.op === 'route'
          ? {
              id: 'e877ebc296836427af78c4682c010491',
              status: 'running',
              handoff: true,
              phase: 'Waiting for resources to be released',
              progress: { workloads },
            }
          : {
              id: 'e877ebc296836427af78c4682c010491',
              status: 'succeeded',
              handoff: true,
              phase: 'Handoff complete',
            },
    }),
    (message, options) => reports.push({ message, options }),
    async () => {},
  );
  await win.eventControl({ action: 'start', container: 'target' });
  assert.ok(reports.every((r) => !r.message.includes('e877ebc296836427af78c4682c010491')));
  assert.deepEqual(reports[0].options.workloads, workloads);
  assert.equal(reports.at(-1).options.kind, 'success');
});

test('notifications use the native Unraid toast API and update only their own toast', () => {
  const calls = [];
  const previousWindow = global.window;
  const toast = Object.fromEntries(
    ['loading', 'success', 'error', 'info', 'dismiss'].map((method) => [
      method,
      (...args) => calls.push({ method, args }),
    ]),
  );
  global.window = { toast };
  try {
    api.report('Waiting for resources to be released', {
      workloads: [{ type: 'docker', name: 'ComfyUI-Nvidia-Docker' }],
    });
    api.report('Handoff complete', { kind: 'success' });
    api.report('Handoff failed', { kind: 'error', detail: 'Shutdown timeout' });
    api.report('');

    assert.deepEqual(
      calls.map((call) => call.method),
      ['loading', 'success', 'error', 'dismiss'],
    );
    assert.equal(calls[0].args[1].duration, Infinity);
    assert.equal(calls[1].args[1].duration, 5000);
    assert.equal(calls[2].args[1].duration, Infinity);
    assert.match(calls[0].args[1].description, /ComfyUI-Nvidia-Docker.*Docker/);
    assert.equal(calls[0].args[1].id, calls[1].args[1].id);
    assert.equal(calls[3].args[0], calls[0].args[1].id);
  } finally {
    global.window = previousWindow;
  }
});

test('notification fallback uses Unraid SweetAlert when the native toaster is unavailable', () => {
  const previousWindow = global.window;
  let shown;
  global.window = {
    swal: (options) => {
      shown = options;
    },
  };
  try {
    api.report('Handoff failed', { kind: 'error', detail: '<img src=x onerror=alert(1)>' });
    assert.equal(shown.title, 'Handoff failed');
    assert.equal(shown.html, false);
    assert.equal(shown.text, '<img src=x onerror=alert(1)>');
  } finally {
    global.window = previousWindow;
  }
});

function bootPage(pathname, dispatchers = {}) {
  const alerts = [];
  const intervals = [];
  const win = {
    location: { pathname },
    swal: (options) => alerts.push(options),
    ...dispatchers,
  };
  const source = require('node:fs').readFileSync(
    require.resolve('../../src/usr/local/emhttp/plugins/deadlock-guard/javascript/integration.js'),
    'utf8',
  );
  require('node:vm').runInNewContext(source, {
    window: win,
    location: win.location,
    document: { readyState: 'complete' },
    setTimeout,
    clearTimeout,
    setInterval: (callback) => intervals.push(callback),
  });
  return { win, alerts, intervals };
}

test('container and VM editors do not require the list-page Start controls', () => {
  for (const pathname of [
    '/Docker/UpdateContainer',
    '/Docker/AddContainer',
    '/VMs/UpdateVM',
    '/VMs/AddVM',
    '/Settings/DeadlockGuard',
  ]) {
    const startAll = () => {};
    const { win, alerts, intervals } = bootPage(pathname, { startAll });
    assert.deepEqual(alerts, [], pathname + ' incorrectly reported a missing integration');
    for (const refresh of intervals) refresh();
    assert.equal(win.startAll, startAll, pathname + ' changed an unrelated page control');
  }
});

test('missing Start dispatchers still warn on the Docker and VMs lists', () => {
  for (const pathname of ['/Docker', '/Docker/', '/VMs', '/VMs/']) {
    const { alerts } = bootPage(pathname);
    assert.equal(alerts.length, 1, pathname);
    assert.equal(alerts[0].type, 'error');
    assert.match(alerts[0].title, /integration is unavailable/i);
  }
});

test('working native list and Dashboard controls boot without warnings and rewrap refreshed dispatchers', () => {
  for (const pathname of ['/Docker', '/VMs', '/Dashboard']) {
    const { win, alerts, intervals } = bootPage(pathname, {
      eventControl() {},
      ajaxVMDispatch() {},
      startAll() {},
    });
    assert.deepEqual(alerts, [], pathname);
    assert.equal(win.eventControl.deadlockGuard, true);
    assert.equal(win.ajaxVMDispatch.deadlockGuard, true);
    if (pathname !== '/Dashboard') assert.equal(win.startAll.deadlockGuard, true);
    win.eventControl = () => {};
    for (const refresh of intervals) refresh();
    assert.equal(win.eventControl.deadlockGuard, true);
  }
});

test('ordinary Stop and unmanaged Start actions do not emit or dismiss handoff notifications', async () => {
  const { win, calls } = fixture();
  const reports = [],
    requests = [];
  api.install(
    win,
    async (body) => {
      requests.push(body);
      return { managed: false };
    },
    (...args) => reports.push(args),
    async () => {},
  );
  await win.eventControl({ action: 'stop', container: 'a' });
  await win.ajaxVMDispatch({ action: 'domain-stop', uuid: 'vm' });
  await win.eventControl({ action: 'start', container: 'a' });
  assert.deepEqual(reports, []);
  assert.equal(requests.length, 1);
  assert.deepEqual(
    calls.map((call) => call[0]),
    ['docker', 'vm', 'docker'],
  );
});

test('a protected start without a handoff is silent and still opens its requested console', async () => {
  const { win, calls } = fixture();
  const reports = [],
    consoles = [];
  api.install(
    win,
    async () => ({
      managed: true,
      requests: [{ workload: { type: 'vm', id: 'vm' } }],
      job: { id: 'quiet', status: 'succeeded', handoff: false, phase: 'Action complete' },
    }),
    (...args) => reports.push(args),
    async (...args) => consoles.push(args),
  );
  await win.ajaxVMDispatchconsole({ action: 'domain-start-console', uuid: 'vm' }, 'loadlist');
  assert.deepEqual(reports, []);
  assert.equal(consoles.length, 1);
  assert.equal(consoles[0][3], false);
  assert.deepEqual(calls, [['refresh']]);
});

test('failed protected starts use the native error dialog without claiming a handoff', async () => {
  const { win, calls, dialogs } = fixture();
  const reports = [];
  api.install(
    win,
    async () => ({
      managed: true,
      job: { id: 'quiet', status: 'failed', handoff: false, error: 'Start rejected' },
    }),
    (...args) => reports.push(args),
    async () => {},
  );
  await win.eventControl({ action: 'start', container: 'a' });
  assert.deepEqual(reports, []);
  assert.deepEqual(calls, []);
  assert.equal(dialogs[0].type, 'error');
  assert.match(dialogs[0].text, /Start rejected/);
  assert.doesNotMatch(dialogs[0].title, /handoff/i);
});

test('the initial safety check stays silent until a conflict actually needs stopping', async () => {
  const { win } = fixture();
  const reports = [];
  let reads = 0;
  api.install(
    win,
    async () => {
      reads++;
      return {
        managed: true,
        job: {
          id: 'switch',
          status: reads === 3 ? 'succeeded' : 'running',
          handoff: reads > 1,
          phase:
            reads === 1 ? 'Validating' : reads === 2 ? 'Stopping conflict' : 'Handoff complete',
        },
      };
    },
    (...args) => reports.push(args),
    async () => {},
  );
  const pending = win.eventControl({ action: 'start', container: 'a' });
  await Promise.resolve();
  assert.deepEqual(reports, []);
  await pending;
  assert.deepEqual(
    reports.map(([message]) => message),
    ['Stopping conflict', 'Handoff complete'],
  );
});

test('debug traces native decisions only when enabled and never includes unrelated fields', async () => {
  const { win, calls } = fixture();
  const logs = [];
  win.console = { debug: (...args) => logs.push(args) };
  api.install(
    win,
    async () => ({ managed: false }),
    () => {},
    async () => {},
  );
  await win.eventControl({ action: 'start', container: 'abc', password: 'secret' });
  assert.equal(logs.length, 0);
  win.DeadlockGuardDebug = true;
  await win.eventControl({ action: 'start', container: 'abc', password: 'secret' });
  assert.ok(logs.length > 0);
  assert.match(JSON.stringify(logs), /abc/);
  assert.doesNotMatch(JSON.stringify(logs), /secret/);
  assert.equal(calls.length, 2);
});
