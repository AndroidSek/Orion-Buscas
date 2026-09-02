<?php
/**
 * Orion Buscas - API interna
 *
 * Endpoints aceitos (todos POST + CSRF):
 *   login    - autenticacao
 *   logout   - encerra a sessao
 *   me       - dados do usuario atual
 *   services - APIs ativas + parametros (para o dashboard)
 *   query    - executa uma consulta dinamica
 *   history  - historico de consultas do usuario
 *   profile  - atualiza dados do proprio usuario
 *   admin_*  - acoes do painel administrativo (requisita role admin)
 *
 * A chamada a API externa e feita pelo servidor (curl). O navegador
 * nunca recebe o endpoint real nem credenciais.
 */

declare(strict_types=1);

require_once __DIR__ . '/src/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'data' => null, 'error' => 'Metodo nao permitido.'], 405);
}

$action = (string) ($_POST['action'] ?? '');

// Todas as acoes autenticadas exigem token CSRF.
if ($action !== 'login') {
    require_csrf();
}

switch ($action) {
    case 'login':
        handle_login();
        break;
    case 'register':
        handle_register();
        break;
    case 'logout':
        handle_logout();
        break;
    case 'me':
        require_user();
        $me = public_user();
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS total, MAX(created_at) AS last
             FROM query_logs WHERE user_id = ?'
        );
        $stmt->execute([(int) current_user()['id']]);
        $row = $stmt->fetch();
        $me['stats'] = [
            'total_queries' => (int) $row['total'],
            'last_activity' => $row['last'],
        ];
        json_response(['success' => true, 'data' => $me, 'error' => null]);
        break;
    case 'services':
        require_user();
        json_response(['success' => true, 'data' => active_services(), 'error' => null]);
        break;
    case 'history':
        require_user();
        json_response(['success' => true, 'data' => user_history(), 'error' => null]);
        break;
    case 'profile':
        require_user();
        handle_profile();
        break;

    // ------------------------------------------------ admin
    case 'admin_stats':
        require_admin();
        json_response(['success' => true, 'data' => admin_stats(), 'error' => null]);
        break;
    case 'admin_services':
        require_admin();
        json_response(['success' => true, 'data' => admin_services(), 'error' => null]);
        break;
    case 'admin_service_save':
        require_admin();
        admin_service_save();
        break;
    case 'admin_service_toggle':
        require_admin();
        admin_service_toggle();
        break;
    case 'admin_service_delete':
        require_admin();
        admin_service_delete();
        break;
    case 'admin_users':
        require_admin();
        json_response(['success' => true, 'data' => admin_users(), 'error' => null]);
        break;
    case 'admin_user_create':
        require_admin();
        admin_user_create();
        break;
    case 'admin_user_update':
        require_admin();
        admin_user_update();
        break;
    case 'admin_user_password':
        require_admin();
        admin_user_password();
        break;
    case 'admin_user_delete':
        require_admin();
        admin_user_delete();
        break;
    case 'admin_credit_move':
        require_admin();
        admin_credit_move();
        break;
    case 'admin_credits':
        require_admin();
        json_response(['success' => true, 'data' => admin_credit_transactions(), 'error' => null]);
        break;
    case 'admin_user_history':
        require_admin();
        $uid = (int) ($_POST['user_id'] ?? 0);
        $stmt = db()->prepare(
            'SELECT service_name, queried_value, status, cost, created_at
             FROM query_logs WHERE user_id = ? ORDER BY id DESC LIMIT 50'
        );
        $stmt->execute([$uid]);
        json_response(['success' => true, 'data' => $stmt->fetchAll(), 'error' => null]);
        break;
    case 'admin_logs':
        require_admin();
        json_response(['success' => true, 'data' => admin_logs(), 'error' => null]);
        break;
    case 'admin_settings_save':
        require_admin();
        admin_settings_save();
        break;
    case 'query':
        require_user();
        handle_query();
        break;
    default:
        json_response(['success' => false, 'data' => null, 'error' => 'Acao desconhecida.'], 400);
}

// -----------------------------------------------------------------
// Login
// -----------------------------------------------------------------
function handle_login(): void
{
    $email    = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 190);
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        json_response(['success' => false, 'data' => null, 'error' => 'Informe o email e a senha.'], 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'data' => null, 'error' => 'Email invalido.'], 422);
    }

    $stmt = db()->prepare(
        'SELECT id, name, email, password_hash, credits, role, status
         FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        system_log('login_failed', 'Tentativa de login com credenciais invalidas: ' . $email, $user['id'] ?? null);
        json_response(['success' => false, 'data' => null, 'error' => 'Credenciais invalidas. Verifique o email e a senha.'], 401);
    }

    if ($user['status'] === 'blocked') {
        system_log('login_blocked', 'Tentativa de login com conta bloqueada: ' . $email, (int) $user['id']);
        json_response(['success' => false, 'data' => null, 'error' => 'Usuario bloqueado. Contate o administrador.'], 403);
    }

    // Regenera o ID da sessao para fixacao de sessao.
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([(int) $user['id']]);
    system_log('login', 'Login realizado', (int) $user['id']);

    json_response(['success' => true, 'data' => public_user($user), 'error' => null]);
}

