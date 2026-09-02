/* =====================================================================
 * Orion Buscas - Frontend
 * Painel do usuario (login, cadastro, consultas, historico, perfil)
 * e painel administrativo (APIs, usuarios, creditos, logs, config).
 * Vanilla JS + Fetch, sem frameworks e sem build.
 * ===================================================================== */
(function () {
    'use strict';

    var CFG = window.ORION || { csrf: '', isAdmin: false };
    var API_URL = 'api.php';

    /* ------------------------------------------------------- Utilitarios */
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function esc(v) {
        return String(v === null || v === undefined ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function setText(sel, value) { var el = $(sel); if (el) el.textContent = value; }

    var fmt = new Intl.NumberFormat('pt-BR');
    var dtf = new Intl.DateTimeFormat('pt-BR', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'
    });

    function parseDate(v) {
        if (!v) return null;
        var d = new Date(String(v).replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    }
    function fmtDateTime(v) { var d = parseDate(v); return d ? dtf.format(d) : (v || '—'); }
    function timeAgo(v) {
        var d = parseDate(v);
        if (!d) return 'Nenhuma atividade';
        var s = (Date.now() - d.getTime()) / 1000;
        if (s < 60) return 'Agora mesmo';
        if (s < 3600) return 'Há ' + Math.floor(s / 60) + ' min';
        if (s < 86400) return 'Há ' + Math.floor(s / 3600) + ' h';
        return dtf.format(d);
    }
    function initials(name) {
        var p = String(name || '').trim().split(/\s+/);
        var s = (p[0] ? p[0].charAt(0) : '') + (p.length > 1 ? p[p.length - 1].charAt(0) : '');
        return (s || '?').toUpperCase();
    }
    function isPlainObject(v) { return v !== null && typeof v === 'object' && !Array.isArray(v); }

    function icons() {
        if (window.lucide) { try { window.lucide.createIcons(); } catch (e) { /* noop */ } }
    }

    /* ------------------------------------------- Olho mostrar/esconder senha */
    function initPasswordToggle() {
        $$('input[data-pwd-toggle]').forEach(function (input) {
            if (input.parentElement && input.parentElement.classList.contains('pwd-wrap')) return;
            var wrap = document.createElement('div');
            wrap.className = 'pwd-wrap';
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pwd-eye';
            btn.setAttribute('aria-label', 'Mostrar senha');
            btn.title = 'Mostrar senha';
            btn.innerHTML = '<i data-lucide="eye"></i>';
            btn.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.innerHTML = '<i data-lucide="' + (show ? 'eye-off' : 'eye') + '"></i>';
                var label = show ? 'Esconder senha' : 'Mostrar senha';
                btn.setAttribute('aria-label', label);
                btn.title = label;
                icons();
                input.focus();
            });
            wrap.appendChild(btn);
        });
        icons();
    }

    /* ------------------------------------------------------ API (fetch) */
    async function api(action, data) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('csrf_token', CFG.csrf || '');
        if (data) {
            Object.keys(data).forEach(function (k) {
                var v = data[k];
                if (v === null || v === undefined) return;
                if (Array.isArray(v)) {
                    v.forEach(function (item) { body.append(k + '[]', String(item)); });
                } else {
                    body.set(k, String(v));
                }
            });
        }
        try {
            var res = await fetch(API_URL, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CFG.csrf || '' },
                body: body,
                credentials: 'same-origin'
            });
            if (res.status === 419) {
                window.location.reload();
                return { ok: false, status: 419, data: null, error: 'Sessão expirada.' };
            }
            var txt = await res.text();
            var payload = null;
            try { payload = JSON.parse(txt); } catch (e) { payload = null; }
            if (!payload || typeof payload !== 'object' || !('success' in payload)) {
                return {
                    ok: res.ok, status: res.status, data: null,
                    error: res.ok ? null : 'Resposta inesperada do servidor (HTTP ' + res.status + ').'
                };
            }
            return {
                ok: res.ok && payload.success === true,
                status: res.status,
                data: payload.data === undefined ? null : payload.data,
                error: payload.error === undefined ? null : payload.error
            };
        } catch (e) {
            return { ok: false, status: 0, data: null, error: 'Falha de conexão com o servidor.' };
        }
    }

    /* ---------------------------------------------------- Estados de UI */
    function setBusy(btn, busy) {
        if (!btn) return;
        btn.disabled = !!busy;
        btn.classList.toggle('is-loading', !!busy);
    }
    function showMsg(el, kind, text) {
        if (!el) return;
        el.textContent = text;
        el.classList.toggle('is-success', kind === 'ok');
        el.classList.toggle('is-error', kind !== 'ok');
        el.hidden = false;
    }
    function hideMsg(el) {
        if (!el) return;
        el.hidden = true;
        el.textContent = '';
        el.classList.remove('is-success', 'is-error');
    }
    function flashLabel(btn, text) {
        var span = btn.querySelector('span');
        if (!span) return;
        var old = span.textContent;
        span.textContent = text;
        setTimeout(function () { span.textContent = old; }, 1400);
    }
    function toast(text, ok) {
        var t = $('#orion-toast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'orion-toast';
            t.setAttribute('role', 'status');
            t.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);' +
                'z-index:99;max-width:min(92vw,480px);padding:11px 16px;border-radius:10px;' +
                'font-size:0.85rem;font-weight:500;box-shadow:0 10px 30px rgba(0,0,0,.45);' +
                'transition:opacity .25s ease;opacity:0;pointer-events:none;';
            document.body.appendChild(t);
        }
        t.textContent = text;
        t.style.background = ok ? 'rgba(62,207,154,.14)' : 'rgba(240,117,104,.14)';
        t.style.color = ok ? '#8fe3c2' : '#f2a9a2';
        t.style.border = '1px solid ' + (ok ? 'rgba(62,207,154,.4)' : 'rgba(240,117,104,.45)');
        t.style.opacity = '1';
        clearTimeout(t._timer);
        t._timer = setTimeout(function () { t.style.opacity = '0'; }, 3200);
    }

    /* ---------------------------------------------------------- Modais */
    function openModal(id) { var m = document.getElementById(id); if (m) m.hidden = false; }
    function closeModal(id) { var m = document.getElementById(id); if (m) m.hidden = true; }
    function closeTopModal() {
        var open = $$('.modal').filter(function (m) { return !m.hidden; });
        if (open.length) open[open.length - 1].hidden = true;
    }
    function initModals() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('[data-modal-close]') : null;
            if (btn) { closeModal(btn.getAttribute('data-modal-close')); return; }
            if (e.target.classList && e.target.classList.contains('modal')) e.target.hidden = true;
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                var sidebar = $('#sidebar');
                if (sidebar) { sidebar.classList.remove('is-open'); var b = $('#backdrop'); if (b) b.hidden = true; }
                closeTopModal();
            }
        });
    }
    var confirmCallback = null;
    function confirmAction(text, cb) {
        var t = $('#confirm-text');
        if (!t) { cb(); return; }
        t.textContent = text;
        confirmCallback = cb;
        openModal('confirm-modal');
    }
    function bindConfirm() {
        var ok = $('#confirm-ok');
        if (ok) ok.addEventListener('click', function () {
            var cb = confirmCallback;
            confirmCallback = null;
            closeModal('confirm-modal');
            if (cb) cb();
        });
    }

    /* -------------------------------------------------- Menu / navegação */
    function initSidebar() {
        var sidebar = $('#sidebar'), backdrop = $('#backdrop'),
            openBtn = $('#menu-btn'), closeBtn = $('#side-close');
        if (!sidebar) return;
        function setOpen(open) {
            sidebar.classList.toggle('is-open', open);
            if (backdrop) backdrop.hidden = !open;
            if (openBtn) openBtn.setAttribute('aria-expanded', String(open));
            if (closeBtn) closeBtn.hidden = !open;
        }
        if (openBtn) openBtn.addEventListener('click', function () { setOpen(true); });
        if (closeBtn) closeBtn.addEventListener('click', function () { setOpen(false); });
        if (backdrop) backdrop.addEventListener('click', function () { setOpen(false); });
    }
    var VIEW_LOADERS = {};
    function switchView(name) {
        $$('.view').forEach(function (v) { v.classList.toggle('active', v.id === 'view-' + name); });
        $$('.side-link[data-view]').forEach(function (l) { l.classList.toggle('active', l.dataset.view === name); });
        var load = VIEW_LOADERS[name];
        if (load) load();
    }
    function initNav() {
        $$('.side-link[data-view]').forEach(function (link) {
            link.addEventListener('click', function () { switchView(link.dataset.view); });
        });
        var logout = $('#logout-btn');
        if (logout) logout.addEventListener('click', doLogout);
    }
    async function doLogout() {
        await api('logout');
        window.location.href = CFG.isAdmin ? 'admin.php' : 'index.php';
    }

    /* ================================================== LOGIN / CADASTRO */
    function initAuthForms() {
        var loginForm = $('#login-form'), registerForm = $('#register-form');
        var tabs = $$('.auth-tab');
        var msg = $('#auth-msg');
        if (!loginForm && !registerForm) return; // pagina ja autenticada (dashboard)

        function show(view) {
            tabs.forEach(function (t) {
                var on = t.dataset.authView === view;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', String(on));
            });
            if (loginForm) loginForm.classList.toggle('is-active', view === 'login');
            if (registerForm) registerForm.classList.toggle('is-active', view === 'register');
            hideMsg(msg);
        }
        tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.authView); }); });

        if (loginForm) {
            loginForm.addEventListener('submit', async function (e) {
                e.preventDefault();
                hideMsg(msg);
                var btn = $('#login-btn');
                setBusy(btn, true);
                var r = await api('login', {
                    email: $('#login-email').value.trim(),
                    password: $('#login-password').value
                });
                setBusy(btn, false);
                if (r.ok) {
                    window.location.href = r.data.role === 'admin' ? 'admin.php' : 'index.php';
                    return;
                }
                showMsg(msg, 'err', r.error || 'Falha no login.');
            });
        }

        if (registerForm) {
            registerForm.addEventListener('submit', async function (e) {
                e.preventDefault();
                hideMsg(msg);
                var pass = $('#reg-password').value;
                var conf = $('#reg-confirm').value;
                if (pass !== conf) {
                    showMsg(msg, 'err', 'A senha e a confirmação precisam ser idênticas.');
                    return;
                }
                var btn = $('#register-btn');
                setBusy(btn, true);
                var r = await api('register', {
                    name: $('#reg-name').value.trim(),
                    email: $('#reg-email').value.trim(),
                    password: pass,
                    confirm_password: conf
                });
                setBusy(btn, false);
                if (r.ok) { window.location.href = 'index.php'; return; }
                showMsg(msg, 'err', r.error || 'Falha ao criar a conta.');
            });
        }
    }

    /* ===================================================== DASHBOARD USUÁRIO */
    function renderCredits(n) {
        setText('#credits-value', fmt.format(n));
        setText('#stat-credits', fmt.format(n));
        $$('[data-credit-note]').forEach(function (el) {
            el.textContent = 'Saldo: ' + fmt.format(n) + ' créditos';
        });
    }

    async function loadMe() {
        var r = await api('me');
        if (!r.ok) {
            if (r.status === 302 || r.status === 0) window.location.href = 'index.php';
            return;
        }
        currentUser = r.data;
        renderCredits(r.data.credits);
        setText('#stat-queries', fmt.format(r.data.stats.total_queries || 0));
        setText('#stat-last', timeAgo(r.data.stats.last_activity));
    }

    /* ================================== Consultas: menu horizontal + pagina por consulta
     * Um menu estilo landing page lista cada consulta na aba Consultas;
     * ao selecionar, a pagina da consulta abre abaixo com um unico campo
     * e o retorno da API renderizado em seguida.
     */
    var servicesCache = [];
    var currentUser = null;
    var consultasCurrent = null;   // id da consulta aberta
    var consultasRendered = null;  // id cuja pagina esta montada no DOM

    async function loadServices() {
        setText('#stat-services', '—');
        var box = $('#svc-cards');
        if (!box) return;
        var r = await api('services');
        if (!r.ok || !r.data.length) {
            setText('#stat-services', '0');
            box.innerHTML = '<div class="result-empty"><i data-lucide="search-x"></i><p>Nenhum serviço disponível no momento.</p></div>';
            icons();
            return;
        }
        setText('#stat-services', fmt.format(r.data.length));
        var same = servicesCache.length === r.data.length &&
            r.data.every(function (s, i) { return servicesCache[i] && servicesCache[i].id === s.id; });
        servicesCache = r.data;
        if (!consultasCurrent || !findService(consultasCurrent)) consultasCurrent = r.data[0].id;
        if (!same) consultasRendered = null; // a lista de consultas mudou: reconstruir
        if (!consultasRendered) {
            renderConsultas(box);
        } else {
            markActiveTab();
        }
        if (currentUser) renderCredits(currentUser.credits);
    }

    function findService(id) {
        var sid = parseInt(id, 10);
        return servicesCache.filter(function (s) { return s.id === sid; })[0] || null;
    }

    /* Rótulo curto para o menu (ex.: "Consulta CPF" -> "CPF"). */
    function tabLabel(s) {
        return s.name.replace(/^consulta\s+/i, '').replace(/^por\s+/i, '');
    }

    function renderConsultas(box) {
        box.innerHTML =
            '<nav class="svc-tabs" role="tablist" aria-label="Consultas">' +
            servicesCache.map(function (s) {
                return '<button type="button" class="svc-tab" role="tab" data-svc-id="' + s.id + '" ' +
                    'title="' + esc(s.name) + '" aria-selected="false">' + esc(tabLabel(s)) + '</button>';
            }).join('') +
            '</nav>' +
            '<div class="svc-page-host" id="svc-page-host"></div>';
        $$('.svc-tab', box).forEach(function (t) {
            t.addEventListener('click', function () {
                openConsulta(parseInt(t.dataset.svcId, 10));
            });
        });
        openConsulta(consultasCurrent);
    }

    function markActiveTab() {
        $$('.svc-tab').forEach(function (t) {
            var on = String(t.dataset.svcId) === String(consultasCurrent);
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', String(on));
        });
    }

    function openConsulta(id) {
        var svc = findService(id);
        if (!svc) return;
        consultasCurrent = svc.id;
        markActiveTab();
        var host = $('#svc-page-host');
        if (host) renderServicePage(host, svc);
    }

    function renderServicePage(box, svc) {
        var p = svc.parameters[0] || {};
        box.innerHTML =
            '<article class="svc-page" data-svc-id="' + svc.id + '">' +
                '<header class="svc-page-head">' +
                    '<div class="svc-page-title">' +
                        '<h2 class="svc-page-name">' + esc(svc.name) + '</h2>' +
                        (svc.description ? '<p class="svc-page-desc">' + esc(svc.description) + '</p>' : '') +
                        '<span class="svc-page-site">' +
                            '<span class="badge badge-cost">' + fmt.format(svc.cost) + ' crédito' + (svc.cost === 1 ? '' : 's') + '</span>' +
                        '</span>' +
                    '</div>' +
                '</header>' +
                '<form class="svc-page-form" id="svc-page-form" novalidate>' +
                    '<div class="field">' +
                        '<label for="qf-' + esc(p.key) + '">' + esc(p.label || svc.name) + '</label>' +
                        '<input type="text" id="qf-' + esc(p.key) + '" data-param="' + esc(p.key) + '" ' +
                        'maxlength="190" autocomplete="off" placeholder="' + esc(p.placeholder || '') + '"' +
                        (p.required ? ' required' : '') + '>' +
                    '</div>' +
                    '<button type="submit" class="btn btn-primary btn-block svc-run-btn">' +
                        '<i data-lucide="search" class="icon-sm"></i>' +
                        '<span class="btn-label">Consultar</span>' +
                    '</button>' +
                    '<div class="form-msg svc-card-msg" role="alert" hidden></div>' +
                '</form>' +
                '<div class="svc-result-host"></div>' +
            '</article>';
        icons();
        $('#svc-page-form', box).addEventListener('submit', function (e) {
            e.preventDefault();
            submitQuery(svc, $('.svc-page', box));
        });
        consultasRendered = svc.id;
    }

    async function submitQuery(svc, card) {
        var msg = $('.svc-card-msg', card);
        hideMsg(msg);
        var values = {};
        var missing = null;
        $$('[data-param]', card).forEach(function (inp) {
            values[inp.dataset.param] = inp.value.trim();
            if (!values[inp.dataset.param] && inp.hasAttribute('required') && !missing) missing = inp;
        });
        if (missing) {
            showMsg(msg, 'err', 'Preencha os campos obrigatórios.');
            missing.focus();
            return;
        }
        setBusy($('.svc-run-btn', card), true);
        var r = await api('query', { api_id: svc.id, values: JSON.stringify(values) });
        setBusy($('.svc-run-btn', card), false);
        if (r.ok) {
            var credits = r.data && typeof r.data.credits === 'number' ? r.data.credits : (currentUser ? currentUser.credits - svc.cost : 0);
            if (currentUser) { currentUser.credits = credits; renderCredits(credits); }
            renderResult(svc.name, r.data.result, {
                empty: !!(r.data && r.data.empty),
                notice: r.error,
                host: $('.svc-result-host', card)
            });
        } else {
            if (r.data && typeof r.data.credits === 'number' && currentUser) {
                currentUser.credits = r.data.credits;
                renderCredits(r.data.credits);
            }
            showMsg(msg, 'err', r.error || 'Não foi possível realizar a consulta.');
        }
    }

    /* ------------------------------------------------ Render do resultado */
    function removeResult(host) {
        var h = host || $('.query-panel');
        if (!h) return;
        $$('.result-panel', h).forEach(function (p) { p.remove(); });
    }
    function renderResult(title, data, opts) {
        opts = opts || {};
        var host = opts.host || $('.query-panel');
        removeResult(host);
        var panel = document.createElement('div');
        panel.className = 'result-panel';
        var raw = JSON.stringify(data, null, 2);

        var head = document.createElement('div');
        head.className = 'result-head';
        head.innerHTML =
            '<div class="result-head-title"><i data-lucide="table-2"></i><span>' + esc(title) + '</span></div>' +
            '<div class="result-actions">' +
                '<button type="button" class="btn btn-ghost btn-sm" data-act="raw"><i data-lucide="braces" class="icon-sm"></i><span>Ver JSON bruto</span></button>' +
                '<button type="button" class="btn btn-ghost btn-sm" data-act="copy"><i data-lucide="copy" class="icon-sm"></i><span>Copiar JSON</span></button>' +
            '</div>';

        var body = document.createElement('div');
        body.className = 'result-body';
        body.innerHTML = renderData(data, opts);

        var rawBox = document.createElement('div');
        rawBox.className = 'raw-json';
        rawBox.hidden = true;
        var pre = document.createElement('pre');
        pre.textContent = raw;
        rawBox.appendChild(pre);

        head.querySelector('[data-act="raw"]').addEventListener('click', function () {
            var open = !rawBox.hidden;
            rawBox.hidden = open;
            rawBox.classList.toggle('is-open', !open);
        });
        head.querySelector('[data-act="copy"]').addEventListener('click', function () {
            var btn = this;
            copyText(raw).then(function (ok) {
                flashLabel(btn, ok ? 'Copiado!' : 'Erro');
            });
        });

        panel.appendChild(head);
        panel.appendChild(body);
        panel.appendChild(rawBox);
        host.appendChild(panel);
        icons();
    }

    function renderData(data, opts) {
        opts = opts || {};
        if (data === null || data === undefined || data === '' ||
            (Array.isArray(data) && !data.length) ||
            (isPlainObject(data) && !Object.keys(data).length)) {
            return '<div class="result-empty"><i data-lucide="search-x"></i><p>' +
                esc(opts.notice || 'Nenhum resultado encontrado para a consulta.') + '</p></div>';
        }
        if (Array.isArray(data)) {
            if (data.length && data.every(isPlainObject)) {
                var cols = [];
                data.slice(0, 8).forEach(function (row) {
                    Object.keys(row).forEach(function (k) {
                        if (cols.indexOf(k) === -1) cols.push(k);
                    });
                });
                return '<div class="result-table-wrap"><table class="result-table">' +
                    '<thead><tr>' + cols.map(function (c) { return '<th scope="col">' + esc(c) + '</th>'; }).join('') + '</tr></thead>' +
                    '<tbody>' + data.map(function (row) {
                        return '<tr>' + cols.map(function (c) {
                            return '<td>' + esc(formatCell(row[c])) + '</td>';
                        }).join('') + '</tr>';
                    }).join('') + '</tbody></table></div>' +
                    (data.length > 8 ? '<p class="cell-sub" style="margin-top:10px">Mostrando ' + 8 + ' de ' + data.length + ' registros.</p>' : '');
            }
            // lista de escalares
            return '<ul class="result-list">' + data.map(function (v) {
                return '<li>' + esc(formatCell(v)) + '</li>';
            }).join('') + '</ul>';
        }
        if (isPlainObject(data)) {
            return renderResultSections(data);
        }
        return '<div class="result-list"><li>' + esc(formatCell(data)) + '</li></div>';
    }
    /* Layout por secoes: objetos viram grid de chaves, arrays viram tabelas. */
    function renderResultSections(obj) {
        // Objetos totalmente planos (ex.: telefone0.php, ViaCEP) viram um unico grid.
        var flat = Object.keys(obj).every(function (k) { return !isPlainObject(obj[k]) && !Array.isArray(obj[k]); });
        if (flat) {
            var skip = ['sucesso', 'mensagem', 'tipo_busca', 'erro', 'data_consulta', 'tempo_resposta'];
            var main = {};
            Object.keys(obj).forEach(function (k) {
                if (skip.indexOf(k) === -1) main[k] = obj[k];
            });
            if (Object.keys(main).length) return objectFields(main);
        }
        var order = ['dados', 'email', 'enderecos', 'telefone', 'score', 'parentes', 'pis', 'poder_aquisitivo', 'tse'];
        var keys = Object.keys(obj);
        var ordered = keys.filter(function (k) { return order.indexOf(k) !== -1; })
            .sort(function (a, b) { return order.indexOf(a) - order.indexOf(b); });
        var extra = keys.filter(function (k) { return order.indexOf(k) === -1; });
        var html = '';
        ordered.concat(extra).forEach(function (k) {
            var v = obj[k];
            if (v === null || v === undefined) return;
            if (isPlainObject(v)) {
                if (!Object.keys(v).length) return;
                html += '<section class="result-section"><h4 class="section-title">' + esc(k) + '</h4>' +
                    objectFields(v) + '</section>';
            } else if (Array.isArray(v)) {
                if (!v.length) return;
                html += '<section class="result-section"><h4 class="section-title">' + esc(k) +
                    ' <small>(' + v.length + ')</small></h4>' + tableFor(v) + '</section>';
            } else {
                html += '<section class="result-section">' + objectFields({ '': v }) + '</section>';
            }
        });
        return html !== '' ? html : '<div class="result-empty"><i data-lucide="search-x"></i><p>Resultado vazio.</p></div>';
    }
    function objectFields(obj) {
        var entries = Object.keys(obj).map(function (k) {
            return '<div class="kv-item"><span class="kv-key">' + esc(k === '' ? 'Valor' : k) + '</span>' +
                '<span class="kv-value">' + esc(formatCell(obj[k])) + '</span></div>';
        });
        if (!entries.length) entries.push('<div class="kv-item"><span class="kv-value">—</span></div>');
        return '<div class="kv-grid">' + entries.join('') + '</div>';
    }
    function tableFor(rows) {
        if (rows.length && rows.every(isPlainObject)) {
            var cols = [];
            rows.slice(0, 10).forEach(function (row) {
                Object.keys(row).forEach(function (k) {
                    if (cols.indexOf(k) === -1) cols.push(k);
                });
            });
            var thead = '<tr>' + cols.map(function (c) { return '<th scope="col">' + esc(c) + '</th>'; }).join('') + '</tr>';
            var tbody = rows.map(function (r) {
                return '<tr>' + cols.map(function (c) { return '<td>' + esc(formatCell(r[c])) + '</td>'; }).join('') + '</tr>';
            }).join('');
            return '<div class="result-table-wrap"><table class="result-table"><thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table></div>';
        }
        return '<ul class="result-list">' + rows.map(function (r) { return '<li>' + esc(formatCell(r)) + '</li>'; }).join('') + '</ul>';
    }
    function formatCell(v) {
        if (v === null || v === undefined) return '—';
        if (typeof v === 'object') return JSON.stringify(v);
        return String(v);
    }
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
        return new Promise(function (resolve) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;opacity:0;';
            document.body.appendChild(ta);
            ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            ta.remove();
            resolve(ok);
        });
    }

    /* -------------------------------------------------------- Histórico */
    function historyRows(rows) {
        if (!rows.length) return '<tr><td colspan="5" class="cell-muted">Nenhuma consulta realizada ainda.</td></tr>';
        return rows.map(function (row) {
            return '<tr>' +
                '<td class="cell-strong">' + esc(row.service_name) + '</td>' +
                '<td>' + (row.queried_value ? esc(row.queried_value) : '<span class="cell-sub">—</span>') + '</td>' +
                '<td class="cell-sub">' + fmtDateTime(row.created_at) + '</td>' +
                '<td>' + statusBadge(row.status) + '</td>' +
                '<td class="num">' + esc(row.cost) + '</td>' +
                '</tr>';
        }).join('');
    }
    function statusBadge(s) {
        if (s === 'success') return '<span class="badge badge-success">Sucesso</span>';
        if (s === 'error') return '<span class="badge badge-danger">Erro</span>';
        return '<span class="badge badge-warn">Vazio</span>';
    }
    async function loadHistory() {
        var body = $('#history-body');
        if (!body) return;
        var r = await api('history');
        if (!r.ok) { body.innerHTML = '<tr><td colspan="5" class="cell-muted">Erro ao carregar o histórico.</td></tr>'; return; }
        body.innerHTML = historyRows(r.data || []);
    }

    /* ------------------------------------------------------------- Perfil */
    function initProfileForm() {
        var form = $('#profile-form');
        if (!form) return;
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            var msg = $('#profile-msg');
            hideMsg(msg);
            var newPass = $('#pf-new').value;
            var conf = $('#pf-confirm').value;
            if (newPass !== conf) {
                showMsg(msg, 'err', 'A nova senha e a confirmação precisam ser idênticas.');
                return;
            }
            if (newPass && newPass.length < 8) {
                showMsg(msg, 'err', 'A nova senha precisa ter ao menos 8 caracteres.');
                return;
            }
            var r = await api('profile', {
                name: form.elements.name.value.trim(),
                email: form.elements.email.value.trim(),
                current_password: $('#pf-current').value,
                new_password: newPass,
                confirm_password: conf
            });
            if (r.ok) {
                if (currentUser) currentUser = r.data;
                showMsg(msg, 'ok', 'Dados atualizados com sucesso.');
                form.reset();
                if (window.lucide) { /* re-render não necessário */ }
                setTimeout(function () { window.location.reload(); }, 900);
            } else {
                showMsg(msg, 'err', r.error || 'Não foi possível salvar.');
            }
        });
    }

    /* =========================================================== ADMIN: DASHBOARD */
    async function loadAdminStats() {
        var r = await api('admin_stats');
        if (!r.ok) return;
        setText('#ad-users', fmt.format(r.data.users));
        setText('#ad-apis', fmt.format(r.data.active_apis) + ' / ' + fmt.format(r.data.total_apis));
        setText('#ad-queries', fmt.format(r.data.total_queries));
        setText('#ad-consumed', fmt.format(r.data.credits_used));
        setText('#ad-errors', fmt.format(r.data.errors));
        var list = $('#activity-list');
        if (!list) return;
        if (!r.data.recent.length) {
            list.innerHTML = '<li class="cell-muted">Nenhuma atividade ainda.</li>';
            return;
        }
        list.innerHTML = r.data.recent.map(function (row) {
            var dot = row.status === 'success' ? 'is-success' : (row.status === 'error' ? 'is-error' : 'is-empty');
            return '<li>' +
                '<span class="activity-dot ' + dot + '"></span>' +
                '<span class="activity-text"><strong>' + esc(row.user_name || '—') + '</strong> · ' + esc(row.service_name) + '</span>' +
                '<span class="activity-time">' + fmtDateTime(row.created_at) + '</span>' +
                '</li>';
        }).join('');
    }

    /* ============================================================= ADMIN: APIS */
    var servicesFull = [];
    async function loadAdminServices() {
        var body = $('#apis-body');
        if (!body) return;
        var r = await api('admin_services');
        if (!r.ok) { body.innerHTML = '<tr><td colspan="6" class="cell-muted">Erro ao carregar as APIs.</td></tr>'; return; }
        servicesFull = r.data || [];
        if (!servicesFull.length) {
            body.innerHTML = '<tr><td colspan="6" class="cell-muted">Nenhuma API cadastrada. Clique em "Adicionar API".</td></tr>';
            return;
        }
        body.innerHTML = servicesFull.map(function (s) {
            var nParams = (s.parameters || []).length;
            return '<tr>' +
                '<td><div class="cell-name"><div class="cell-name-text"><strong>' + esc(s.name) + '</strong>' +
                    (s.description ? '<span class="cell-sub">' + esc(s.description) + '</span>' : '') +
                    '</div></div></td>' +
                '<td>' + (nParams ? nParams + ' par.' : '<span class="cell-sub">—</span>') + '</td>' +
                '<td><span class="badge badge-muted">' + esc(s.method) + '</span></td>' +
                '<td class="num">' + esc(s.cost) + '</td>' +
                '<td>' + (s.status === 'active' ? '<span class="badge badge-success">Ativa</span>' : '<span class="badge badge-muted">Inativa</span>') + '</td>' +
                '<td><div class="row-actions">' +
                    '<button type="button" class="icon-btn icon-btn-sm" data-api-edit="' + s.id + '" title="Editar"><i data-lucide="pencil"></i></button>' +
                    '<button type="button" class="icon-btn icon-btn-sm" data-api-toggle="' + s.id + '" title="' + (s.status === 'active' ? 'Desativar' : 'Ativar') + '"><i data-lucide="' + (s.status === 'active' ? 'pause' : 'play') + '"></i></button>' +
                    '<button type="button" class="icon-btn icon-btn-sm" data-api-del="' + s.id + '" title="Excluir"><i data-lucide="trash-2"></i></button>' +
                '</div></td>' +
                '</tr>';
        }).join('');
        icons();
        $$('#apis-body [data-api-edit]').forEach(function (b) { b.addEventListener('click', function () { openApiModal(parseInt(b.dataset.apiEdit, 10)); }); });
        $$('#apis-body [data-api-toggle]').forEach(function (b) { b.addEventListener('click', function () { toggleApi(parseInt(b.dataset.apiToggle, 10)); }); });
        $$('#apis-body [data-api-del]').forEach(function (b) { b.addEventListener('click', function () { delApi(parseInt(b.dataset.apiDel, 10)); }); });
    }
    async function toggleApi(id) {
        var svc = servicesFull.filter(function (s) { return s.id === id; })[0];
        if (!svc) return;
        var r = await api('admin_service_toggle', { service_id: id, status: svc.status === 'active' ? 'inactive' : 'active' });
        if (r.ok) { loadAdminServices(); } else { toast(r.error || 'Falha ao alterar status.', false); }
    }
    function delApi(id) {
        var svc = servicesFull.filter(function (s) { return s.id === id; })[0];
        confirmAction('Excluir a API "' + (svc ? svc.name : '') + '"? Esta ação não pode ser desfeita.', async function () {
            var r = await api('admin_service_delete', { service_id: id });
            if (r.ok) { loadAdminServices(); } else { toast(r.error || 'Falha ao excluir.', false); }
        });
    }

    function paramRowHtml(key, label, placeholder, required) {
        key = key || ''; label = label || ''; placeholder = placeholder || ''; required = required ? ' checked' : '';
        return '<div class="param-row">' +
            '<div class="field"><label>Chave</label><input type="text" class="pk" maxlength="64" placeholder="cidade" value="' + esc(key) + '" required></div>' +
            '<div class="field"><label>Rótulo</label><input type="text" class="pl" maxlength="120" placeholder="Cidade" value="' + esc(label) + '" required></div>' +
            '<div class="field"><label>Placeholder</label><input type="text" class="pp" maxlength="120" placeholder="Ex.: Fortaleza" value="' + esc(placeholder) + '"></div>' +
            '<label class="param-req" title="Obrigatório"><input type="checkbox" class="pq"' + required + '></label>' +
            '<button type="button" class="icon-btn icon-btn-sm param-remove" title="Remover"><i data-lucide="x"></i></button>' +
            '</div>';
    }
    function initParamRows() {
        var add = $('#param-add-btn'), list = $('#params-list');
        if (!add || !list) return;
        add.addEventListener('click', function () {
            list.insertAdjacentHTML('beforeend', paramRowHtml());
            icons();
        });
        list.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.param-remove') : null;
            if (btn && btn.parentNode) btn.parentNode.remove();
        });
    }
    function readParamRows() {
        var keys = [], labels = [], phs = [], reqs = [];
        $$('#params-list .param-row').forEach(function (row) {
            var k = $('.pk', row).value.trim();
            var l = $('.pl', row).value.trim();
            if (!k && !l) return;
            keys.push(k); labels.push(l);
            phs.push($('.pp', row).value.trim());
            reqs.push($('.pq', row).checked ? '1' : '0');
        });
        return { keys: keys, labels: labels, phs: phs, reqs: reqs };
    }
    function openApiModal(id) {
        var form = $('#api-form');
        form.reset();
        $('#api-id').value = id || 0;
        $('#api-modal-title').textContent = id ? 'Editar API' : 'Adicionar API';
        hideMsg($('#api-msg'));
        $('#params-list').innerHTML = '';
        if (id) {
            var s = servicesFull.filter(function (x) { return x.id === id; })[0];
            if (s) {
                form.elements.name.value = s.name;
                form.elements.description.value = s.description || '';
                form.elements.endpoint.value = s.endpoint;
                form.elements.method.value = s.method;
                form.elements.cost.value = s.cost;
                form.elements.status.value = s.status;
                form.elements.api_key.value = '';
                (s.parameters || []).forEach(function (p) {
                    $('#params-list').insertAdjacentHTML('beforeend',
                        paramRowHtml(p.param_key, p.label, p.placeholder, p.required));
                });
            }
        }
        openModal('api-modal');
        icons();
    }
    function initApiForm() {
        var form = $('#api-form');
        if (!form) return;
        var newBtn = $('#api-new-btn');
        if (newBtn) newBtn.addEventListener('click', function () { openApiModal(null); });
        initParamRows();
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            var msg = $('#api-msg');
            hideMsg(msg);
            var p = readParamRows();
            var btn = $('#api-save-btn');
            setBusy(btn, true);
            var r = await api('admin_service_save', {
                service_id: $('#api-id').value || 0,
                name: form.elements.name.value.trim(),
                description: form.elements.description.value.trim(),
                endpoint: form.elements.endpoint.value.trim(),
                method: form.elements.method.value,
                cost: form.elements.cost.value,
                status: form.elements.status.value,
                api_key: form.elements.api_key.value.trim(),
                param_keys: p.keys,
                param_labels: p.labels,
                param_placeholders: p.phs,
                param_required: p.reqs
            });
            setBusy(btn, false);
            if (r.ok) {
                closeModal('api-modal');
                loadAdminServices();
            } else {
                showMsg(msg, 'err', r.error || 'Falha ao salvar a API.');
            }
        });
    }

    /* ========================================================== ADMIN: USUÁRIOS */
    var usersFull = [];
    async function loadAdminUsers() {
        var body = $('#users-body');
        if (!body) return;
        var r = await api('admin_users');
        if (!r.ok) { body.innerHTML = '<tr><td colspan="6" class="cell-muted">Erro ao carregar usuários.</td></tr>'; return; }
        usersFull = r.data || [];
        body.innerHTML = usersFull.map(function (u) {
            return '<tr>' +
                '<td><div class="cell-name"><span class="avatar">' + esc(initials(u.name)) + '</span>' +
                    '<div class="cell-name-text"><strong>' + esc(u.name) + '</strong>' +
                    '<span class="cell-sub">' + esc(u.email) + '</span></div></div></td>' +
                '<td>' + (u.role === 'admin' ? '<span class="badge badge-warn">Admin</span>' : '<span class="badge badge-muted">Usuário</span>') + '</td>' +
                '<td class="num cell-strong">' + fmt.format(u.credits) + '</td>' +
                '<td>' + (u.status === 'active' ? '<span class="badge badge-success">Ativo</span>' : '<span class="badge badge-danger">Bloqueado</span>') + '</td>' +
                '<td class="cell-sub">' + timeAgo(u.last_login) + '</td>' +
                '<td><div class="row-actions">' +
                    '<button type="button" class="icon-btn icon-btn-sm" data-usr-hist="' + u.id + '" title="Histórico"><i data-lucide="history"></i></button>' +
                    '<button type="button" class="icon-btn icon-btn-sm" data-usr-cred="' + u.id + '" title="Créditos"><i data-lucide="wallet"></i></button>' +
                    '<button type="button" class="icon-btn icon-btn-sm" data-usr-edit="' + u.id + '" title="Editar"><i data-lucide="pencil"></i></button>' +
                    '<button type="button" class="icon-btn icon-btn-sm" data-usr-del="' + u.id + '" title="Excluir"><i data-lucide="trash-2"></i></button>' +
                '</div></td>' +
                '</tr>';
        }).join('');
        icons();
        $$('#users-body [data-usr-hist]').forEach(function (b) { b.addEventListener('click', function () { openUserHistory(parseInt(b.dataset.usrHist, 10)); }); });
        $$('#users-body [data-usr-cred]').forEach(function (b) { b.addEventListener('click', function () { openCreditModal(parseInt(b.dataset.usrCred, 10)); }); });
        $$('#users-body [data-usr-edit]').forEach(function (b) { b.addEventListener('click', function () { openUserModal(parseInt(b.dataset.usrEdit, 10)); }); });
        $$('#users-body [data-usr-del]').forEach(function (b) { b.addEventListener('click', function () { delUser(parseInt(b.dataset.usrDel, 10)); }); });
    }
    function delUser(id) {
        var u = usersFull.filter(function (x) { return x.id === id; })[0];
        confirmAction('Excluir o usuário "' + (u ? u.name : '') + '"? O histórico e os créditos serão removidos.', async function () {
            var r = await api('admin_user_delete', { user_id: id });
            if (r.ok) { loadAdminUsers(); } else { toast(r.error || 'Falha ao excluir.', false); }
        });
    }
    function openUserModal(id) {
        var form = $('#user-form');
        form.reset();
        var create = !id;
        $('#user-id').value = id || 0;
        $('#user-modal-title').textContent = create ? 'Novo usuário' : 'Editar usuário';
        $('#user-credits').value = CFG.defaultCredits || 0;
        // criação: mostra créditos; edição: esconde
        $('#user-default-credits-field').hidden = !create;
        // senha: obrigatória na criação; opcional na edição
        $('#user-pass').required = create;
        $('#user-pass-hint').textContent = create ? 'Mínimo 8 caracteres.' : 'Deixe em branco para manter a senha atual.';
        hideMsg($('#user-msg'));
        if (!create) {
            var u = usersFull.filter(function (x) { return x.id === id; })[0];
            if (u) {
                form.elements.name.value = u.name;
                form.elements.email.value = u.email;
                form.elements.role.value = u.role;
                form.elements.status.value = u.status;
            }
        }
        openModal('user-modal');
        icons();
    }
    function initUserForm() {
        var form = $('#user-form');
        if (!form) return;
        var newBtn = $('#user-new-btn');
        if (newBtn) newBtn.addEventListener('click', function () { openUserModal(null); });
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            var msg = $('#user-msg');
            hideMsg(msg);
            var id = parseInt($('#user-id').value, 10) || 0;
            var pass = form.elements.password.value;
            var btn = $('#user-save-btn');
            setBusy(btn, true);
            var r;
            if (!id) {
                if (pass.length < 8) {
                    setBusy(btn, false);
                    showMsg(msg, 'err', 'A senha precisa ter ao menos 8 caracteres.');
                    return;
                }
                r = await api('admin_user_create', {
                    name: form.elements.name.value.trim(),
                    email: form.elements.email.value.trim(),
                    password: pass,
                    role: form.elements.role.value,
                    credits: form.elements.credits.value
                });
            } else {
                r = await api('admin_user_update', {
                    user_id: id,
                    name: form.elements.name.value.trim(),
                    email: form.elements.email.value.trim(),
                    role: form.elements.role.value,
                    status: form.elements.status.value
                });
                if (r.ok && pass) {
                    var r2 = await api('admin_user_password', { user_id: id, password: pass });
                    if (!r2.ok) { showMsg(msg, 'err', r2.error || 'Usuário salvo, mas a senha não foi alterada.'); setBusy(btn, false); return; }
                }
            }
            setBusy(btn, false);
            if (r.ok) {
                closeModal('user-modal');
                loadAdminUsers();
            } else {
                showMsg(msg, 'err', r.error || 'Falha ao salvar o usuário.');
            }
        });
    }

    /* ====================================================== ADMIN: CRÉDITOS / HISTÓRICO / LOGS / CONFIG */
    function openCreditModal(id) {
        var u = usersFull.filter(function (x) { return x.id === id; })[0];
        if (!u) return;
        $('#credit-user-id').value = id;
        $('#credit-target').textContent = u.name + ' (' + u.email + ') — saldo atual: ' + fmt.format(u.credits) + ' créditos.';
        $('#credit-amount').value = '10';
        $('#credit-op').value = 'add';
        hideMsg($('#credit-msg'));
        openModal('credit-modal');
    }
    function initCreditForm() {
        var form = $('#credit-form');
        if (!form) return;
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            var msg = $('#credit-msg');
            hideMsg(msg);
            var r = await api('admin_credit_move', {
                user_id: $('#credit-user-id').value,
                op: $('#credit-op').value,
                amount: $('#credit-amount').value,
                reason: $('#credit-reason').value.trim()
            });
            if (r.ok) {
                closeModal('credit-modal');
                loadAdminUsers();
            } else {
                showMsg(msg, 'err', r.error || 'Falha na movimentação.');
            }
        });
    }
    async function openUserHistory(id) {
        var u = usersFull.filter(function (x) { return x.id === id; })[0];
        setText('#hist-modal-title', 'Histórico — ' + (u ? u.name : ''));
        openModal('hist-modal');
        var body = $('#hist-body');
        if (body) body.innerHTML = '<tr><td colspan="5" class="cell-muted">Carregando...</td></tr>';
        var r = await api('admin_user_history', { user_id: id });
        if (!body) return;
        if (!r.ok) { body.innerHTML = '<tr><td colspan="5" class="cell-muted">Erro ao carregar o histórico.</td></tr>'; return; }
        body.innerHTML = historyRows(r.data || []);
    }
    async function loadAdminCredits() {
        var body = $('#credits-body');
        if (!body) return;
        var r = await api('admin_credits');
        if (!r.ok) { body.innerHTML = '<tr><td colspan="7" class="cell-muted">Erro ao carregar as movimentações.</td></tr>'; return; }
        if (!r.data.length) { body.innerHTML = '<tr><td colspan="7" class="cell-muted">Nenhuma movimentação registrada.</td></tr>'; return; }
        body.innerHTML = r.data.map(function (t) {
            var badge = t.type === 'grant' ? '<span class="badge badge-success">Entrada</span>'
                : (t.type === 'revoke' ? '<span class="badge badge-danger">Saída</span>'
                : '<span class="badge badge-muted">Consumo</span>');
            var sign = t.amount > 0 ? '+' : '';
            return '<tr>' +
                '<td class="cell-strong">' + esc(t.user_name) + '</td>' +
                '<td>' + badge + '</td>' +
                '<td class="num">' + (sign + fmt.format(t.amount)) + '</td>' +
                '<td class="num">' + fmt.format(t.balance_before) + '</td>' +
                '<td class="num">' + fmt.format(t.balance_after) + '</td>' +
                '<td>' + (t.reason ? esc(t.reason) : '<span class="cell-sub">—</span>') + '</td>' +
                '<td class="cell-sub">' + fmtDateTime(t.created_at) + '</td>' +
                '</tr>';
        }).join('');
    }
    async function loadAdminLogs() {
        var body = $('#logs-body');
        if (!body) return;
        var r = await api('admin_logs');
        if (!r.ok) { body.innerHTML = '<tr><td colspan="4" class="cell-muted">Erro ao carregar os logs.</td></tr>'; return; }
        if (!r.data.length) { body.innerHTML = '<tr><td colspan="4" class="cell-muted">Nenhum evento registrado.</td></tr>'; return; }
        body.innerHTML = r.data.map(function (l) {
            return '<tr>' +
                '<td><span class="badge badge-muted">' + esc(l.action) + '</span></td>' +
                '<td>' + (l.user_name ? esc(l.user_name) : '<span class="cell-sub">Sistema</span>') + '</td>' +
                '<td>' + (l.detail ? esc(l.detail) : '<span class="cell-sub">—</span>') + '</td>' +
                '<td class="cell-sub">' + fmtDateTime(l.created_at) + '</td>' +
                '</tr>';
        }).join('');
    }
    function initSettingsForm() {
        var form = $('#settings-form');
        if (!form) return;
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            var msg = $('#settings-msg');
            hideMsg(msg);
            var r = await api('admin_settings_save', {
                platform_name: form.elements.platform_name.value.trim(),
                default_credits: form.elements.default_credits.value,
                query_timeout: form.elements.query_timeout.value
            });
            if (r.ok) {
                showMsg(msg, 'ok', 'Configurações salvas.');
                setTimeout(function () { hideMsg(msg); }, 1800);
            } else {
                showMsg(msg, 'err', r.error || 'Falha ao salvar as configurações.');
            }
        });
    }

    /* ============================================================ INÍCIO ADMIN */
    function initAdminApp() {
        initSidebar();
        initNav();
        initModals();
        bindConfirm();
        initApiForm();
        initUserForm();
        initCreditForm();
        initSettingsForm();
        VIEW_LOADERS.dash = loadAdminStats;
        VIEW_LOADERS.apis = loadAdminServices;
        VIEW_LOADERS.usuarios = loadAdminUsers;
        VIEW_LOADERS.creditos = loadAdminCredits;
        VIEW_LOADERS.logs = loadAdminLogs;
        loadAdminStats();
    }

    /* ============================================================== BOOTSTRAP */
    function boot() {
        initModals();
        bindConfirm();
        initPasswordToggle();
        icons();
        if (CFG.isAdmin) { initAdminApp(); } else { initUserApp(); }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    /* ========================================================== INÍCIO USUÁRIO */
    function initUserApp() {
        initSidebar();
        initNav();
        initModals();
        initAuthForms();
        if ($('#login-form')) {
            // apenas tela de login/cadastro
            icons();
            return;
        }
        initProfileForm();
        VIEW_LOADERS.dashboard = function () { loadMe(); loadServices(); };
        VIEW_LOADERS.consultar = function () { loadServices(); };
        VIEW_LOADERS.historico = loadHistory;
        loadMe().then(function () {
            var go = $('#go-consult');
            if (go) go.addEventListener('click', function () { switchView('consultar'); });
            switchView('dashboard');
        });
    }
})();
