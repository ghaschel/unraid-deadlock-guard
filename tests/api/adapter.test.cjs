const test = require('node:test');
const assert = require('node:assert/strict');
const { buildSchema, graphql } = require('graphql');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const source = path.resolve(
  __dirname,
  '../../src/usr/local/emhttp/plugins/deadlock-guard/api-plugin/adapter.mjs',
);
const adapter = () => import(pathToFileURL(source));
const uuid = '11111111-1111-1111-1111-111111111111';

async function fixture({
  managed = true,
  failed = false,
  removed = false,
  rpcError = false,
  handoff = true,
  debug,
} = {}) {
  const native = [],
    requests = [],
    permissions = [],
    finalizations = [];
  const schema = buildSchema(
    [
      'scalar PrefixedID',
      'type DockerContainer { id: String! }',
      'type DockerMutations { start(id: PrefixedID!): DockerContainer! restart(id: PrefixedID!): DockerContainer! unpause(id: PrefixedID!): DockerContainer! stop(id: PrefixedID!): DockerContainer! }',
      'type VmMutations { start(id: PrefixedID!): Boolean! resume(id: PrefixedID!): Boolean! reboot(id: PrefixedID!): Boolean! reset(id: PrefixedID!): Boolean! stop(id: PrefixedID!): Boolean! }',
      'type Mutation { docker: DockerMutations! vm: VmMutations! }',
      'type Query { healthy: Boolean! }',
    ].join('\n'),
  );
  schema.getType('PrefixedID').parseValue = (value) => value.split(':').at(-1);
  const docker = {};
  for (const name of ['start', 'restart', 'unpause', 'stop']) {
    docker[name] = async (id) => {
      native.push(name + ':' + id);
      return { id };
    };
  }
  docker.finalizeMutation = async (id, description) => {
    finalizations.push(description);
    return { id };
  };
  const vm = {};
  for (const name of ['startVm', 'resumeVm', 'rebootVm', 'resetVm', 'stopVm']) {
    vm[name] = async (id) => {
      native.push(name + ':' + id);
      return true;
    };
  }
  for (const [type, service, fields] of [
    [
      'DockerMutations',
      docker,
      { start: 'start', restart: 'restart', unpause: 'unpause', stop: 'stop' },
    ],
    [
      'VmMutations',
      vm,
      {
        start: 'startVm',
        resume: 'resumeVm',
        reboot: 'rebootVm',
        reset: 'resetVm',
        stop: 'stopVm',
      },
    ],
  ]) {
    for (const [field, method] of Object.entries(fields)) {
      schema.getType(type).getFields()[field].resolve = async (parent, args, context) => {
        // Native auth guards stand between the schema wrapper and service call.
        if (!context.req.user) throw Error('Unauthenticated');
        if (context.req.user.denied) throw Error('Forbidden');
        return service[method](args.id);
      };
    }
  }
  const rpc = async (body) => {
    requests.push(body);
    if (rpcError) throw Error('Bridge unavailable');
    if (body.op === 'route')
      return { managed, job: { id: 'a'.repeat(32), status: 'running', handoff } };
    return {
      job: {
        id: 'a'.repeat(32),
        status: failed ? 'failed' : 'succeeded',
        handoff,
        error: failed ? 'Shutdown timeout' : null,
      },
    };
  };
  const { installAdapter } = await adapter();
  installAdapter({
    schema,
    services: { docker, vm },
    rpc,
    enabled: () => !removed,
    authorize: async (subject, resource, action) => {
      permissions.push([subject, resource, action]);
      return resource === 'DOCKER';
    },
    pause: async () => {},
    debug,
  });
  return {
    native,
    finalizations,
    requests,
    permissions,
    run: (query, variables, user = { id: 'key-123' }) =>
      graphql({
        schema,
        source: query,
        variableValues: variables,
        contextValue: { req: { user } },
        rootValue: { docker: {}, vm: {} },
      }),
  };
}

