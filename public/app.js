'use strict';

const $ = (id) => document.getElementById(id);
const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const num = (n) => Number(n).toLocaleString('es-ES');

let limits = { max_bytes: 15996, warn_bytes: 3999 };
let current = null;                // ticket abierto
let session = { mode: 'dev', auth_required: false, user: null, account: null };
const knownStatus = new Map();     // id de petición -> status, para resaltar los cambios
const waiting = new Map();         // peticiones de esta página que esperan resultado -> texto enviado (botones desactivados)

// localStorage solo para recordar la cuenta y el personaje entre recargas; puede no estar disponible
const store = {
    get(k) { try { return localStorage.getItem(k); } catch { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch { /* sin almacenamiento */ } },
};

async function api(action, { query = {}, body = null, showSql = true } = {}) {
    const qs = new URLSearchParams({ action, ...query });
    // X-Requested-With: la API rechaza sin ella las acciones que cambian algo (protección contra CSRF)
    const headers = { 'X-Requested-With': 'gmts' };
    const res = await fetch('api.php?' + qs, body
        ? { method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
        : { headers });
    const json = await res.json();
    if (res.status === 401) showLogin();
    if (showSql) renderSql(json.sql);
    return json;
}

function message(kind, content) {
    const body = Array.isArray(content)
        ? '<ul>' + content.map((c) => `<li>${esc(c)}</li>`).join('') + '</ul>'
        : esc(content);
    return `<div class="msg ${kind}">${body}</div>`;
}

function renderSql(queries) {
    if (!queries?.length) return;
    $('sql').innerHTML = queries.map((q) => {
        const sql = q.sql.replace(/\s+/g, ' ').trim();
        const params = q.params.length ? `\n<span class="params">-- parámetros: ${esc(JSON.stringify(q.params))}</span>` : '';
        return `<pre>${esc(sql)}${params}</pre>`;
    }).join('');
}

function duration(seconds) {
    if (seconds < 60) return `${seconds} s`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)} min ${seconds % 60} s`;
    return `${Math.floor(seconds / 3600)} h ${Math.floor((seconds % 3600) / 60)} min`;
}

// Avatar con la inicial y un color estable según el nombre
function avatar(name, { online = false, small = false, gm = false } = {}) {
    const n = String(name ?? '?');
    let h = 0;
    for (const ch of n) h = (h + ch.codePointAt(0)) % 4;
    const cls = ['avatar', gm ? 'gm' : (h ? 'av' + h : ''), online ? 'online' : '', small ? 'sm' : ''].filter(Boolean).join(' ');
    return `<span class="${cls}" aria-hidden="true">${esc(n.slice(0, 1))}</span>`;
}

const gmGuid = () => +($('gm').value || 0);
const account = () => session.account ?? +($('account').value || 0);

// ---------------------------------------------------------------- Tema (claro / oscuro / sistema)

function applyTheme(choice) {
    if (choice === 'light' || choice === 'dark') document.documentElement.dataset.theme = choice;
    else delete document.documentElement.dataset.theme;
    store.set('theme', choice);
    document.querySelectorAll('[data-theme-choice]').forEach((b) =>
        b.setAttribute('aria-checked', String(b.dataset.themeChoice === choice)));
}
document.querySelectorAll('[data-theme-choice]').forEach((b) =>
    b.addEventListener('click', () => applyTheme(b.dataset.themeChoice)));
applyTheme(store.get('theme') || 'system');

// Resalta en la barra lateral la sección visible
const navLinks = [...document.querySelectorAll('.nav a')];
window.addEventListener('scroll', () => {
    let active = navLinks[0];
    for (const a of navLinks) {
        const el = document.querySelector(a.getAttribute('href'));
        if (el && !el.hidden && el.getBoundingClientRect().top < 140) active = a;
    }
    navLinks.forEach((a) => a.classList.toggle('active', a === active));
}, { passive: true });

// ---------------------------------------------------------------- Guía de pasos

function updateSteps() {
    const hasGm = !!gmGuid() && !$('gm-box').hidden;
    const hasTicket = !!current;
    $('step-gm').classList.toggle('done', hasGm);
    $('step-ticket').classList.toggle('done', hasTicket);
    document.querySelector('.agent').classList.toggle('done', hasGm);
    const step = !hasGm ? 'step-gm' : !hasTicket ? 'step-ticket' : 'step-reply';
    ['step-gm', 'step-ticket', 'step-reply'].forEach((id) => $(id).classList.toggle('current', id === step));
}

// ---------------------------------------------------------------- Conexión

async function checkConnection() {
    const r = await api('status', { showSql: false });
    if (!r.ok || r.errors.length) {
        $('connection').innerHTML = message('err', r.errors);
        return;
    }
    limits = r.data;
    $('connection').textContent = r.data.version ? `Conectado a ${r.data.version}` : 'Conectado';
    $('connection').classList.add('ok');
}

// ---------------------------------------------------------------- GM

async function loadGm(showSql = true) {
    const r = await api('gm_characters', { query: { account: account() }, showSql });
    if (!r.ok || !r.data.length) {
        $('gm-box').hidden = true;
        $('gm-result').innerHTML = message('err', r.ok ? 'La cuenta no tiene personajes.' : r.errors);
        updateButtons();
        updateSteps();
        return;
    }
    const saved = +store.get('gm');
    $('gm').innerHTML = r.data.map((c) =>
        `<option value="${c.guid}" ${c.guid === saved ? 'selected' : ''}>${esc(c.name)} · nivel ${c.level}${c.online ? ' · conectado' : ''} (guid ${c.guid})</option>`
    ).join('');
    $('gm-box').hidden = false;
    $('gm-result').innerHTML = '';
    if (!session.account) store.set('account', account());
    store.set('gm', gmGuid());
    updateButtons();
    updateSteps();
}

$('account-form').addEventListener('submit', (e) => { e.preventDefault(); loadGm(); });
$('gm').addEventListener('change', () => { store.set('gm', gmGuid()); updateSteps(); });

// ---------------------------------------------------------------- Lista de tickets

async function refreshTickets(showSql = false) {
    const r = await api('tickets', { query: { all: $('show-all').checked ? 1 : 0 }, showSql });
    if (!r.ok) return;

    const open = r.data.filter((t) => !t.closed && !t.completed);
    $('stat-open').textContent = num(open.length);
    $('stat-unseen').textContent = num(open.filter((t) => !t.viewed).length);
    $('stat-unassigned').textContent = num(open.filter((t) => !t.assignedTo).length);

    $('tickets').innerHTML = r.data.map((t) => {
        const chips = [
            t.viewed ? '' : '<span class="chip pend">Sin ver</span>',
            t.response_bytes ? '<span class="chip info">Con respuesta</span>' : '',
            t.assignedTo ? `<span class="chip violet">Asignado a ${esc(t.assigned_name ?? t.assignedTo)}</span>` : '',
        ].join('');
        return `<tr class="clickable ${current?.id === t.id ? 'selected' : ''}" data-id="${t.id}">
            <td class="key">GM-${t.id}</td>
            <td><div class="person">${avatar(t.name, { online: t.online })}<div><div class="person-name">${esc(t.name)}</div><div class="muted">${t.online ? 'Conectado' : 'Desconectado'}</div></div></div></td>
            <td><span class="summary-link">${esc(t.description)}</span>${chips ? `<div class="chips">${chips}</div>` : ''}</td>
            <td>${stateBadge(t)}</td>
            <td class="muted">${esc(t.created)}</td>
        </tr>`;
    }).join('') || '<tr class="empty-row"><td colspan="5"><span class="big" aria-hidden="true">🎉</span>No hay tickets pendientes. ¡Todo al día!</td></tr>';
    if (current) updateNav();
}

function stateBadge(t) {
    const cls = t.closed ? 'closed' : t.completed ? 's1' : 'open';
    return `<span class="badge ${cls}">${esc(t.state)}</span>`;
}

$('tickets').addEventListener('click', (e) => {
    const row = e.target.closest('tr[data-id]');
    if (row) openTicket(+row.dataset.id, true);
});
$('show-all').addEventListener('change', () => refreshTickets(true));

// ---------------------------------------------------------------- Ticket abierto

async function openTicket(id, byUser) {
    const r = await api('ticket', { query: { id }, showSql: byUser });
    if (!r.ok) {
        showDialog();
        $('ticket').innerHTML = message('err', r.errors);
        return;
    }
    const changedTicket = current?.id !== id;
    current = r.data;
    renderTicket();
    updateSteps();
    if (changedTicket) {
        waiting.clear();
        $('reply').value = '';
        $('action-result').innerHTML = '';
        checkReply();
    }
    refreshTickets();
    refreshRequests();

    // Al abrirlo un GM, la web manda VIEW: marca "visto" para el jugador y deja el viewid en el registro del GM
    if (byUser && gmGuid() && !current.closed && !current.completed) {
        sendAction('view', '');
    } else if (byUser && !gmGuid()) {
        $('action-result').innerHTML = message('pend', 'Elige tu personaje GM (paso 1) para poder responder y para que el ticket se marque como visto.');
    }
}

function renderTicket() {
    const t = current;
    $('ticket-title').textContent = `GM-${t.id} · ${t.name}`;
    const meta = [
        stateBadge(t),
        t.viewed ? '<span class="chip ok">Visto</span>' : '<span class="chip pend">Sin ver</span>',
        t.escalated ? '<span class="chip pend">Escalado</span>' : '',
        `<span class="chip"><span class="dot ${t.online ? 'on' : ''}"></span>${t.online ? 'Jugador conectado' : 'Jugador desconectado'}</span>`,
    ].join('');
    const hasResponse = !!t.response;
    $('ticket').innerHTML = `
        <div class="ticket-meta">${meta}</div>
        <dl class="info">
            <dt>Jugador</dt><dd><strong>${esc(t.name)}</strong> <span class="muted">· guid ${t.playerGuid}</span></dd>
            <dt>Creado</dt><dd>${esc(t.created)} <span class="muted">· modificado ${esc(t.modified)}</span></dd>
            <dt>Asignado a</dt><dd>${t.assignedTo ? esc(t.assigned_name ?? 'guid ' + t.assignedTo) : '<span class="muted">Nadie</span>'}</dd>
            ${t.comment ? `<dt>Comentario</dt><dd>${esc(t.comment)}</dd>` : ''}
        </dl>
        <div class="thread">
            <div class="bubble player">
                <div class="bubble-head">${avatar(t.name, { small: true, online: t.online })}<strong>${esc(t.name)}</strong><span class="muted">escribió</span></div>
                <div class="bubble-text">${esc(t.description)}</div>
            </div>
            <div class="bubble gm ${hasResponse ? '' : 'empty'}">
                <div class="bubble-head">
                    ${hasResponse
                        ? `<span class="muted">${num(t.response_bytes)} bytes</span><strong>${esc(t.responsedByName || 'Respuesta')}</strong>${avatar(t.responsedByName || 'G', { small: true, gm: true })}`
                        : '<span class="muted">Respuesta actual</span>'}
                </div>
                <div class="bubble-text">${hasResponse ? esc(t.response) : 'Todavía no se ha respondido a este ticket.'}</div>
            </div>
        </div>
        <div class="history" id="player-history"></div>`;
    loadPlayerHistory(t);
    showDialog();
    updateNav();
    $('requests-filter').textContent = $('only-ticket').checked ? `· ticket ${t.id}` : '';
    checkReply();
}

// Otros tickets del mismo personaje, para seguir el caso cuando pone varios
async function loadPlayerHistory(t) {
    const r = await api('player_tickets', { query: { guid: t.playerGuid, exclude: t.id }, showSql: false });
    if (!r.ok || current?.id !== t.id) return;
    $('player-history').innerHTML = `<h3>Otros tickets de ${esc(t.name)} <span class="muted small">${r.data.length ? `(${r.data.length})` : ''}</span></h3>`
        + (r.data.length
            ? '<div class="history-list">' + r.data.map((o) => `<button type="button" class="history-item" data-id="${o.id}">
                    <div class="row-top"><span class="key">GM-${o.id}</span>${stateBadge(o)}<span class="muted small">${esc(o.created)}</span></div>
                    <div>${esc(o.description)}</div>
                    ${o.response_bytes ? `<div class="muted small">${esc(o.responsedByName || 'Respuesta')}: ${esc(o.response_preview)}${o.response_bytes > 100 ? '…' : ''}</div>` : ''}
                </button>`).join('') + '</div>'
            : '<p class="muted small">No tiene otros tickets.</p>');
}

$('ticket').addEventListener('click', (e) => {
    const item = e.target.closest('.history-item');
    if (item) openTicket(+item.dataset.id, true);
});

// ---------------------------------------------------------------- Buscador

$('search-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await api('search', { query: { by: $('search-by').value, q: $('search-q').value } });
    if (!r.ok) {
        $('search-result').innerHTML = message('err', r.errors);
        $('search-wrap').hidden = true;
        return;
    }
    $('search-result').innerHTML = r.data.length ? '' : message('pend', 'No hay tickets con ese nombre.');
    $('search-wrap').hidden = !r.data.length;
    $('search-tickets').innerHTML = r.data.map((t) => `<tr class="clickable" data-id="${t.id}">
            <td class="key">GM-${t.id}</td>
            <td><div class="person">${avatar(t.name)}<div class="person-name">${esc(t.name)}</div></div></td>
            <td><span class="summary-link">${esc(t.description)}</span>${t.response_bytes ? `<div class="muted small">${esc(t.response_preview)}${t.response_bytes > 100 ? '…' : ''}</div>` : ''}</td>
            <td>${t.responsedByName ? esc(t.responsedByName) : '<span class="muted">—</span>'}</td>
            <td>${stateBadge(t)}</td>
            <td class="muted">${esc(t.created)}</td>
        </tr>`).join('');
});

$('search-tickets').addEventListener('click', (e) => {
    const row = e.target.closest('tr[data-id]');
    if (row) openTicket(+row.dataset.id, true);
});

// ---------------------------------------------------------------- Ventana emergente del ticket

function showDialog() {
    const dlg = $('ticket-card');
    if (!dlg.open) {
        dlg.showModal();
        $('reply').focus();
    }
}

// Tickets de la cola en el orden en que se ven, para ir al anterior / siguiente sin cerrar la ventana
const queueIds = () => [...document.querySelectorAll('#tickets tr[data-id]')].map((tr) => +tr.dataset.id);

function updateNav() {
    const ids = queueIds();
    const i = current ? ids.indexOf(current.id) : -1;
    $('ticket-pos').textContent = i >= 0 ? `${i + 1} de ${ids.length}` : '';
    $('btn-prev').disabled = i <= 0;
    $('btn-next').disabled = i < 0 || i >= ids.length - 1;
}

function goTo(step) {
    const ids = queueIds();
    const i = ids.indexOf(current?.id);
    const id = ids[i + step];
    if (i >= 0 && id !== undefined) openTicket(id, true);
}

$('btn-prev').addEventListener('click', () => goTo(-1));
$('btn-next').addEventListener('click', () => goTo(1));
$('btn-close').addEventListener('click', () => $('ticket-card').close());

// Clic fuera del contenido (en el fondo oscuro) también cierra
$('ticket-card').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) e.currentTarget.close();
});

// Al cerrar se vuelve a la cola tal como estaba
$('ticket-card').addEventListener('close', () => {
    current = null;
    waiting.clear();
    $('requests-filter').textContent = '';
    updateSteps();
    refreshTickets();
    refreshRequests();
});

// ---------------------------------------------------------------- Texto de la respuesta

// Mismas reglas que el servidor (gm_ticket_support.cpp, IsValidTicketText). Los límites son en bytes UTF-8.
function checkText(text, responseBytes) {
    const errors = [];
    const warnings = [];
    if (/[\u{10000}-\u{10FFFF}]/u.test(text)) errors.push('Tiene emojis u otros caracteres de 4 bytes, que el ticket no puede guardar.');
    if (text.includes('|')) errors.push('Tiene el carácter "|", que el cliente del juego interpreta como código de color o enlace.');
    if (/[\x00-\x09\x0B-\x1F\x7F-\x9F]/.test(text)) errors.push('Tiene caracteres de control (tabuladores u otros invisibles). Solo se admiten saltos de línea.');
    const bytes = new TextEncoder().encode(text).length;
    const total = responseBytes + bytes;
    if (total > limits.max_bytes) errors.push(`La respuesta quedaría en ${num(total)} bytes y el máximo es ${num(limits.max_bytes)}.`);
    else if (total > limits.warn_bytes) warnings.push(`La respuesta quedaría en ${num(total)} bytes: el juego la enviará en varios trozos y una tilde o eñe puede verse mal justo en el corte.`);
    return { bytes, total, errors, warnings };
}

const replyText = () => $('reply').value.replace(/\r\n?/g, '\n');

function checkReply() {
    if (!current) return;
    const text = replyText();
    const c = checkText(text, current.response_bytes);
    $('reply-bytes').textContent = text
        ? `${num(c.bytes)} bytes escritos · la respuesta ocuparía ${num(c.total)} de ${num(limits.max_bytes)}`
        : (current.response_bytes ? `La respuesta actual ocupa ${num(current.response_bytes)} de ${num(limits.max_bytes)} bytes` : '');
    const bar = $('reply-meter');
    bar.style.width = Math.min(100, (c.total / limits.max_bytes) * 100) + '%';
    bar.classList.toggle('err', c.total > limits.max_bytes);
    bar.classList.toggle('warn', c.total <= limits.max_bytes && c.total > limits.warn_bytes);
    $('reply-checks').innerHTML = (c.errors.length ? message('err', c.errors) : '') + (c.warnings.length ? message('pend', c.warnings) : '');
    updateButtons(c);
}

function updateButtons(c = null) {
    if (!current) return;
    c ??= checkText(replyText(), current.response_bytes);
    const skip = $('skip-checks').checked;
    const open = !current.closed && !current.completed;
    const busy = waiting.size > 0;
    const hasText = replyText() !== '';
    const ok = skip || (open && gmGuid() && !c.errors.length);
    $('btn-respond').disabled = busy || !ok || (!skip && !hasText);
    $('btn-complete').disabled = busy || !ok || (!skip && !hasText && !current.response_bytes);
}

$('reply').addEventListener('input', checkReply);
$('skip-checks').addEventListener('change', () => updateButtons());

// ---------------------------------------------------------------- Acciones

async function sendAction(action, text) {
    const r = await api(action, {
        body: {
            account: account(), gm_guid: gmGuid(), ticket_id: current.id, player_guid: current.playerGuid,
            text, skip_checks: $('skip-checks').checked,
        },
    });
    if (!r.ok) {
        $('action-result').innerHTML = message('err', r.errors);
        return;
    }
    const req = r.data.request;
    knownStatus.set(req.id, req.status);
    if (action === 'view') {
        refreshRequests(); // VIEW no bloquea los botones: el resultado se ve en la lista
        return;
    }
    waiting.set(req.id, text);
    $('action-result').innerHTML = message('pend', `Petición ${req.id} (${req.action_name}) enviada. Esperando a que el servidor la procese…`)
        + (r.data.warnings.length ? message('pend', r.data.warnings) : '');
    updateButtons();
    refreshRequests();
}

$('btn-respond').addEventListener('click', () => sendAction('respond', replyText()));
$('btn-complete').addEventListener('click', () => sendAction('complete', replyText()));

$('raw-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await api('raw', {
        body: {
            ticket_id: +$('raw-ticket').value, player_guid: +$('raw-player').value, action: +$('raw-action').value,
            gm_guid: +$('raw-gm').value, text: $('raw-text').value,
        },
    });
    $('raw-result').innerHTML = r.ok ? message('pend', `Petición ${r.data.request.id} insertada.`) : message('err', r.errors);
    refreshRequests();
});

$('only-ticket').addEventListener('change', () => {
    if (current) renderTicket();
    refreshRequests();
});

// ---------------------------------------------------------------- Peticiones (sondeo cada 3 s)

async function refreshRequests() {
    const ticket = $('only-ticket').checked && current ? current.id : 0;
    const r = await api('requests', { query: { ticket }, showSql: false });
    if (!r.ok) return;

    $('stat-pending').textContent = num(r.data.filter((req) => +req.status === 0).length);

    let currentChanged = false;
    $('requests').innerHTML = r.data.map((req) => {
        const prev = knownStatus.get(req.id);
        const changed = prev !== undefined && prev !== req.status;
        knownStatus.set(req.id, req.status);
        if (changed) {
            if (current && req.ticket_id === current.id) currentChanged = true;
            if (waiting.has(req.id)) {
                const sent = waiting.get(req.id);
                waiting.delete(req.id);
                finished(req, sent);
            }
        }
        const text = req.text ? `<div class="muted">${esc(req.text)}${req.text_bytes > 120 ? '…' : ''}</div>` : '';
        return `<tr class="${changed ? 'flash' : ''}">
            <td class="key">#${req.id}</td>
            <td><span class="key">GM-${req.ticket_id}</span><div class="muted">jugador ${req.player_guid}</div></td>
            <td><strong>${esc(req.action_name)}</strong>${text}</td>
            <td><div class="person">${avatar(req.gm_name ?? '?', { small: true, gm: true })}<div>${esc(req.gm_name ?? '?')}<div class="muted">guid ${req.gm_guid}</div></div></div></td>
            <td><span class="badge s${req.status}">${esc(req.status_name)}</span></td>
            <td>${esc(req.message)}${req.status ? `<div class="muted">código ${req.result_code}</div>` : ''}</td>
            <td class="muted">${esc(req.created_at)}</td>
            <td class="muted">${duration(req.wait_seconds)}</td>
        </tr>`;
    }).join('') || '<tr class="empty-row"><td colspan="8"><span class="big" aria-hidden="true">📭</span>Todavía no hay peticiones.</td></tr>';

    // Si el servidor acaba de procesar una petición del ticket abierto, se recarga (respuesta, estado…)
    if (currentChanged) openTicket(current.id, false);
}

// Resultado de una petición enviada desde esta página
function finished(req, sent) {
    const kind = req.status === 1 ? 'ok' : 'err';
    $('action-result').innerHTML = message(kind, `Petición ${req.id} (${req.action_name}): ${req.message}`);
    if (req.status === 1 && replyText() === sent) $('reply').value = ''; // solo si no ha seguido escribiendo
    updateButtons();
}

// ---------------------------------------------------------------- Inicio

// ---------------------------------------------------------------- Sesión

function showLogin() {
    const dlg = $('login-dialog');
    if ($('ticket-card').open) $('ticket-card').close();
    if (!dlg.open) dlg.showModal();
    $('login-user').focus();
}

// La ventana de inicio de sesión no se cierra con Esc
$('login-dialog').addEventListener('cancel', (e) => e.preventDefault());

$('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    $('btn-login').disabled = true;
    const r = await api('login', { body: { user: $('login-user').value, password: $('login-password').value }, showSql: false });
    $('btn-login').disabled = false;
    $('login-password').value = '';
    if (!r.ok) {
        $('login-result').innerHTML = message('err', r.errors);
        return;
    }
    $('login-result').innerHTML = '';
    $('login-dialog').close();
    applySession(r.data);
    startApp();
});

$('btn-logout').addEventListener('click', async () => {
    await api('logout', { body: {}, showSql: false });
    location.reload();
});

function applySession(s) {
    session = s;
    document.body.classList.toggle('staff', s.mode === 'staff');
    const logged = !!s.user;
    $('session-box').hidden = !logged;
    $('session-user').textContent = logged ? s.user : '';
    // Con sesión la cuenta es la del usuario: no se puede escribir otra
    $('account-form').hidden = logged;
    $('account-hint').hidden = logged;
}

// ---------------------------------------------------------------- Inicio

let started = false;

async function startApp() {
    if (started) return;
    started = true;
    updateSteps();
    await checkConnection();
    const savedAccount = session.account ?? store.get('account');
    if (savedAccount) {
        $('account').value = savedAccount;
        await loadGm(false);
    }
    refreshTickets();
    refreshRequests();
    setInterval(refreshRequests, 3000);
    setInterval(() => refreshTickets(), 10000);
}

(async () => {
    const r = await api('session', { showSql: false });
    applySession(r.data ?? session);
    if (!r.ok) {
        $('connection').innerHTML = message('err', r.errors);
        return;
    }
    if (session.auth_required && !session.user) showLogin();
    else startApp();
})();