// -----------------------------------------------------------------
// Cadastro de novos usuarios
// -----------------------------------------------------------------
function handle_register(): void
{
    $name   = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
    $email  = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 190);
    $pass   = (string) ($_POST['password'] ?? '');
    $conf   = (string) ($_POST['confirm_password'] ?? '');

    if ($name === '' || $email === '' || $pass === '') {
        json_response(['success' => false, 'data' => null, 'error' => 'Preencha nome, email e senha.'], 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'data' => null, 'error' => 'Email invalido.'], 422);
    }
    if (strlen($pass) < 8) {
        json_response(['success' => false, 'data' => null, 'error' => 'A senha precisa ter ao menos 8 caracteres.'], 422);
    }
    if ($pass !== $conf) {
        json_response(['success' => false, 'data' => null, 'error' => 'A senha e a confirmacao precisam ser identicas.'], 422);
    }

    $dup = db()->prepare('SELECT id FROM users WHERE email = ?');
    $dup->execute([$email]);
    if ($dup->fetch()) {
        json_response(['success' => false, 'data' => null, 'error' => 'Este email ja esta cadastrado. Use a opcao Entrar.'], 409);
    }

    $initial = (int) setting('default_credits', '10');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, credits, role, status, last_login)
             VALUES (?, ?, ?, ?, "user", "active", NOW())'
        )->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $initial]);

        $uid = (int) $pdo->lastInsertId();
        if ($initial > 0) {
            $pdo->prepare(
                'INSERT INTO credit_transactions (user_id, amount, type, reason, balance_before, balance_after)
                 VALUES (?, ?, "grant", "Creditos iniciais", 0, ?)'
            )->execute([$uid, $initial, $initial]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ((string) $e->getCode() === '23000') {
            json_response(['success' => false, 'data' => null, 'error' => 'Este email ja esta cadastrado. Use a opcao Entrar.'], 409);
        }
        json_response(['success' => false, 'data' => null, 'error' => 'Falha ao criar a conta. Tente novamente.'], 500);
    }

    // Entra diretamente na plataforma apos o cadastro.
    session_regenerate_id(true);
    $_SESSION['user_id'] = $uid;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    system_log('register', 'Conta criada: ' . $email, $uid);

    $stmt = db()->prepare(
        'SELECT id, name, email, password_hash, credits, role, status FROM users WHERE id = ?'
    );
    $stmt->execute([$uid]);
    json_response(['success' => true, 'data' => public_user($stmt->fetch()), 'error' => null]);
}

// -----------------------------------------------------------------
// Logout
// -----------------------------------------------------------------
function handle_logout(): void
{
    $userId = $_SESSION['user_id'] ?? null;
    system_log('logout', 'Logout realizado', is_int($userId) ? $userId : null);

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();

    json_response(['success' => true, 'data' => null, 'error' => null]);
}

// -----------------------------------------------------------------
// Dados publicos do usuario atual
// -----------------------------------------------------------------
function public_user(?array $override = null): array
{
    $u = $override ?? current_user();
    return [
        'id'      => (int) $u['id'],
        'name'    => $u['name'],
        'email'   => $u['email'],
        'credits' => (int) $u['credits'],
        'role'    => $u['role'],
    ];
}

// -----------------------------------------------------------------
// Servicos ativos + parametros (ordenados)
// -----------------------------------------------------------------
function active_services(): array
{
    $rows = db()->query(
        'SELECT s.id, s.name, s.description, s.cost, s.endpoint
         FROM api_services s
         WHERE s.status = "active"
         ORDER BY s.name'
    )->fetchAll();

    $params = db()->query(
        'SELECT p.api_id, p.param_key, p.label, p.placeholder, p.required, p.sort_order
         FROM api_parameters p
         ORDER BY p.api_id, p.sort_order, p.id'
    )->fetchAll();

    $byApi = [];
    foreach ($params as $p) {
        $byApi[(int) $p['api_id']][] = [
            'key'        => $p['param_key'],
            'label'      => $p['label'],
            'placeholder'=> $p['placeholder'],
            'required'   => (bool) $p['required'],
        ];
    }

    $services = [];
    foreach ($rows as $s) {
        $services[] = [
            'id'          => (int) $s['id'],
            'name'        => $s['name'],
            'description' => $s['description'],
            'cost'        => (int) $s['cost'],
            'parameters'  => $byApi[(int) $s['id']] ?? [],
        ];
    }
    return $services;
}

