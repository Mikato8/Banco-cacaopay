const state = { token: null, user: null, fiscalLoaded: false, fiscalIvaRate: 0.16, fiscalTransfers: {} };
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
  $('fiscalView').classList.toggle('hidden', view !== 'fiscal');
  $('logoutBtn').classList.toggle('hidden', view === 'auth');
  $('mainNav').classList.toggle('hidden', view === 'auth');
  $('navWallet').classList.toggle('active', view === 'app');
  $('navFiscal').classList.toggle('active', view === 'fiscal');
  applyFiscalRole();
  if (view === 'fiscal' && !state.fiscalLoaded) {
    state.fiscalLoaded = true;
    loadFiscal();
  }
}

function showAuth() {
  state.token = null;
  state.user = null;
  state.fiscalLoaded = false;
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
    $('investRate').textContent = '(tasa anual ' + Math.round(balance.invest_annual_rate * 100) + '%)';
    $('loanRate').textContent = '(tasa anual ' + Math.round(balance.loan_annual_rate * 100) + '%)';

    await Promise.all([loadProducts(), loadLedger(), loadStpTransfers(), loadInvestments(), loadLoans()]);
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

async function loadStpTransfers() {
  try {
    const data = await api('/stp/transfers');
    const wrap = $('stpTransfers');
    if (!data.transfers.length) {
      wrap.innerHTML = '<p class="muted small">Sin transferencias STP.</p>';
      return;
    }
    wrap.innerHTML = data.transfers
      .map((t) => {
        const dir = t.direction === 'IN' ? '+' : '-';
        const cls = t.direction === 'IN' ? 'in' : 'out';
        const label = t.direction === 'IN'
          ? 'Depósito · ' + (t.reference || '')
          : 'Retiro a ' + (t.beneficiary || '') + ' · ' + (t.account_number || '');
        return `<div class="ledger-row">
          <span class="badge ${t.direction.toLowerCase()}">STP ${t.direction}</span>
          <span class="amount ${cls}">${dir}${Number(t.amount_cacao).toFixed(2)} ${SEED}</span>
          <span class="muted small">${escapeHtml(label)} · ${new Date(t.created_at).toLocaleString()}</span>
        </div>`;
      })
      .join('');
  } catch (e) {
    $('stpTransfers').innerHTML = '<p class="muted small">Error al cargar transferencias.</p>';
  }
}

async function loadInvestments() {
  try {
    const data = await api('/investments');
    const wrap = $('investments');
    if (!data.investments.length) {
      wrap.innerHTML = '<p class="muted small">No tienes inversiones activas.</p>';
      return;
    }
    wrap.innerHTML = data.investments
      .map((i) => `
        <div class="ledger-row">
          <span class="amount in">${Number(i.total).toFixed(2)} ${SEED}</span>
          <span class="muted small">Capital ${i.principal_cacao} · +${i.interest_accrued} interés (${i.days_elapsed}d)</span>
          <button class="btn btn-small btn-outline" data-redeem-inv="${i.id}">Redimir</button>
        </div>`)
      .join('');
    wrap.querySelectorAll('[data-redeem-inv]').forEach((btn) =>
      btn.addEventListener('click', () => redeemInvestment(btn.dataset.redeemInv))
    );
  } catch (e) {
    $('investments').innerHTML = '<p class="muted small">Error al cargar inversiones.</p>';
  }
}

async function loadLoans() {
  try {
    const data = await api('/loans');
    const wrap = $('loans');
    if (!data.loans.length) {
      wrap.innerHTML = '<p class="muted small">No tienes préstamos activos.</p>';
      return;
    }
    wrap.innerHTML = data.loans
      .map((l) => `
        <div class="ledger-row">
          <span class="amount out">${Number(l.total_due).toFixed(2)} ${SEED}</span>
          <span class="muted small">Capital ${l.principal_cacao} · +${l.interest_accrued} interés (${l.days_elapsed}d)</span>
          <button class="btn btn-small btn-outline" data-repay="${l.id}">Pagar</button>
        </div>`)
      .join('');
    wrap.querySelectorAll('[data-repay]').forEach((btn) =>
      btn.addEventListener('click', () => repayLoan(btn.dataset.repay))
    );
  } catch (e) {
    $('loans').innerHTML = '<p class="muted small">Error al cargar préstamos.</p>';
  }
}

async function redeem(productId) {
  try {
    const res = await api('/products/redeem', { method: 'POST', body: { productId, quantity: 1 }, idempotent: true });
    toast('Canje exitoso: ' + res.redemption.product.name);
    await loadDashboard();
  } catch (e) { toast(e.message, true); }
}

async function redeemInvestment(investmentId) {
  try {
    const res = await api('/investments/redeem', { method: 'POST', body: { investmentId }, idempotent: true });
    toast('Inversión redimida: +' + res.total + ' cacao (' + res.interest + ' de interés)');
    await loadDashboard();
  } catch (e) { toast(e.message, true); }
}

async function repayLoan(loanId) {
  try {
    const res = await api('/loans/repay', { method: 'POST', body: { loanId }, idempotent: true });
    toast('Préstamo pagado: ' + res.total + ' cacao');
    await loadDashboard();
  } catch (e) { toast(e.message, true); }
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])
  );
}

