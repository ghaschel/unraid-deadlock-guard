import { AsyncLocalStorage } from 'node:async_hooks';
import { randomUUID } from 'node:crypto';
import { collectActions, operations } from './graphql.mjs';
import { debugLog, errorClass, trace } from './debug.mjs';

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
  debug = debugLog,
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
      const failure = (error) => {
        trace(debug, 'resolver.failure', {
          type: operation.type,
          action: operation.action,
          reason: 'resolver_rejected',
          errorClass: errorClass(error),
        });
        throw error;
      };
      return context.run({ requestContext, info, operation, id: args.id }, () => {
        try {
          const result = originalResolver.call(this, parent, args, requestContext, info);
          return result && typeof result.then === 'function'
            ? Promise.resolve(result).catch(failure)
            : result;
        } catch (error) {
          return failure(error);
        }
      });
    };

    const method = async function (...args) {
      const call = context.getStore();
      const active = enabled();
      if (!active || !call || call.operation !== operation || call.id !== args[0]) {
        trace(debug, 'adapter.passthrough', {
          type: operation.type,
          action: operation.action,
          reason: !active ? 'plugin_disabled' : !call ? 'outside_request' : 'context_mismatch',
        });
        return original.apply(this, args);
      }
      const details = { type: operation.type, action: operation.action };
      let stage = 'authorization';
      let job;
      try {
        // req.user is set by the native authentication guard, never from JSON.
        const user = call.requestContext?.req?.user;
        const subject = typeof user?.id === 'string' ? user.id.trim() : '';
        if (!subject) {
          trace(debug, 'authorization.result', {
            ...details,
            status: 'rejected',
            reason: 'missing_subject',
          });
          throw Error('Unraid API authentication context is missing');
        }
        const allowedTypes = [];
        for (const [type, resource] of [
          ['docker', 'DOCKER'],
          ['vm', 'VMS'],
        ]) {
          const allowed = await authorize(subject, resource, 'UPDATE_ANY');
          trace(debug, 'authorization.result', { type, status: allowed ? 'allowed' : 'rejected' });
          if (allowed) allowedTypes.push(type);
        }
        stage = 'batch';
        const batch = collectActions(call.info);
        details.workloadId = args[0];
        trace(debug, 'adapter.intercepted', details);
        stage = 'rpc';
        const result = await rpc({
          op: 'route',
          native: { type: operation.type, id: args[0], action: operation.action },
          batch,
          key: 'api-' + randomUUID(),
          allowedTypes,
        });
        if (!result.managed) {
          trace(debug, 'adapter.passthrough', { ...details, managed: false, reason: 'unmanaged' });
          return original.apply(this, args);
        }
        job = result.job;
        while (!['succeeded', 'failed', 'quarantined'].includes(job.status)) {
          await pause();
          job = (await rpc({ op: 'status', id: job.id })).job;
        }
        stage = 'job';
        trace(debug, 'adapter.result', {
          ...details,
          managed: true,
          jobId: job.id,
          status: job.status,
          handoff: job.handoff,
        });
        if (job.status !== 'succeeded') throw Error(job.error || job.phase || 'Handoff failed');
        if (operation.type === 'vm') return true;
        stage = 'finalize';
        return await this.finalizeMutation(
          args[0],
          job.handoff === false ? 'Deadlock Guard action' : 'Deadlock Guard handoff',
        );
      } catch (error) {
        trace(debug, 'adapter.failure', {
          ...details,
          stage,
          jobId: job?.id,
          status: job?.status,
          errorClass: errorClass(error),
        });
        throw error;
      }
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
