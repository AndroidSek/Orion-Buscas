<?php
/**
 * Orion Buscas - Portal do usuario
 * Tela de login + dashboard (creditos, consulta, historico, perfil).
 */

declare(strict_types=1);

require_once __DIR__ . '/src/config.php';

// Administradores trabalham no painel administrativo.
if (is_logged_in() && is_admin()) {
    header('Location: admin.php');
    exit;
}

$csrf      = csrf_token();
$user      = current_user();
$platform  = setting('platform_name', 'Orion Buscas');
$isNewUser = $user !== null && $user['last_login'] === null;

function render_logo(int $size = 34): string
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
<meta name="description" content="Orion Buscas - plataforma de consultas dinamicas via APIs autorizadas.">
<meta name="robots" content="noindex">
<title><?= e($platform) ?><?= $user ? ' - Painel' : ' - Login' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js" defer></script>
<script>window.ORION = { csrf: '<?= e($csrf) ?>', isAdmin: false };</script>
</head>
<body>
<?php if (!$user): ?>
<!-- ====================================================== LOGIN / CADASTRO -->
<div class="auth-wrap">
    <div class="auth-card" id="auth-card">
        <div class="auth-brand">
            <?= render_logo(44) ?>
            <h1 class="auth-title">ORION&nbsp;BUSCAS</h1>
            <p class="auth-subtitle">Plataforma de consultas dinamicas</p>
        </div>

        <div class="auth-tabs" role="tablist" aria-label="Autenticacao">
            <button type="button" class="auth-tab is-active" id="auth-tab-login" role="tab"
                    aria-selected="true" aria-controls="login-form" data-auth-view="login">Entrar</button>
            <button type="button" class="auth-tab" id="auth-tab-register" role="tab"
                    aria-selected="false" aria-controls="register-form" data-auth-view="register">Criar conta</button>
        </div>

        <div class="form-msg" id="auth-msg" role="alert" hidden></div>

        <form class="auth-form is-active" id="login-form" role="tabpanel" aria-labelledby="auth-tab-login" novalidate>
            <div class="field">
                <label for="login-email">Email</label>
                <input type="email" id="login-email" name="email" autocomplete="email"
                       placeholder="seu@email.com" required>
            </div>

            <div class="field">
                <label for="login-password">Senha</label>
                <input type="password" id="login-password" name="password" autocomplete="current-password"
                       placeholder="Sua senha" required data-pwd-toggle>
            </div>

            <button type="submit" class="btn btn-primary btn-block" id="login-btn">
                <span class="btn-label">Entrar</span>
            </button>
        </form>

        <form class="auth-form" id="register-form" role="tabpanel" aria-labelledby="auth-tab-register" novalidate>
            <div class="field">
                <label for="reg-name">Nome</label>
                <input type="text" id="reg-name" name="name" maxlength="100" autocomplete="name"
                       placeholder="Seu nome" required>
            </div>

            <div class="field">
                <label for="reg-email">Email</label>
                <input type="email" id="reg-email" name="email" autocomplete="email"
                       placeholder="seu@email.com" required>
            </div>

            <div class="field">
                <label for="reg-password">Senha</label>
                <input type="password" id="reg-password" name="password" minlength="8" autocomplete="new-password"
                       placeholder="Minimo 8 caracteres" required data-pwd-toggle>
            </div>

            <div class="field">
                <label for="reg-confirm">Confirmar senha</label>
                <input type="password" id="reg-confirm" name="confirm_password" autocomplete="new-password"
                       placeholder="Repita a senha" required data-pwd-toggle>
            </div>

            <button type="submit" class="btn btn-primary btn-block" id="register-btn">
                <span class="btn-label">Criar conta</span>
            </button>
        </form>

        <p class="auth-foot">Acesso restrito a usuarios autorizados.</p>
    </div>