// ===================== FISCAL · SAT =====================
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

function pad2(n) { return String(n).padStart(2, '0'); }
function isoLocal(d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }

function fmtNum(n) {
  return Number(n || 0).toLocaleString('es-MX', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}
function fmtMxn(n) { return '$' + fmtNum(n) + ' MXN'; }
function fmtMxnShort(n) { return '$' + fmtNum(n); }
function fmtPct(n) { return fmtNum(n) + ' %'; }

function setFiscalPeriod(kind) {
  const now = new Date();
  let from;
  let to;
  if (kind === 'year') {
    from = new Date(now.getFullYear(), 0, 1);
    to = new Date(now.getFullYear(), 11, 31);
  } else {
    from = new Date(now.getFullYear(), now.getMonth(), 1);
    to = new Date(now.getFullYear(), now.getMonth() + 1, 0);
  }
  $('fiscalFrom').value = isoLocal(from);
  $('fiscalTo').value = isoLocal(to);
}

function currentFiscalPeriod() {
  let from = $('fiscalFrom').value;
  let to = $('fiscalTo').value;
  if (!from || !to) {
    setFiscalPeriod('month');
    from = $('fiscalFrom').value;
    to = $('fiscalTo').value;
  }
  return { from, to };
}

function applyFiscalRole() {
  const isAdmin = !!(state.user && state.user.role === 'ADMIN');
  $('cfdiFormCard').classList.toggle('hidden', !isAdmin);
  $('fiscalSettingsCard').classList.toggle('hidden', !isAdmin);
  $('closePeriodBtn').classList.toggle('hidden', !isAdmin);
}

async function loadFiscal() {
  const { from, to } = currentFiscalPeriod();
  if (from > to) {
    toast('Periodo inválido: «Desde» no puede ser posterior a «Hasta».', true);
    return;
  }

  if (!state.user) {
    try {
      const me = await api('/auth/me');
      state.user = me.user;
    } catch (e) {
      /* el 401 lo maneja el catch principal */
    }
  }
  applyFiscalRole();

  const qs = '?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
  try {
    const [summary, congruence, invoices, periods] = await Promise.all([
      api('/fiscal/summary' + qs),
      api('/fiscal/congruence' + qs),
      api('/fiscal/invoices' + qs),
      api('/fiscal/periods').catch(() => ({ periods: [] })),
    ]);
    renderFiscalSummary(summary);
    renderFiscalCongruence(congruence);
    renderFiscalInvoices(invoices);
    renderFiscalPeriods(periods.periods || [], summary);
    recalcCfdi();
  } catch (e) {
    toast('No se pudo cargar el módulo fiscal: ' + e.message, true);
    $('fiscalAlerts').innerHTML = '<p class="empty">No se pudieron cargar los datos fiscales.</p>';
  }
}

function renderFiscalSummary(s) {
  const f = s.facturacion || {};
  const m = s.movimientos || {};
  const c = s.congruencia || {};
  const settings = s.settings || {};
  const period = s.period || {};

  if (settings.iva_rate != null) state.fiscalIvaRate = Number(settings.iva_rate);

  $('kpiFacturado').textContent = fmtMxnShort(f.facturado_neto);
  $('kpiFacturadoSub').textContent =
    (f.cfdi_vigentes || 0) + ' CFDI vigentes · ' + (f.cfdi_cancelados || 0) + ' cancelados';
  $('kpiIva').textContent = fmtMxnShort(f.iva_neto);
  $('kpiDepositos').textContent = fmtMxnShort(m.depositos_mxn);
  $('kpiDepositosSub').textContent = (m.depositos_count || 0) + ' depósito(s) STP';
  $('kpiRetiros').textContent = fmtMxnShort(m.retiros_mxn);
  $('kpiRetirosSub').textContent = (m.retiros_count || 0) + ' retiro(s) STP';
  $('kpiCobertura').textContent = fmtPct(c.cobertura_pct);
  $('kpiDiferencia').textContent = fmtMxnShort(c.diferencia_total);
  const meses = Array.isArray(s.por_mes) ? s.por_mes.length : 0;
  $('kpiMesesSub').textContent = meses + ' mes(es) en el periodo';

  const estado = String(c.estado || 'REVISAR').toUpperCase() === 'CUADRA' ? 'CUADRA' : 'REVISAR';
  const badge = $('fiscalEstado');
  badge.textContent = estado;
  badge.className = 'badge ' + (estado === 'CUADRA' ? 'ok' : 'warn');

  const alcance = s.scope === 'GLOBAL' ? 'Vista global (ADMIN)' : 'Vista de tus propios movimientos';
  const rango = period.from && period.to ? ' · ' + period.from + ' a ' + period.to : '';
  $('fiscalScopeNote').textContent =
    alcance + rango + (period.label ? ' · ' + period.label : '') + ' · cobertura ' + fmtPct(c.cobertura_pct);

  renderFiscalAlerts(s.alertas || []);
  fillFiscalSettings(settings);
}

function renderFiscalAlerts(alertas) {
  const wrap = $('fiscalAlerts');
  if (!alertas.length) {
    wrap.innerHTML = '<p class="empty">Sin alertas fiscales en el periodo.</p>';
    return;
  }
  wrap.innerHTML = alertas
    .map((a) => {
      const sev = String(a.severidad || 'INFO').toUpperCase();
      const cls = sev === 'ALTA' ? 'ALTA' : sev === 'MEDIA' ? 'MEDIA' : 'INFO';
      const badgeCls = sev === 'ALTA' ? 'bad' : sev === 'MEDIA' ? 'warn' : 'info';
      const monto = Number(a.monto || 0);
      return `<div class="alert ${cls}">
        <span class="badge ${badgeCls}">${escapeHtml(sev)}</span>
        <span class="alert-msg"><strong>${escapeHtml(a.tipo || '')}</strong> · ${escapeHtml(a.mensaje || '')}</span>
        ${monto > 0 ? '<span class="alert-monto">' + fmtMxn(monto) + '</span>' : ''}
      </div>`;
    })
    .join('');
}

function estadoBadge(estado) {
  const e = String(estado || '').toUpperCase();
  const cls = e === 'FACTURADO' ? 'ok' : e === 'PARCIAL' ? 'warn' : e === 'EXCEDE' ? 'bad' : e === 'SIN_FACTURA' ? 'bad' : '';
  const label = e === 'SIN_FACTURA' ? 'SIN FACTURA' : e;
  return `<span class="badge ${cls}">${escapeHtml(label)}</span>`;
}

function cfdiRefs(list) {
  const uuids = Array.isArray(list) ? list : [];
  if (!uuids.length) return '<span class="muted">—</span>';
  return uuids
    .map((u) => `<span class="mono" title="${escapeHtml(u)}">${escapeHtml(String(u).slice(0, 8))}…</span>`)
    .join(' ');
}

function renderFiscalCongruence(data) {
  const dep = Array.isArray(data.depositos) ? data.depositos : [];
  const ret = Array.isArray(data.retiros) ? data.retiros : [];
  const sinDep = Array.isArray(data.cfdi_sin_deposito) ? data.cfdi_sin_deposito : [];
  const cancelados = Array.isArray(data.cfdi_cancelados_con_deposito) ? data.cfdi_cancelados_con_deposito : [];

  populateFiscalTransfers(dep);

  let html = '<h4 class="table-title">Depósitos del periodo</h4>';
  if (!dep.length) {
    html += '<p class="empty">Sin depósitos en el periodo.</p>';
  } else {
    html +=
      '<table class="table"><thead><tr>' +
      '<th>Fecha</th><th class="num">Monto (MXN)</th><th>Referencia</th><th>Receptor</th>' +
      '<th class="num">Facturado</th><th class="num">Diferencia</th><th>Estado</th><th>CFDI</th>' +
      '</tr></thead><tbody>' +
      dep
        .map((d) => {
          const receptor = d.receptor
            ? d.receptor.name || d.receptor.email || d.receptor.rfc || '—'
            : '—';
          return `<tr>
            <td>${escapeHtml(String(d.fecha || ''))}</td>
            <td class="num">${fmtMxnShort(d.monto_mxn)}</td>
            <td>${escapeHtml(d.referencia || '—')}</td>
            <td>${escapeHtml(receptor)}</td>
            <td class="num">${fmtMxnShort(d.facturado)}</td>
            <td class="num">${fmtMxnShort(d.diferencia)}</td>
            <td>${estadoBadge(d.estado)}</td>
            <td>${cfdiRefs(d.cfdi_uuids)}</td>
          </tr>`;
        })
        .join('') +
      '</tbody></table>';
  }

  html += '<h4 class="table-title">Retiros del periodo</h4>';
  if (!ret.length) {
    html += '<p class="empty">Sin retiros en el periodo.</p>';
  } else {
    html +=
      '<table class="table"><thead><tr>' +
      '<th>Fecha</th><th class="num">Monto (MXN)</th><th>Beneficiario</th><th>CLABE</th><th>Banco</th>' +
      '<th class="num">Facturado</th><th class="num">Diferencia</th><th>Estado</th><th>CFDI</th>' +
      '</tr></thead><tbody>' +
      ret
        .map(
          (r) => `<tr>
            <td>${escapeHtml(String(r.fecha || ''))}</td>
            <td class="num">${fmtMxnShort(r.monto_mxn)}</td>
            <td>${escapeHtml(r.beneficiario || '—')}</td>
            <td class="mono">${escapeHtml(r.clabe || '—')}</td>
            <td>${escapeHtml(r.banco || '—')}</td>
            <td class="num">${fmtMxnShort(r.facturado)}</td>
            <td class="num">${fmtMxnShort(r.diferencia)}</td>
            <td>${estadoBadge(r.estado)}</td>
            <td>${cfdiRefs(r.cfdi_uuids)}</td>
          </tr>`
        )
        .join('') +
      '</tbody></table>';
  }

  if (sinDep.length) {
    html +=
      '<h4 class="table-title">CFDI vigentes sin depósito que los respalde</h4>' +
      '<table class="table"><thead><tr><th>Fecha</th><th>UUID</th><th>Tipo</th>' +
      '<th>RFC receptor</th><th class="num">Total (MXN)</th></tr></thead><tbody>' +
      sinDep
        .map(
          (c) => `<tr>
            <td>${escapeHtml(String(c.issued_at || ''))}</td>
            <td class="mono" title="${escapeHtml(c.uuid_fiscal || '')}">${escapeHtml(String(c.uuid_fiscal || '').slice(0, 8))}…</td>
            <td>${escapeHtml(c.tipo || '')}</td>
            <td>${escapeHtml(c.receptor_rfc || '—')}</td>
            <td class="num">${fmtMxnShort(c.total)}</td>
          </tr>`
        )
        .join('') +
      '</tbody></table>';
  }

  if (cancelados.length) {
    html +=
      '<h4 class="table-title">CFDI cancelados con depósito</h4>' +
      '<table class="table"><thead><tr><th>UUID</th><th class="num">Total (MXN)</th>' +
      '<th>Transferencia</th></tr></thead><tbody>' +
      cancelados
        .map(
          (c) => `<tr>
            <td class="mono" title="${escapeHtml(c.uuid_fiscal || '')}">${escapeHtml(String(c.uuid_fiscal || '').slice(0, 8))}…</td>
            <td class="num">${fmtMxnShort(c.total)}</td>
            <td class="mono">${escapeHtml(c.stp_transfer_id || '—')}</td>
          </tr>`
        )
        .join('') +
      '</tbody></table>';
  }

  $('fiscalCongruencia').innerHTML = html;
  renderCuadreLedger(data.cuadre_ledger || (data.resumen || {}).cuadre_ledger || {});
}

function populateFiscalTransfers(depositos) {
  const sel = $('cfdiTransfer');
  if (!sel) return;
  state.fiscalTransfers = {};
  const prev = sel.value;
  const lista = depositos || [];
  lista.forEach((d) => {
    if (!d || !d.id) return;
    state.fiscalTransfers[d.id] = {
      receptor_id: d.receptor ? d.receptor.id : null,
      receptor_name: d.receptor ? d.receptor.name || d.receptor.email : null,
      monto: Number(d.monto_mxn || 0),
      facturado: Number(d.facturado || 0),
    };
  });
  const disponibles = lista.filter((d) => {
    const e = String(d.estado || '').toUpperCase();
    return e === 'SIN_FACTURA' || e === 'PARCIAL';
  });
  const options = disponibles
    .map((d) => {
      const receptor = d.receptor ? d.receptor.name || d.receptor.email || '—' : '—';
      const fecha = String(d.fecha || '').slice(0, 10);
      const restante = Number(d.monto_mxn || 0) - Number(d.facturado || 0);
      const label =
        fecha + ' · ' + fmtMxnShort(d.monto_mxn) + ' · ' + receptor + ' · disp. ' + fmtMxnShort(restante);
      return `<option value="${escapeHtml(d.id)}">${escapeHtml(label)}</option>`;
    })
    .join('');
  sel.innerHTML = '<option value="">Sin transferencia relacionada</option>' + options;
  if (prev && Array.from(sel.options).some((o) => o.value === prev)) sel.value = prev;
}

function renderCuadreLedger(c) {
  const cuadre = c || {};
  const ok = !!cuadre.ok;
  const row = (label, ledger, stp) => `<div class="ledger-row">
    <span class="badge ${ok ? 'ok' : 'bad'}">${ok ? 'OK' : 'DESCUADRE'}</span>
    <span>${escapeHtml(label)}</span>
    <span class="muted small">libro mayor ${fmtMxn(ledger)} · STP ${fmtMxn(stp)}</span>
    <span class="amount ${ok ? 'in' : 'out'}">${fmtMxnShort((Number(ledger || 0)) - (Number(stp || 0)))}</span>
  </div>`;
  $('fiscalCuadreLedger').innerHTML =
    row('Depósitos', cuadre.depositos_ledger, cuadre.depositos_stp) +
    row('Retiros', cuadre.retiros_ledger, cuadre.retiros_stp) +
    `<p class="note">${
      ok
        ? 'El libro mayor coincide con las transferencias STP (tolerancia $0.01).'
        : 'El libro mayor no coincide con las transferencias STP: revisa ajustes o movimientos manuales.'
    }</p>`;
}

function renderFiscalInvoices(data) {
  const invoices = Array.isArray(data.invoices) ? data.invoices : [];
  const totals = data.totals || {};
  const table = $('cfdiList');
  const caption = table.querySelector('caption');
  const tbody = table.querySelector('tbody');

  if (caption) {
    caption.textContent =
      'Vigentes: ' + (totals.vigentes || 0) + ' · Subtotal ' +
      fmtMxnShort(totals.subtotal) + ' · IVA ' + fmtMxnShort(totals.iva) + ' · Total ' +
      fmtMxnShort(totals.total) + ' · Cancelados: ' + (totals.cancelados || 0) +
      ' (' + fmtMxnShort(totals.cancelado_total) + ')';
  }

  if (!invoices.length) {
    tbody.innerHTML = '<tr><td class="empty" colspan="11">Sin CFDI registrados en el periodo.</td></tr>';
    return;
  }

  tbody.innerHTML = invoices
    .map((i) => {
      const vigente = String(i.estatus || '').toUpperCase() === 'VIGENTE';
      const receptor = i.receptor_nombre || i.receptor_rfc || '—';
      return `<tr>
        <td>${escapeHtml(String(i.fecha || ''))}</td>
        <td class="mono" title="${escapeHtml(i.uuid_fiscal || '')}">${escapeHtml(String(i.uuid_fiscal || '').slice(0, 8))}…</td>
        <td>${escapeHtml(i.tipo || '')}</td>
        <td>${escapeHtml(receptor)}</td>
        <td class="num">${fmtMxnShort(i.subtotal)}</td>
        <td class="num">${fmtMxnShort(i.iva)}</td>
        <td class="num">${fmtMxnShort(i.total)}</td>
        <td>${escapeHtml(i.forma_pago || '—')} / ${escapeHtml(i.metodo_pago || '—')}</td>
        <td>${i.referencia ? escapeHtml(i.referencia) : i.stp_transfer_id ? '<span class="mono">' + escapeHtml(String(i.stp_transfer_id).slice(0, 8)) + '…</span>' : '<span class="muted">—</span>'}</td>
        <td><span class="badge ${vigente ? 'ok' : 'bad'}"${i.cancel_reason ? ' title="' + escapeHtml(i.cancel_reason) + '"' : ''}>${vigente ? 'VIGENTE' : 'CANCELADA'}</span></td>
        <td>${
          vigente
            ? `<button class="btn btn-small btn-outline" type="button" data-cancel-cfdi="${escapeHtml(i.id)}" data-uuid="${escapeHtml(i.uuid_fiscal || '')}">Cancelar</button>`
            : '<span class="muted small">—</span>'
        }</td>
      </tr>`;
    })
    .join('');
}

function renderFiscalPeriods(periods, summary) {
  const wrap = $('fiscalPeriods');
  if (!periods.length) {
    wrap.innerHTML = '<p class="empty">Aún no hay cierres de periodo.</p>';
  } else {
    wrap.innerHTML =
      '<table class="table"><thead><tr><th>Periodo</th><th>Revisión</th><th>Rango</th>' +
      '<th>Cerrado</th><th>Digest</th><th>Encadenado</th><th class="num">Facturado neto</th>' +
      '</tr></thead><tbody>' +
      periods
        .map((p) => {
          const t = p.totals || {};
          const fact =
            t.facturacion && t.facturacion.facturado_neto != null
              ? t.facturacion.facturado_neto
              : t.facturado_neto;
          const closedBy = p.closed_by && p.closed_by.name ? p.closed_by.name : '—';
          return `<tr>
            <td class="mono">${escapeHtml(p.period_key || '')}</td>
            <td>${escapeHtml(String(p.revision == null ? '' : p.revision))}</td>
            <td>${escapeHtml(String(p.from_date || ''))} → ${escapeHtml(String(p.to_date || ''))}</td>
            <td>${escapeHtml(String(p.closed_at || ''))} · ${escapeHtml(closedBy)}</td>
            <td class="mono" title="${escapeHtml(p.digest || '')}">${escapeHtml(String(p.digest || '').slice(0, 12))}…</td>
            <td class="mono">${p.prev_digest ? escapeHtml(String(p.prev_digest).slice(0, 12)) + '…' : 'inicio'}</td>
            <td class="num">${fact != null ? fmtMxnShort(fact) : '—'}</td>
          </tr>`;
        })
        .join('') +
      '</tbody></table>';
  }

  const period = summary && summary.period ? summary.period : {};
  let key = period.from ? String(period.from).slice(0, 7) : '';
  if (!key) key = String($('fiscalFrom').value || '').slice(0, 7);
  const last = periods[0];
  $('closePeriodNote').textContent =
    'El cierre del periodo es inmutable y se encadena con el cierre anterior mediante un digest SHA-256. ' +
    'Periodo a cerrar: ' +
    (key || '—') +
    (last
      ? ' · último cierre: ' + last.period_key + ' rev. ' + last.revision + '.'
      : ' · sin cierres previos.');
}

function fillFiscalSettings(settings) {
  const form = $('fiscalSettingsForm');
  if (form && form.contains(document.activeElement)) return;
  $('fsRfc').value = settings.rfc_emisor || '';
  $('fsRazonSocial').value = settings.razon_social || '';
  $('fsRegimen').value = settings.regimen_fiscal || '612';
  $('fsCp').value = settings.codigo_postal || '';
  $('fsSerie').value = settings.serie || 'A';
  const rate = settings.iva_rate != null ? Number(settings.iva_rate) : state.fiscalIvaRate;
  $('fsIvaRate').value = String(Number((rate * 100).toFixed(2)));
}

function recalcCfdi() {
  const rate = Number(state.fiscalIvaRate || 0.16);
  const subtotal = Number($('cfdiSubtotal').value || 0);
  if (!(subtotal > 0)) {
    $('cfdiIva').value = '';
    $('cfdiTotal').value = '';
    return;
  }
  const iva = Math.round(subtotal * rate * 100) / 100;
  const total = Math.round((subtotal + iva) * 100) / 100;
  $('cfdiIva').value = iva.toFixed(2);
  $('cfdiTotal').value = total.toFixed(2);
}

async function submitFiscalInvoice(e) {
  e.preventDefault();
  const subtotal = Number($('cfdiSubtotal').value);
  if (!(subtotal > 0)) {
    toast('El subtotal debe ser mayor a cero.', true);
    return;
  }

  const transferId = $('cfdiTransfer').value;
  const transferInfo = transferId && state.fiscalTransfers ? state.fiscalTransfers[transferId] : null;
  // Si el CFDI se liga a un depósito, el receptor es el dueño del depósito:
  // prellenamos el nombre (solo si el campo está vacío) para no forzar al ADMIN a escribirlo.
  if (transferInfo && transferInfo.receptor_name && $('cfdiNombre').value.trim() === '') {
    $('cfdiNombre').value = transferInfo.receptor_name;
  }

  const body = {
    uuid: $('cfdiUuid').value.trim(),
    receptorRfc: $('cfdiRfc').value.trim().toUpperCase(),
    receptorNombre: $('cfdiNombre').value.trim(),
    subtotal: Math.round(subtotal * 100) / 100,
    iva: Number($('cfdiIva').value || 0),
    total: Number($('cfdiTotal').value || 0),
    tipo: $('cfdiTipo').value,
    formaPago: $('cfdiFormaPago').value,
    metodoPago: $('cfdiMetodoPago').value,
    usoCfdi: $('cfdiUso').value,
    ivaRate: Number(state.fiscalIvaRate || 0.16),
  };
  if ($('cfdiFecha').value) body.issuedAt = $('cfdiFecha').value;
  if (transferId) {
    body.stpTransferId = transferId;
    if (transferInfo && transferInfo.receptor_id) body.receptorUserId = transferInfo.receptor_id;
  }

  const submitBtn = $('cfdiSubmit');
  submitBtn.disabled = true;
  try {
    const res = await api('/fiscal/invoices', { method: 'POST', body });
    const uuid = res.invoice && res.invoice.uuid_fiscal ? res.invoice.uuid_fiscal : body.uuid;
    toast('CFDI registrado: ' + uuid);
    $('cfdiUuid').value = '';
    $('cfdiSubtotal').value = '';
    $('cfdiIva').value = '';
    $('cfdiTotal').value = '';
    $('cfdiTransfer').value = '';
    await loadFiscal();
  } catch (err) {
    toast(err.message, true);
  } finally {
    submitBtn.disabled = false;
  }
}

async function cancelFiscalInvoice(invoiceId, uuid) {
  if (!confirm('¿Cancelar el CFDI ' + uuid + '?\n\nNo se elimina: queda marcado como CANCELADA.')) return;
  try {
    await api('/fiscal/invoices/cancel', {
      method: 'POST',
      body: { invoiceId, reason: 'Cancelado desde el sitio' },
    });
    toast('CFDI cancelado: ' + uuid);
    await loadFiscal();
  } catch (err) {
    toast(err.message, true);
  }
}

async function exportFiscalCsv(type) {
  const { from, to } = currentFiscalPeriod();
  const nombres = { cfdi: 'CFDI', movimientos: 'movimientos', congruencia: 'congruencia' };
  const label = nombres[type] || type;
  try {
    const res = await fetch(
      '/api/fiscal/export?from=' + encodeURIComponent(from) +
        '&to=' + encodeURIComponent(to) +
        '&type=' + encodeURIComponent(type) +
        '&format=csv',
      { headers: { Authorization: 'Bearer ' + state.token } }
    );
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(data.error || 'Error ' + res.status);
    }
    const blob = await res.blob();
    let filename = type + '_' + from + '_' + to + '.csv';
    const disposition = res.headers.get('Content-Disposition') || '';
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition);
    if (match && match[1]) filename = decodeURIComponent(match[1].replace(/^"|"$/g, ''));
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    toast('Exportación de ' + label + ' descargada: ' + filename);
  } catch (err) {
    toast('No se pudo exportar ' + label + ': ' + err.message, true);
  }
}