test('API adapter keeps native authentication ahead of all handoff side effects', async () => {
  const f = await fixture();
  for (const user of [null, { id: 'key-123', denied: true }]) {
    const result = await f.run('mutation { docker { start(id:"container") { id } } }', {}, user);
    assert.ok(result.errors);
    assert.deepEqual(f.requests, []);
    assert.deepEqual(f.permissions, []);
    assert.deepEqual(f.native, []);
  }
});

test('API handoff preserves response shape without issuing a second native start', async () => {
  const f = await fixture();
  const result = await f.run('mutation { docker { start(id:"container") { id } } }');
  assert.equal(result.errors, undefined);
  assert.equal(result.data.docker.start.id, 'container');
  assert.deepEqual(f.native, []);
  assert.deepEqual(f.requests[0].allowedTypes, ['docker']);
  assert.deepEqual(f.permissions, [
    ['key-123', 'DOCKER', 'UPDATE_ANY'],
    ['key-123', 'VMS', 'UPDATE_ANY'],
  ]);
  assert.equal(f.requests[1].op, 'status');
});

test('unchecked Docker source and plugin removal retain native start and stop', async () => {
  for (const options of [{ managed: false }, { removed: true }]) {
    const f = await fixture(options);
    const result = await f.run(
      'mutation { docker { start(id:"container") { id } stop(id:"container") { id } } }',
    );
    assert.equal(result.errors, undefined);
    assert.deepEqual(f.native.sort(), ['start:container', 'stop:container']);
    assert.equal(f.requests.length, options.removed ? 0 : 1);
  }
});

test('API failures never fall through to native starts', async () => {
  const f = await fixture({ failed: true });
  const result = await f.run('mutation { vm { start(id:"' + uuid + '") } }');
  assert.match(result.errors[0].message, /Shutdown timeout/);
  assert.deepEqual(f.native, []);
});

test('API batches include aliases, fragments and variables while respecting directives', async () => {
  const f = await fixture();
  const result = await f.run(
    'mutation($first:PrefixedID!,$skip:Boolean!){ docker { one:start(id:$first){id} ...More } vm @skip(if:$skip){start(id:"' +
      uuid +
      '")} } fragment More on DockerMutations { two:restart(id:"other"){id} }',
    { first: 'server:container', skip: true },
  );
  assert.equal(result.errors, undefined);
  for (const request of f.requests.filter((item) => item.op === 'route')) {
    assert.deepEqual(request.batch, [
      { type: 'docker', id: 'container', action: 'start' },
      { type: 'docker', id: 'other', action: 'restart' },
    ]);
  }
});

test('API VM resume and guest reboot retain their action meanings', async () => {
  const f = await fixture();
  const result = await f.run(
    'mutation { vm { resume(id:"' + uuid + '") reboot(id:"' + uuid + '") } }',
  );
  assert.equal(result.errors, undefined);
  assert.deepEqual(
    f.requests
      .filter((r) => r.op === 'route')
      .map((r) => r.native.action)
      .sort(),
    ['restart', 'resume'],
  );
  assert.deepEqual(f.native, []);
});

test('concurrent API callers keep authentication and batch context separate', async () => {
  const f = await fixture();
  const results = await Promise.all(
    ['first', 'second'].map((id) =>
      f.run('mutation { docker { start(id:"' + id + '") { id } } }', {}, { id: 'key-' + id }),
    ),
  );
  assert.ok(results.every((result) => !result.errors));
  const routes = f.requests.filter((request) => request.op === 'route');
  assert.equal(new Set(routes.map((request) => request.key)).size, 2);
  for (const request of routes) assert.deepEqual(request.batch, [request.native]);
  assert.deepEqual([...new Set(f.permissions.map((p) => p[0]))].sort(), [
    'key-first',
    'key-second',
  ]);
  assert.deepEqual(f.native, []);
});

test('an unavailable bridge fails the API request without a native start', async () => {
  const f = await fixture({ rpcError: true });
  const result = await f.run('mutation { docker { start(id:"container") { id } } }');
  assert.match(result.errors[0].message, /Bridge unavailable/);
  assert.deepEqual(f.native, []);
});

