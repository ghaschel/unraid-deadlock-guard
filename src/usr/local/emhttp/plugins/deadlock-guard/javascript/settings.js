(function () {
  'use strict';
  const REQUEST_TIMEOUT_MS = 15000;
  const CHECK_TIMEOUT_MS = 30000;
  const HISTORY_REFRESH_MS = 3000;
  const VISIBLE_HISTORY_LIMIT = 30;
  const start = () => {
    const root = document.getElementById('deadlock-guard');
    if (!root) return;
    const api = window.DeadlockGuard;
    let snapshot = null;
    let savedDraft = '';
    let busy = false;
    let checking = false;
    const el = (tag, text, props = {}) =>
      Object.assign(document.createElement(tag), { textContent: text, ...props });
    const byId = (id) => document.getElementById(id);
    const message = (text, kind = 'info') => {
      byId('dg-message').textContent = text;
      byId('dg-message').dataset.kind = kind;
    };
    const key = (member) => member.type + ':' + member.id;
    function fieldValue(input) {
      if (input.type === 'checkbox') return input.checked;
      if (input.type === 'number') return Number(input.value);
      return input.value;
    }

    function formGroups() {
      return Array.from(root.querySelectorAll('.dg-group')).map((card) => {
        const group = {
          id: card.dataset.id,
          members: Array.from(card.querySelectorAll('[data-member]:checked')).map((input) =>
            JSON.parse(input.dataset.member),
          ),
        };
        for (const input of card.querySelectorAll('[data-field]')) {
          group[input.dataset.field] = fieldValue(input);
        }
        return group;
      });
    }

    function updateActions() {
      byId('dg-discard').hidden = !snapshot || JSON.stringify(formGroups()) === savedDraft;
      for (const id of ['dg-add', 'dg-save', 'dg-discard']) byId(id).disabled = busy || !snapshot;
      byId('dg-retry').disabled = busy;
      for (const card of root.querySelectorAll('.dg-group')) card.disabled = busy;
    }
    function renderGroups() {
      byId('dg-groups').replaceChildren();
      snapshot.config.groups.forEach(groupEditor);
      savedDraft = JSON.stringify(formGroups());
      updateActions();
    }
    function field(parent, title, input) {
      const label = el('label', '');
      label.append(el('span', title), input);
      parent.append(label);
      return input;
    }
    function groupEditor(group) {
      const card = el('fieldset', '', { className: 'dg-group' });
      card.dataset.id = group.id;
      const name = field(
        card,
        'Group name',
        el('input', '', { type: 'text', value: group.name, maxLength: 120 }),
      );
      name.dataset.field = 'name';
      const enabled = field(
        card,
        'Enabled',
        el('input', '', { type: 'checkbox', checked: group.enabled }),
      );
      enabled.dataset.field = 'enabled';
      sourceFields(card, group);
      memberFields(card, group);
      shutdownFields(card, group);
      const remove = el('button', 'Remove group', { type: 'button' });
      remove.onclick = () => {
        card.remove();
        updateActions();
      };
      card.append(remove);
      byId('dg-groups').append(card);
    }
    function sourceFields(card, group) {
      const sources = el('div', '', { className: 'dg-sources' });
      card.append(el('p', 'Trigger handoffs from (select at least one):'), sources);
      for (const [label, source, defaultValue] of [
        ['WebUI', 'webui', true],
        ['API', 'api', true],
      ]) {
        const checkbox = field(
          sources,
          label,
          el('input', '', {
            type: 'checkbox',
            checked: group[source] ?? defaultValue,
          }),
        );
        checkbox.dataset.field = source;
      }
    }

    function memberFields(card, group) {
      const list = el('div', '', { className: 'dg-members' });
      card.append(
        el('p', 'Select at least two VMs or containers. Each can belong to more than one group.'),
        list,
      );
      const available = [...snapshot.inventory.workloads];
      for (const member of group.members) {
        if (!available.some((item) => key(item) === key(member))) {
          available.push({ ...member, name: member.id, status: 'missing' });
        }
      }
      for (const member of available) {
        const checkbox = el('input', '', {
          type: 'checkbox',
          checked: group.members.some((selected) => key(selected) === key(member)),
        });
        checkbox.dataset.member = JSON.stringify({ type: member.type, id: member.id });
        const label =
          member.type.toUpperCase() +
          ' · ' +
          (member.name || member.id) +
          ' · ' +
          (member.error || member.status);
        field(list, label, checkbox);
      }
    }

    function shutdownFields(card, group) {
      for (const [label, prop, defaultValue] of [
        ['VM timeout (seconds)', 'vmTimeout', 120],
        ['Container timeout (seconds)', 'containerTimeout', 30],
      ]) {
        const input = field(
          card,
          label,
          el('input', '', {
            type: 'number',
            min: 1,
            max: 1800,
            value: group[prop] ?? defaultValue,
          }),
        );
        input.dataset.field = prop;
      }
      for (const [label, prop] of [
        ['Allow force-stop for VMs after timeout', 'forceVm'],
        ['Allow force-stop for containers after timeout', 'forceContainer'],
      ]) {
        const box = field(
          card,
          label,
          el('input', '', { type: 'checkbox', checked: !!group[prop] }),
        );
        box.dataset.field = prop;
      }
      card.append(el('p', 'Force-stop is optional and can lose unsaved data.'));
    }

    function history(jobs) {
      const node = byId('dg-history');
      const visible = jobs.slice(0, VISIBLE_HISTORY_LIMIT);
      const rows = new Map(
        Array.from(node.querySelectorAll('details')).map((row) => [row.dataset.jobId, row]),
      );

      if (!visible.length) {
        if (!node.querySelector('p')) node.append(el('p', 'No handoffs yet.'));
      } else {
        node.querySelector('p')?.remove();
      }

      visible.forEach((job, index) => {
        let row = rows.get(job.id);
        if (!row) {
          row = el('details', '');
          row.dataset.jobId = job.id;
          row.append(el('summary', ''), el('pre', ''));
        }
        rows.delete(job.id);

        const title =
          new Date(job.createdAt * 1000).toLocaleString() + ' · ' + job.status + ' · ' + job.phase;
        const lines = [
          job.id,
          job.error || '',
          ...Object.entries(job.states || {}).map(([id, state]) => id + ': ' + state.status),
          ...(job.history || []).map(
            (entry) => new Date(entry.at * 1000).toLocaleTimeString() + ' ' + entry.message,
          ),
        ];
        const text = lines.filter(Boolean).join('\n');
        const summary = row.querySelector('summary');
        const details = row.querySelector('pre');
        if (summary.textContent !== title) summary.textContent = title;
        if (details.textContent !== text) details.textContent = text;

        // Keep the same details element so refreshes retain expansion, focus and
        // selected text. Only insert or move rows when the history order changes.
        const position = node.children[index] || null;
        if (position !== row) node.insertBefore(row, position);
      });
      for (const row of rows.values()) row.remove();
    }

    function renderHealth(health, id = 'dg-health') {
      const status = byId(id);
      status.textContent = health.message;
      status.dataset.kind = health.ready ? 'success' : 'error';
    }

    function serviceErrors(data) {
      const node = byId('dg-service-errors');
      node.replaceChildren();
      for (const [type, error] of Object.entries(data.errors))
        node.append(el('p', (type === 'vm' ? 'VM' : 'Docker') + ': ' + error));
      node.hidden = !node.childElementCount;
    }
    async function reload(text = 'Configuration loaded.') {
      if (busy) return;
      busy = true;
      updateActions();
      for (const id of ['dg-health', 'dg-api-health']) {
        byId(id).textContent = 'Loading integration status…';
        byId(id).dataset.kind = 'info';
      }
      try {
        if (!api?.request)
          throw Error('The integration script could not be loaded. Retry to reload the page.');
        const next = await api.request({ op: 'snapshot' }, { timeout: REQUEST_TIMEOUT_MS });
        if (
          !Array.isArray(next.config?.groups) ||
          !Array.isArray(next.inventory?.workloads) ||
          !Array.isArray(next.jobs) ||
          typeof next.health?.message !== 'string' ||
          typeof next.apiHealth?.message !== 'string'
        )
          throw Error('Unraid returned incomplete settings. Please retry.');
        snapshot = next;
        renderHealth(snapshot.health);
        renderHealth(snapshot.apiHealth, 'dg-api-health');
        renderGroups();
        serviceErrors(snapshot.inventory);
        history(snapshot.jobs);
        message(text);
        byId('dg-retry').hidden = true;
      } catch (error) {
        for (const id of ['dg-health', 'dg-api-health'])
          renderHealth({ ready: false, message: 'Unable to load integration status.' }, id);
        message(
          (snapshot
            ? 'Could not refresh settings. Your unsaved edits are still shown. '
            : 'Saved groups could not be loaded. ') + error.message,
          'error',
        );
        byId('dg-retry').hidden = false;
      } finally {
        busy = false;
        updateActions();
      }
    }
    const safe = (fn) => async () => {
      try {
        await fn();
      } catch (error) {
        message(error.message, 'error');
      }
    };
    function addGroup() {
      if (!snapshot || busy) return;
      groupEditor({
        id: 'g-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 7),
        name: 'New group',
        enabled: true,
        members: [],
      });
      updateActions();
    }

    async function saveGroups() {
      if (!snapshot || busy) return;
      busy = true;
      updateActions();
      const button = byId('dg-save');
      button.textContent = 'Saving…';
      message('Saving groups…');
      try {
        const groups = formGroups();
        const missingSource = groups.find((group) => !group.webui && !group.api);
        if (missingSource) {
          throw Error('Select WebUI, API, or both for group: ' + missingSource.name);
        }
        const response = await api.request({
          op: 'config',
          config: { version: 1, groups },
          revision: snapshot.revision,
        });
        snapshot.config = response.config;
        snapshot.revision = response.revision;
        const explanation =
          'Changes apply to future starts. Saving does not start or stop VMs or containers.';
        message('Groups saved. ' + explanation, 'success');
        api.report('Groups saved', { kind: 'success', detail: explanation });
        renderGroups();
      } catch (error) {
        message('Groups were not saved. ' + error.message, 'error');
        api.report('Groups were not saved', { kind: 'error', detail: error.message });
      } finally {
        busy = false;
        button.textContent = 'Save groups';
        updateActions();
      }
    }

    function bindEditorActions() {
      byId('dg-retry').onclick = safe(() => (api?.request ? reload() : location.reload()));
      byId('dg-discard').onclick = safe(() => reload('Unsaved changes discarded.'));
      byId('dg-groups').addEventListener('input', updateActions);
      byId('dg-groups').addEventListener('change', updateActions);
      byId('dg-add').onclick = addGroup;
      byId('dg-save').onclick = saveGroups;
    }

    const check = (op, progress, done) => async () => {
      if (checking) return;
      checking = true;
      const buttons = ['dg-check-integration', 'dg-check-pending'].map(byId);
      buttons.forEach((b) => (b.disabled = true));
      message(progress);
      try {
        done(await api.request({ op }, { timeout: CHECK_TIMEOUT_MS }));
      } catch (error) {
        if (op === 'integration') {
          for (const id of ['dg-health', 'dg-api-health'])
            renderHealth({ ready: false, message: 'Unable to check integration.' }, id);
        }
        message(
          (op === 'integration'
            ? 'Unable to check integration. '
            : 'Unable to check pending jobs. ') + error.message,
          'error',
        );
      } finally {
        checking = false;
        buttons.forEach((b) => (b.disabled = false));
      }
    };
    function bindTroubleshootingActions() {
      byId('dg-check-integration').onclick = check(
        'integration',
        'Checking integration…',
        (response) => {
          renderHealth(response.health);
          renderHealth(response.apiHealth, 'dg-api-health');
          const failures = [response.health, response.apiHealth].filter((status) => !status.ready);
          message(
            'Integration checked.' +
              (failures.length ? ' ' + failures.map((status) => status.message).join(' ') : ''),
            failures.length ? 'error' : 'success',
          );
        },
      );
      byId('dg-check-pending').onclick = check('pending', 'Checking pending jobs…', (response) => {
        history(response.jobs);
        const attention = response.jobs.some((j) => j.status === 'quarantined');
        message(
          attention
            ? 'Pending jobs checked. Some handoffs need attention; see recent handoffs.'
            : 'Pending jobs checked.',
          attention ? 'error' : 'success',
        );
      });
    }

    function refreshHistory() {
      if (document.hidden || !snapshot || busy) return;
      api
        .request({ op: 'history' }, { timeout: REQUEST_TIMEOUT_MS })
        .then((response) => history(response.jobs))
        .catch((error) => message(error.message));
    }

    bindEditorActions();
    bindTroubleshootingActions();
    clearTimeout(window.DeadlockGuardSettings?.timer);
    safe(reload)();
    setInterval(refreshHistory, HISTORY_REFRESH_MS);
  };
  const launch = () => {
    try {
      start();
    } catch (error) {
      window.DeadlockGuardSettings?.failed('The settings page could not start. ' + error.message);
    }
  };
  if (document.readyState === 'loading')
    document.addEventListener('DOMContentLoaded', launch, { once: true });
  else launch();
})();
