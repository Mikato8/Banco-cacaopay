const state = { token: null, user: null };
const SEED = '<img class="seed" src="/assets/cacao-seed.svg" alt="">';

const $ = (id) => document.getElementById(id);

function toast(message, isError = false) {
  const el = $('toast');
  el.textContent = message;
  el.className = 'toast' + (isError ? ' error' : '');
  clearTimeout(el._t);
  el._t = setTimeout(() => el.classList.add('hidden'), 3500);
}

async function api(path, { method = 'GET', body, auth = true, idempotent = false } = {}) {
  const headers = { 'Content-Type': 'application/json' };
  if (auth && state.token) headers['Authorization'] = 'Bearer ' + state.token;
  if (idempotent) headers['Idempotency-Key'] = crypto.randomUUID();

  const res = await fetch('/api' + path, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data.error || ('Error ' + res.status));
  }
  return data;
}

function showView(view) {
  $('authView').classList.toggle('hidden', view !== 'auth');
  $('appView').classList.toggle('hidden', view !== 'app');
  $('logoutBtn').classList.toggle('hidden', view !== 'app');
}

function showAuth() {
  state.token = null;
  state.user = null;
  localStorage.removeItem('cacaopay_token');
  showView('auth');
}

async function loadDashboard() {
  try {
    const me = await api('/auth/me');
    state.user = me.user;

    const balance = await api('/wallet/balance');
    $('balance').textContent = Number(balance.cacao_balance).toFixed(2);
    $('fiatLabel').textContent = balance.fiat_currency;
    $('rateInfo').textContent =
      'Tasa: 1 ' + balance.fiat_currency + ' = ' + balance.cacao_per_fiat + ' cacao';

    await Promise.all([loadProducts(), loadLedger()]);
  } catch (e) {
    toast(e.message, true);
    showAuth();
  }
}

async function loadProducts() {
  try {
    const data = await api('/products', { auth: false });
    const wrap = $('products');
    if (!data.products.length) {
      wrap.innerHTML = '<p class="muted">No hay productos disponibles.</p>';
      return;
    }
    wrap.innerHTML = data.products
      .map(
        (p) => `
        <div class="product">
          <div>
            <div class="product-name">${escapeHtml(p.name)}</div>
            <div class="muted small">${escapeHtml(p.description || '')}</div>
            <div class="small price">${p.cacao_price} ${SEED} · stock ${p.stock}${p.seller ? ' · ' + escapeHtml(p.seller.name) : ''}</div>
          </div>
          <button class="btn btn-primary btn-small" data-redeem="${p.id}">Canjear</button>
        </div>`
      )
      .join('');
    wrap.querySelectorAll('[data-redeem]').forEach((btn) =>
      btn.addEventListener('click', () => redeem(btn.dataset.redeem))
    );
  } catch (e) {
    $('products').innerHTML = '<p class="muted">Error al cargar productos.</p>';
  }
}

async function loadLedger() {
  try {
    const data = await api('/wallet/ledger');
    const wrap = $('ledger');
    if (!data.entries.length) {
      wrap.innerHTML = '<p class="muted">Sin movimientos todavía.</p>';
      return;
    }
    wrap.innerHTML = data.entries
      .map((e) => {
        const sign = e.direction === 'IN' ? '+' : '-';
        const cls = e.direction === 'IN' ? 'in' : 'out';
        return `<div class="ledger-row">
          <span class="badge ${e.type.toLowerCase()}">${e.type}</span>
          <span class="amount ${cls}">${sign}${Number(e.amount).toFixed(2)} ${SEED}</span>
          <span class="muted small">${new Date(e.created_at).toLocaleString()}</span>
        </div>`;
      })
      .join('');
  } catch (e) {
    $('ledger').innerHTML = '<p class="muted">Error al cargar movimientos.</p>';
  }
}

async function redeem(productId) {
  try {
    const res = await api('/products/redeem', {
      method: 'POST',
      body: { productId, quantity: 1 },
      idempotent: true,
    });
    toast('Canje exitoso: ' + res.redemption.product.name, false);
    await loadDashboard();
  } catch (e) {
    toast(e.message, true);
  }
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])
  );
}

// ---------- bindings ----------
$('tabLogin').addEventListener('click', () => {
  $('tabLogin').classList.add('active');
  $('tabRegister').classList.remove('active');
  $('loginForm').classList.remove('hidden');
  $('registerForm').classList.add('hidden');
});
$('tabRegister').addEventListener('click', () => {
  $('tabRegister').classList.add('active');
  $('tabLogin').classList.remove('active');
  $('loginForm').classList.add('hidden');
  $('registerForm').classList.remove('hidden');
});

$('loginForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const res = await api('/auth/login', {
      method: 'POST',
      body: { email: $('loginEmail').value, password: $('loginPassword').value },
      auth: false,
    });
    state.token = res.token;
    localStorage.setItem('cacaopay_token', res.token);
    showView('app');
    await loadDashboard();
  } catch (err) {
    toast(err.message, true);
  }
});

$('registerForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const res = await api('/auth/register', {
      method: 'POST',
      body: {
        email: $('regEmail').value,
        name: $('regName').value,
        password: $('regPassword').value,
        role: $('regRole').value,
      },
      auth: false,
    });
    state.token = res.token;
    localStorage.setItem('cacaopay_token', res.token);
    showView('app');
    await loadDashboard();
  } catch (err) {
    toast(err.message, true);
  }
});

$('depositForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const res = await api('/wallet/deposit', {
      method: 'POST',
      body: { amountFiat: parseFloat($('depositAmount').value) },
      idempotent: true,
    });
    toast('Depositado: +' + res.cacao_credited + ' cacao');
    $('depositAmount').value = '';
    await loadDashboard();
  } catch (err) {
    toast(err.message, true);
  }
});

$('transferForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const res = await api('/wallet/transfer', {
      method: 'POST',
      body: {
        toEmail: $('transferEmail').value,
        amountCacao: parseFloat($('transferAmount').value),
      },
      idempotent: true,
    });
    toast('Enviado: ' + res.sent + ' cacao a ' + res.to.email);
    $('transferEmail').value = '';
    $('transferAmount').value = '';
    await loadDashboard();
  } catch (err) {
    toast(err.message, true);
  }
});

$('logoutBtn').addEventListener('click', showAuth);

// ---------- init ----------
(function init() {
  const token = localStorage.getItem('cacaopay_token');
  if (token) {
    state.token = token;
    showView('app');
    loadDashboard();
  } else {
    showView('auth');
  }
})();
