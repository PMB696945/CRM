// Confirm destructive actions.
document.addEventListener('submit', (e) => {
  const msg = e.target.dataset.confirm;
  if (msg && !window.confirm(msg)) e.preventDefault();
});

// When the customer changes on a form, reload the customer-scoped dropdowns
// (e.g. a ticket's affected service and reporting contact).
document.querySelectorAll('form[data-entity]').forEach((form) => {
  const account = form.querySelector('select[name="account_id"]');
  const scoped = form.querySelectorAll('select[data-scoped]');
  if (!account || !scoped.length) return;

  account.addEventListener('change', async () => {
    for (const select of scoped) {
      select.replaceChildren(new Option('—', ''));
      if (!account.value) continue;
      const params = new URLSearchParams({ page: 'refs', ref: select.dataset.scoped, account_id: account.value });
      try {
        const res = await fetch('index.php?' + params, { credentials: 'same-origin' });
        for (const item of await res.json()) select.add(new Option(item.label, item.id));
      } catch (err) {
        console.error(err);
      }
    }
  });
});

// Copy buttons: <button data-copy="#input-id">
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-copy]');
  if (!btn) return;
  const input = document.querySelector(btn.dataset.copy);
  if (!input) return;
  input.select();
  try {
    await navigator.clipboard.writeText(input.value);
  } catch {
    document.execCommand('copy');
  }
  const label = btn.textContent;
  btn.textContent = 'Copied ✓';
  setTimeout(() => { btn.textContent = label; }, 1500);
});

// Mobile sidebar
document.addEventListener('click', (e) => {
  if (e.target.closest('[data-sidebar-toggle]')) document.body.classList.toggle('sidebar-open');
  else if (e.target.closest('[data-sidebar-close]')) document.body.classList.remove('sidebar-open');
});

// Light / dark theme, remembered per browser
document.addEventListener('click', (e) => {
  if (!e.target.closest('[data-theme-toggle]')) return;
  const dark = document.documentElement.classList.toggle('dark');
  try { localStorage.setItem('theme', dark ? 'dark' : 'light'); } catch (err) { /* private mode */ }
});