// -----------------------------------------------------------------
// Historico de consultas do usuario (ultimas 50)
// -----------------------------------------------------------------
function user_history(): array
{
    $stmt = db()->prepare(
        'SELECT service_name, queried_value, status, cost, created_at
         FROM query_logs
         WHERE user_id = ?
         ORDER BY id DESC
         LIMIT 50'
    );
    $stmt->execute([(int) current_user()['id']]);
    return $stmt->fetchAll();
}

// -----------------------------------------------------------------
// Perfil do proprio usuario (nome, email, senha)
// -----------------------------------------------------------------
function handle_profile(): void
{
    $user = current_user();

    $name  = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
    $email = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 190);
    $cur   = (string) ($_POST['current_password'] ?? '');
    $new   = (string) ($_POST['new_password'] ?? '');
    $conf  = (string) ($_POST['confirm_password'] ?? '');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'data' => null, 'error' => 'Nome e email validos sao obrigatorios.'], 422);
    }

    $dup = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
    $dup->execute([$email, (int) $user['id']]);
    if ($dup->fetch()) {
        json_response(['success' => false, 'data' => null, 'error' => 'Este email ja esta em uso por outro usuario.'], 409);
    }

    if ($new !== '' || $conf !== '') {
        if ($new === '' || $new !== $conf) {
            json_response(['success' => false, 'data' => null, 'error' => 'A nova senha e a confirmacao precisam ser identicas.'], 422);
        }
        if (strlen($new) < 8) {
            json_response(['success' => false, 'data' => null, 'error' => 'A nova senha precisa ter ao menos 8 caracteres.'], 422);
        }
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
        if (!password_verify($cur, (string) $stmt->fetchColumn())) {
            json_response(['success' => false, 'data' => null, 'error' => 'A senha atual informada esta incorreta.'], 422);
        }
    }

    if ($new !== '') {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        db()->prepare('UPDATE users SET name = ?, email = ?, password_hash = ? WHERE id = ?')
            ->execute([$name, $email, $hash, (int) $user['id']]);
        system_log('profile_update', 'Perfil e senha atualizados', (int) $user['id']);
    } else {
        db()->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')
            ->execute([$name, $email, (int) $user['id']]);
        system_log('profile_update', 'Perfil atualizado', (int) $user['id']);
    }

    session_regenerate_id(true);
    json_response(['success' => true, 'data' => public_user(), 'error' => null]);
}

