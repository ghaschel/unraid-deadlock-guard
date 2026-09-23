const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
require('reflect-metadata');
const { Module } = require('@nestjs/common');
const { NestFactory } = require('@nestjs/core');
const { GraphQLSchemaHost } = require('@nestjs/graphql');
const { buildSchema } = require('graphql');

// Exercise the shipped module through actual Nest DI and bootstrap hooks. Only
// the host files and Docker/libvirt services are simulated; no host mutation.
test('Nest bootstrap discovers native providers, activates the adapter and restores on shutdown', async (t) => {
  const files = new Map();
  const runDir = '/var/run/deadlock-guard';
  const statusFile = runDir + '/api-integration.json';
  const originalRead = fs.readFileSync;
  t.mock.method(fs, 'readFileSync', function (file, ...args) {
    if (file === '/usr/local/unraid-api/package.json') return '{"version":"4.37.4"}';
    if (file === '/proc/sys/kernel/random/boot_id') return 'fixture-boot';
    if (file === '/proc/' + process.pid + '/stat')
      return process.pid + ' (node) ' + Array(20).fill('1').join(' ');
    if (files.has(file)) return files.get(file);
    return originalRead.call(this, file, ...args);
  });
  const originalExists = fs.existsSync;
  t.mock.method(fs, 'existsSync', (file) => files.has(file) || originalExists(file));
  const originalMkdir = fs.mkdirSync;
  t.mock.method(fs, 'mkdirSync', (file, ...args) =>
    file === runDir ? undefined : originalMkdir(file, ...args),
  );
  const originalWrite = fs.writeFileSync;
  t.mock.method(fs, 'writeFileSync', (file, data, ...args) =>
    String(file).startsWith(runDir + '/')
      ? files.set(file, data)
      : originalWrite(file, data, ...args),
  );
  const originalRename = fs.renameSync;
  t.mock.method(fs, 'renameSync', (from, to) => {
    if (!files.has(from)) return originalRename(from, to);
    files.set(to, files.get(from));
    files.delete(from);
  });
  const originalUnlink = fs.unlinkSync;
  t.mock.method(fs, 'unlinkSync', (file) =>
    files.has(file) ? files.delete(file) : originalUnlink(file),
  );

  class DockerService {
    async start() {}
    async restart() {}
    async unpause() {}
    async finalizeMutation() {}
  }
  class VmsService {
    async startVm() {}
    async resumeVm() {}
    async rebootVm() {}
    async resetVm() {}
  }
  class AuthZService {
    async enforce() {
      return true;
    }
  }
  const docker = new DockerService(),
    vm = new VmsService();
  const originalStart = docker.start;
  const host = new GraphQLSchemaHost();
  host.schema = buildSchema(
    'type Query { ok:Boolean } type Mutation { docker:DockerMutations vm:VmMutations } type DockerMutations { start(id:ID!):Boolean restart(id:ID!):Boolean unpause(id:ID!):Boolean } type VmMutations { start(id:ID!):Boolean resume(id:ID!):Boolean reboot(id:ID!):Boolean reset(id:ID!):Boolean }',
  );
  for (const type of ['DockerMutations', 'VmMutations']) {
    for (const field of Object.values(host.schema.getType(type).getFields()))
      field.resolve = () => true;
  }
  const source = path.resolve(
    __dirname,
    '../../src/usr/local/emhttp/plugins/deadlock-guard/api-plugin/index.mjs',
  );
  const { ApiModule, adapter } = await import(pathToFileURL(source));
  assert.equal(adapter, 'nestjs');
  class NativeModule {}
  Module({
    providers: [
      { provide: DockerService, useValue: docker },
      { provide: VmsService, useValue: vm },
      { provide: AuthZService, useValue: new AuthZService() },
      { provide: GraphQLSchemaHost, useValue: host },
    ],
  })(NativeModule);
  class Application {}
  Module({ imports: [NativeModule, ApiModule] })(Application);
  const app = await NestFactory.createApplicationContext(Application, { logger: false });
  try {
    const status = JSON.parse(files.get(statusFile));
    assert.equal(status.error, undefined);
    assert.equal(status.apiVersion, '4.37.4');
    assert.match(status.hash, /^[a-f0-9]{64}$/);
    assert.notEqual(docker.start, originalStart);
  } finally {
    await app.close();
  }
  assert.equal(docker.start, originalStart);
  assert.equal(files.has(statusFile), false);
});
