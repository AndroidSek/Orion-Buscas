<?php
/**
 * Orion Buscas - Painel administrativo
 * Dashboard, APIs, usuarios, creditos, logs e configuracoes.
 */

declare(strict_types=1);

require_once __DIR__ . '/src/config.php';

require_admin();

$csrf     = csrf_token();
$admin    = current_user();
$platform = setting('platform_name', 'Orion Buscas');
$settings = [
    'platform_name'   => setting('platform_name', 'Orion Buscas'),
    'default_credits' => (int) setting('default_credits', '10'),
    'query_timeout'   => (int) setting('query_timeout', '10'),
];

function admin_logo(int $size = 30): string
{
    $s = (int) $size;
    return '<svg class="logo" width="' . $s . '" height="' . $s . '" viewBox="0 0 48 48" fill="none" aria-hidden="true">'
        . '<rect x="7" y="7" width="34" height="34" rx="9" stroke="currentColor" stroke-width="2.5"/>'
        . '<circle cx="24" cy="24" r="8.5" stroke="currentColor" stroke-width="2.5"/>'
        . '<circle cx="24" cy="24" r="2.6" fill="currentColor"/>'
        . '<path d="M24 7v6M24 35v6M7 24h6M35 24h6" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>'
        . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($platform) ?> - Administracao</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js" defer></script>
<script>window.ORION = {
    csrf: '<?= e($csrf) ?>',
    isAdmin: true,
    defaultCredits: <?= (int) $settings['default_credits'] ?>
};
if (typeof lucide !== 'undefined') lucide.createIcons();</script>
</head>
<body class="admin-body">
<div class="app">
    <header class="topbar">
        <div class="topbar-left">
            <button type="button" class="icon-btn menu-btn" id="menu-btn" aria-label="Abrir menu" aria-expanded="false" aria-controls="sidebar">
                <i data-lucide="menu"></i>
            </button>
            <a class="brand" href="admin.php">
                <?= admin_logo(30) ?>
                <span class="brand-name">ORION<span class="brand-accent"> BUSCAS</span></span>
                <span class="brand-tag">ADMIN</span>
            </a>
        </div>
        <div class="topbar-right">
            <a class="topbar-link" href="index.php" title="Painel do usuario"><i data-lucide="user" class="icon-sm"></i><span>Usuario</span></a>
            <div class="topbar-user">
                <span class="avatar"><?= e(mb_strtoupper(mb_substr($admin['name'], 0, 1))) ?></span>
                <span class="topbar-name"><?= e($admin['name']) ?></span>
            </div>
        </div>
    </header>

    <div class="layout">
        <aside class="sidebar" id="sidebar" aria-label="Menu administrativo">
            <div class="side-head">
                <button type="button" class="icon-btn side-close" id="side-close" aria-label="Fechar menu" hidden>
                    <i data-lucide="x"></i>
                </button>
                <div class="side-user">
                    <span class="avatar avatar-lg"><?= e(mb_strtoupper(mb_substr($admin['name'], 0, 1))) ?></span>
                    <div class="side-user-info">
                        <strong><?= e($admin['name']) ?></strong>
                        <span>Administrador</span>
                    </div>
                </div>
            </div>
            <nav class="side-nav">
                <a href="#" class="side-link active" data-view="dash"><i data-lucide="layout-dashboard"></i><span>Dashboard</span></a>
                <a href="#" class="side-link" data-view="apis"><i data-lucide="plug-zap"></i><span>APIs</span></a>
                <a href="#" class="side-link" data-view="usuarios"><i data-lucide="users"></i><span>Usuarios</span></a>
                <a href="#" class="side-link" data-view="creditos"><i data-lucide="wallet"></i><span>Creditos</span></a>
                <a href="#" class="side-link" data-view="logs"><i data-lucide="scroll-text"></i><span>Logs</span></a>
                <a href="#" class="side-link" data-view="config"><i data-lucide="settings-2"></i><span>Configuracoes</span></a>
            </nav>
            <div class="side-foot">
                <button type="button" class="side-link" id="logout-btn"><i data-lucide="log-out"></i><span>Sair</span></button>
            </div>
        </aside>
        <div class="backdrop" id="backdrop" hidden></div>

        <main class="content">
            <!-- =================================================== DASHBOARD -->
            <section class="view active" id="view-dash">
                <div class="page-head">
                    <h1 class="page-title">Dashboard</h1>
                    <p class="page-subtitle">Visao geral da plataforma.</p>
                </div>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i data-lucide="users"></i></div>
                        <div><span class="stat-value" id="ad-users">…</span><span class="stat-label">Usuarios</span></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i data-lucide="plug-zap"></i></div>
                        <div><span class="stat-value" id="ad-apis">…</span><span class="stat-label">APIs ativas</span></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i data-lucide="activity"></i></div>
                        <div><span class="stat-value" id="ad-queries">…</span><span class="stat-label">Consultas realizadas</span></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i data-lucide="coins"></i></div>
                        <div><span class="stat-value" id="ad-consumed">…</span><span class="stat-label">Creditos consumidos</span></div>
                    </div>
                    <div class="stat-card stat-card-warn">
                        <div class="stat-icon"><i data-lucide="triangle-alert"></i></div>
                        <div><span class="stat-value" id="ad-errors">…</span><span class="stat-label">Consultas com erro</span></div>
                    </div>
                </div>
                <div class="panel-card">
                    <div class="panel-card-head">
                        <h2 class="panel-card-title">Atividade recente</h2>
                    </div>
                    <ul class="activity-list" id="activity-list">
                        <li class="cell-muted">Carregando…</li>
                    </ul>
                </div>
            </section>

            <!-- ======================================================= APIS -->
            <section class="view" id="view-apis">
                <div class="page-head">
                    <h1 class="page-title">APIs</h1>
                    <p class="page-subtitle">Gerencie os servicos disponiveis aos usuarios.</p>
                    <button type="button" class="btn btn-primary" id="api-new-btn"><i data-lucide="plus" class="icon-sm"></i><span>Adicionar API</span></button>
                </div>
                <div class="table-card">
                    <table class="table" id="apis-table">
                        <thead>
                            <tr>
                                <th scope="col">Nome</th>
                                <th scope="col">Parametros</th>
                                <th scope="col">Metodo</th>
                                <th scope="col" class="num">Custo</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="col-actions">Acoes</th>
                            </tr>
                        </thead>
                        <tbody id="apis-body">
                            <tr><td colspan="6" class="cell-muted">Carregando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ================================================== USUARIOS -->
            <section class="view" id="view-usuarios">
                <div class="page-head">
                    <h1 class="page-title">Usuarios</h1>
                    <p class="page-subtitle">Contas da plataforma, creditos e status.</p>
                    <button type="button" class="btn btn-primary" id="user-new-btn"><i data-lucide="user-plus" class="icon-sm"></i><span>Novo usuario</span></button>
                </div>
                <div class="table-card">
                    <table class="table" id="users-table">
                        <thead>
                            <tr>
                                <th scope="col">Usuario</th>
                                <th scope="col">Funcao</th>
                                <th scope="col" class="num">Creditos</th>
                                <th scope="col">Status</th>
                                <th scope="col">Ultimo acesso</th>
                                <th scope="col" class="col-actions">Acoes</th>
                            </tr>
                        </thead>
                        <tbody id="users-body">
                            <tr><td colspan="6" class="cell-muted">Carregando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ================================================== CREDITOS -->
            <section class="view" id="view-creditos">
                <div class="page-head">
                    <h1 class="page-title">Creditos</h1>
                    <p class="page-subtitle">Movimentacoes de creditos por usuario.</p>
                </div>
                <div class="table-card">
                    <table class="table" id="credits-table">
                        <thead>
                            <tr>
                                <th scope="col">Usuario</th>
                                <th scope="col">Tipo</th>
                                <th scope="col" class="num">Valor</th>
                                <th scope="col">Saldo antes</th>
                                <th scope="col" class="num">Saldo depois</th>
                                <th scope="col">Motivo</th>
                                <th scope="col">Data</th>
                            </tr>
                        </thead>
                        <tbody id="credits-body">
                            <tr><td colspan="7" class="cell-muted">Carregando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ====================================================== LOGS -->
            <section class="view" id="view-logs">
                <div class="page-head">
                    <h1 class="page-title">Logs</h1>
                    <p class="page-subtitle">Ultimos eventos registrados pelo sistema.</p>
                </div>
                <div class="table-card">
                    <table class="table" id="logs-table">
                        <thead>
                            <tr>
                                <th scope="col">Acao</th>
                                <th scope="col">Usuario</th>
                                <th scope="col">Detalhe</th>
                                <th scope="col">Data</th>
                            </tr>
                        </thead>
                        <tbody id="logs-body">
                            <tr><td colspan="4" class="cell-muted">Carregando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ============================================== CONFIGURACOES -->
            <section class="view" id="view-config">
                <div class="page-head">
                    <h1 class="page-title">Configuracoes</h1>
                    <p class="page-subtitle">Parametros gerais da plataforma.</p>
                </div>
                <div class="panel-card">
                    <form id="settings-form" class="form-grid" novalidate>
                        <div class="field">
                            <label for="cfg-name">Nome da plataforma</label>
                            <input type="text" id="cfg-name" name="platform_name" maxlength="80" required value="<?= e($settings['platform_name']) ?>">
                        </div>
                        <div class="field">
                            <label for="cfg-default">Creditos iniciais de novos usuarios</label>
                            <input type="number" id="cfg-default" name="default_credits" min="0" max="100000" value="<?= (int) $settings['default_credits'] ?>">
                        </div>
                        <div class="field">
                            <label for="cfg-timeout">Timeout das consultas externas (segundos)</label>
                            <input type="number" id="cfg-timeout" name="query_timeout" min="3" max="60" value="<?= (int) $settings['query_timeout'] ?>">
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Salvar configuracoes</button>
                        </div>
                        <div class="form-msg form-msg-wide" id="settings-msg" role="alert" hidden></div>
                    </form>
                </div>
            </section>
        </main>
    </div>
</div>

<!-- ===================================================== MODAL: API -->
<div class="modal" id="api-modal" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="api-modal-title">
        <div class="modal-head">
            <h2 id="api-modal-title" class="modal-title">Adicionar API</h2>
            <button type="button" class="icon-btn" data-modal-close="api-modal" aria-label="Fechar"><i data-lucide="x"></i></button>
        </div>
        <form id="api-form" class="form-grid" novalidate>
            <input type="hidden" name="service_id" id="api-id" value="0">
            <div class="field">
                <label for="api-name">Nome</label>
                <input type="text" id="api-name" name="name" maxlength="120" required placeholder="Consulta CEP">
            </div>
            <div class="field">
                <label for="api-desc">Descricao</label>
                <input type="text" id="api-desc" name="description" maxlength="255" placeholder="Breve descricao do servico">
            </div>
            <div class="field field-wide">
                <label for="api-endpoint">Endpoint</label>
                <input type="url" id="api-endpoint" name="endpoint" maxlength="500" required placeholder="https://api.exemplo.com/consulta">
            </div>
            <div class="field">
                <label for="api-method">Metodo</label>
                <select id="api-method" name="method">
                    <option value="GET">GET</option>
                    <option value="POST">POST</option>
                </select>
            </div>
            <div class="field">
                <label for="api-cost">Custo em creditos</label>
                <input type="number" id="api-cost" name="cost" min="0" max="1000" value="1">
            </div>
            <div class="field">
                <label for="api-status">Status</label>
                <select id="api-status" name="status">
                    <option value="active">Ativa</option>
                    <option value="inactive">Inativa</option>
                </select>
            </div>
            <div class="field field-wide">
                <label for="api-key">Chave da API (opcional)</label>
                <input type="text" id="api-key" name="api_key" maxlength="255" autocomplete="off"
                       placeholder="Preencha apenas para definir ou substituir a chave">
                <small class="field-hint">A chave fica somente no servidor. Para remover, use a acao correspondente ao editar.</small>
            </div>

            <div class="params-block">
                <div class="params-block-head">
                    <h3 class="params-title">Parametros</h3>
                    <button type="button" class="btn btn-ghost btn-sm" id="param-add-btn"><i data-lucide="plus" class="icon-sm"></i><span>Adicionar parametro</span></button>
                </div>
                <div class="params-list" id="params-list"></div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-ghost" data-modal-close="api-modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="api-save-btn">Salvar API</button>
            </div>
            <div class="form-msg form-msg-wide" id="api-msg" role="alert" hidden></div>
        </form>
    </div>
</div>

<!-- ================================================== MODAL: USUARIO -->
<div class="modal" id="user-modal" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
        <div class="modal-head">
            <h2 id="user-modal-title" class="modal-title">Novo usuario</h2>
            <button type="button" class="icon-btn" data-modal-close="user-modal" aria-label="Fechar"><i data-lucide="x"></i></button>
        </div>
        <form id="user-form" class="form-grid" novalidate>
            <input type="hidden" name="user_id" id="user-id" value="0">
            <div class="field">
                <label for="user-name">Nome</label>
                <input type="text" id="user-name" name="name" maxlength="100" required>
            </div>
            <div class="field">
                <label for="user-email">Email</label>
                <input type="email" id="user-email" name="email" maxlength="190" required>
            </div>
            <div class="field">
                <label for="user-pass">Senha</label>
                <input type="password" id="user-pass" name="password" minlength="8" autocomplete="new-password"
                       placeholder="Minimo 8 caracteres">
                <small class="field-hint" id="user-pass-hint">Deixe em branco para manter a senha atual.</small>
            </div>
            <div class="field" id="user-default-credits-field">
                <label for="user-credits">Creditos iniciais</label>
                <input type="number" id="user-credits" name="credits" min="0" max="100000" value="0">
            </div>
            <div class="field" id="user-role-field">
                <label for="user-role">Funcao</label>
                <select id="user-role" name="role">
                    <option value="user">Usuario</option>
                    <option value="admin">Administrador</option>
                </select>
            </div>
            <div class="field" id="user-status-field">
                <label for="user-status">Status</label>
                <select id="user-status" name="status">
                    <option value="active">Ativo</option>
                    <option value="blocked">Bloqueado</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-ghost" data-modal-close="user-modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="user-save-btn">Salvar usuario</button>
            </div>
            <div class="form-msg form-msg-wide" id="user-msg" role="alert" hidden></div>
        </form>
    </div>
</div>

<!-- ============================================= MODAL: CREDITOS -->
<div class="modal" id="credit-modal" hidden>
    <div class="modal-card modal-card-sm" role="dialog" aria-modal="true" aria-labelledby="credit-modal-title">
        <div class="modal-head">
            <h2 id="credit-modal-title" class="modal-title">Movimentar creditos</h2>
            <button type="button" class="icon-btn" data-modal-close="credit-modal" aria-label="Fechar"><i data-lucide="x"></i></button>
        </div>
        <form id="credit-form" class="form-grid" novalidate>
            <input type="hidden" name="user_id" id="credit-user-id">
            <p class="credit-target" id="credit-target"></p>
            <div class="field">
                <label for="credit-op">Operacao</label>
                <select id="credit-op" name="op">
                    <option value="add">Adicionar creditos</option>
                    <option value="remove">Remover creditos</option>
                </select>
            </div>
            <div class="field">
                <label for="credit-amount">Quantidade</label>
                <input type="number" id="credit-amount" name="amount" min="1" max="100000" value="10">
            </div>
            <div class="field">
                <label for="credit-reason">Motivo</label>
                <input type="text" id="credit-reason" name="reason" maxlength="255" placeholder="Ex.: recarga, ajuste manual">
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-ghost" data-modal-close="credit-modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Confirmar</button>
            </div>
            <div class="form-msg form-msg-wide" id="credit-msg" role="alert" hidden></div>
        </form>
    </div>
</div>

<!-- ================================================= MODAL: HISTORICO -->
<div class="modal" id="hist-modal" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="hist-modal-title">
        <div class="modal-head">
            <h2 id="hist-modal-title" class="modal-title">Historico de consultas</h2>
            <button type="button" class="icon-btn" data-modal-close="hist-modal" aria-label="Fechar"><i data-lucide="x"></i></button>
        </div>
        <div class="table-card table-card-inset">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Servico</th>
                        <th scope="col">Consulta</th>
                        <th scope="col">Data</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="num">Custo</th>
                    </tr>
                </thead>
                <tbody id="hist-body">
                    <tr><td colspan="5" class="cell-muted">Carregando…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ================================================= MODAL: CONFIRMAR -->
<div class="modal" id="confirm-modal" hidden>
    <div class="modal-card modal-card-sm" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title">
        <div class="modal-head">
            <h2 id="confirm-title" class="modal-title">Confirmar acao</h2>
            <button type="button" class="icon-btn" data-modal-close="confirm-modal" aria-label="Fechar"><i data-lucide="x"></i></button>
        </div>
        <p class="confirm-text" id="confirm-text"></p>
        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close="confirm-modal">Cancelar</button>
            <button type="button" class="btn btn-danger" id="confirm-ok">Confirmar</button>
        </div>
    </div>
</div>

<script src="assets/js/script.js" defer></script>
</body>
</html>