// -----------------------------------------------------------------
// Consulta dinamica
// -----------------------------------------------------------------
function handle_query(): void
{
    $user = current_user();

    $apiId  = (int) ($_POST['api_id'] ?? 0);
    $values = json_decode((string) ($_POST['values'] ?? '{}'), true);
    if (!is_array($values)) {
        $values = [];
    }

    // 1. Busca a configuracao da API no banco (nunca confia no frontend).
    $stmt = db()->prepare(
        'SELECT id, name, endpoint, method, cost, status, api_key
         FROM api_services WHERE id = ?'
    );
    $stmt->execute([$apiId]);
    $api = $stmt->fetch();

    if (!$api || $api['status'] !== 'active') {
        json_response(['success' => false, 'data' => null, 'error' => 'Servico indisponivel.'], 400);
    }

    // 2. Valida os parametros enviados contra os cadastrados.
    $pstmt = db()->prepare(
        'SELECT param_key, label, required FROM api_parameters WHERE api_id = ? ORDER BY sort_order, id'
    );
    $pstmt->execute([$apiId]);
    $params = $pstmt->fetchAll();

    if (empty($params)) {
        json_response(['success' => false, 'data' => null, 'error' => 'Servico mal configurado.'], 400);
    }

    $cleanValues = [];
    $cleanKeys   = [];
    foreach ($params as $param) {
        $raw = trim((string) ($values[$param['param_key']] ?? ''));
        if ($raw === '' && $param['required']) {
            json_response([
                'success' => false,
                'data'    => null,
                'error'   => 'O campo ' . $param['label'] . ' e obrigatorio.',
            ], 422);
        }
        if ($raw !== '') {
            $cleanValues[$param['param_key']] = mb_substr($raw, 0, 190);
            $cleanKeys[] = $param['param_key'];
        }
    }

    if (empty($cleanValues)) {
        json_response([
            'success' => false,
            'data'    => null,
            'error'   => 'Informe ao menos um valor para a consulta.',
        ], 422);
    }

    // 3. Creditos: custo vem do banco; debita antes de chamar a API.
    $cost   = (int) $api['cost'];
    $before = (int) $user['credits'];
    if ($before < $cost) {
        system_log('query_blocked', 'Creditos insuficientes para ' . $api['name'], (int) $user['id']);
        json_response([
            'success' => false,
            'data'    => null,
            'error'   => 'Creditos insuficientes para realizar esta consulta.',
        ], 402);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT credits FROM users WHERE id = ? FOR UPDATE');
        $lock->execute([(int) $user['id']]);
        $current = (int) $lock->fetchColumn();
        if ($current < $cost) {
            $pdo->rollBack();
            json_response([
                'success' => false,
                'data'    => null,
                'error'   => 'Creditos insuficientes para realizar esta consulta.',
            ], 402);
        }
        $after = $current - $cost;
        $pdo->prepare('UPDATE users SET credits = ? WHERE id = ?')->execute([$after, (int) $user['id']]);
        $pdo->prepare(
            'INSERT INTO credit_transactions
                (user_id, amount, type, reason, balance_before, balance_after)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $user['id'],
            -$cost,
            'consume',
            'Consulta em ' . $api['name'],
            $current,
            $after,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['success' => false, 'data' => null, 'error' => 'Falha ao debitar creditos. Tente novamente.'], 500);
    }

    // 4. Monta a URL e executa a requisicao externa.
    // Endpoints podem usar placeholder no caminho (ex.: /ws/{cep}/json/);
    // o restante dos parametros vai na query string (GET) ou no body (POST).
    $timeout = (int) setting('query_timeout', '10');
    $url     = $api['endpoint'];
    $pathKeys = [];
    if (preg_match_all('/\{([a-zA-Z0-9_.-]+)\}/', $url, $m)) {
        $pathKeys = $m[1];
    }
    foreach ($pathKeys as $k) {
        if (isset($cleanValues[$k])) {
            $url = str_replace('{' . $k . '}', rawurlencode($cleanValues[$k]), $url);
        }
    }
    $rest  = array_diff_key($cleanValues, array_flip($pathKeys));
    $query = http_build_query($rest);
    if ($api['method'] === 'GET') {
        if ($query !== '') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
        }
        $postFields = null;
    } else {
        $url = $api['endpoint'];
        $postFields = $query;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => max(1, $timeout),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'OrionBuscas/' . APP_VERSION,
        CURLOPT_HTTPHEADER     => build_headers($api),
    ]);
    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    }

    $body      = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // 5. Processa a resposta.
    $data        = null;
    $logStatus   = 'success';
    $logError    = null;
    $emptyNotice = null;

    if ($body === false || $body === '') {
        $logStatus = 'error';
        $logError  = $curlError !== ''
            ? 'Falha na conexao com a API externa.'
            : 'A API externa nao retornou dados.';
    } else {
        // Remove prefixo/assinatura nao-JSON (ex.: "api desenvolvida por ...") antes do decode.
        $cleaned = $body;
        $firstJson = strcspn($body, "{}");
        if ($firstJson > 0) {
            $prefix = trim(mb_substr($body, 0, $firstJson));
            // So trata como "lixo" se o prefixo nao parece JSON (ex.: comeca com '[').
            if ($prefix === '' || $prefix[0] !== '[') {
                $cleaned = substr($body, $firstJson);
            }
        }

        $decoded = json_decode($cleaned, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            // API que retorna texto puro: apresenta como string simples.
            $text = trim((string) $body);
            if ($httpCode >= 200 && $httpCode < 300 && $text !== '') {
                $data = ['resultado' => mb_substr($text, 0, 4000)];
            } else {
                $logStatus = 'error';
                $logError  = http_error_message($httpCode);
            }
        } else {
            [$data, $isEmpty, $notice] = normalize_external_response($decoded);
            if ($isEmpty) {
                // Envelope "sucesso: false" chega mesmo com HTTP 500 nesta API;
                // o corpo ja tem a mensagem, entao trata como vazio, nao erro.
                $logStatus = 'empty';
                $emptyNotice = $notice;
            } elseif ($httpCode < 200 || $httpCode >= 300) {
                $logStatus = 'error';
                $logError  = http_error_message($httpCode);
            }
        }
    }

    // 6. Registra no historico do usuario e no log do sistema.
    $queried = implode(' | ', array_map(fn($k) => $cleanValues[$k], array_keys($cleanValues)));
    db()->prepare(
        'INSERT INTO query_logs
            (user_id, api_id, service_name, queried_value, status, cost, http_status, error_message)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        (int) $user['id'],
        $apiId,
        $api['name'],
        mb_substr($queried, 0, 190),
        $logStatus,
        $cost,
        $httpCode > 0 ? $httpCode : null,
        $logError !== null ? mb_substr($logError, 0, 255) : null,
    ]);

    system_log('query', $api['name'] . ' (' . $logStatus . ')', (int) $user['id']);

    if ($logStatus === 'success') {
        json_response([
            'success' => true,
            'data'    => [
                'result'  => $data,
                'credits' => $after,
            ],
            'error'   => null,
        ]);
    }
    if ($logStatus === 'empty') {
        json_response([
            'success' => true,
            'data'    => [
                'result'  => $data,
                'credits' => $after,
                'empty'   => true,
            ],
            'error'   => $emptyNotice ?? 'Nenhum resultado encontrado para a consulta.',
        ]);
    }

    json_response([
        'success' => false,
        'data'    => ['credits' => $after],
        'error'   => $logError ?? 'Nao foi possivel realizar a consulta.',
    ], 502);
}

