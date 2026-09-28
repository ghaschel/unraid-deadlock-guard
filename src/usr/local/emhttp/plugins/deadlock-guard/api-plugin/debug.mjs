import fs from 'node:fs';
import path from 'node:path';

const MAX_BYTES = 512 * 1024;
const MAX_CONFIG_BYTES = 4096;
const values = {
  op: ['route', 'status'],
  type: ['docker', 'vm'],
  action: ['start', 'restart', 'resume', 'reset', 'stop'],
  status: [
    'pending',
    'queued',
    'running',
    'succeeded',
    'failed',
    'quarantined',
    'allowed',
    'rejected',
  ],
  stage: [
    'metadata',
    'version',
    'providers',
    'interface',
    'status',
    'restore',
    'authorization',
    'batch',
    'rpc',
    'job',
    'finalize',
  ],
  reason: [
    'plugin_disabled',
    'outside_request',
    'context_mismatch',
    'unmanaged',
    'missing_subject',
    'resolver_rejected',
    'bridge_error',
    'bridge_response',
    'bridge_transport',
    'bootstrap_error',
    'status_error',
    'shutdown_error',
  ],
  errorClass: [
    'Error',
    'TypeError',
    'SyntaxError',
    'RangeError',
    'ReferenceError',
    'URIError',
    'EvalError',
    'AggregateError',
  ],
};

// Never serialize arbitrary objects, request context, GraphQL variables or errors.
function selectedFields(fields) {
  const result = {};
  for (const [key, allowed] of Object.entries(values)) {
    if (allowed.includes(fields[key])) result[key] = fields[key];
  }
  for (const key of ['managed', 'handoff']) {
    if (typeof fields[key] === 'boolean') result[key] = fields[key];
  }
  if (Number.isFinite(fields.durationMs) && fields.durationMs >= 0)
    result.durationMs = Math.round(fields.durationMs);
  if (Number.isSafeInteger(fields.version) && fields.version >= 0) result.version = fields.version;
  if (
    typeof fields.apiVersion === 'string' &&
    fields.apiVersion.length <= 64 &&
    /^\d+\.\d+\.\d+(?:[-+][a-zA-Z0-9.-]+)?$/.test(fields.apiVersion)
  )
    result.apiVersion = fields.apiVersion;
  if (typeof fields.jobId === 'string' && /^[a-f0-9]{32}$/.test(fields.jobId))
    result.jobId = fields.jobId;
  if (
    typeof fields.workloadId === 'string' &&
    fields.workloadId.length <= 255 &&
    /^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/.test(fields.workloadId)
  )
    result.workloadId = fields.workloadId;
  return result;
}

function debugEnabled(configFile) {
  let descriptor;
  try {
    descriptor = fs.openSync(
      configFile,
      fs.constants.O_RDONLY | fs.constants.O_NOFOLLOW | fs.constants.O_NONBLOCK,
    );
    const stat = fs.fstatSync(descriptor);
    if (!stat.isFile() || stat.size > MAX_CONFIG_BYTES) return false;
    const buffer = Buffer.alloc(MAX_CONFIG_BYTES + 1);
    const length = fs.readSync(descriptor, buffer, 0, buffer.length, 0);
    if (length > MAX_CONFIG_BYTES) return false;
    const config = JSON.parse(buffer.subarray(0, length).toString('utf8'));
    return config?.version === 1 && config?.enabled === true;
  } finally {
    if (descriptor !== undefined) fs.closeSync(descriptor);
  }
}

export function errorClass(error) {
  try {
    return values.errorClass.includes(error?.name) ? error.name : 'Error';
  } catch {
    return 'Error';
  }
}

// The wrapper also protects callers using an injected diagnostic sink.
export function trace(debug, event, fields) {
  try {
    debug(event, fields);
  } catch {
    // Diagnostics cannot change an API result or an authorization decision.
  }
}

export function createDebugLogger({
  configFile = '/boot/config/plugins/deadlock-guard/debug.json',
  logFile = '/var/run/deadlock-guard/debug-api.log',
} = {}) {
  return (event, fields = {}) => {
    let descriptor;
    try {
      if (!debugEnabled(configFile)) return;
      if (typeof event !== 'string' || !/^[a-z][a-z0-9_.-]{0,63}$/.test(event)) return;
      const line =
        JSON.stringify({
          time: new Date().toISOString(),
          component: 'api',
          event,
          pid: process.pid,
          ...selectedFields(fields),
        }) + '\n';
      const directory = path.dirname(logFile);
      fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
      if (!fs.lstatSync(directory).isDirectory()) return;
      fs.chmodSync(directory, 0o700);
      const open = () =>
        fs.openSync(
          logFile,
          fs.constants.O_CREAT |
            fs.constants.O_WRONLY |
            fs.constants.O_APPEND |
            fs.constants.O_NOFOLLOW |
            fs.constants.O_NONBLOCK,
          0o600,
        );
      descriptor = open();
      const stat = fs.fstatSync(descriptor);
      if (!stat.isFile()) return;
      fs.fchmodSync(descriptor, 0o600);
      if (stat.size + Buffer.byteLength(line) > MAX_BYTES) {
        // No unbounded read/copy. The API owns this log; PHP has a separate file.
        if (stat.size > MAX_BYTES) fs.ftruncateSync(descriptor, MAX_BYTES);
        fs.closeSync(descriptor);
        descriptor = undefined;
        fs.renameSync(logFile, logFile + '.1');
        descriptor = open();
        if (!fs.fstatSync(descriptor).isFile()) return;
        fs.fchmodSync(descriptor, 0o600);
      }
      fs.writeSync(descriptor, line);
    } catch {
      // Missing/malformed settings, permissions and storage failures are silent.
    } finally {
      if (descriptor !== undefined) {
        try {
          fs.closeSync(descriptor);
        } catch {
          /* Best effort only. */
        }
      }
    }
  };
}

export const debugLog = createDebugLogger();
