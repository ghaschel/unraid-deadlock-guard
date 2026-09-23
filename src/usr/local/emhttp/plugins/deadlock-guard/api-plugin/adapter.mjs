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
      bindings.push({
        field,
        service,
        operation,
        original: service[operation.method],
        originalResolver: field.resolve,
      });
    }
  }
  if (typeof services.docker.finalizeMutation !== 'function') {
    throw Error('Unsupported Docker mutation result interface');
  }

  for (const { field, service, operation, original } of bindings) {
    const originalResolver = field.resolve;
    field.resolve = function (parent, args, requestContext, info) {
      return context.run({ requestContext, info, operation, id: args.id }, () =>
        originalResolver.call(this, parent, args, requestContext, info),
      );
    };

    service[operation.method] = async function (...args) {
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
  }

  return () => {
    for (const { field, service, operation, original, originalResolver } of bindings) {
      service[operation.method] = original;
      field.resolve = originalResolver;
    }
  };
}
