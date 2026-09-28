const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const fixture = require('./debug-fixture.cjs');

test('debug logging is absent by default and reacts to each toggle without restart', async (t) => {
  const f = await fixture(t, null);
  f.log('rpc.begin', { op: 'route' });
  assert.equal(fs.existsSync(path.dirname(f.logFile)), false);
  f.configure({ version: 1, enabled: true });
  f.log('rpc.begin', { op: 'route' });
  f.configure({ version: 1, enabled: false });
  f.log('rpc.result', { op: 'route' });
  assert.deepEqual(
    f.events().map((event) => event.event),
    ['rpc.begin'],
  );
  f.configure({ version: 1, enabled: true });
  f.log('rpc.result', { op: 'route' });
  assert.equal(f.events().length, 2);
});

test('invalid or oversized debug settings keep logging off', async (t) => {
  const f = await fixture(t, false);
  for (const value of [
    '{',
    'null',
    '{"enabled":true}',
    '{"version":2,"enabled":true}',
    '{"version":1,"enabled":"true"}',
    ' '.repeat(5000) + '{"version":1,"enabled":true}',
  ]) {
    fs.writeFileSync(f.configFile, value);
    f.log('rpc.begin', { op: 'route' });
  }
  assert.equal(fs.existsSync(path.dirname(f.logFile)), false);
});

test('debug records contain only bounded selected scalars and no request secrets', async (t) => {
  const f = await fixture(t);
  f.log('rpc.result', {
    op: 'route',
    type: 'docker',
    action: 'start',
    workloadId: 'media-server',
    jobId: 'a'.repeat(32),
    status: 'running',
    managed: true,
    durationMs: 12,
    version: 1,
    apiVersion: '4.37.4+ad268301',
    reason: 'bridge_error',
    errorClass: 'TypeError',
    request: { password: 'secret-body' },
    variables: { token: 'secret-variable' },
    authorization: 'Bearer secret-token',
    message: 'secret-error',
    error: Error('secret-error'),
    environment: { TOKEN: 'secret-environment' },
    payload: 'secret-payload',
  });
  const [record] = f.events();
  assert.equal(record.component, 'api');
  assert.equal(record.event, 'rpc.result');
  assert.equal(record.pid, process.pid);
  assert.equal(new Date(record.time).toISOString(), record.time);
  assert.equal(record.workloadId, 'media-server');
  assert.equal(record.jobId, 'a'.repeat(32));
  assert.equal(record.errorClass, 'TypeError');
  assert.equal(record.managed, true);
  assert.equal(record.durationMs, 12);
  assert.equal(fs.readFileSync(f.logFile, 'utf8').includes('secret'), false);
  f.log('rpc.failure', {
    workloadId: 'password=secret',
    jobId: 'secret',
    errorClass: 'SecretError',
    status: 'secret',
    reason: 'secret',
    op: {},
    action: [],
    durationMs: Infinity,
  });
  assert.deepEqual(Object.keys(f.events()[1]).sort(), ['component', 'event', 'pid', 'time']);
  f.log('rpc.result', { type: 'docker', workloadId: 'x'.repeat(10000) });
  assert.equal(f.events()[2].workloadId, undefined);
  assert.ok(fs.statSync(f.logFile).size < 2048);
});

test('debug log rotation caps each file and keeps private directory and file modes', async (t) => {
  const f = await fixture(t);
  fs.mkdirSync(path.dirname(f.logFile), { mode: 0o755 });
  fs.writeFileSync(f.logFile, 'x'.repeat(512 * 1024 - 1), { mode: 0o644 });
  f.log('rpc.result', { op: 'route' });
  assert.equal(fs.statSync(f.logFile + '.1').size, 512 * 1024 - 1);
  assert.deepEqual(
    f.events().map((event) => event.event),
    ['rpc.result'],
  );
  fs.writeFileSync(f.logFile, 'y'.repeat(512 * 1024));
  f.log('rpc.begin', { op: 'status' });
  assert.equal(fs.readFileSync(f.logFile + '.1', 'utf8'), 'y'.repeat(512 * 1024));
  assert.deepEqual(fs.readdirSync(path.dirname(f.logFile)).sort(), [
    'debug-api.log',
    'debug-api.log.1',
  ]);
  for (const file of [f.logFile, f.logFile + '.1']) {
    assert.equal(fs.statSync(file).mode & 0o777, 0o600);
    assert.ok(fs.statSync(file).size <= 512 * 1024);
  }
  assert.equal(fs.statSync(path.dirname(f.logFile)).mode & 0o777, 0o700);
});

test('logging silently ignores unavailable paths and refuses symbolic links', async (t) => {
  const f = await fixture(t);
  fs.writeFileSync(path.dirname(f.logFile), 'occupied');
  assert.doesNotThrow(() => f.log('rpc.begin', { op: 'route' }));
  assert.equal(fs.readFileSync(path.dirname(f.logFile), 'utf8'), 'occupied');
  fs.unlinkSync(path.dirname(f.logFile));
  fs.mkdirSync(path.dirname(f.logFile));
  const other = path.join(f.root, 'other');
  fs.writeFileSync(other, 'keep');
  fs.symlinkSync(other, f.logFile);
  assert.doesNotThrow(() => f.log('rpc.begin', { op: 'route' }));
  assert.equal(fs.readFileSync(other, 'utf8'), 'keep');
});

test('unreadable or linked debug settings and linked log directories remain harmless', async (t) => {
  const f = await fixture(t);
  const settings = path.join(f.root, 'settings.json');
  fs.renameSync(f.configFile, settings);
  fs.symlinkSync(settings, f.configFile);
  assert.doesNotThrow(() => f.log('rpc.begin', { op: 'route' }));
  assert.equal(fs.existsSync(f.logFile), false);
  fs.unlinkSync(f.configFile);
  fs.mkdirSync(f.configFile);
  assert.doesNotThrow(() => f.log('rpc.begin', { op: 'route' }));
  assert.equal(fs.existsSync(f.logFile), false);
  fs.rmdirSync(f.configFile);
  fs.renameSync(settings, f.configFile);
  const target = path.join(f.root, 'target');
  fs.mkdirSync(target, { mode: 0o755 });
  fs.symlinkSync(target, path.dirname(f.logFile));
  assert.doesNotThrow(() => f.log('rpc.begin', { op: 'route' }));
  assert.deepEqual(fs.readdirSync(target), []);
  assert.equal(fs.statSync(target).mode & 0o777, 0o755);
});
