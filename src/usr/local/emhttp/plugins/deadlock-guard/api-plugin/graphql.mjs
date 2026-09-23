import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const {
  getArgumentValues,
  getDirectiveValues,
  getNamedType,
  GraphQLSkipDirective,
  GraphQLIncludeDirective,
} = require('graphql');

export const operations = {
  DockerMutations: {
    start: { type: 'docker', action: 'start', method: 'start' },
    restart: { type: 'docker', action: 'restart', method: 'restart' },
    unpause: { type: 'docker', action: 'resume', method: 'unpause' },
  },
  VmMutations: {
    start: { type: 'vm', action: 'start', method: 'startVm' },
    resume: { type: 'vm', action: 'resume', method: 'resumeVm' },
    reboot: { type: 'vm', action: 'restart', method: 'rebootVm' },
    reset: { type: 'vm', action: 'reset', method: 'resetVm' },
  },
};

/** Resolve the selected start-like fields before any handoff can stop a member. */
// Mirrors Config::MAX_REQUESTS; both sides independently bound untrusted batches.
const MAX_START_OPERATIONS = 128;
const MAX_VISITED_NODES = 2048;

export function collectActions(info) {
  if (info.operation.operation !== 'mutation') throw Error('Unsupported API operation');
  const actions = new Map();
  let visited = 0;

  function walk(selectionSet, parentType, path = [], fragments = new Set()) {
    if (!selectionSet) return;
    for (const selection of selectionSet.selections) {
      if (++visited > MAX_VISITED_NODES) throw Error('API operation is too large');
      if (getDirectiveValues(GraphQLSkipDirective, selection, info.variableValues)?.if === true)
        continue;
      if (getDirectiveValues(GraphQLIncludeDirective, selection, info.variableValues)?.if === false)
        continue;
      if (selection.kind === 'FragmentSpread') {
        const name = selection.name.value;
        if (fragments.has(name)) throw Error('Recursive API fragment');
        const fragment = info.fragments[name];
        if (!fragment) throw Error('Missing API fragment');
        if (fragment.typeCondition.name.value !== parentType.name) continue;
        walk(fragment.selectionSet, parentType, path, new Set([...fragments, name]));
        continue;
      }
      if (selection.kind === 'InlineFragment') {
        if (selection.typeCondition && selection.typeCondition.name.value !== parentType.name)
          continue;
        walk(selection.selectionSet, parentType, path, fragments);
        continue;
      }
      const name = selection.name.value;
      const field = parentType.getFields?.()[name];
      if (!field) continue; // GraphQL has already validated introspection fields.
      const fieldPath = [...path, selection.alias?.value || name];
      const operation = operations[parentType.name]?.[name];
      if (operation) {
        const { id } = getArgumentValues(field, selection, info.variableValues);
        if (typeof id !== 'string' || id.length > 255) throw Error('Invalid API workload ID');
        actions.set(fieldPath.join('.'), { type: operation.type, id, action: operation.action });
        if (actions.size > MAX_START_OPERATIONS) throw Error('Too many API start operations');
      }
      walk(selection.selectionSet, getNamedType(field.type), fieldPath, fragments);
    }
  }

  walk(info.operation.selectionSet, info.schema.getMutationType());
  return [...actions.values()];
}
