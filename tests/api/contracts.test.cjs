const test = require('node:test');
const assert = require('node:assert/strict');
const { buildSchema } = require('graphql');
const { pathToFileURL } = require('node:url');
const path = require('node:path');
const adapterUrl = pathToFileURL(
  path.resolve(
    __dirname,
    '../../src/usr/local/emhttp/plugins/deadlock-guard/api-plugin/adapter.mjs',
  ),
);

function contractFixture(change = (source) => source) {
  const schema = buildSchema(
    change(`
    scalar PrefixedID
    type DockerContainer { id: PrefixedID! }
    type Query { ok: Boolean }
    type Mutation { docker: DockerMutations! vm: VmMutations! }
    type DockerMutations {
      start(id: PrefixedID!): DockerContainer!
      restart(id: PrefixedID!): DockerContainer!
      unpause(id: PrefixedID!): DockerContainer!
    }
    type VmMutations {
      start(id: PrefixedID!): Boolean!
      resume(id: PrefixedID!): Boolean!
      reboot(id: PrefixedID!): Boolean!
      reset(id: PrefixedID!): Boolean!
    }
  `),
  );
  const services = { docker: {}, vm: {} };
  for (const name of ['start', 'restart', 'unpause', 'finalizeMutation'])
    services.docker[name] = async () => ({});
  for (const name of ['startVm', 'resumeVm', 'rebootVm', 'resetVm'])
    services.vm[name] = async () => true;
  for (const type of ['DockerMutations', 'VmMutations']) {
    for (const field of Object.values(schema.getType(type).getFields()))
      field.resolve = async () => true;
  }
  return {
    schema,
    services,
    authorize: async () => true,
    enabled: () => true,
    rpc: async () => {
      throw Error('Unexpected handoff');
    },
  };
}

for (const [name, change, alter] of [
  ['changed ID argument', (s) => s.replace('reset(id: PrefixedID!)', 'reset(id: Int!)')],
  [
    'extra required argument',
    (s) => s.replace('reset(id: PrefixedID!)', 'reset(id: PrefixedID!, force: Boolean!)'),
  ],
  [
    'changed VM result',
    (s) => s.replace('reset(id: PrefixedID!): Boolean!', 'reset(id: PrefixedID!): String!'),
  ],
  [
    'changed Docker result',
    (s) =>
      s.replace('unpause(id: PrefixedID!): DockerContainer!', 'unpause(id: PrefixedID!): Boolean!'),
  ],
  ['missing mutation', (s) => s.replace('reset(id: PrefixedID!): Boolean!', '')],
  ['renamed mutation root', (s) => s.replace('vm: VmMutations!', 'virtualMachines: VmMutations!')],
  [
    'missing VM service',
    undefined,
    (f) => {
      delete f.services.vm.resetVm;
    },
  ],
  [
    'missing finalization',
    undefined,
    (f) => {
      delete f.services.docker.finalizeMutation;
    },
  ],
  [
    'missing authorization',
    undefined,
    (f) => {
      f.authorize = undefined;
    },
  ],
  [
    'readonly service',
    undefined,
    (f) => {
      Object.freeze(f.services.vm);
    },
  ],
  [
    'readonly resolver',
    undefined,
    (f) => {
      Object.freeze(f.schema.getType('VmMutations').getFields().reset);
    },
  ],
]) {
  test('incompatible API leaves every wrapper untouched: ' + name, async () => {
    const { installAdapter } = await import(adapterUrl);
    const fixture = contractFixture(change);
    alter?.(fixture);
    const fields = ['DockerMutations', 'VmMutations'].flatMap((type) =>
      Object.values(fixture.schema.getType(type).getFields()),
    );
    const resolvers = fields.map((field) => field.resolve);
    const methods = Object.values(fixture.services).map((service) => ({ ...service }));
    assert.throws(() => installAdapter(fixture), /API|interface|authorization/i);
    assert.deepEqual(
      fields.map((field) => field.resolve),
      resolvers,
    );
    assert.deepEqual(
      Object.values(fixture.services).map((service) => ({ ...service })),
      methods,
    );
  });
}
