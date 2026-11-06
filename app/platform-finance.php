<?php
declare(strict_types=1);

/**
 * LeagueCut platform cash ("Caixa da plataforma").
 *
 * Money in   = creator subscription cycles confirmed as paid.
 * Money out  = clipper withdrawals paid + platform withdrawals paid.
 * Committed  = everything credited to users (ledger) and not yet paid out.
 * Available  = in - out - committed - platform withdrawals still in flight.
 *
 * The Asaas account is shared with other products, so its balance is NEVER used
 * as LeagueCut's balance: it is only checked as "enough funds" before a transfer.
 *
 * Platform withdrawal lifecycle:
 *   awaiting_authorization -> (master opens the WhatsApp link, password + 2FA)
 *   authorized -> sending -> processing -> paid
 *   side exits: refused | cancelled | expired | failed (reserve released)
 *   unknown: network/5xx while creating the transfer; never retried blindly.
 * Every step is appended to platform_finance_events (never updated/deleted).
 */
require_once __DIR__ . '/input-validation.php';

const LC_PF_LINK_TTL = 900;          // authorization link: 15 minutes
const LC_PF_REQUEST_TTL = 86400;     // unauthorized request expires after 24h
const LC_PF_LINK_MAX_SENDS = 5;
const LC_PF_LINK_COOLDOWN = 60;
const LC_PF_MIN_CENTS = 100;         // R$ 1,00
const LC_PF_MAX_CENTS = 100000000;   // R$ 1.000.000,00 per request
const LC_PF_APPROVER_DEFAULT = '5585921627669';
const LC_PF_RESERVED = ['awaiting_authorization','authorized','sending','processing','unknown'];
const LC_PF_STATUS_LABELS = [
    'awaiting_authorization'=>'Aguardando autorização','authorized'=>'Autorizado','sending'=>'Enviando ao Asaas',
    'processing'=>'Em processamento','paid'=>'Pago','failed'=>'Falhou','refused'=>'Recusado',
    'cancelled'=>'Cancelado','expired'=>'Expirado','unknown'=>'Conferir no Asaas',
];
const LC_PF_EVENT_LABELS = [
    'requested'=>'Saque solicitado','link_issued'=>'Link de autorização gerado','link_sent'=>'Link enviado por WhatsApp',
    'link_send_failed'=>'Falha ao enviar WhatsApp','authorization_denied'=>'Tentativa de autorização negada',
    'authorized'=>'Autorizado pelo master','refused'=>'Recusado pelo master','cancelled'=>'Cancelado',
    'expired'=>'Expirado sem autorização','dispatch_blocked'=>'Envio ao Asaas bloqueado','dispatch_started'=>'Enviando ao Asaas',
    'dispatched'=>'Transferência criada no Asaas','dispatch_rejected'=>'Asaas recusou a transferência',
    'dispatch_unknown'=>'Resultado incerto no Asaas','status_synced'=>'Status atualizado pelo Asaas',
    'manual_link'=>'ID do Asaas vinculado manualmente','manual_not_created'=>'Confirmado: transferência não criada',
];

function lc_pf_approver_phone(): string {
    $phone = lc_digits(lc_env('FINANCE_APPROVER_WHATSAPP', LC_PF_APPROVER_DEFAULT));
    if (!preg_match('/^55[1-9][0-9]{9,10}$/D', $phone)) throw new RuntimeException('FINANCE_APPROVER_WHATSAPP inválido no servidor.');
    return $phone;
}
function lc_pf_payouts_enabled(): bool {
    return function_exists('lc_asaas_platform_payouts_enabled') && lc_asaas_platform_payouts_enabled();
}
function lc_pf_ip_hash(): ?string {
    if (PHP_SAPI === 'cli') return null;
    return function_exists('lc_mfa_ip_hash') ? lc_mfa_ip_hash() : null;
}
/** Append-only log. Details must never contain the Pix key, tokens or credentials. */
function lc_pf_log(?int $withdrawalId, string $event, ?int $actorId, ?string $from = null, ?string $to = null, string $detail = '', ?int $amount = null): void {
    lc_query('INSERT INTO platform_finance_events(withdrawal_id,event,actor_user_id,amount_cents,status_from,status_to,detail,ip_hash) VALUES(?,?,?,?,?,?,?,?)',
        [$withdrawalId, mb_substr($event, 0, 60), $actorId, $amount, $from, $to, mb_substr($detail, 0, 255), lc_pf_ip_hash()]);
}
function lc_pf_status_label(string $status): string { return LC_PF_STATUS_LABELS[$status] ?? $status; }
function lc_pf_event_label(string $event): string { return LC_PF_EVENT_LABELS[$event] ?? $event; }