/**
 * Normaliza a resposta externa para o payload {result, empty?, notice?}.
 *
 * Suporta dois formatos:
 *  - Envelope: {"sucesso": bool, "resultado": {...}, "mensagem"/"erro": "..."}.
 *    Retorna somente o "resultado" (ou a mensagem quando sucesso=false).
 *  - Direto: qualquer JSON sem a chave "sucesso" (passa direto).
 *
 * Retorno: [array $data, bool $isEmpty, ?string $notice]
 */
function normalize_external_response($decoded): array
{
    if (!is_array($decoded) || empty($decoded)) {
        return [[], true, null];
    }

    $isEnvelope = array_key_exists('sucesso', $decoded);
    if (!$isEnvelope) {
        // Padrao de "nao encontrado" via chave de erro (ex.: ViaCEP: {"erro":"true"}).
        if (isset($decoded['erro']) && in_array(strtolower((string) $decoded['erro']), ['true', '1'], true)) {
            return [[], true, 'Nenhum registro encontrado.'];
        }
        return [$decoded, false, null];
    }

    // Envelope de sucesso: exibe apenas o bloco de resultado (ignora tempo etc.).
    if (empty($decoded['sucesso'])) {
        $msg = (string) ($decoded['mensagem'] ?? $decoded['erro'] ?? '');
        return [
            ['mensagem' => $msg !== '' ? mb_substr($msg, 0, 500) : 'Nenhum registro encontrado.'],
            true,
            $msg !== '' ? mb_substr($msg, 0, 500) : null,
        ];
    }

    // Alguns endpoints usam "resultados" (plural) para o bloco de dados.
    $result = $decoded['resultado'] ?? $decoded['resultados'] ?? $decoded;
    if (is_array($result)) {
        return [$result, count($result) === 0, null];
    }
    return [$result, $result === null || $result === '' || $result === [], null];
}

function build_headers(array $api): array
{
    $headers = ['Accept: application/json'];
    $key = $api['api_key'] ?? '';
    if ($key !== '') {
        // Convencao: chaves iniciando com "bearer " viram Authorization: Bearer <chave>.
        if (stripos($key, 'bearer ') === 0) {
            $headers[] = 'Authorization: ' . $key;
        } else {
            $headers[] = 'X-API-Key: ' . $key;
        }
    }
    return $headers;
}

function http_error_message(int $code): string
{
    return match (true) {
        $code === 0     => 'API externa indisponivel ou tempo limite excedido.',
        $code < 400     => 'Falha inesperada na chamada externa.',
        $code === 401   => 'Falha de autenticacao na API externa.',
        $code === 403   => 'Acesso negado pela API externa.',
        $code === 404   => 'Recurso nao encontrado na API externa.',
        $code === 429   => 'Limite de requisicoes da API externa atingido.',
        $code < 500     => 'Parametros recusados pela API externa (HTTP ' . $code . ').',
        default         => 'Erro na API externa (HTTP ' . $code . ').',
    };
}

// =================================================================
// Painel administrativo
// =================================================================

