// Confirm destructive actions.
document.addEventListener('submit', (e) => {
  const msg = e.target.dataset.confirm;
  if (e.submitter && e.submitter.hasAttribute('data-skip-confirm')) return;
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

// New user form: a typed password is only needed when not emailing a temporary one.
document.querySelectorAll('[data-toggle-password]').forEach((box) => {
  const field = box.form.querySelector('[data-password-field]');
  if (!field) return;
  const sync = () => { field.hidden = box.checked; };
  box.addEventListener('change', sync);
  sync();
});

// Products: a one-off billing cycle means no minimum term.
document.querySelectorAll('form[data-entity="products"], form[data-entity="supplier_products"]').forEach((form) => {
  const cycle = form.querySelector('[name="billing_frequency"]');
  const term = form.querySelector('[name="term_months"]');
  if (!cycle || !term) return;
  cycle.addEventListener('change', () => {
    if (cycle.value === 'one_off') term.value = '0';
    else if (term.value === '0') term.value = '24';
  });
});

// Find an address by postcode on customer and site forms, and fill in the address fields.
document.querySelectorAll('form[data-address-lookup]').forEach((form) => {
  const line1 = form.querySelector('[name="address"]');
  if (!line1) return;
  const field = line1.closest('.field') || line1.parentElement;
  const box = document.createElement('div');
  box.className = 'field wide address-lookup';
  box.innerHTML = '<label for="lookup-postcode">Find address</label>'
    + '<div class="lookup-row"><input id="lookup-postcode" type="text" placeholder="Postcode, e.g. GL53 0ED" autocomplete="off" aria-describedby="lookup-help">'
    + '<button type="button" class="btn btn-sm">Find</button></div>'
    + '<select hidden aria-label="Choose the address"></select>'
    + '<div class="help" id="lookup-help">Pick an address to fill in the fields below, or type it in yourself.</div>';
  field.before(box);
  const input = box.querySelector('input');
  const button = box.querySelector('button');
  const list = box.querySelector('select');
  const help = box.querySelector('.help');
  let found = [];
  const set = (name, value) => {
    const el = form.querySelector(`[name="${name}"]`);
    if (el && value !== undefined) { el.value = value; el.dispatchEvent(new Event('input', { bubbles: true })); }
  };
  const find = async () => {
    const postcode = input.value.trim();
    if (!postcode) { input.focus(); return; }
    button.disabled = true;
    help.textContent = 'Looking up addresses…';
    try {
      const res = await fetch(form.dataset.addressLookup + '&' + new URLSearchParams({ postcode }), { credentials: 'same-origin' });
      const data = await res.json();
      found = data.addresses || [];
      if (!found.length) { list.hidden = true; help.textContent = data.error || 'No addresses found.'; return; }
      list.replaceChildren(new Option(`${found.length} address${found.length === 1 ? '' : 'es'} found: choose one`, ''));
      found.forEach((a, i) => list.add(new Option(a.label, String(i))));
      list.hidden = false;
      list.focus();
      help.textContent = 'Pick an address to fill in the fields below.';
    } catch (err) {
      help.textContent = 'The address lookup isn\'t available just now. Type the address in yourself.';
    } finally {
      button.disabled = false;
    }
  };
  button.addEventListener('click', find);
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); find(); } });
  list.addEventListener('change', () => {
    const a = found[Number(list.value)];
    if (!a || list.value === '') return;
    ['address', 'address2', 'city', 'county', 'postcode'].forEach((k) => set(k, a.fields[k]));
    const name = form.querySelector('[name="name"]');
    if (form.dataset.entity === 'accounts' && name && !name.value.trim() && a.fields.organisation) set('name', a.fields.organisation);
    help.textContent = 'Filled in. Check the details below.';
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
  // A line with only a one-off cost (e.g. installation) has no term; give it one again if a monthly price is added.
  form.addEventListener('change', (e) => {
    if (!e.target.matches('[name="line_monthly_price[]"], [name="line_setup_fee[]"]')) return;
    const tr = e.target.closest('tr');
    const monthly = parseFloat(tr.querySelector('[name="line_monthly_price[]"]').value) || 0;
    const setup = parseFloat(tr.querySelector('[name="line_setup_fee[]"]').value) || 0;
    const term = tr.querySelector('[name="line_term_months[]"]');
    if (monthly === 0 && setup > 0) term.value = '0';
    else if (monthly > 0 && term.value === '0') term.value = '24';
    totals();
  });
  form.addEventListener('change', (e) => {
    const sel = e.target.closest('[data-product]');
    if (sel && sel.value) {
      const o = sel.selectedOptions[0], tr = sel.closest('tr');
      tr.querySelector('[name="line_service_type[]"]').value = o.dataset.type;
      tr.querySelector('[name="line_description[]"]').value = o.dataset.name;
      tr.querySelector('[name="line_monthly_price[]"]').value = o.dataset.monthly;
      tr.querySelector('[name="line_setup_fee[]"]').value = o.dataset.setup;
      const term = tr.querySelector('[name="line_term_months[]"]');
      if (![...term.options].some((x) => x.value === o.dataset.term)) term.add(new Option(o.dataset.term === '0' ? 'One-off (no term)' : `${o.dataset.term} months`, o.dataset.term));
      term.value = o.dataset.term;
    }
    totals();
  });
  form.addEventListener('click', (e) => {
    if (e.target.closest('[data-add-line]')) {
      const row = body.querySelector('tr').cloneNode(true);
      row.querySelectorAll('input').forEach((i) => { i.value = i.name.startsWith('line_quantity') ? 1 : ''; });
      row.querySelector('[name="line_term_months[]"]').value = '24';
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

// Fields shown only when another field has a value, e.g. data-show-if="billing_same=0".
document.querySelectorAll('[data-show-if]').forEach((field) => {
  const [name, want] = field.dataset.showIf.split('=');
  const form = field.closest('form');
  const input = form && form.querySelector(`[name="${name}"]:not([type=hidden])`);
  if (!input) return;
  const value = () => (input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value);
  const update = () => { field.hidden = value() !== want; };
  input.addEventListener('change', update);
  update();
});

// Sections that apply to one choice of a radio group: <div data-when="kind=marketing">.
document.querySelectorAll('[data-when]').forEach((el) => {
  const [name, want] = el.dataset.when.split('=');
  const form = el.closest('form');
  if (!form) return;
  const update = () => {
    const checked = form.querySelector(`[name="${name}"]:checked`);
    el.hidden = !checked || checked.value !== want;
  };
  form.addEventListener('change', update);
  update();
});

// Keep sending in batches: forms marked data-auto-continue submit themselves.
document.querySelectorAll('form[data-auto-continue]').forEach((form) => {
  setTimeout(() => form.submit(), 1500);
});

// Tick-box selection on lists (e.g. send several products to Xero).
const bulkForm = document.getElementById('bulk-form');
if (bulkForm) {
  const boxes = () => [...document.querySelectorAll('input[name="ids[]"][form="bulk-form"]')];
  const update = () => {
    const n = boxes().filter((b) => b.checked).length;
    bulkForm.querySelectorAll('[data-needs-selection]').forEach((b) => { b.disabled = n === 0; });
    const label = bulkForm.querySelector('[data-selected-count]');
    if (label) label.textContent = n ? `${n} selected` : (label.dataset.empty || 'Tick products to send them to Xero');
  };
  document.addEventListener('change', (e) => {
    if (e.target.matches('[data-check-all]')) boxes().forEach((b) => { b.checked = e.target.checked; });
    if (e.target.matches('[data-check-all], input[name="ids[]"]')) update();
  });
  update();
}

// Broadband order form: show the full broadband username as it's typed.
document.querySelectorAll('[data-full-username]').forEach((out) => {
  const form = out.closest('form');
  const get = (n) => (form.querySelector(`[name="${n}"]`)?.value || '').trim();
  const update = () => {
    let user = get('bb_username').split('@')[0] || 'username';
    let suffix = out.dataset.suffix ?? get('bb_suffix');
    let realm = out.dataset.realm ?? get('realm');
    const at = realm.lastIndexOf('@');
    if (at >= 0) { if (!suffix) suffix = realm.slice(0, at); realm = realm.slice(at + 1); }
    if (suffix && user.toLowerCase().endsWith(suffix.toLowerCase())) suffix = '';
    out.textContent = realm ? `${user}${suffix}@${realm}` : user;
  };
  form.addEventListener('input', update);
  update();
});

// Filter a results table by supplier, technology and speed (data-filter-table).
document.querySelectorAll('[data-filter-for]').forEach((bar) => {
  const table = document.getElementById(bar.dataset.filterFor);
  if (!table) return;
  const rows = [...table.querySelectorAll('tbody tr[data-supplier]')];
  const count = bar.querySelector('[data-filter-count]');
  const apply = () => {
    const supplier = bar.querySelector('[name=f_supplier]').value;
    const tech = bar.querySelector('[name=f_tech]').value;
    const speed = parseFloat(bar.querySelector('[name=f_speed]').value || '0');
    let shown = 0;
    rows.forEach((r) => {
      const ok = (!supplier || r.dataset.supplier === supplier) && (!tech || r.dataset.tech === tech) && parseFloat(r.dataset.speed || '0') >= speed;
      r.hidden = !ok;
      if (ok) shown++;
    });
    if (count) count.textContent = `${shown} of ${rows.length} shown`;
  };
  bar.addEventListener('change', apply);
  apply();
});

// Print buttons.
document.querySelectorAll('[data-print]').forEach((b) => b.addEventListener('click', () => window.print()));

// Purchase order line editor: fill from the supplier's products, add/remove rows, live total.
document.querySelectorAll('.po-form').forEach((form) => {
  const body = form.querySelector('[data-lines]');
  const money = (n) => '£' + n.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const total = () => {
    let t = 0;
    body.querySelectorAll('tr').forEach((tr) => {
      const v = (n) => parseFloat(tr.querySelector(`[name="${n}[]"]`).value) || 0;
      t += v('line_quantity') * v('line_unit_cost');
    });
    form.querySelector('[data-po-total]').textContent = money(t);
  };
  form.addEventListener('input', total);
  form.addEventListener('change', (e) => {
    const sel = e.target.closest('[data-po-product]');
    if (sel && sel.value) {
      const o = sel.selectedOptions[0], tr = sel.closest('tr');
      tr.querySelector('[name="line_sku[]"]').value = o.dataset.sku;
      tr.querySelector('[name="line_description[]"]').value = o.dataset.name;
      tr.querySelector('[name="line_unit_cost[]"]').value = o.dataset.cost;
    }
    total();
  });
  form.addEventListener('click', (e) => {
    if (e.target.closest('[data-add-line]')) {
      const row = body.querySelector('tr').cloneNode(true);
      row.querySelectorAll('input').forEach((i) => { i.value = i.name.startsWith('line_quantity') ? 1 : ''; });
      row.querySelector('[data-po-product]').value = '';
      body.appendChild(row);
      row.querySelector('[data-po-product]').focus();
    }
    const rm = e.target.closest('[data-remove-line]');
    if (rm && body.querySelectorAll('tr').length > 1) { rm.closest('tr').remove(); total(); }
  });
  total();
});

// Order step form: show the default customer message for the chosen step.
document.querySelectorAll('[data-order-step]').forEach((form) => {
  const select = form.querySelector('[data-step-select]');
  const message = form.querySelector('[data-step-message]');
  let last = message.value;
  select.addEventListener('change', () => {
    const msg = select.selectedOptions[0].dataset.message || '';
    // Only replace the text if it hasn't been edited.
    if (message.value === last) message.value = msg;
    last = msg;
  });
});

// Signing page: show the typed name as a signature.
document.querySelectorAll('[data-signature-source]').forEach((input) => {
  const preview = document.querySelector('[data-signature-preview]');
  if (!preview) return;
  input.addEventListener('input', () => { preview.textContent = input.value; });
});

// Show options that only apply to one order step (e.g. raising purchase orders when processing).
document.querySelectorAll('[data-order-step]').forEach((form) => {
  const select = form.querySelector('[data-step-select]');
  const sync = () => form.querySelectorAll('[data-when-step]').forEach((el) => {
    const on = el.dataset.whenStep === select.value;
    el.hidden = !on;
    el.querySelectorAll('input').forEach((i) => { i.disabled = !on; });
  });
  select.addEventListener('change', sync);
  sync();
});

// A "select all" box in a table header ticks the row boxes in the same form.
document.addEventListener('change', (e) => {
  if (!e.target.matches('[data-toggle-boxes]')) return;
  e.target.form?.querySelectorAll('input[type="checkbox"][name="ids[]"]').forEach((b) => { b.checked = e.target.checked; });
});

// FTTP orders: a new service (provide) gets a new ONT; taking over (migrate) keeps the existing one.
document.querySelectorAll('select[data-ont-follows-order-type]').forEach((select) => {
  select.form?.querySelectorAll('input[name="order_type"]').forEach((radio) => {
    radio.addEventListener('change', () => { if (radio.checked) select.value = radio.value === 'migrate' ? 'N' : 'Y'; });
  });
});

// Broadband orders: the install dates depend on the engineer visit, so fetch them again when it changes.
const refreshDates = (select) => {
  const button = select.form?.querySelector('[data-refresh-dates]');
  if (!button) return;
  select.form.querySelectorAll('[data-dates-note]').forEach((n) => { n.textContent = 'Getting the dates for this visit…'; });
  button.click();
};
document.querySelectorAll('[data-refresh-dates]').forEach((b) => { b.hidden = true; });

// Broadband orders: only offer engineer visits at or above the minimum for the order type.
document.querySelectorAll('select[data-min-provide]').forEach((select) => {
  const order = ['NO_SITE_VISIT', 'STANDARD', 'PREMIUM', 'ADVANCED'];
  const apply = (type) => {
    const min = select.dataset[type === 'migrate' ? 'minMigrate' : 'minProvide'];
    const floor = min ? order.indexOf(min) : 0;
    [...select.options].forEach((o) => { const below = order.indexOf(o.value) < floor; o.hidden = below; o.disabled = below; });
    return min;
  };
  // Switching the order type starts from that type's minimum (as the form does when it opens).
  select.form?.querySelectorAll('input[name="order_type"]').forEach((radio) => {
    radio.addEventListener('change', () => {
      if (!radio.checked) return;
      const before = select.value;
      select.value = apply(radio.value) || 'NO_SITE_VISIT';
      if (select.value !== before) refreshDates(select);
    });
  });
  select.addEventListener('change', () => refreshDates(select));
});

// Broadband orders: the appointment slots shown follow the required-by date (none set if none are offered that day).
document.querySelectorAll('input[data-appointment-date]').forEach((input) => {
  const box = input.form?.querySelector('[data-appointment-slots]');
  if (!box) return;
  const none = box.querySelector('[data-slot-none]');
  const note = box.querySelector('[data-slot-none-note]');
  const sync = () => {
    const slots = [...box.querySelectorAll('[data-slot-date]')];
    const today = slots.filter((l) => l.dataset.slotDate === input.value);
    slots.forEach((l) => { l.hidden = !today.includes(l); });
    const checked = box.querySelector('input[name="appointment"]:checked');
    if (today.length) {
      if (!checked || !today.includes(checked.closest('label'))) today[0].querySelector('input').checked = true;
    } else {
      none.querySelector('input').checked = true;
    }
    note.hidden = today.length > 0;
  };
  input.addEventListener('change', sync);
  input.addEventListener('input', sync);
  sync();
});

// Broadband orders: copy the customer (main contact) details into the site contact.
document.querySelectorAll('[data-copy-contact]').forEach((button) => {
  button.addEventListener('click', () => {
    const form = button.closest('form');
    button.dataset.copyContact.split(',').forEach((pair) => {
      const [from, to] = pair.split(':');
      const source = form.querySelector(`[name="${from}"]`);
      const target = form.querySelector(`[name="${to}"]`);
      if (source && target) { target.value = source.value; target.dispatchEvent(new Event('input', { bubbles: true })); }
    });
  });
});

// Lists: search as you type. The matching rows are fetched from the server (so paging, filters, sorting and
// permissions all still apply) and swapped in, and the address bar is updated so refresh and back keep the search.
document.querySelectorAll('form[data-live-search]').forEach((form) => {
  const input = form.querySelector('input[name="q"]');
  if (!input) return;
  let timer;
  let controller;
  let last = input.value;
  const run = async () => {
    if (input.value === last) return;
    last = input.value;
    const params = new URLSearchParams(new FormData(form));
    params.delete('p'); // back to the first page
    [...params.keys()].forEach((k) => { if (params.get(k) === '') params.delete(k); });
    const url = form.getAttribute('action') + '?' + params.toString();
    controller?.abort();
    controller = new AbortController();
    form.classList.add('is-loading');
    try {
      const res = await fetch(url, { signal: controller.signal, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' });
      if (!res.ok || res.redirected) { window.location = url; return; }
      const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
      ['[data-live-results]', '[data-live-count]', '[data-live-export]', '[data-live-clear]'].forEach((sel) => {
        const now = document.querySelector(sel);
        const next = doc.querySelector(sel);
        if (now && next) now.replaceWith(next);
      });
      history.replaceState(null, '', url);
    } catch (e) {
      if (e.name !== 'AbortError') window.location = url;
    } finally {
      form.classList.remove('is-loading');
    }
  };
  input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 250); });
  // Enter searches straight away rather than reloading the page.
  form.addEventListener('submit', (e) => {
    if (document.activeElement === input) { e.preventDefault(); clearTimeout(timer); last = null; run(); }
  });
});
