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

// Quote line editor: add/remove rows, fill from products, live totals.
document.querySelectorAll('.quote-form').forEach((form) => {
  const body = form.querySelector('[data-lines]');
  const money = (n) => '£' + n.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const totals = () => {
    let monthly = 0, setup = 0, tcv = 0;
    body.querySelectorAll('tr').forEach((tr) => {
      const v = (n) => parseFloat(tr.querySelector(`[name="${n}[]"]`).value) || 0;
      const qty = v('line_quantity');
      monthly += qty * v('line_monthly_price');
      setup += qty * v('line_setup_fee');
      tcv += qty * (v('line_monthly_price') * v('line_term_months') + v('line_setup_fee'));
    });
    form.querySelector('[data-total-monthly]').textContent = money(monthly);
    form.querySelector('[data-total-setup]').textContent = money(setup);
    form.querySelector('[data-total-tcv]').textContent = money(tcv);
  };
  form.addEventListener('input', totals);
  form.addEventListener('change', (e) => {
    const sel = e.target.closest('[data-product]');
    if (sel && sel.value) {
      const o = sel.selectedOptions[0], tr = sel.closest('tr');
      tr.querySelector('[name="line_service_type[]"]').value = o.dataset.type;
      tr.querySelector('[name="line_description[]"]').value = o.dataset.name;
      tr.querySelector('[name="line_monthly_price[]"]').value = o.dataset.monthly;
      tr.querySelector('[name="line_setup_fee[]"]').value = o.dataset.setup;
      tr.querySelector('[name="line_term_months[]"]').value = o.dataset.term;
    }
    totals();
  });
  form.addEventListener('click', (e) => {
    if (e.target.closest('[data-add-line]')) {
      const row = body.querySelector('tr').cloneNode(true);
      row.querySelectorAll('input').forEach((i) => { i.value = i.name.startsWith('line_quantity') ? 1 : (i.name.startsWith('line_term') ? 24 : ''); });
      row.querySelector('[data-product]').value = '';
      body.appendChild(row);
      row.querySelector('[data-product]').focus();
    }
    const rm = e.target.closest('[data-remove-line]');
    if (rm && body.querySelectorAll('tr').length > 1) { rm.closest('tr').remove(); totals(); }
  });
  totals();
});

// Quote recipient picker fills name/email.
document.querySelectorAll('[data-recipient-pick]').forEach((sel) => {
  sel.addEventListener('change', () => {
    const o = sel.selectedOptions[0], form = sel.closest('form');
    if (!o.dataset.email) return;
    form.querySelector('[name=recipient_name]').value = o.dataset.name;
    form.querySelector('[name=recipient_email]').value = o.dataset.email;
  });
});

// Select-all on click for copyable fields, and auto-submitting filters
// (kept here rather than inline so the Content Security Policy can block inline scripts).
document.addEventListener('click', (e) => {
  const el = e.target.closest('[data-select-all]');
  if (el) el.select();
});
document.addEventListener('change', (e) => {
  if (e.target.matches('select[data-autosubmit]')) e.target.form.submit();
});

// QR codes (two-factor setup) drawn locally; the secret never leaves the browser.
document.querySelectorAll('[data-qr]').forEach((el) => {
  if (typeof qrcode !== 'function') return;
  const qr = qrcode(0, 'M');
  qr.addData(el.dataset.qr);
  qr.make();
  el.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
});
