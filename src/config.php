<?php
/**
 * Orion Buscas - Configuracao central
 *
 * Concentra: conexao PDO, sessao segura, helpers de utilitario,
 * CSRF, log de sistema e gestao de creditos.
 * Todos os arquivos do projeto incluem este arquivo primeiro.
 */

declare(strict_types=1);

// -----------------------------------------------------------------
// Constantes de ambiente
// Ajuste os credenciais do banco conforme o seu servidor.
// -----------------------------------------------------------------
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'orion_buscas');
define('DB_USER', 'orion');
define('DB_PASS', 'Orion#2026-Local');

define('SESSION_LIFETIME', 1800);        // 30 minutos de inatividade
define('APP_VERSION', '1.0.0');

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set('America/Fortaleza');

// -----------------------------------------------------------------
// Sessao
// -----------------------------------------------------------------
session_name('orion_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// -----------------------------------------------------------------
// Cabecoes de seguranca
// -----------------------------------------------------------------
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

// -----------------------------------------------------------------
// Conexao PDO
// -----------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        // Tenta o usuario dedicado primeiro; em XAMPP/ksweb o root costuma
        // existir sem senha — assim o database.sql importa e roda sem ajustes.
        $candidates = [
            [DB_USER, DB_PASS],
            ['root', ''],
        ];

        // Se o banco ainda nao existir (primeira execucao em ksweb/XAMPP),
        // cria para que o fluxo nao quebre antes do import do database.sql.
        $booted = false;
        foreach ($candidates as [$user, $pass]) {
            try {
                $boot = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT), $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $boot->query('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $booted = true;
                break;
            } catch (PDOException $e) {
                // Candidato sem acesso: tenta o proximo.
            }
        }
        if (!$booted) {
            http_response_code(500);
            exit('Erro de conexao com o banco de dados. Verifique a configuracao em config.php.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
        foreach ($candidates as [$user, $pass]) {
            try {
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                break;
            } catch (PDOException $e) {
                $pdo = null;
            }
        }
        if ($pdo === null) {
            http_response_code(500);
            exit('Erro de conexao com o banco de dados. Verifique a configuracao em config.php.');
        }
        try {
            $pdo->query('SELECT 1 FROM users LIMIT 1');
        } catch (PDOException $e) {
            http_response_code(503);
            exit('Estrutura do banco nao encontrada. Importe o arquivo database.sql (phpMyAdmin > Importar) e recarregue a pagina.');
        }
    }
    return $pdo;
}

// -----------------------------------------------------------------
// Configurações gerais (tabela settings)
// -----------------------------------------------------------------
function setting(string $key, string $default): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
            foreach ($rows as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (PDOException $e) {
            // Banco indisponivel: usa padroes.
        }
    }
    return $cache[$key] ?? $default;
}

// -----------------------------------------------------------------
// Sessao / usuario autenticado
// -----------------------------------------------------------------
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        $id = $_SESSION['user_id'] ?? null;
        if ($id) {
            $stmt = db()->prepare(
                'SELECT id, name, email, credits, role, status, last_login
                 FROM users WHERE id = ?'
            );
            $stmt->execute([(int) $id]);
            $row = $stmt->fetch();
            if ($row && $row['status'] === 'active') {
                $user = $row;
            } else {
                unset($_SESSION['user_id']);
            }
        }
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $user = current_user();
    return $user !== null && $user['role'] === 'admin';
}

function require_user(): void
{
    if (!is_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function require_admin(): void
{
    if (!is_admin()) {
        // Usuario logado sem permissao: volta ao painel dele.
        // Anonimo: vai para o login.
        if (is_logged_in()) {
            http_response_code(403);
            exit('Acesso negado. Esta area requer permissao administrativa.');
        }
        header('Location: index.php');
        exit;
    }
}

// -----------------------------------------------------------------
// CSRF
// -----------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verify_csrf($token)) {
        http_response_code(419);
        exit('Sessao invalida ou expirada. Recarregue a pagina e tente novamente.');
    }
}

// -----------------------------------------------------------------
// Log de sistema (senhas, tokens e chaves jamais sao registrados)
// -----------------------------------------------------------------
function system_log(string $action, string $detail = '', ?int $userId = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO system_logs (user_id, action, detail, ip_address) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $action,
            mb_substr($detail, 0, 480),
            client_ip(),
        ]);
    } catch (PDOException $e) {
        // Falha de log nao deve derrubar a operacao principal.
    }
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) ? substr($ip, 0, 45) : '';
}

// -----------------------------------------------------------------
// Creditos - entrada e saida com transacao atomica
// $amount: negativo para debito, positivo para credito
// Retorna [ok, message, balance]
// -----------------------------------------------------------------
function apply_credit(int $userId, int $amount, string $type, string $reason): array
{
    if ($amount === 0) {
        return [false, 'Informe uma quantidade diferente de zero.', null];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT credits FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Usuario nao encontrado.');
        }

        $before = (int) $row['credits'];
        $after = $before + $amount;
        if ($after < 0) {
            $pdo->rollBack();
            return [false, sprintf('Saldo insuficiente. O usuario possui apenas %d credito(s).', $before), null];
        }

        $pdo->prepare('UPDATE users SET credits = ? WHERE id = ?')->execute([$after, $userId]);
        $pdo->prepare(
            'INSERT INTO credit_transactions
                (user_id, amount, type, reason, balance_before, balance_after)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $amount, $type, $reason, $before, $after]);

        $pdo->commit();
        return [true, '', $after];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return [false, 'Falha ao processar o movimento de creditos.', null];
    }
}

// -----------------------------------------------------------------
// Utilitarios de saida
// -----------------------------------------------------------------
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mask_key(?string $key): string
{
    if ($key === null || $key === '') {
        return '';
    }
    $len = strlen($key);
    if ($len <= 8) {
        return str_repeat('*', $len);
    }
    return substr($key, 0, 4) . str_repeat('*', 4) . substr($key, -4);
}