function admin_stats(): array
{
    $stats = [
        'users'        => (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'active_apis'  => (int) db()->query("SELECT COUNT(*) FROM api_services WHERE status = 'active'")->fetchColumn(),
        'total_apis'   => (int) db()->query('SELECT COUNT(*) FROM api_services')->fetchColumn(),
        'total_queries'=> (int) db()->query('SELECT COUNT(*) FROM query_logs')->fetchColumn(),
        'credits_used' => (int) db()->query('SELECT COALESCE(SUM(cost), 0) FROM query_logs')->fetchColumn(),
        'errors'       => (int) db()->query("SELECT COUNT(*) FROM query_logs WHERE status = 'error'")->fetchColumn(),
        'recent'       => db()->query(
            'SELECT q.service_name, q.status, q.created_at, u.name AS user_name
             FROM query_logs q
             LEFT JOIN users u ON u.id = q.user_id
             ORDER BY q.id DESC
             LIMIT 8'
        )->fetchAll(),
    ];
    return $stats;
}

function admin_services(): array
{
    $rows = db()->query(
        'SELECT s.id, s.name, s.description, s.endpoint, s.method, s.cost, s.status,
                s.api_key IS NOT NULL AND s.api_key <> "" AS has_key,
                s.created_at, s.updated_at
         FROM api_services s
         ORDER BY s.name'
    )->fetchAll();

    $params = db()->query(
        'SELECT p.api_id, p.param_key, p.label, p.placeholder, p.required, p.sort_order
         FROM api_parameters p ORDER BY p.api_id, p.sort_order, p.id'
    )->fetchAll();

    $byApi = [];
    foreach ($params as $p) {
        $byApi[(int) $p['api_id']][] = $p;
    }
    foreach ($rows as &$r) {
        $r['parameters'] = $byApi[(int) $r['id']] ?? [];
        $r['has_key'] = (bool) $r['has_key'];
    }
    return $rows;
}

/**
 * Cria ou edita um servico + seus parametros.
 * O novo campo de chave de API e enviado apenas quando preenchido.
 */
function admin_service_save(): void
{
    $id     = (int) ($_POST['service_id'] ?? 0);
    $name   = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
    $desc   = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255);
    $endp   = mb_substr(trim((string) ($_POST['endpoint'] ?? '')), 0, 500);
    $method = (string) ($_POST['method'] ?? 'GET');
    $cost   = (int) ($_POST['cost'] ?? 0);
    $status = (string) ($_POST['status'] ?? 'active');
    $apiKey = trim((string) ($_POST['api_key'] ?? ''));

    if ($name === '' || $endp === '') {
        json_response(['success' => false, 'data' => null, 'error' => 'Nome e endpoint sao obrigatorios.'], 422);
    }
    if (!preg_match('#^https?://#i', $endp)) {
        json_response(['success' => false, 'data' => null, 'error' => 'O endpoint precisa iniciar com http:// ou https://.'], 422);
    }
    if (!in_array($method, ['GET', 'POST'], true)) {
        json_response(['success' => false, 'data' => null, 'error' => 'Metodo invalido. Use GET ou POST.'], 422);
    }
    if ($cost < 0 || $cost > 1000) {
        json_response(['success' => false, 'data' => null, 'error' => 'Custo deve estar entre 0 e 1000 creditos.'], 422);
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        json_response(['success' => false, 'data' => null, 'error' => 'Status invalido.'], 422);
    }

    // Parametros: arrays paralelos [key, label, placeholder, required, sort]
    $keys   = (array) ($_POST['param_keys'] ?? []);
    $labels = (array) ($_POST['param_labels'] ?? []);
    $phs    = (array) ($_POST['param_placeholders'] ?? []);
    $reqs   = (array) ($_POST['param_required'] ?? []);

    $params = [];
    foreach ($keys as $i => $k) {
        $key = mb_substr(trim((string) $k), 0, 64);
        $label = mb_substr(trim((string) ($labels[$i] ?? '')), 0, 120);
        if ($key === '' || $label === '') {
            continue; // linhas em branco sao ignoradas
        }
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $key)) {
            json_response([
                'success' => false,
                'data'    => null,
                'error'   => 'Nome de parametro invalido: ' . $key . ' (use letras, numeros, ponto, hifen e sublinhado).',
            ], 422);
        }
        $params[] = [
            'key'   => $key,
            'label' => $label,
            'ph'    => mb_substr(trim((string) ($phs[$i] ?? '')), 0, 120),
            'req'   => !empty($reqs[$i]) ? 1 : 0,
        ];
    }
    if (empty($params)) {
        json_response(['success' => false, 'data' => null, 'error' => 'Defina ao menos um parametro para a API.'], 422);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $exists = $pdo->prepare('SELECT id FROM api_services WHERE id = ?');
            $exists->execute([$id]);
            if (!$exists->fetch()) {
                throw new RuntimeException('Servico nao encontrado.');
            }
            $upd = $pdo->prepare(
                'UPDATE api_services SET name = ?, description = ?, endpoint = ?, method = ?, cost = ?, status = ?,
                        api_key = COALESCE(?, api_key) WHERE id = ?'
            );
            $upd->execute([$name, $desc, $endp, $method, $cost, $status, $apiKey !== '' ? $apiKey : null, $id]);
            $pdo->prepare('DELETE FROM api_parameters WHERE api_id = ?')->execute([$id]);
            $action = 'api_updated';
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO api_services (name, description, endpoint, method, cost, status, api_key)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$name, $desc, $endp, $method, $cost, $status, $apiKey !== '' ? $apiKey : null]);
            $id = (int) $pdo->lastInsertId();
            $action = 'api_created';
        }

        $pIns = $pdo->prepare(
            'INSERT INTO api_parameters (api_id, param_key, label, placeholder, required, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($params as $i => $p) {
            $pIns->execute([$id, $p['key'], $p['label'], $p['ph'], $p['req'], $i]);
        }

        $pdo->commit();
        system_log($action, 'Servico ' . $name . ' (' . $id . ')', (int) current_user()['id']);
        json_response(['success' => true, 'data' => ['id' => $id], 'error' => null]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['success' => false, 'data' => null, 'error' => 'Falha ao salvar o servico.'], 500);
    }
}