async function saveFiscalSettings(e) {
  e.preventDefault();
  const pct = Number($('fsIvaRate').value);
  if (Number.isNaN(pct) || pct < 0 || pct > 100) {
    toast('La tasa de IVA debe estar entre 0 y 100 %.', true);
    return;
  }
  const body = {
    rfcEmisor: $('fsRfc').value.trim().toUpperCase(),
    razonSocial: $('fsRazonSocial').value.trim(),
    regimenFiscal: $('fsRegimen').value.trim(),
    codigoPostal: $('fsCp').value.trim(),
    serie: $('fsSerie').value.trim(),
    ivaRate: Math.round((pct / 100) * 10000) / 10000,
  };
  try {
    const res = await api('/fiscal/settings', { method: 'POST', body });
    toast(res.configured ? 'Configuración fiscal guardada.' : 'Configuración fiscal actualizada.');
    await loadFiscal();
  } catch (err) {
    toast(err.message, true);
  }
}

async function closeFiscalPeriod() {
  const { from } = currentFiscalPeriod();
  const period = String(from || '').slice(0, 7);
  if (!/^\d{4}-\d{2}$/.test(period)) {
    toast('Selecciona un periodo válido antes de cerrar.', true);
    return;
  }
  if (!confirm('¿Cerrar el periodo ' + period + '?\n\nEl cierre es inmutable y se encadena con el cierre anterior.')) return;
  try {
    const res = await api('/fiscal/periods/close', { method: 'POST', body: { period } });
    const p = res.period || {};
    toast('Periodo ' + (p.period_key || period) + ' cerrado (revisión ' + (p.revision || 1) + ').');
    await loadFiscal();
  } catch (err) {
    toast(err.message, true);
  }
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
  } catch (err) { toast(err.message, true); }
});

