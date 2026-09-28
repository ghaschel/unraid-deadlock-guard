import { execFile } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { debugLog, errorClass, trace } from './debug.mjs';

export const pluginRoot = '/usr/local/emhttp/plugins/deadlock-guard';
export const runDir = '/var/run/deadlock-guard';
export const installMarker = '/boot/config/plugins/deadlock-guard/installed.json';

export function codeHash(directory) {
  const lines = fs
    .readdirSync(directory)
    .filter((name) => name.endsWith('.mjs'))
    .sort()
    .map(
      (name) =>
        name +
        ':' +
        createHash('sha256')
          .update(fs.readFileSync(path.join(directory, name)))
          .digest('hex'),
    );
  return createHash('sha256').update(lines.join('\n')).digest('hex');
}

export function identity() {
  const stat = fs.readFileSync('/proc/' + process.pid + '/stat', 'utf8');
  const fields = stat
    .slice(stat.lastIndexOf(')') + 1)
    .trim()
    .split(/\s+/);
  const boot = fs.readFileSync('/proc/sys/kernel/random/boot_id', 'utf8').trim();
  return { pid: process.pid, processIdentity: boot + ':' + process.pid + ':' + fields[19] };
}

export function publishStatus(status) {
  fs.mkdirSync(runDir, { recursive: true, mode: 0o700 });
  const temporary = path.join(runDir, '.api-' + randomBytes(8).toString('hex'));
  try {
    fs.writeFileSync(temporary, JSON.stringify({ ...identity(), ...status }), {
      mode: 0o600,
      flag: 'wx',
    });
    fs.renameSync(temporary, path.join(runDir, 'api-integration.json'));
  } finally {
    if (fs.existsSync(temporary)) fs.unlinkSync(temporary);
  }
}

export function clearStatus() {
  const file = path.join(runDir, 'api-integration.json');
  if (!fs.existsSync(file)) return;
  const status = JSON.parse(fs.readFileSync(file, 'utf8'));
  if (status.processIdentity === identity().processIdentity) fs.unlinkSync(file);
}

export function createRpc(adapterHash, { debug = debugLog } = {}) {
  return async (request) => {
    const started = performance.now();
    const details = {
      op: request?.op,
      type: request?.native?.type,
      action: request?.native?.action,
      workloadId: request?.native?.id,
      jobId: request?.op === 'status' ? request.id : undefined,
    };
    let reason = 'bridge_transport';
    let failureClass;
    trace(debug, 'rpc.begin', details);
    try {
      const response = await new Promise((resolve, reject) => {
        // No shell, user-controlled executable, command argument, API key or token.
        const child = execFile(
          '/usr/bin/php',
          ['-d', 'auto_prepend_file=', path.join(pluginRoot, 'scripts/api-bridge.php')],
          { timeout: 20000, maxBuffer: 2 * 1024 * 1024, encoding: 'utf8' },
          (error, stdout) => {
            try {
              reason = 'bridge_response';
              const response = JSON.parse(stdout);
              if (response.error) {
                reason = 'bridge_error';
                throw Error(response.error);
              }
              reason = 'bridge_transport';
              if (error) throw error;
              resolve(response);
            } catch (failure) {
              failureClass = errorClass(failure);
              reject(
                Error(
                  'Deadlock Guard: ' +
                    (failure instanceof SyntaxError
                      ? 'API bridge did not respond. An accepted job continues; see Recent handoffs.'
                      : failure.message),
                ),
              );
            }
          },
        );
        child.stdin.on('error', () => {}); // An early bridge failure is handled above.
        child.stdin.end(JSON.stringify({ ...request, adapterHash }));
      });
      trace(debug, 'rpc.result', {
        ...details,
        managed: response?.managed,
        jobId: response?.job?.id ?? details.jobId,
        status: response?.job?.status,
        durationMs: performance.now() - started,
      });
      return response;
    } catch (error) {
      trace(debug, 'rpc.failure', {
        ...details,
        reason,
        errorClass: failureClass || errorClass(error),
        durationMs: performance.now() - started,
      });
      throw error;
    }
  };
}
