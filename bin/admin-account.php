<?php
declare(strict_types=1);

/**
 * Provision or promote an admin directly in the LeagueCut users table.
 * The password is read from an interactive TTY without terminal echo.
 * NEVER pass passwords as command arguments, environment variables or GitHub secrets.
 *
 * Usage: php bin/admin-account.php admin@example.com
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require dirname(__DIR__) . '/app/core.php';

function admin_input(string $label): string {
    fwrite(STDOUT, $label);
    $line = fgets(STDIN);
    if ($line === false) throw new RuntimeException('Entrada interrompida.');
    return trim($line);
}
function admin_hidden(string $label): string {
    fwrite(STDOUT, $label);
    $output = [];
    $exitCode = 1;
    exec('stty -g 2>/dev/null', $output, $exitCode);
    if ($exitCode !== 0 || empty($output[0])) {
        throw new RuntimeException('Nao foi possivel proteger a digitacao da senha.');
    }
    $old = trim($output[0]);
    $disabled = false;
    try {
        exec('stty -echo 2>/dev/null', $unused, $code);
        if ($code !== 0) throw new RuntimeException('Nao foi possivel ocultar a senha.');
        $disabled = true;
        $value = fgets(STDIN);
    } finally {
        if ($disabled) exec('stty ' . escapeshellarg($old) . ' 2>/dev/null');
        fwrite(STDOUT, "\n");
    }
    if ($value === false) throw new RuntimeException('Entrada interrompida.');
    return rtrim($value, "\r\n");
}

try {
    if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
        throw new RuntimeException('Execute este comando interativamente no terminal SSH da VPS.');
    }
    $email = mb_strtolower(trim((string)($argv[1] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        throw new RuntimeException('Uso: php bin/admin-account.php administrador@dominio.com');
    }
    $db = lc_db();
    $existing = lc_one('SELECT id,full_name,role FROM users WHERE email=?', [$email]);
    if ($existing) {
        fwrite(STDOUT, "Conta encontrada. O perfil sera aprovado como administrador e a senha sera substituida.\n");
        $name = (string)$existing['full_name'];
    } else {
        $name = admin_input('Nome completo do administrador: ');
        if (mb_strlen($name) < 3 || mb_strlen($name) > 160) {
            throw new RuntimeException('Informe um nome de 3 a 160 caracteres.');
        }
    }
    fwrite(STDOUT, "Escolha uma senha NOVA que nao tenha sido compartilhada em chats ou imagens.\n");
    $password = admin_hidden('Nova senha (12 a 128 caracteres): ');
    $confirm = admin_hidden('Confirme a nova senha: ');
    if (strlen($password) < 12 || strlen($password) > 128) {
        throw new RuntimeException('Senha precisa ter de 12 a 128 caracteres.');
    }
    if (!hash_equals($password, $confirm)) {
        throw new RuntimeException('As senhas nao conferem. Nenhuma alteracao foi feita.');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    unset($password, $confirm);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT id FROM users WHERE email=? FOR UPDATE');
        $stmt->execute([$email]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $update = $db->prepare("UPDATE users SET role='admin',status='approved',password_hash=?,approved_at=NOW(),approved_by=NULL WHERE id=?");
            $update->execute([$hash,(int)$id]);
            $action = 'atualizada';
        } else {
            $insert = $db->prepare("INSERT INTO users(full_name,email,password_hash,whatsapp,instagram,portfolio_url,pix_key_enc,role,status,approved_at)
                VALUES(?,?,?,'','',NULL,'','admin','approved',NOW())");
            $insert->execute([$name,$email,$hash]);
            $id = (int)$db->lastInsertId();
            $action = 'criada';
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    fwrite(STDOUT, "Conta administrativa {$action} no MySQL. ID={$id}; role=admin; status=approved.\n");
    fwrite(STDOUT, "Entre em https://leaguecut.com/entrar e abra /admin.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Nao foi possivel criar/atualizar o administrador: " . $e->getMessage() . "\n");
    exit(1);
}