$('registerForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const res = await api('/auth/register', {
      method: 'POST',
      body: { email: $('regEmail').value, name: $('regName').value, password: $('regPassword').value, role: $('regRole').value },
      auth: false,
    });
    state.token = res.token;
    localStorage.setItem('cacaopay_token', res.token);
    showView('app');
    await loadDashboard();
  } catch (err) { toast(err.message, true); }
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
  } catch (err) { toast(err.message, true); }
});

$('transferForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const res = await api('/wallet/transfer', {
      method: 'POST',
      body: { toEmail: $('transferEmail').value, amountCacao: parseFloat($('transferAmount').value) },
      idempotent: true,
    });
    toast('Enviado: ' + res.sent + ' cacao a ' + res.to.email);
    $('transferEmail').value = '';
    $('transferAmount').value = '';
    await loadDashboard();
  } catch (err) { toast(err.message, true); }
});

$('withdrawForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const res = await api('/stp/withdraw', {
      method: 'POST',
      body: {
        amountCacao: parseFloat($('withdrawAmount').value),
        clabe: $('withdrawClabe').value,
        beneficiary: $('withdrawBeneficiary').value,
        bankName: $('withdrawBank').value,
      },
      idempotent: true,
    });
    toast('Retiro STP enviado: ' + res.amount_mxn + ' MXN a ' + res.transfer.beneficiary);
    $('withdrawAmount').value = '';
    $('withdrawClabe').value = '';
    $('withdrawBeneficiary').value = '';
    $('withdrawBank').value = '';
    await loadDashboard();
  } catch (err) { toast(err.message, true); }
});