test('API reset is checked before the native destructive method', async () => {
  const blocked = await fixture({ rpcError: true });
  const result = await blocked.run('mutation { vm { reset(id:"' + uuid + '") } }');
  assert.ok(result.errors);
  assert.equal(blocked.requests[0].native.action, 'reset');
  assert.deepEqual(blocked.native, []);
  const ungrouped = await fixture({ managed: false });
  const allowed = await ungrouped.run('mutation { vm { reset(id:"' + uuid + '") } }');
  assert.equal(allowed.errors, undefined);
  assert.deepEqual(ungrouped.native, ['resetVm:' + uuid]);
});

test('API starts with no handoff keep their response without being described as a handoff', async () => {
  const f = await fixture({ handoff: false });
  const result = await f.run('mutation { docker { start(id:"container") { id } } }');
  assert.equal(result.errors, undefined);
  assert.equal(result.data.docker.start.id, 'container');
  assert.deepEqual(f.native, []);
  assert.deepEqual(f.finalizations, ['Deadlock Guard action']);
});

test('real API adapter traces authorization and managed handoffs without secrets', async (t) => {
  const trace = await require('./debug-fixture.cjs')(t);
  const f = await fixture({ debug: trace.log });
  const result = await f.run(
    'mutation($id:PrefixedID!){docker{start(id:$id){id}}}',
    { id: 'media-server' },
    { id: 'secret-api-key' },
  );
  assert.equal(result.errors, undefined);
  const events = trace.events();
  assert.ok(
    events.some(
      (event) => event.event === 'adapter.intercepted' && event.workloadId === 'media-server',
    ),
  );
  assert.ok(
    events.some(
      (event) =>
        event.event === 'authorization.result' &&
        event.type === 'docker' &&
        event.status === 'allowed',
    ),
  );
  assert.ok(
    events.some(
      (event) =>
        event.event === 'authorization.result' &&
        event.type === 'vm' &&
        event.status === 'rejected',
    ),
  );
  assert.ok(
    events.some(
      (event) =>
        event.event === 'adapter.result' &&
        event.jobId === 'a'.repeat(32) &&
        event.status === 'succeeded',
    ),
  );
  assert.equal(
    require('node:fs').readFileSync(trace.logFile, 'utf8').includes('secret-api-key'),
    false,
  );
});

test('API adapter traces native passthrough and failures without changing their outcomes', async (t) => {
  const trace = await require('./debug-fixture.cjs')(t);
  for (const options of [
    { removed: true },
    { managed: false },
    { rpcError: true },
    { failed: true },
  ]) {
    const f = await fixture({ ...options, debug: trace.log });
    const result = await f.run('mutation { docker { start(id:"container") { id } } }');
    assert.equal(Boolean(result.errors), Boolean(options.rpcError || options.failed));
    assert.equal(f.native.length, options.rpcError || options.failed ? 0 : 1);
  }
  const events = trace.events();
  assert.ok(
    events.some(
      (event) => event.event === 'adapter.passthrough' && event.reason === 'plugin_disabled',
    ),
  );
  assert.ok(
    events.some((event) => event.event === 'adapter.passthrough' && event.reason === 'unmanaged'),
  );
  assert.ok(events.some((event) => event.event === 'adapter.failure' && event.stage === 'rpc'));
  assert.ok(events.some((event) => event.event === 'adapter.failure' && event.status === 'failed'));
  const denied = await fixture({ debug: trace.log });
  assert.ok(
    (await denied.run('mutation { docker { start(id:"container") { id } } }', {}, null)).errors,
  );
  assert.ok(trace.events().some((event) => event.event === 'resolver.failure'));
});

test('a broken debug sink cannot reject or authorize an API call', async () => {
  const f = await fixture({
    debug: () => {
      throw Error('Broken sink');
    },
  });
  assert.equal(
    (await f.run('mutation { docker { start(id:"container") { id } } }')).errors,
    undefined,
  );
  const denied = await f.run('mutation { docker { start(id:"container") { id } } }', {}, null);
  assert.ok(denied.errors);
  assert.deepEqual(f.native, []);
});
