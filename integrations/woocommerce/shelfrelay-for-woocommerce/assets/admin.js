(() => {
  const active = document.querySelector('[data-shelfrelay-active]');
  if (!active) return;
  let paused = false;
  const pause = active.querySelector('.sr-pause-view');
  pause?.addEventListener('click', () => {
    paused = !paused;
    pause.textContent = paused ? 'Resume page updates' : 'Pause page updates';
    if (!paused) step();
  });
  async function step() {
    if (paused || document.hidden) { setTimeout(step, 5000); return; }
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 90000);
    try {
      const body = new URLSearchParams({action: 'shelfrelay', intent: 'tick', _wpnonce: active.dataset.nonce});
      const response = await fetch(active.dataset.url, {method: 'POST', body, credentials: 'same-origin', signal: controller.signal});
      if (!response.ok) throw new Error('Receipt refresh failed');
      if (!paused) window.location.reload();
    } catch (_) {
      const message = active.querySelector('.sr-processing');
      if (message) message.textContent = 'Automatic page refresh paused. Use Refresh receipt; the saved queue remains available.';
    } finally { clearTimeout(timeout); }
  }
  setTimeout(step, 1800);
})();
