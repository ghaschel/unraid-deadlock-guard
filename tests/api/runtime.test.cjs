const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const childProcess = require('node:child_process');
const { syncBuiltinESMExports } = require('node:module');
const { pathToFileURL } = require('node:url');
const source = pathToFileURL(
  path.resolve(
    __dirname,
    '../../src/usr/local/emhttp/plugins/deadlock-guard/api-plugin/runtime.mjs',
  ),
);

for (const kind of [
  'success',
  'bridge error',
  'invalid response',
  'transport error',
  'spawn error',
]) {
  test('API RPC traces ' + kind + ' without request bodies or error messages', async (t) => {
    const trace = await require('./debug-fixture.cjs')(t);
    let sent;
    t.mock.method(childProcess, 'execFile', (executable, args, options, callback) => {
      assert.equal(executable, '/usr/bin/php');
      if (kind === 'spawn error') throw TypeError('secret-spawn');
      return {
        stdin: {
          on() {},
          end(body) {
            sent = JSON.parse(body);
            queueMicrotask(() =>
              callback(
                kind === 'transport error' ? Error('secret-transport') : null,
                kind === 'invalid response'
                  ? 'secret-invalid-json'
                  : JSON.stringify(
                      kind === 'bridge error'
                        ? { error: 'secret-bridge' }
                        : {
                            managed: true,
                            job: { id: 'b'.repeat(32), status: 'running' },
                            payload: 'secret-payload',
                          },
                    ),
              ),
            );
          },
        },
      };
    });
    syncBuiltinESMExports();
    t.after(() => {
      t.mock.restoreAll();
      syncBuiltinESMExports();
    });
    const { createRpc } = await import(source);
    const rpc = createRpc('fixture-hash', { debug: trace.log });
    const request = {
      op: 'route',
      native: { type: 'docker', id: 'media-server', action: 'start' },
      key: 'secret-credential',
      batch: [{ token: 'secret-token' }],
    };
    if (kind === 'success') {
      const response = await rpc(request);
      assert.equal(response.job.status, 'running');
    } else {
      await assert.rejects(
        rpc(request),
        kind === 'invalid response' ? /API bridge did not respond/ : /secret-/,
      );
    }
    if (kind !== 'spawn error') assert.equal(sent.key, 'secret-credential');
    const events = trace.events();
    assert.equal(events.length, 2, 'RPC must emit begin and outcome records');
    assert.equal(events[0].event, 'rpc.begin');
    assert.equal(events[0].op, 'route');
    assert.equal(events[0].workloadId, 'media-server');
    assert.equal(events[1].event, kind === 'success' ? 'rpc.result' : 'rpc.failure');
    assert.ok(events[1].durationMs >= 0);
    if (kind === 'success') {
      assert.equal(events[1].jobId, 'b'.repeat(32));
      assert.equal(events[1].status, 'running');
    } else assert.ok(events[1].errorClass);
    assert.equal(fs.readFileSync(trace.logFile, 'utf8').includes('secret'), false);
  });
}
