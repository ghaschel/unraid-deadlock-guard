// Test double for Unraid's global vue-sonner API. This is never packaged.
(() => {
  const timers = new Map();
  const notices = new Map();
  // vue-sonner 2.0.9 captures remainingTime on mount, retaining Infinity when
  // a loading/error toast is updated to success under the same ID.
  // https://github.com/xiaoluoboding/vue-sonner/blob/v2.0.9/src/packages/Toast.vue
  const remainingTime = new Map();
  window.nativeToastCalls = [];

  function dismiss(id) {
    clearTimeout(timers.get(id));
    notices.get(id)?.remove();
    notices.delete(id);
    timers.delete(id);
    remainingTime.delete(id);
  }

  function show(kind, title, options = {}) {
    window.nativeToastCalls.push({kind, title, options});
    const id = options.id || 'fixture-toast';
    clearTimeout(timers.get(id));
    let notice = notices.get(id);
    if (!notice) {
      notice = document.createElement('div');
      notice.dataset.sonnerToast = '';
      notice.dataset.testToastId = id;
      document.body.append(notice);
      notices.set(id, notice);
      remainingTime.set(id, options.duration ?? 4000);
    }
    notice.replaceChildren();
    const heading = document.createElement('strong');
    heading.textContent = title;
    const description = document.createElement('p');
    description.textContent = options.description || '';
    notice.append(heading, description);

    if (kind !== 'loading') {
      const close = document.createElement('button');
      close.textContent = 'Close notification';
      close.onclick = () => dismiss(id);
      notice.append(close);
    }
    if (options.action) {
      const button = document.createElement('button');
      button.textContent = options.action.label;
      button.onclick = options.action.onClick;
      notice.append(button);
    }
    if (kind !== 'loading' && options.duration !== Infinity && Number.isFinite(remainingTime.get(id))) {
      timers.set(id, setTimeout(() => dismiss(id), remainingTime.get(id)));
    }
    return id;
  }

  window.toast = {
    loading: (title, options) => show('loading', title, options),
    success: (title, options) => show('success', title, options),
    error: (title, options) => show('error', title, options),
    info: (title, options) => show('info', title, options),
    dismiss,
  };
})();