function admin_service_toggle(): void
{
    $id = (int) ($_POST['service_id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    if (!in_array($status, ['active', 'inactive'], true)) {
        json_response(['success' => false, 'data' => null, 'error' => 'Status invalido.'], 422);
    }
    $stmt = db()->prepare('UPDATE api_services SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
    if ($stmt->rowCount() > 0) {
        system_log('api_status', 'Servico ' . $id . ' alterado para ' . $status, (int) current_user()['id']);
    }
    json_response(['success' => true, 'data' => null, 'error' => null]);
}

function admin_service_delete(): void
{
    $id = (int) ($_POST['service_id'] ?? 0);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $name = $pdo->prepare('SELECT name FROM api_services WHERE id = ?');
        $name->execute([$id]);
        $n = (string) $name->fetchColumn();
        $pdo->prepare('DELETE FROM api_services WHERE id = ?')->execute([$id]);
        $pdo->commit();
        system_log('api_deleted', 'Servico removido: ' . $n, (int) current_user()['id']);
        json_response(['success' => true, 'data' => null, 'error' => null]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['success' => false, 'data' => null, 'error' => 'Falha ao excluir o servico.'], 500);
    }
}

function admin_users(): array
{
    return db()->query(
        'SELECT u.id, u.name, u.email, u.credits, u.role, u.status, u.created_at, u.last_login,
                (SELECT COUNT(*) FROM query_logs q WHERE q.user_id = u.id) AS total_queries
         FROM users u ORDER BY u.id'
    )->fetchAll();
}

function admin_user_create(): void
{
    $name   = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
    $email  = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 190);
    $pass   = (string) ($_POST['password'] ?? '');
    $role   = (string) ($_POST['role'] ?? 'user');
    $credits= (int) ($_POST['credits'] ?? 0);

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8 || $credits < 0) {
        json_response(['success' => false, 'data' => null, 'error' => 'Dados invalidos. A senha precisa de 8+ caracteres.'], 422);
    }
    if (!in_array($role, ['user', 'admin'], true)) {
        $role = 'user';
    }
    $dup = db()->prepare('SELECT id FROM users WHERE email = ?');
    $dup->execute([$email]);
    if ($dup->fetch()) {
        json_response(['success' => false, 'data' => null, 'error' => 'Este email ja esta cadastrado.'], 409);
    }

    try {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, credits, role, status) VALUES (?, ?, ?, ?, ?, "active")'
        )->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), $credits, $role]);
        $uid = (int) $pdo->lastInsertId();
        if ($credits > 0) {
            $pdo->prepare(
                'INSERT INTO credit_transactions (user_id, amount, type, reason, balance_before, balance_after)
                 VALUES (?, ?, "grant", "Creditos iniciais", 0, ?)'
            )->execute([$uid, $credits, $credits]);
        }
        $pdo->commit();
        system_log('user_created', 'Usuario ' . $email . ' criado', (int) current_user()['id']);
        json_response(['success' => true, 'data' => ['id' => $uid], 'error' => null]);
    } catch (Throwable $e) {
        db()->rollBack();
        json_response(['success' => false, 'data' => null, 'error' => 'Falha ao criar o usuario.'], 500);
    }
}

function admin_user_update(): void
{
    $id     = (int) ($_POST['user_id'] ?? 0);
    $name   = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
    $email  = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 190);
    $role   = (string) ($_POST['role'] ?? 'user');
    $status = (string) ($_POST['status'] ?? 'active');

    if ($id === (int) current_user()['id']) {
        json_response(['success' => false, 'data' => null, 'error' => 'Voce nao pode alterar os proprios dados por aqui.'], 422);
    }
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'data' => null, 'error' => 'Nome e email validos sao obrigatorios.'], 422);
    }
    if (!in_array($role, ['user', 'admin'], true) || !in_array($status, ['active', 'blocked'], true)) {
        json_response(['success' => false, 'data' => null, 'error' => 'Valor invalido.'], 422);
    }
    $dup = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
    $dup->execute([$email, $id]);
    if ($dup->fetch()) {
        json_response(['success' => false, 'data' => null, 'error' => 'Email ja utilizado por outro usuario.'], 409);
    }

    db()->prepare('UPDATE users SET name = ?, email = ?, role = ?, status = ? WHERE id = ?')
        ->execute([$name, $email, $role, $status, $id]);
    system_log('user_updated', 'Usuario ' . $email . ' atualizado', (int) current_user()['id']);
    json_response(['success' => true, 'data' => null, 'error' => null]);
}