function lc_pf_sum(string $sql, array $args = []): int {
    return (int)(lc_one($sql, $args)['total'] ?? 0);
}
/** @return array<string,int> all values in cents */
function lc_pf_summary(): array {
    $monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00');
    $reserved = "'" . implode("','", LC_PF_RESERVED) . "'";
    $income = lc_pf_sum("SELECT COALESCE(SUM(s.price_cents_snapshot),0) AS total FROM creator_subscription_cycles c
        JOIN creator_subscriptions s ON s.id=c.subscription_id WHERE c.payment_status='confirmed'");
    $incomeMonth = lc_pf_sum("SELECT COALESCE(SUM(s.price_cents_snapshot),0) AS total FROM creator_subscription_cycles c
        JOIN creator_subscriptions s ON s.id=c.subscription_id WHERE c.payment_status='confirmed' AND c.created_at>=?", [$monthStart]);
    $earned = lc_pf_sum('SELECT COALESCE(SUM(amount_cents),0) AS total FROM ledger_entries');
    $usersPaid = lc_pf_sum("SELECT COALESCE(SUM(amount_cents),0) AS total FROM withdrawals WHERE status='paid'");
    $usersPaidMonth = lc_pf_sum("SELECT COALESCE(SUM(amount_cents),0) AS total FROM withdrawals WHERE status='paid' AND processed_at>=?", [$monthStart]);
    $usersRequested = lc_pf_sum("SELECT COALESCE(SUM(amount_cents),0) AS total FROM withdrawals WHERE status IN ('requested','processing')");
    $platformPaid = lc_pf_sum("SELECT COALESCE(SUM(amount_cents),0) AS total FROM platform_withdrawals WHERE status='paid'");
    $platformPaidMonth = lc_pf_sum("SELECT COALESCE(SUM(amount_cents),0) AS total FROM platform_withdrawals WHERE status='paid' AND completed_at>=?", [$monthStart]);
    $platformReserved = lc_pf_sum("SELECT COALESCE(SUM(amount_cents),0) AS total FROM platform_withdrawals WHERE status IN ($reserved)");
    $committed = max(0, $earned - $usersPaid);
    $cash = $income - $usersPaid - $platformPaid;
    return [
        'income' => $income, 'income_month' => $incomeMonth,
        'out' => $usersPaid + $platformPaid, 'out_month' => $usersPaidMonth + $platformPaidMonth,
        'users_paid' => $usersPaid, 'platform_paid' => $platformPaid,
        'committed' => $committed, 'users_requested' => $usersRequested,
        'platform_reserved' => $platformReserved, 'cash' => $cash,
        'available' => max(0, $cash - $committed - $platformReserved),
    ];
}
/**
 * Unified statement: money in (confirmed cycles), clipper payouts, platform payouts.
 * @return array{rows:array<int,array>,total:int}
 */
function lc_pf_statement(string $kind, ?string $from, ?string $to, int $page, int $perPage = 25): array {
    $parts = [];
    $args = [];
    $range = static function (string $column) use ($from, $to, &$args): string {
        $sql = '';
        if ($from !== null) { $sql .= " AND $column>=?"; $args[] = $from . ' 00:00:00'; }
        if ($to !== null) { $sql .= " AND $column<?"; $args[] = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d 00:00:00'); }
        return $sql;
    };
    if ($kind === '' || $kind === 'in') {
        $parts[] = "SELECT 'in' AS kind,c.created_at AS at,CAST(s.price_cents_snapshot AS SIGNED) AS amount,s.id AS ref_id,s.creator_id AS user_id
            FROM creator_subscription_cycles c JOIN creator_subscriptions s ON s.id=c.subscription_id
            WHERE c.payment_status='confirmed'" . $range('c.created_at');
    }
    if ($kind === '' || $kind === 'clipper') {
        $parts[] = "SELECT 'clipper' AS kind,w.processed_at AS at,-CAST(w.amount_cents AS SIGNED) AS amount,w.id AS ref_id,w.user_id AS user_id
            FROM withdrawals w WHERE w.status='paid'" . $range('w.processed_at');
    }
    if ($kind === '' || $kind === 'platform') {
        $parts[] = "SELECT 'platform' AS kind,p.completed_at AS at,-CAST(p.amount_cents AS SIGNED) AS amount,p.id AS ref_id,p.requested_by AS user_id
            FROM platform_withdrawals p WHERE p.status='paid'" . $range('p.completed_at');
    }
    if (!$parts) return ['rows' => [], 'total' => 0];
    $union = implode(' UNION ALL ', $parts);
    $total = (int)(lc_one("SELECT COUNT(*) AS n FROM ($union) t", $args)['n'] ?? 0);
    $offset = max(0, ($page - 1) * $perPage);
    $rows = lc_all("SELECT t.*,u.full_name FROM ($union) t LEFT JOIN users u ON u.id=t.user_id ORDER BY t.at DESC,t.ref_id DESC LIMIT " . (int)$perPage . ' OFFSET ' . (int)$offset, $args);
    return ['rows' => $rows, 'total' => $total];
}
/** Requests that were never authorized expire after 24h and release their reserve. */
function lc_pf_expire_stale(): void {
    $stale = lc_all("SELECT id,amount_cents FROM platform_withdrawals WHERE status='awaiting_authorization' AND created_at<?",
        [date('Y-m-d H:i:s', time() - LC_PF_REQUEST_TTL)]);
    // A dispatch interrupted before Asaas answered (process crash) must be checked by the master.
    foreach (lc_all("SELECT id FROM platform_withdrawals WHERE status='sending' AND provider_transfer_id IS NULL AND updated_at<?", [date('Y-m-d H:i:s', time() - 300)]) as $hung) {
        $n = lc_query("UPDATE platform_withdrawals SET status='unknown',last_error='Envio interrompido sem resposta do Asaas. Confira no painel Asaas.' WHERE id=? AND status='sending'", [(int)$hung['id']])->rowCount();
        if ($n === 1) lc_pf_log((int)$hung['id'], 'dispatch_unknown', null, 'sending', 'unknown', 'Envio interrompido sem resposta registrada.');
    }
    foreach ($stale as $row) {
        $db = lc_db();
        $db->beginTransaction();
        try {
            $locked = lc_one('SELECT id,status FROM platform_withdrawals WHERE id=? FOR UPDATE', [(int)$row['id']]);
            if ($locked && $locked['status'] === 'awaiting_authorization') {
                lc_query("UPDATE platform_withdrawals SET status='expired',link_token_hash=NULL,link_expires_at=NULL WHERE id=?", [(int)$row['id']]);
                lc_pf_log((int)$row['id'], 'expired', null, 'awaiting_authorization', 'expired', 'Sem autorização em 24 horas.', (int)$row['amount_cents']);
            }
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }
}
function lc_pf_with_cash_lock(callable $fn): mixed {
    if ((int)lc_query('SELECT GET_LOCK(?,10) AS ok', ['lc_platform_cash'])->fetchColumn() !== 1)
        throw new RuntimeException('Outra operação financeira está em andamento. Tente novamente em alguns segundos.');
    try { return $fn(); } finally { lc_query('SELECT RELEASE_LOCK(?)', ['lc_platform_cash']); }
}
/** Create a request and reserve the amount. Returns the new withdrawal ID. */
function lc_pf_request(array $actor, int $amountCents, string $type, string $key, string $description): int {
    if (($actor['role'] ?? '') !== 'admin' || !lc_admin_allowed($actor, 'finance')) throw new RuntimeException('Sem permissão financeira.');
    if ($amountCents < LC_PF_MIN_CENTS || $amountCents > LC_PF_MAX_CENTS)
        throw new InvalidArgumentException('Valor inválido: mínimo R$ 1,00 e máximo R$ 1.000.000,00 por saque.');
    $normalized = lc_pix_key_normalize($type, $key);
    $type = strtoupper($type);
    $description = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $description));
    $description = $description === '' ? null : mb_substr($description, 0, 120);
    $recent = (int)(lc_one("SELECT COUNT(*) AS n FROM platform_finance_events WHERE event='requested' AND actor_user_id=? AND created_at>?",
        [(int)$actor['id'], date('Y-m-d H:i:s', time() - 3600)])['n'] ?? 0);
    if ($recent >= 5) throw new RuntimeException('Limite de 5 solicitações por hora atingido. Aguarde para solicitar outro saque.');
    lc_pf_expire_stale();
    return lc_pf_with_cash_lock(function () use ($actor, $amountCents, $type, $normalized, $description): int {
        $db = lc_db();
        $db->beginTransaction();
        try {
            $summary = lc_pf_summary();
            if ($amountCents > $summary['available'])
                throw new InvalidArgumentException('Valor acima do disponível para saque (' . lc_money($summary['available']) . ').');
            lc_query("INSERT INTO platform_withdrawals(amount_cents,pix_key_type,pix_key_enc,pix_key_hint,description,requested_by,provider_reference)
                VALUES(?,?,?,?,?,?,?)", [$amountCents, $type, lc_encrypt($normalized), lc_pix_key_hint($type, $normalized), $description,
                (int)$actor['id'], 'tmp-' . bin2hex(random_bytes(12))]);
            $id = (int)$db->lastInsertId();
            lc_query('UPDATE platform_withdrawals SET provider_reference=? WHERE id=?', ['lcpw-' . $id . '-' . bin2hex(random_bytes(8)), $id]);
            lc_pf_log($id, 'requested', (int)$actor['id'], null, 'awaiting_authorization', lc_pix_key_hint($type, $normalized), $amountCents);
            $db->commit();
            return $id;
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    });
}
function lc_pf_token_hash(int $id, string $token): string {
    return hash_hmac('sha256', 'lc-platform-withdrawal:' . $id . ':' . $token, lc_env('APP_KEY'));
}
function lc_pf_base_url(): string {
    $base = rtrim(lc_env('APP_URL', 'https://leaguecut.com'), '/');
    return preg_match('~^https?://[a-z0-9.-]+(?::[0-9]{2,5})?$~iD', $base) ? $base : 'https://leaguecut.com';
}
/**
 * Issue a fresh single-use link (any previous link stops working) and send it
 * to the approver's WhatsApp. Throws when the send fails; the request stays pending.
 */
function lc_pf_send_link(int $id, ?int $actorId): void {
    [$token, $row] = lc_pf_issue_token($id, $actorId);
    lc_pf_deliver_link($id, $token, $row, $actorId);
}
/** @return array{0:string,1:array} fresh token (shown once) and the locked row. */
function lc_pf_issue_token(int $id, ?int $actorId): array {
    $db = lc_db();
    $db->beginTransaction();
    try {
        $row = lc_one('SELECT * FROM platform_withdrawals WHERE id=? FOR UPDATE', [$id]);
        if (!$row || $row['status'] !== 'awaiting_authorization') throw new RuntimeException('Este saque não está aguardando autorização.');
        if (strtotime((string)$row['created_at']) < time() - LC_PF_REQUEST_TTL) throw new RuntimeException('Pedido expirado. Crie uma nova solicitação.');
        if ((int)$row['link_sends'] >= LC_PF_LINK_MAX_SENDS) throw new RuntimeException('Limite de reenvios atingido. Cancele e crie uma nova solicitação.');
        if ($row['link_last_sent_at'] && strtotime((string)$row['link_last_sent_at']) > time() - LC_PF_LINK_COOLDOWN)
            throw new RuntimeException('Aguarde 1 minuto antes de reenviar o link.');
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        lc_query('UPDATE platform_withdrawals SET link_token_hash=?,link_expires_at=?,link_sends=link_sends+1,link_last_sent_at=NOW() WHERE id=?',
            [lc_pf_token_hash($id, $token), date('Y-m-d H:i:s', time() + LC_PF_LINK_TTL), $id]);
        lc_pf_log($id, 'link_issued', $actorId, null, null, 'Validade de 15 minutos; links anteriores invalidados.');
        $db->commit();
        return [$token, $row];
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
function lc_pf_deliver_link(int $id, string $token, array $row, ?int $actorId): void {
    $requester = lc_one('SELECT full_name,nickname FROM users WHERE id=?', [(int)$row['requested_by']]);
    $link = lc_pf_base_url() . '/admin/financeiro/autorizar/' . $id . '/' . $token;
    $message = "LeagueCut · Autorização de saque da plataforma\n\n" .
        'Pedido #' . $id . "\n" .
        'Valor: ' . lc_money((int)$row['amount_cents']) . "\n" .
        'Destino: ' . $row['pix_key_hint'] . "\n" .
        'Solicitado por: ' . ($requester['full_name'] ?? 'administrador') . "\n\n" .
        "Para autorizar, abra o link em até 15 minutos, entre na conta master e confirme com senha e 2FA:\n" . $link . "\n\n" .
        'Não reconhece este pedido? Não autorize: abra o link e toque em Recusar.';
    try {
        if (!function_exists('lc_wpp_send')) throw new RuntimeException('Módulo WhatsApp indisponível.');
        lc_wpp_send(lc_pf_approver_phone(), $message);
        lc_pf_log($id, 'link_sent', $actorId, null, null, 'WhatsApp final ' . substr(lc_pf_approver_phone(), -4));
    } catch (Throwable $e) {
        lc_pf_log($id, 'link_send_failed', $actorId, null, null, mb_substr($e->getMessage(), 0, 200));
        throw new RuntimeException('O pedido foi registrado, mas o WhatsApp de autorização não foi enviado: ' . $e->getMessage() . ' Use "Reenviar link" quando o WhatsApp estiver conectado.');
    }
}
/** Valid, unexpired link for a pending request, or null. Constant-time comparison. */
function lc_pf_link_row(int $id, string $token): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) return null;
    $row = lc_one('SELECT * FROM platform_withdrawals WHERE id=?', [$id]);
    if (!$row || $row['status'] !== 'awaiting_authorization' || !$row['link_token_hash'] || !$row['link_expires_at']) return null;
    if (!hash_equals((string)$row['link_token_hash'], lc_pf_token_hash($id, $token))) return null;
    if (strtotime((string)$row['link_expires_at']) < time()) return null;
    return $row;
}
/** Master decision through the link. Password/2FA must be verified by the caller. */
function lc_pf_decide(int $id, string $token, array $actor, string $decision): string {
    if (!in_array($decision, ['authorize', 'refuse'], true)) throw new InvalidArgumentException('Decisão inválida.');
    if (!lc_is_master($actor)) {
        if (lc_one('SELECT id FROM platform_withdrawals WHERE id=?', [$id])) lc_pf_log($id, 'authorization_denied', (int)$actor['id'], null, null, 'Conta não é a master.');
        throw new RuntimeException('Somente a conta master pode autorizar saques da plataforma.');
    }
    $db = lc_db();
    $db->beginTransaction();
    try {
        $row = lc_one('SELECT * FROM platform_withdrawals WHERE id=? FOR UPDATE', [$id]);
        $valid = $row && $row['status'] === 'awaiting_authorization' && $row['link_token_hash']
            && hash_equals((string)$row['link_token_hash'], lc_pf_token_hash($id, $token))
            && strtotime((string)$row['link_expires_at']) >= time();
        if (!$valid) {
            $db->rollBack();
            if ($row) lc_pf_log($id, 'authorization_denied', (int)$actor['id'], null, null, 'Link inválido, expirado ou já utilizado.');
            throw new RuntimeException('Link inválido, expirado ou já utilizado. Gere um novo link no Caixa da plataforma.');
        }
        $to = $decision === 'authorize' ? 'authorized' : 'refused';
        lc_query('UPDATE platform_withdrawals SET status=?,authorized_by=?,authorized_at=NOW(),link_token_hash=NULL,link_expires_at=NULL WHERE id=?',
            [$to, (int)$actor['id'], $id]);
        lc_pf_log($id, $decision === 'authorize' ? 'authorized' : 'refused', (int)$actor['id'], 'awaiting_authorization', $to,
            $decision === 'authorize' ? 'Senha e 2FA confirmados pelo master.' : 'Pedido recusado pelo master.', (int)$row['amount_cents']);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    if ($decision === 'refuse') return 'Saque recusado. O valor voltou para o disponível.';
    return lc_pf_dispatch($id, $actor);
}
function lc_pf_cancel(int $id, array $actor): string {
    $db = lc_db();
    $db->beginTransaction();
    try {
        $row = lc_one('SELECT * FROM platform_withdrawals WHERE id=? FOR UPDATE', [$id]);
        $allowed = ['awaiting_authorization'];
        if (lc_is_master($actor)) $allowed[] = 'authorized';
        if (!$row || !in_array($row['status'], $allowed, true)) throw new RuntimeException('Este saque não pode mais ser cancelado.');
        lc_query("UPDATE platform_withdrawals SET status='cancelled',link_token_hash=NULL,link_expires_at=NULL WHERE id=?", [$id]);
        lc_pf_log($id, 'cancelled', (int)$actor['id'], (string)$row['status'], 'cancelled', 'Cancelado no painel.', (int)$row['amount_cents']);
        $db->commit();
        return 'Saque cancelado. O valor voltou para o disponível.';
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
/** Map an Asaas transfer status to ours. */
function lc_pf_map_provider_status(string $status): string {
    return match (strtoupper($status)) {
        'DONE' => 'paid',
        'FAILED', 'CANCELLED' => 'failed',
        default => 'processing',
    };
}
function lc_pf_safe_receipt(?string $url): ?string {
    if (!$url || !lc_valid_https($url)) return null;
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    return preg_match('/(^|\.)asaas\.com(\.br)?$/D', $host) ? mb_substr($url, 0, 500) : null;
}
/**
 * Send an authorized withdrawal to Asaas. Intent is recorded (status=sending)
 * BEFORE the request; a network failure ends in "unknown", never in a retry.
 */
function lc_pf_dispatch(int $id, array $actor): string {
    if (!lc_is_master($actor)) throw new RuntimeException('Somente a conta master pode enviar o saque ao Asaas.');
    $row = lc_one('SELECT * FROM platform_withdrawals WHERE id=?', [$id]);
    if (!$row || $row['status'] !== 'authorized') throw new RuntimeException('Saque não está autorizado para envio.');
    $blocked = static function (string $reason) use ($id, $actor): string {
        lc_query('UPDATE platform_withdrawals SET last_error=? WHERE id=?', [mb_substr($reason, 0, 255), $id]);
        lc_pf_log($id, 'dispatch_blocked', (int)$actor['id'], 'authorized', 'authorized', $reason);
        return 'Saque autorizado, mas não enviado: ' . $reason;
    };
    if (!lc_pf_payouts_enabled())
        return $blocked('Pix automático desligado no servidor (ASAAS_ENABLED e ASAAS_PLATFORM_PAYOUTS_ENABLED precisam ser 1).');
    try {
        $balance = lc_asaas_request('GET', '/finance/balance');
        $balanceCents = (int)round(((float)($balance['balance'] ?? 0)) * 100);
        if ($balanceCents < (int)$row['amount_cents']) return $blocked('Saldo da conta Asaas insuficiente para este valor.');
    } catch (Throwable $e) {
        return $blocked('Não foi possível consultar o saldo Asaas: ' . mb_substr($e->getMessage(), 0, 160));
    }
    $db = lc_db();
    $db->beginTransaction();
    try {
        $locked = lc_one('SELECT * FROM platform_withdrawals WHERE id=? FOR UPDATE', [$id]);
        if (!$locked || $locked['status'] !== 'authorized') throw new RuntimeException('O status do saque mudou. Atualize a página.');
        lc_query("UPDATE platform_withdrawals SET status='sending',last_error=NULL WHERE id=?", [$id]);
        lc_pf_log($id, 'dispatch_started', (int)$actor['id'], 'authorized', 'sending', 'Referência ' . $locked['provider_reference'], (int)$locked['amount_cents']);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    $key = lc_decrypt((string)$row['pix_key_enc']);
    if ($key === '') {
        lc_query("UPDATE platform_withdrawals SET status='authorized',last_error=? WHERE id=?", ['Chave Pix ilegível (APP_KEY alterada?).', $id]);
        lc_pf_log($id, 'dispatch_blocked', (int)$actor['id'], 'sending', 'authorized', 'Chave Pix não pôde ser lida.');
        return 'Saque não enviado: a chave Pix não pôde ser lida. Cancele e crie um novo pedido.';
    }
    $body = [
        'value' => round((int)$row['amount_cents'] / 100, 2),
        'operationType' => 'PIX',
        'pixAddressKey' => $key,
        'pixAddressKeyType' => (string)$row['pix_key_type'],
        'description' => mb_substr('LeagueCut saque #' . $id . ($row['description'] ? ' - ' . $row['description'] : ''), 0, 140),
        'externalReference' => (string)$row['provider_reference'],
    ];
    try {
        $result = lc_asaas_platform_transfer($body);
    } catch (Throwable $e) {
        $result = ['status' => -1, 'json' => null, 'error' => $e->getMessage()];
    }
    $status = (int)$result['status'];
    $json = $result['json'] ?? null;
    if ($status >= 200 && $status < 300 && is_array($json) && preg_match('/^[A-Za-z0-9_-]{6,90}$/D', (string)($json['id'] ?? ''))) {
        $providerStatus = mb_substr((string)($json['status'] ?? 'PENDING'), 0, 40);
        $to = lc_pf_map_provider_status($providerStatus);
        lc_query('UPDATE platform_withdrawals SET status=?,provider_transfer_id=?,provider_status=?,receipt_url=?,completed_at=IF(?=\'paid\',NOW(),NULL),last_error=? WHERE id=?',
            [$to, (string)$json['id'], $providerStatus, lc_pf_safe_receipt($json['transactionReceiptUrl'] ?? null), $to,
             $to === 'failed' ? mb_substr((string)($json['failReason'] ?? 'Falha informada pelo Asaas'), 0, 255) : null, $id]);
        lc_pf_log($id, 'dispatched', (int)$actor['id'], 'sending', $to, 'Asaas ' . $providerStatus . ' · ID ' . $json['id'], (int)$row['amount_cents']);
        return $to === 'paid' ? 'Pix concluído pelo Asaas.' : 'Transferência criada no Asaas (' . $providerStatus . '). O status será atualizado automaticamente.';
    }
    if ($status >= 400 && $status < 500) {
        $error = $json['errors'][0] ?? [];
        $code = (string)($error['code'] ?? '');
        $text = mb_substr((string)($error['description'] ?? 'HTTP ' . $status), 0, 200);
        if ($code === 'invalid_action') $text = 'A conta Asaas exige autorização crítica (token SMS/app) para transferências via API. Cadastre o IP da VPS na whitelist do Asaas e tente "Enviar ao Asaas" novamente.';
        lc_query("UPDATE platform_withdrawals SET status='authorized',last_error=? WHERE id=?", [mb_substr($text, 0, 255), $id]);
        lc_pf_log($id, 'dispatch_rejected', (int)$actor['id'], 'sending', 'authorized', ($code !== '' ? $code . ': ' : '') . $text);
        return 'O Asaas recusou a transferência: ' . $text;
    }
    // Network failure, timeout or 5xx: the transfer may or may not exist. Never retry blindly.
    lc_query("UPDATE platform_withdrawals SET status='unknown',last_error=? WHERE id=?", ['Sem confirmação do Asaas (HTTP ' . $status . '). Confira no painel Asaas pela referência.', $id]);
    lc_pf_log($id, 'dispatch_unknown', (int)$actor['id'], 'sending', 'unknown', 'HTTP ' . $status . ' · referência ' . $row['provider_reference']);
    return 'Não houve confirmação do Asaas. Confira no painel Asaas pela referência ' . $row['provider_reference'] . ' antes de qualquer nova tentativa.';
}
/** Re-read the transfer from Asaas (GET) and update our status. */
function lc_pf_sync(int $id, ?int $actorId = null): string {
    $row = lc_one('SELECT * FROM platform_withdrawals WHERE id=?', [$id]);
    if (!$row || !$row['provider_transfer_id']) throw new RuntimeException('Saque sem transferência vinculada no Asaas.');
    if (!in_array($row['status'], ['processing', 'unknown', 'sending'], true)) return 'Status já finalizado: ' . lc_pf_status_label((string)$row['status']) . '.';
    $remote = lc_asaas_request('GET', '/transfers/' . $row['provider_transfer_id']);
    if (!hash_equals((string)$row['provider_transfer_id'], (string)($remote['id'] ?? ''))
        || abs((float)($remote['value'] ?? 0) * 100 - (int)$row['amount_cents']) > 0.5)
        throw new RuntimeException('Dados do Asaas não conferem com o pedido. Atualização bloqueada para conferência manual.');
    $providerStatus = mb_substr((string)($remote['status'] ?? ''), 0, 40);
    $to = lc_pf_map_provider_status($providerStatus);
    $db = lc_db();
    $db->beginTransaction();
    try {
        $locked = lc_one('SELECT status FROM platform_withdrawals WHERE id=? FOR UPDATE', [$id]);
        if ($locked && ($locked['status'] !== $to || $row['provider_status'] !== $providerStatus)) {
            lc_query('UPDATE platform_withdrawals SET status=?,provider_status=?,receipt_url=COALESCE(?,receipt_url),completed_at=IF(?=\'paid\',COALESCE(completed_at,NOW()),completed_at),last_error=? WHERE id=?',
                [$to, $providerStatus, lc_pf_safe_receipt($remote['transactionReceiptUrl'] ?? null), $to,
                 $to === 'failed' ? mb_substr((string)($remote['failReason'] ?? 'Falha informada pelo Asaas'), 0, 255) : null, $id]);
            lc_pf_log($id, 'status_synced', $actorId, (string)$locked['status'], $to, 'Asaas ' . $providerStatus, (int)$row['amount_cents']);
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return 'Status no Asaas: ' . $providerStatus . ' (' . lc_pf_status_label($to) . ').';
}
function lc_pf_sync_by_transfer_id(string $transferId): void {
    $row = lc_one('SELECT id FROM platform_withdrawals WHERE provider_transfer_id=?', [$transferId]);
    if ($row) lc_pf_sync((int)$row['id']);
}
/** Master resolves an "unknown" dispatch after checking the Asaas panel. */
function lc_pf_resolve_unknown(int $id, array $actor, string $action, string $transferId = ''): string {
    if (!lc_is_master($actor)) throw new RuntimeException('Ação exclusiva da conta master.');
    $db = lc_db();
    $db->beginTransaction();
    try {
        $row = lc_one('SELECT * FROM platform_withdrawals WHERE id=? FOR UPDATE', [$id]);
        if (!$row || $row['status'] !== 'unknown') throw new RuntimeException('Este saque não está pendente de conferência.');
        if ($action === 'link') {
            if (!preg_match('/^[A-Za-z0-9_-]{6,90}$/D', $transferId)) throw new InvalidArgumentException('Informe o ID da transferência exatamente como aparece no Asaas.');
            if (lc_one('SELECT id FROM platform_withdrawals WHERE provider_transfer_id=? AND id<>?', [$transferId, $id])) throw new InvalidArgumentException('Este ID já está vinculado a outro saque.');
            lc_query("UPDATE platform_withdrawals SET status='processing',provider_transfer_id=? WHERE id=?", [$transferId, $id]);
            lc_pf_log($id, 'manual_link', (int)$actor['id'], 'unknown', 'processing', 'ID ' . $transferId);
        } elseif ($action === 'not_created') {
            lc_query("UPDATE platform_withdrawals SET status='authorized',last_error='Confirmado pelo master: transferência não criada no Asaas.' WHERE id=?", [$id]);
            lc_pf_log($id, 'manual_not_created', (int)$actor['id'], 'unknown', 'authorized', 'Master conferiu o painel Asaas.');
        } else throw new InvalidArgumentException('Ação inválida.');
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    if ($action === 'link') {
        try { return lc_pf_sync($id, (int)$actor['id']); } catch (Throwable $e) { return 'ID vinculado. Status será conferido: ' . $e->getMessage(); }
    }
    return 'Saque voltou para "Autorizado". Você pode enviar novamente ao Asaas.';
}
/** Safe post-login return for the authorization link only. */
function lc_after_login_destination(string $role, string $default): string {
    $target = (string)($_SESSION['lc_after_login'] ?? '');
    unset($_SESSION['lc_after_login']);
    if ($role === 'admin' && preg_match('~^/admin/financeiro/autorizar/[0-9]{1,18}/[A-Za-z0-9_-]{43}$~D', $target)) return $target;
    return $default;
}
