import { AsyncLocalStorage } from 'node:async_hooks';
import { randomUUID } from 'node:crypto';
import { collectActions, operations } from './graphql.mjs';

/**
 * Preserve the native GraphQL resolver and its guards. The schema wrapper only
 * carries request context; handoff side effects happen inside the service call,
 * after Unraid's original authentication and authorization have succeeded.
 */
export function installAdapter({
  schema,
  services,
  authorize,
  rpc,
  enabled,
  pause = () => new Promise((resolve) => setTimeout(resolve, 750)),
}) {
  const context = new AsyncLocalStorage();
  const bindings = [];
  if (typeof authorize !== 'function') throw Error('API authorization is unavailable');
  const roots = schema?.getMutationType?.()?.getFields?.();
  for (const [name, type] of [
    ['docker', 'DockerMutations!'],
    ['vm', 'VmMutations!'],
  ]) {
    if (String(roots?.[name]?.type) !== type)
      throw Error('Unsupported Unraid API interface: Mutation.' + name);
  }
  for (const [typeName, fields] of Object.entries(operations)) {
    const type = schema.getType(typeName);
    for (const [name, operation] of Object.entries(fields)) {
      const field = type?.getFields?.()[name];
      const service = services[operation.type];
      if (
        typeof field?.resolve !== 'function' ||
        typeof service?.[operation.method] !== 'function'
      ) {
        throw Error('Unsupported Unraid API interface: ' + typeName + '.' + name);
      }
      const resultType = operation.type === 'vm' ? 'Boolean!' : 'DockerContainer!';
      if (
        field.args.length !== 1 ||
        field.args[0].name !== 'id' ||
        String(field.args[0].type) !== 'PrefixedID!' ||
        String(field.type) !== resultType
      ) {
        throw Error('Unsupported Unraid API argument/result interface: ' + typeName + '.' + name);
      }
      assertWritable(field, 'resolve');
      assertWritable(service, operation.method);
      bindings.push({
        field,
        service,
        operation,
        original: service[operation.method],
      });
    }
  }
  if (typeof services.docker.finalizeMutation !== 'function') {
    throw Error('Unsupported Docker mutation result interface');
  }

  const replacements = [];
  for (const { field, service, operation, original } of bindings) {
    const originalResolver = field.resolve;
    const resolver = function (parent, args, requestContext, info) {
      return context.run({ requestContext, info, operation, id: args.id }, () =>
        originalResolver.call(this, parent, args, requestContext, info),
      );
    };

    const method = async function (...args) {
      const call = context.getStore();
      if (!enabled() || !call || call.operation !== operation || call.id !== args[0]) {
        return original.apply(this, args);
      }
      // req.user is set by the native authentication guard, never from JSON.
      const user = call.requestContext?.req?.user;
      const subject = typeof user?.id === 'string' ? user.id.trim() : '';
      if (!subject) throw Error('Unraid API authentication context is missing');
      const allowedTypes = [];
      for (const [type, resource] of [
        ['docker', 'DOCKER'],
        ['vm', 'VMS'],
      ]) {
        if (await authorize(subject, resource, 'UPDATE_ANY')) allowedTypes.push(type);
      }
      const result = await rpc({
        op: 'route',
        native: { type: operation.type, id: args[0], action: operation.action },
        batch: collectActions(call.info),
        key: 'api-' + randomUUID(),
        allowedTypes,
      });
      if (!result.managed) return original.apply(this, args);
      let job = result.job;
      while (!['succeeded', 'failed', 'quarantined'].includes(job.status)) {
        await pause();
        job = (await rpc({ op: 'status', id: job.id })).job;
      }
      if (job.status !== 'succeeded') throw Error(job.error || job.phase || 'Handoff failed');
      if (operation.type === 'vm') return true;
      return this.finalizeMutation(args[0], 'Deadlock Guard handoff');
    };
    replacements.push({ object: field, key: 'resolve', value: resolver });
    replacements.push({ object: service, key: operation.method, value: method });
  }

  const applied = [];
  const restore = () => {
    for (const { object, key, value, descriptor } of [...applied].reverse()) {
      if (object[key] !== value) continue;
      if (descriptor) Object.defineProperty(object, key, descriptor);
      else delete object[key];
    }
  };
  try {
    for (const replacement of replacements) {
      const { object, key, value } = replacement;
      const descriptor = Object.getOwnPropertyDescriptor(object, key);
      Object.defineProperty(
        object,
        key,
        descriptor
          ? { ...descriptor, value }
          : {
              value,
              writable: true,
              configurable: true,
              enumerable: true,
            },
      );
      applied.push({ ...replacement, descriptor });
    }
  } catch (error) {
    restore();
    throw Error('Unable to install Unraid API interface: ' + error.message);
  }
  return restore;
}

function assertWritable(object, key) {
  const descriptor = Object.getOwnPropertyDescriptor(object, key);
  const writable = descriptor
    ? Object.hasOwn(descriptor, 'value') && descriptor.writable
    : Object.isExtensible(object);
  if (!writable) throw Error('Unsupported readonly Unraid API interface: ' + key);
}
