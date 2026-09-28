const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

module.exports = async function debugFixture(t, enabled = true) {
  const source = path.resolve(
    __dirname,
    '../../src/usr/local/emhttp/plugins/deadlock-guard/api-plugin/debug.mjs',
  );
  assert.ok(fs.existsSync(source), 'API debug logger must be implemented');
  const { createDebugLogger } = await import(pathToFileURL(source));
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'dg-api-debug-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  const configFile = path.join(root, 'debug.json');
  const logFile = path.join(root, 'run', 'debug-api.log');
  const configure = (value) => fs.writeFileSync(configFile, JSON.stringify(value));
  if (enabled !== null) configure({ version: 1, enabled });
  return {
    root,
    configFile,
    logFile,
    configure,
    log: createDebugLogger({ configFile, logFile }),
    events: () =>
      fs.existsSync(logFile)
        ? fs.readFileSync(logFile, 'utf8').trim().split('\n').filter(Boolean).map(JSON.parse)
        : [],
  };
};
