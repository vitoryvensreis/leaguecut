<?php
declare(strict_types=1);

const LC_ROOT = __DIR__ . '/..';

function lc_load_env(): void {
    $file = LC_ROOT . '/.env';
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || getenv($key) !== false) continue;
        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) $value = substr($value, 1, -1);
        putenv($key . '=' . $value);
    }
}
lc_load_env();
function lc_env(string $key, string $default = ''): string {
    $v = getenv($key);
    return $v === false ? $default : (string)$v;
}
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('America/Fortaleza');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (PHP_SAPI !== 'cli') {
    session_name('leaguecut_session');
    session_set_cookie_params(['lifetime' => 0,'path' => '/','secure' => $https,'httponly' => true,'samesite' => 'Lax']);
    session_start();
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
}
function lc_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . lc_env('DB_HOST','127.0.0.1') . ';port=' . lc_env('DB_PORT','3306') . ';dbname=' . lc_env('DB_NAME','leaguecut') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, lc_env('DB_USER'), lc_env('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    return $pdo;
}
function lc_query(string $sql, array $args = []): PDOStatement {
    $stmt = lc_db()->prepare($sql);
    $stmt->execute($args);
    return $stmt;
}
function lc_one(string $sql, array $args = []): ?array {
    $r = lc_query($sql, $args)->fetch();
    return $r ?: null;
}
function lc_all(string $sql, array $args = []): array { return lc_query($sql, $args)->fetchAll(); }
function lc_e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function lc_money(int|string $cents): string { return 'R$ ' . number_format(((int)$cents) / 100, 2, ',', '.'); }
function lc_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function lc_csrf(): string { return '<input type="hidden" name="_csrf" value="' . lc_e(lc_token()) . '">'; }
function lc_check_csrf(): void {
    if (!hash_equals(lc_token(), (string)($_POST['_csrf'] ?? ''))) {
        http_response_code(419); exit('Sessão expirada. Atualize a página e tente novamente.');
    }
}
function lc_flash(string $message, string $type = 'info'): void { $_SESSION['flash'] = ['message' => $message,'type' => $type]; }
function lc_take_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function lc_redirect(string $path): never { header('Location: ' . $path, true, 303); exit; }
function lc_user(): ?array {
    if (empty($_SESSION['uid']) && function_exists('lc_remember_restore')) lc_remember_restore();
    if (empty($_SESSION['uid'])) return null;
    $uid=(int)$_SESSION['uid'];
    // Versions invalidate other browser sessions after MFA enrollment/revocation.
    // Before MFA exists, legacy sessions remain available without disruption.
    if (function_exists('lc_mfa_session_is_valid') && !lc_mfa_session_is_valid($uid)) {
        unset($_SESSION['uid'],$_SESSION['mfa_verified'],$_SESSION['mfa_version']);
        return null;
    }
    return lc_one('SELECT id,full_name,nickname,email,whatsapp,instagram,portfolio_url,role,status,consent_whatsapp FROM users WHERE id=?',[$uid]);
}
function lc_login_required(bool $approved = true): array {
    $u = lc_user();
    if (!$u) lc_redirect(!empty($_SESSION['mfa_pending_uid'])?'/seguranca/verificar':'/entrar');
    if ($approved && $u['role'] !== 'admin' && $u['status'] !== 'approved') lc_redirect('/aguardando');
    return $u;
}
function lc_admin(): array {
    $u = lc_login_required(false);
    if ($u['role'] !== 'admin' || $u['status'] !== 'approved') { http_response_code(403); exit('Acesso restrito.'); }
    $path=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)?:'';
    if(str_starts_with($path,'/admin') && function_exists('lc_admin_can_url') && !lc_admin_can_url($u,$path)){
        if($path==='/admin' && ($_SERVER['REQUEST_METHOD']??'GET')==='GET' && function_exists('lc_admin_first_url'))lc_redirect(lc_admin_first_url($u));
        http_response_code(403);exit('Você não tem permissão para acessar esta aba.');
    }
    return $u;
}
function lc_settings(): array {
    $rows = lc_all('SELECT setting_key,setting_value FROM platform_settings');
    return array_column($rows, 'setting_value', 'setting_key');
}
function lc_post(string $key, int $max = 800): string { return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $max); }
function lc_bool(string $key): bool { return !empty($_POST[$key]); }
function lc_encrypt(string $value): string {
    $secret = base64_decode(lc_env('APP_KEY'), true);
    if (!$secret || strlen($secret) !== 32) throw new RuntimeException('APP_KEY inválida. Gere uma chave de 32 bytes.');
    $iv = random_bytes(12);
    $encrypted = openssl_encrypt($value, 'aes-256-gcm', $secret, OPENSSL_RAW_DATA, $iv, $tag);
    if ($encrypted === false) throw new RuntimeException('Falha ao criptografar.');
    return base64_encode($iv . $tag . $encrypted);
}
function lc_decrypt(string $cipher): string {
    $secret = base64_decode(lc_env('APP_KEY'), true);
    $data = base64_decode($cipher, true);
    if (!$secret || strlen($secret) !== 32 || !$data || strlen($data) < 29) return '';
    return (string)openssl_decrypt(substr($data,28), 'aes-256-gcm', $secret, OPENSSL_RAW_DATA, substr($data,0,12), substr($data,12,16));
}
function lc_valid_https(string $url): bool { return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) && strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https'; }
function lc_network_url(string $network, string $username): string {
    $clean = ltrim($username,'@');
    return $network === 'tiktok' ? 'https://www.tiktok.com/@' . rawurlencode($clean) : 'https://www.instagram.com/' . rawurlencode($clean) . '/';
}
function lc_slug(string $text): string {
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    return ($text ?: 'campanha') . '-' . bin2hex(random_bytes(3));
}
function lc_int(string $name, int $default, int $max): int {
    $v = filter_var($_POST[$name] ?? null, FILTER_VALIDATE_INT);
    return $v === false || $v === null ? $default : max(0,min($max,$v));
}
function lc_cents(string $name, int $default = 0): int {
    $input = trim((string)($_POST[$name] ?? ''));
    if (!preg_match('/^\d{1,8}(?:[,.]\d{1,2})?$/', $input)) return $default;
    $p = explode('.', str_replace(',', '.', $input));
    return ((int)$p[0]) * 100 + (int)str_pad($p[1] ?? '', 2, '0');
}
function lc_balance(int $userId): array {
    $total = (int)(lc_one('SELECT COALESCE(SUM(amount_cents),0) AS total FROM ledger_entries WHERE user_id=?',[$userId])['total'] ?? 0);
    $blocked = (int)(lc_one("SELECT COALESCE(SUM(amount_cents),0) AS total FROM withdrawals WHERE user_id=? AND status IN ('requested','processing','paid')",[$userId])['total'] ?? 0);
    return ['earned'=>$total,'reserved'=>$blocked,'available'=>max(0,$total-$blocked)];
}
function lc_outbox(int $userId, string $channel, string $template, array $payload): void {
    lc_query('INSERT INTO notification_outbox(user_id,channel,template_name,payload_json) VALUES(?,?,?,?)',[$userId,$channel,$template,json_encode($payload,JSON_THROW_ON_ERROR)]);
}