$('investForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const res = await api('/investments', {
      method: 'POST',
      body: { amountCacao: parseFloat($('investAmount').value) },
      idempotent: true,
    });
    toast('Invertido: ' + res.investment.principal_cacao + ' cacao');
    $('investAmount').value = '';
    await loadDashboard();
  } catch (err) { toast(err.message, true); }
});

$('loanForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const res = await api('/loans', {
      method: 'POST',
      body: { amountCacao: parseFloat($('loanAmount').value), termDays: parseInt($('loanTerm').value, 10) },
      idempotent: true,
    });
    toast('Préstamo otorgado: +' + res.loan.principal_cacao + ' cacao');
    $('loanAmount').value = '';
    await loadDashboard();
  } catch (err) { toast(err.message, true); }
});

$('logoutBtn').addEventListener('click', showAuth);

// ---------- fiscal bindings ----------
$('navWallet').addEventListener('click', () => showView('app'));
$('navFiscal').addEventListener('click', () => showView('fiscal'));

$('fiscalQuickMonth').addEventListener('click', () => {
  setFiscalPeriod('month');
  loadFiscal();
});
$('fiscalQuickYear').addEventListener('click', () => {
  setFiscalPeriod('year');
  loadFiscal();
});
$('fiscalRefresh').addEventListener('click', () => loadFiscal());