</div>
<?php else: ?>
<!-- ========================================================== DASHBOARD -->
<div class="app">
    <header class="topbar">
        <div class="topbar-left">
            <button type="button" class="icon-btn menu-btn" id="menu-btn" aria-label="Abrir menu" aria-expanded="false" aria-controls="sidebar">
                <i data-lucide="menu"></i>
            </button>
            <a class="brand" href="index.php">
                <?= render_logo(30) ?>
                <span class="brand-name">ORION<span class="brand-accent"> BUSCAS</span></span>
            </a>
        </div>
        <div class="topbar-right">
            <div class="credits-pill" title="Creditos disponiveis">
                <i data-lucide="wallet" class="icon-sm"></i>
                <span id="credits-value" class="credits-value">…</span>
            </div>
            <div class="topbar-user">
                <span class="avatar"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
                <span class="topbar-name"><?= e($user['name']) ?></span>
            </div>
        </div>
    </header>

    <div class="layout">
        <aside class="sidebar" id="sidebar" aria-label="Menu principal">
            <div class="side-head">
                <button type="button" class="icon-btn side-close" id="side-close" aria-label="Fechar menu" hidden>
                    <i data-lucide="x"></i>
                </button>
                <div class="side-user">
                    <span class="avatar avatar-lg"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
                    <div class="side-user-info">
                        <strong><?= e($user['name']) ?></strong>
                        <span><?= e($user['email']) ?></span>
                    </div>
                </div>
            </div>
            <nav class="side-nav">
                <a href="#" class="side-link active" data-view="dashboard"><i data-lucide="layout-dashboard"></i><span>Painel</span></a>
                <a href="#" class="side-link" data-view="consultar"><i data-lucide="search"></i><span>Consultas</span></a>
                <a href="#" class="side-link" data-view="historico"><i data-lucide="history"></i><span>Historico</span></a>
                <a href="#" class="side-link" data-view="perfil"><i data-lucide="user"></i><span>Perfil</span></a>
            </nav>
            <div class="side-foot">
                <button type="button" class="side-link" id="logout-btn"><i data-lucide="log-out"></i><span>Sair</span></button>
            </div>
        </aside>
        <div class="backdrop" id="backdrop" hidden></div>

        <main class="content">
            <!-- ------------------------------------------------ PAINEL -->
            <section class="view active" id="view-dashboard">
                <div class="page-head">
                    <h1 class="page-title">Painel</h1>
                    <p class="page-subtitle">
                        <?= $isNewUser
                            ? 'Bem-vindo ao Orion Buscas. Selecione um servico e realize sua primeira consulta.'
                            : 'Bem-vindo de volta. Sua plataforma de consultas em um painel unico.' ?>
                    </p>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon"><i data-lucide="wallet"></i></div>
                        <div>
                            <span class="stat-value" id="stat-credits">…</span>
                            <span class="stat-label">Creditos disponiveis</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i data-lucide="activity"></i></div>
                        <div>
                            <span class="stat-value" id="stat-queries">…</span>
                            <span class="stat-label">Consultas realizadas</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i data-lucide="plug-zap"></i></div>
                        <div>
                            <span class="stat-value" id="stat-services">…</span>
                            <span class="stat-label">APIs disponiveis</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon"><i data-lucide="clock-3"></i></div>
                        <div>
                            <span class="stat-value stat-value-sm" id="stat-last">…</span>
                            <span class="stat-label">Ultima atividade</span>
                        </div>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-card-head">
                        <h2 class="panel-card-title">Nova consulta</h2>
                        <p class="panel-card-sub">Selecione um servico e informe os dados para consultar.</p>
                    </div>
                    <div class="cta-row">
                        <button type="button" class="btn btn-primary" id="go-consult">
                            <i data-lucide="search" class="icon-sm"></i>
                            <span>Iniciar consulta</span>
                        </button>
                    </div>
                </div>
            </section>

            <!-- ---------------------------------------------- CONSULTAS -->
            <section class="view" id="view-consultar">
                <div class="page-head">
                    <h1 class="page-title">Consultas</h1>
                    <p class="page-subtitle">Selecione uma consulta para abrir a sua pagina. O custo e debitado antes da execucao.</p>
                </div>
                <div class="svc-cards" id="svc-cards" aria-live="polite">
                    <div class="skeleton-line" style="width:55%"></div>
                    <div class="skeleton-line" style="width:45%"></div>
                    <div class="skeleton-line" style="width:60%"></div>
                </div>
            </section>

            <!-- --------------------------------------------- HISTORICO -->
            <section class="view" id="view-historico">
                <div class="page-head">
                    <h1 class="page-title">Historico</h1>
                    <p class="page-subtitle">Suas ultimas consultas (maximo de 50 registros).</p>
                </div>
                <div class="table-card">
                    <table class="table" id="history-table">
                        <thead>
                            <tr>
                                <th scope="col">Servico</th>
                                <th scope="col">Consulta</th>
                                <th scope="col">Data</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="num">Custo</th>
                            </tr>
                        </thead>
                        <tbody id="history-body">
                            <tr><td colspan="5" class="cell-muted">Carregando historico…</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ------------------------------------------------ PERFIL -->
            <section class="view" id="view-perfil">
                <div class="page-head">
                    <h1 class="page-title">Perfil</h1>
                    <p class="page-subtitle">Dados pessoais e alteracao de senha.</p>
                </div>
                <div class="panel-card">
                    <form id="profile-form" class="form-grid" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <div class="field">
                            <label for="pf-name">Nome</label>
                            <input type="text" id="pf-name" name="name" maxlength="100" required value="<?= e($user['name']) ?>">
                        </div>
                        <div class="field">
                            <label for="pf-email">Email</label>
                            <input type="email" id="pf-email" name="email" maxlength="190" required value="<?= e($user['email']) ?>">
                        </div>
                        <div class="field">
                            <label for="pf-role">Perfil de acesso</label>
                            <input type="text" id="pf-role" value="<?= e($user['role']) ?>" disabled>
                        </div>
                        <div class="field field-note">
                            <label for="pf-current">Senha atual</label>
                            <input type="password" id="pf-current" name="current_password" autocomplete="current-password" data-pwd-toggle>
                        </div>
                        <div class="field">
                            <label for="pf-new">Nova senha</label>
                            <input type="password" id="pf-new" name="new_password" minlength="8" autocomplete="new-password" data-pwd-toggle>
                        </div>
                        <div class="field">
                            <label for="pf-confirm">Confirmar nova senha</label>
                            <input type="password" id="pf-confirm" name="confirm_password" autocomplete="new-password" data-pwd-toggle>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">Salvar alteracoes</button>
                        </div>
                        <div class="form-msg form-msg-wide" id="profile-msg" role="alert" hidden></div>
                    </form>
                </div>
            </section>
        </main>
    </div>
</div>
<?php endif; ?>

<script src="assets/js/script.js" defer></script>
</body>
</html>