function admin_user_password(): void
{
    $id   = (int) ($_POST['user_id'] ?? 0);
    $pass = (string) ($_POST['password'] ?? '');
    if (strlen($pass) < 8) {
        json_response(['success' => false, 'data' => null, 'error' => 'A senha precisa ter ao menos 8 caracteres.'], 422);
    }
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
    system_log('user_password', 'Senha redefinida para o usuario ' . $id, (int) current_user()['id']);
    json_response(['success' => true, 'data' => null, 'error' => null]);
}

function admin_user_delete(): void
{
    $id = (int) ($_POST['user_id'] ?? 0);
    if ($id === (int) current_user()['id']) {
        json_response(['success' => false, 'data' => null, 'error' => 'Voce nao pode excluir a propria conta.'], 422);
    }
    $stmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $name = (string) $stmt->fetchColumn();
    if ($name === '') {
        json_response(['success' => false, 'data' => null, 'error' => 'Usuario nao encontrado.'], 404);
    }
    try {
        // FKs ON DELETE CASCADE (query_logs, credit_transactions) e
        // ON DELETE SET NULL (system_logs) cuidam do restante.
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        system_log('user_deleted', 'Usuario removido: ' . $name, (int) current_user()['id']);
        json_response(['success' => true, 'data' => null, 'error' => null]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'data' => null, 'error' => 'Falha ao excluir o usuario.'], 500);
    }
}

function admin_credit_move(): void
{
    $id     = (int) ($_POST['user_id'] ?? 0);
    $amount = (int) ($_POST['amount'] ?? 0);
    $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255);
    $op     = (string) ($_POST['op'] ?? '');

    if ($amount <= 0) {
        json_response(['success' => false, 'data' => null, 'error' => 'Informe uma quantidade positiva.'], 422);
    }
    if (!in_array($op, ['add', 'remove'], true)) {
        json_response(['success' => false, 'data' => null, 'error' => 'Operacao invalida.'], 422);
    }

    $type = $op === 'add' ? 'grant' : 'revoke';
    $delta = $op === 'add' ? $amount : -$amount;
    $prefix = $op === 'add' ? 'Credito concedido pelo admin' : 'Credito removido pelo admin';
    $full = $prefix . ($reason !== '' ? ': ' . $reason : '');

    [$ok, $msg, $balance] = apply_credit($id, $delta, $type, $full);
    if (!$ok) {
        json_response(['success' => false, 'data' => null, 'error' => $msg], 422);
    }
    system_log($op === 'add' ? 'credits_added' : 'credits_removed',
        $amount . ' credito(s) para o usuario ' . $id, (int) current_user()['id']);
    json_response(['success' => true, 'data' => ['balance' => $balance], 'error' => null]);
}

function admin_credit_transactions(): array
{
    return db()->query(
        'SELECT t.amount, t.type, t.reason, t.balance_before, t.balance_after, t.created_at,
                u.name AS user_name
         FROM credit_transactions t
         JOIN users u ON u.id = t.user_id
         ORDER BY t.id DESC
         LIMIT 60'
    )->fetchAll();
}

function admin_logs(): array
{
    return db()->query(
        'SELECT l.action, l.detail, l.ip_address, l.created_at, u.name AS user_name
         FROM system_logs l
         LEFT JOIN users u ON u.id = l.user_id
         ORDER BY l.id DESC
         LIMIT 120'
    )->fetchAll();
}

function admin_settings_save(): void
{
    $name    = mb_substr(trim((string) ($_POST['platform_name'] ?? '')), 0, 80);
    $credits = (int) ($_POST['default_credits'] ?? 0);
    $timeout = (int) ($_POST['query_timeout'] ?? 0);

    if ($name === '') {
        json_response(['success' => false, 'data' => null, 'error' => 'O nome da plataforma e obrigatorio.'], 422);
    }
    if ($credits < 0 || $credits > 100000 || $timeout < 3 || $timeout > 60) {
        json_response(['success' => false, 'data' => null, 'error' => 'Valores fora do intervalo permitido.'], 422);
    }

    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute(['platform_name', $name]);
    $stmt->execute(['default_credits', (string) $credits]);
    $stmt->execute(['query_timeout', (string) $timeout]);
    system_log('settings_updated', 'Configuracoes gerais atualizadas', (int) current_user()['id']);
    json_response(['success' => true, 'data' => null, 'error' => null]);
}