$('cfdiTransfer').addEventListener('change', () => {
  const id = $('cfdiTransfer').value;
  const info = id && state.fiscalTransfers ? state.fiscalTransfers[id] : null;
  if (info && info.receptor_name && $('cfdiNombre').value.trim() === '') {
    $('cfdiNombre').value = info.receptor_name;
  }
});

$('cfdiSubtotal').addEventListener('input', recalcCfdi);
$('cfdiForm').addEventListener('submit', submitFiscalInvoice);
$('fiscalSettingsForm').addEventListener('submit', saveFiscalSettings);
$('closePeriodBtn').addEventListener('click', closeFiscalPeriod);

$('exportCfdi').addEventListener('click', () => exportFiscalCsv('cfdi'));
$('exportMovimientos').addEventListener('click', () => exportFiscalCsv('movimientos'));
$('exportCongruencia').addEventListener('click', () => exportFiscalCsv('congruencia'));

$('cfdiList').addEventListener('click', (e) => {
  const btn = e.target.closest('[data-cancel-cfdi]');
  if (!btn) return;
  cancelFiscalInvoice(btn.dataset.cancelCfdi, btn.dataset.uuid);
});

// ---------- init ----------
(function init() {
  setFiscalPeriod('month');
  $('cfdiFecha').value = isoLocal(new Date());
  const token = localStorage.getItem('cacaopay_token');
  if (token) {
    state.token = token;
    showView('app');
    loadDashboard();
  } else {
    showView('auth');
  }
})();
