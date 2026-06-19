<?php
declare(strict_types=1);

const DB_HOST = 'localhost';
const DB_NAME = 'acisjm';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: index.php');
        exit;
    }
}

function require_admin(): void
{
    require_login();

    if (current_user()['perfil'] !== 'admin') {
        http_response_code(403);
        exit('Acesso reservado à administração.');
    }
}

function require_associado(): void
{
    require_login();

    if (current_user()['perfil'] !== 'associado') {
        http_response_code(403);
        exit('Acesso reservado a empresas associadas.');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Sessão expirada. Volte atrás e tente novamente.');
    }
}

function ensure_security_tables(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            success TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_login_attempts_lookup (email, ip_address, attempted_at)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
}

function db_column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);

    return (int) $stmt->fetchColumn() > 0;
}

function ensure_membership_payment_columns(): void
{
    $companyColumns = [
        'quota_plano'       => "ALTER TABLE empresas_associadas ADD quota_plano ENUM('mensal','semestral','anual') NULL AFTER tipo_associado",
        'quota_valor'       => "ALTER TABLE empresas_associadas ADD quota_valor DECIMAL(8,2) NULL AFTER quota_plano",
        'quota_estado'      => "ALTER TABLE empresas_associadas ADD quota_estado ENUM('pendente','pago','atrasado','isento') NOT NULL DEFAULT 'pendente' AFTER quota_valor",
        'quota_pago_em'     => "ALTER TABLE empresas_associadas ADD quota_pago_em DATE NULL AFTER quota_estado",
        'quota_validade'    => "ALTER TABLE empresas_associadas ADD quota_validade DATE NULL AFTER quota_pago_em",
        'comprovativo_quota'=> "ALTER TABLE empresas_associadas ADD comprovativo_quota VARCHAR(255) NULL AFTER quota_validade",
    ];

    foreach ($companyColumns as $column => $sql) {
        if (!db_column_exists('empresas_associadas', $column)) {
            db()->exec($sql);
        }
    }

    $requestColumns = [
        'quota_plano'        => "ALTER TABLE solicitacoes_associado ADD quota_plano ENUM('mensal','semestral','anual') NULL AFTER telefone",
        'quota_valor'        => "ALTER TABLE solicitacoes_associado ADD quota_valor DECIMAL(8,2) NULL AFTER quota_plano",
        'comprovativo_quota' => "ALTER TABLE solicitacoes_associado ADD comprovativo_quota VARCHAR(255) NULL AFTER quota_valor",
        'aceita_privacidade' => "ALTER TABLE solicitacoes_associado ADD aceita_privacidade TINYINT(1) NOT NULL DEFAULT 0 AFTER comprovativo_quota",
        'aceita_contacto'    => "ALTER TABLE solicitacoes_associado ADD aceita_contacto TINYINT(1) NOT NULL DEFAULT 0 AFTER aceita_privacidade",
    ];

    foreach ($requestColumns as $column => $sql) {
        if (!db_column_exists('solicitacoes_associado', $column)) {
            db()->exec($sql);
        }
    }
}

function membership_plans(): array
{
    return [
        'mensal'    => ['label' => 'Mensal', 'value' => 5.00, 'period' => '+1 month'],
        'semestral' => ['label' => 'Semestral', 'value' => 30.00, 'period' => '+6 months'],
        'anual'     => ['label' => 'Anual', 'value' => 60.00, 'period' => '+1 year'],
    ];
}

function membership_plan_value(?string $plan): ?float
{
    $plans = membership_plans();

    return isset($plans[$plan]) ? $plans[$plan]['value'] : null;
}

function membership_plan_label(?string $plan): string
{
    $plans = membership_plans();

    return isset($plans[$plan]) ? $plans[$plan]['label'] . ' (' . number_format($plans[$plan]['value'], 2, ',', '.') . '€)' : '-';
}

function membership_paid(?array $company): bool
{
    if (!$company) {
        return false;
    }

    if (($company['quota_estado'] ?? '') === 'isento') {
        return true;
    }

    if (($company['quota_estado'] ?? '') !== 'pago') {
        return false;
    }

    $validUntil = $company['quota_validade'] ?? null;
    if (!$validUntil) {
        return true;
    }

    return strtotime((string) $validUntil) >= strtotime(date('Y-m-d'));
}

function current_user_company(): ?array
{
    $user = current_user();
    if (!$user || empty($user['empresa_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM empresas_associadas WHERE id = ?');
    $stmt->execute([(int) $user['empresa_id']]);

    return $stmt->fetch() ?: null;
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45);
}

function login_is_blocked(string $email): bool
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM login_attempts
        WHERE email = ? AND ip_address = ? AND success = 0
        AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)"
    );
    $stmt->execute([strtolower($email), client_ip()]);

    return (int) $stmt->fetchColumn() >= 5;
}

function record_login_attempt(string $email, bool $success): void
{
    db()->prepare('INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)')
        ->execute([strtolower($email), client_ip(), $success ? 1 : 0]);
}

function clear_failed_login_attempts(string $email): void
{
    db()->prepare('DELETE FROM login_attempts WHERE email = ? AND ip_address = ? AND success = 0')
        ->execute([strtolower($email), client_ip()]);
}

function display_status(string $status): string
{
    $label = match ($status) {
        'concluido' => 'concluído',
        'em_preparacao' => 'em preparação',
        default => str_replace('_', ' ', $status),
    };

    return match ($status) {
        'ativo', 'pago', 'isento' => '<span class="badge ok">' . e($label) . '</span>',
        'suspenso', 'em_preparacao', 'pendente' => '<span class="badge warn">' . e($label) . '</span>',
        default => '<span class="badge danger">' . e($label) . '</span>',
    };
}

function ensure_default_admin(): void
{
    $countUsers = (int) db()->query('SELECT COUNT(*) FROM utilizadores')->fetchColumn();

    if ($countUsers > 0) {
        return;
    }

    $stmt = db()->prepare(
        'INSERT INTO utilizadores (nome, email, password, perfil) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        'Admin ACISJM',
        'admin@acisjm.pt',
        password_hash('Admin123!', PASSWORD_DEFAULT),
        'admin',
    ]);
}

function ensure_registration_requests_table(): void
{
    db()->exec(
        "CREATE TABLE IF NOT EXISTS solicitacoes_associado (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome_empresa VARCHAR(255) NOT NULL,
            nif VARCHAR(20) NOT NULL,
            setor_atividade VARCHAR(255),
            localidade VARCHAR(100),
            responsavel VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            telefone VARCHAR(50),
            password_hash VARCHAR(255) NOT NULL,
            estado ENUM('pendente', 'aprovado', 'rejeitado') NOT NULL DEFAULT 'pendente',
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            analisado_em DATETIME NULL,
            UNIQUE KEY uniq_solicitacao_email_estado (email, estado),
            UNIQUE KEY uniq_solicitacao_nif_estado (nif, estado)
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
}

function ensure_qr_code(int $empresaId): string
{
    $stmt = db()->prepare('SELECT codigo_qr FROM qr_codes WHERE empresa_id = ?');
    $stmt->execute([$empresaId]);
    $code = $stmt->fetchColumn();

    if ($code) {
        return (string) $code;
    }

    $code = 'ACISJM-' . $empresaId . '-' . bin2hex(random_bytes(12));
    $stmt = db()->prepare('INSERT INTO qr_codes (empresa_id, codigo_qr) VALUES (?, ?)');
    $stmt->execute([$empresaId, $code]);

    return $code;
}

function app_base_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    return $scheme . '://' . $host . ($path === '' ? '' : $path);
}
?>
