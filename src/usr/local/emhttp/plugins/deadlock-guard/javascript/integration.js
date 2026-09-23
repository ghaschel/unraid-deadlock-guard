/* Deadlock Guard native control adapter. Contracts: Unraid webgui 7.3. */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else {
    root.DeadlockGuard = api;
    api.boot(root);
  }
})(typeof window === 'undefined' ? globalThis : window, function () {
  'use strict';
  const JOB_POLL_MS = 1000;
  const DISPATCH_REFRESH_MS = 1000;
  const SUCCESS_TOAST_MS = 5000;
  // Quarantined jobs stop browser polling but still retain server reservations.
  const POLL_STOP_STATUSES = ['succeeded', 'failed', 'quarantined'];

  const vmActions = {
    'domain-start': 'start',
    'domain-start-console': 'start',
    'domain-start-consoleRV': 'start',
    'domain-restart': 'restart',
    'domain-resume': 'resume',
    'domain-pmwakeup': 'wake',
  };
  const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  const key = () =>
    'dg-' +
    (globalThis.crypto?.randomUUID?.() ||
      Date.now().toString(36) + Math.random().toString(36).slice(2));
  async function request(body, { timeout = 0 } = {}) {
    const csrf = window.DeadlockGuardToken || window.csrf_token || '';
    const controller = timeout ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), timeout) : null;
    try {
      const response = await fetch('/plugins/deadlock-guard/include/api.php', {
        signal: controller?.signal,
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ ...body, csrf }),
      });
      const data = await response.json().catch(() => {
        throw Error(
          'Unraid session or integration unavailable. Reload and sign in; an accepted handoff continues in the background.',
        );
      });
      if (!response.ok || data.error) throw Error(data.error || 'Request failed');
      return data;
    } catch (error) {
      if (controller?.signal.aborted) throw Error('Unraid did not respond in time. Please retry.');
      throw error;
    } finally {
      clearTimeout(timer);
    }
  }
  function install(win, send, report, openConsole) {
    const pending = (win.__deadlockGuardPending ||= new Map());
    async function waitForJob(job) {
      while (!POLL_STOP_STATUSES.includes(job.status)) {
        report(job.phase, { workloads: job.progress?.workloads || [] });
        await delay(JOB_POLL_MS);
        job = (await send({ op: 'status', id: job.id })).job;
      }
      if (job.status !== 'succeeded') throw Error(job.error || job.phase);
      return job;
    }

    async function handoff(native, mode, popup, originalAction, refresh) {
      let accepted = null;
      try {
        report('Checking exclusive groups…');
        const result = await send({ op: 'route', native, key: key() });
        if (!result.managed) {
          popup?.close();
          report('');
          return originalAction();
        }
        accepted = result.job.id;
        const job = await waitForJob(result.job);
        report(job.phase || 'Handoff complete', { kind: 'success' });
        if (mode) await openConsole(result.requests[0].workload, mode, popup);
        refresh();
      } catch (error) {
        popup?.close();
        report('Handoff could not complete', {
          kind: 'error',
          detail:
            error.message +
            (accepted ? ' See recent handoffs in Settings → Deadlock Guard for details.' : ''),
        });
      }
    }

    function wrap(name, translate) {
      const original = win[name];
      if (typeof original !== 'function' || original.deadlockGuard) return false;
      const wrapped = function (...args) {
        const native = translate(args[0]);
        if (!native) return original.apply(this, args);
        const identity = JSON.stringify(native);
        if (pending.has(identity)) return pending.get(identity);
        const mode = consoleMode(name);
        // Open in the original click gesture so delayed handoffs can retain the console window.
        const popup = mode === 'browser' && win.open ? win.open('about:blank', '_blank') : null;
        const work = handoff(
          native,
          mode,
          popup,
          () => original.apply(this, args),
          () => refreshNative(win, args[1]),
        );
        pending.set(identity, work);
        work.finally(() => pending.delete(identity));
        return work;
      };
      wrapped.deadlockGuard = true;
      win[name] = wrapped;
      return true;
    }
    wrap('eventControl', dockerRequest);
    for (const name of [
      'ajaxVMDispatch',
      'ajaxVMDispatchconsole',
      'ajaxVMDispatchconsoleRV',
      'ajaxVMDispatchWebUI',
    ]) {
      wrap(name, vmRequest);
    }
    const page = win.location.pathname;
    const type = /^\/(Docker)(\/|$)/i.test(page)
      ? 'docker'
      : /^\/(VMs|VM)(\/|$)/i.test(page)
        ? 'vm'
        : null;
    if (type) wrap('startAll', () => ({ type, bulk: true, action: 'start' }));
    if (type === 'docker') wrap('resumeAll', () => ({ type, bulk: true, action: 'resume' }));
    return { docker: !!win.eventControl?.deadlockGuard, vm: !!win.ajaxVMDispatch?.deadlockGuard };
  }
  function dockerRequest(parameters) {
    if (!parameters || !['start', 'restart', 'resume'].includes(parameters.action)) return null;
    return { type: 'docker', id: parameters.container, action: parameters.action };
  }

  function vmRequest(parameters) {
    if (!parameters || !vmActions[parameters.action]) return null;
    return { type: 'vm', id: parameters.uuid, action: vmActions[parameters.action] };
  }

  function consoleMode(dispatcher) {
    if (dispatcher === 'ajaxVMDispatchconsoleRV') return 'rv';
    if (dispatcher === 'ajaxVMDispatchconsole') return 'browser';
    return null;
  }

  function refreshNative(win, callbackName) {
    if (typeof win[callbackName] === 'function') win[callbackName]();
    else if (typeof win.loadlist === 'function') win.loadlist();
  }

  const notificationId = 'deadlock-guard';

  function report(message, { kind = 'progress', detail = '', workloads = [], action } = {}) {
    const toast = window.toast;
    if (!message) {
      toast?.dismiss(notificationId);
      return;
    }

    const members = workloads.map((member) => {
      const type = member.type === 'vm' ? 'VM' : 'Docker';
      return `${member.name || 'Unnamed ' + type} (${type})`;
    });
    const description = [detail, members.join(', ')].filter(Boolean).join(' — ');
    const method = kind === 'progress' ? 'loading' : kind;
    const duration = kind === 'success' ? SUCCESS_TOAST_MS : Infinity;

    if (typeof toast?.[method] === 'function') {
      // Unraid owns the position, theme, close button and notification lifetime.
      // A stable ID updates this handoff's notification without touching other plugins.
      toast[method](message, { id: notificationId, description, duration, action });
    } else if (kind !== 'progress' && typeof window.swal === 'function') {
      // The native toaster mounts asynchronously. If it failed to load, use
      // Unraid's existing dialog for the final result, never a custom popup.
      window.swal(
        {
          title: message,
          text: description,
          type: kind === 'error' ? 'error' : kind === 'success' ? 'success' : 'info',
          html: false,
          timer: kind === 'success' ? SUCCESS_TOAST_MS : undefined,
          confirmButtonText: action?.label || 'OK',
        },
        () => action?.onClick(),
      );
    }
  }

  async function openConsole(workload, mode, popup) {
    const data = await request({ op: 'console', workload });
    if (mode === 'rv') {
      const blob = new Blob(
        [
          '[virt-viewer]\ntype=' +
            data.protocol +
            '\nhost=' +
            location.hostname +
            '\nport=' +
            data.port +
            '\ndelete-this-file=1\n',
        ],
        { type: 'application/x-virt-viewer' },
      );
      const url = URL.createObjectURL(blob),
        a = document.createElement('a');
      a.href = url;
      a.download = 'deadlock-guard.vv';
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 30000);
      return;
    }
    const url = new URL('/plugins/dynamix.vm.manager/' + data.protocol + '.html', location.origin);
    url.searchParams.set('autoconnect', 'true');
    url.searchParams.set('host', location.host);
    if (data.protocol === 'vnc') {
      url.searchParams.set('port', '');
      url.searchParams.set('path', '/wsproxy/' + data.websocket + '/');
      url.searchParams.set('resize', 'scale');
    } else {
      url.searchParams.set('port', '/wsproxy/' + data.port + '/');
      url.searchParams.set('vmname', data.name || workload.id);
    }
    if (popup && !popup.closed) popup.location.replace(url.href);
    else {
      report('Handoff complete', {
        kind: 'info',
        detail: 'Your browser did not open the console automatically.',
        action: {
          label: 'Open VM console',
          onClick: () => window.open(url.href, '_blank', 'noopener'),
        },
      });
    }
  }
  function boot(win) {
    const setup = () => {
      const coverage = install(win, request, report, openConsole);
      if (
        (/^\/Docker(?:\/|$)/i.test(location.pathname) && !coverage.docker) ||
        (/^\/VMs(?:\/|$)/i.test(location.pathname) && !coverage.vm)
      )
        report('Deadlock Guard integration is unavailable on this page.', {
          kind: 'error',
          detail: 'Reload before starting grouped workloads.',
        });
      // Unraid can refresh page fragments. Re-wrap newly installed dispatchers idempotently.
      setInterval(() => install(win, request, report, openConsole), DISPATCH_REFRESH_MS);
    };
    if (document.readyState === 'loading')
      document.addEventListener('DOMContentLoaded', setup, { once: true });
    else setup();
  }
  return { install, request, report, boot };
});
