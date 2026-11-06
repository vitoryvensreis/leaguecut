<?php
declare(strict_types=1);

/**
 * Campaign capacity rules.
 *
 * - max_clippers: how many distinct clippers may take part in a campaign.
 *   A clipper becomes a participant with the FIRST clip sent (pending,
 *   approved or rejected all count). NULL = unlimited.
 * - max_approved_per_week: how many clips of the SAME clipper can be approved
 *   in one campaign week (Monday 00:00 to next Monday, by submitted_at, the
 *   same window used by lc_settle_week). NULL = unlimited.
 *
 * Both checks are repeated under a campaign row lock (SELECT ... FOR UPDATE)
 * inside the write transaction, so concurrent requests cannot exceed limits.
 */
const LC_CAMPAIGN_MAX_CLIPPERS = 1000000;
const LC_CAMPAIGN_MAX_WEEKLY = 1000;

function lc_campaign_limit_value(mixed $raw): ?int {
    if ($raw === null || $raw === '') return null;
    $value = (int)$raw;
    return $value > 0 ? $value : null;
}
/** Read a limit field from the admin form. Empty or "unlimited" checkbox = NULL. */
function lc_campaign_limit_from_post(string $field, int $max): ?int {
    if (!empty($_POST[$field . '_unlimited'])) return null;
    $raw = preg_replace('/\D/', '', (string)($_POST[$field] ?? '')) ?? '';
    if ($raw === '') return null;
    $value = (int)$raw;
    if ($value < 1 || $value > $max) throw new InvalidArgumentException('Limite inválido: informe um número entre 1 e ' . number_format($max, 0, ',', '.') . ' ou marque "Ilimitado".');
    return $value;
}
/** @return array{0:string,1:string} [monday 00:00, next monday 00:00] */
function lc_campaign_week_bounds(string $moment): array {
    $begin = (new DateTimeImmutable($moment))->modify('monday this week')->format('Y-m-d 00:00:00');
    $end = (new DateTimeImmutable($begin))->modify('+7 days')->format('Y-m-d H:i:s');
    return [$begin, $end];
}
/** "Now" in the database clock, the same clock that fills clips.submitted_at. */
function lc_campaign_db_now(): string {
    return (string)lc_query('SELECT NOW()')->fetchColumn();
}
function lc_campaign_participant_count(int $campaignId): int {
    return (int)(lc_one('SELECT COUNT(DISTINCT user_id) AS n FROM clips WHERE campaign_id=?', [$campaignId])['n'] ?? 0);
}
function lc_campaign_is_participant(int $campaignId, int $userId): bool {
    return (bool)lc_one('SELECT 1 AS ok FROM clips WHERE campaign_id=? AND user_id=? LIMIT 1', [$campaignId, $userId]);
}
function lc_campaign_approved_in_week(int $campaignId, int $userId, string $moment, ?int $excludeClipId = null): int {
    [$begin, $end] = lc_campaign_week_bounds($moment);
    $sql = "SELECT COUNT(*) AS n FROM clips WHERE campaign_id=? AND user_id=? AND status='approved' AND submitted_at>=? AND submitted_at<?";
    $args = [$campaignId, $userId, $begin, $end];
    if ($excludeClipId !== null) { $sql .= ' AND id<>?'; $args[] = $excludeClipId; }
    return (int)(lc_one($sql, $args)['n'] ?? 0);
}
/**
 * Why a clipper cannot send a new clip right now, or null when allowed.
 * Call before the upload (fast feedback) and again under lock before INSERT.
 */
function lc_campaign_submission_block(array $campaign, int $userId, ?string $moment = null): ?string {
    $campaignId = (int)$campaign['id'];
    $maxClippers = lc_campaign_limit_value($campaign['max_clippers'] ?? null);
    if ($maxClippers !== null && !lc_campaign_is_participant($campaignId, $userId)
        && lc_campaign_participant_count($campaignId) >= $maxClippers) {
        return 'As vagas desta campanha estão esgotadas (' . $maxClippers . ' clipadores). Escolha outra campanha no catálogo.';
    }
    $weekly = lc_campaign_limit_value($campaign['max_approved_per_week'] ?? null);
    if ($weekly !== null && lc_campaign_approved_in_week($campaignId, $userId, $moment ?? lc_campaign_db_now()) >= $weekly) {
        return 'Você já atingiu o limite de ' . $weekly . ' clipes aprovados nesta semana nesta campanha. Novos envios abrem na próxima segunda-feira.';
    }
    return null;
}
/** Throws when approving this clip would exceed the clipper's weekly cap. Use inside a transaction. */
function lc_campaign_assert_can_approve(array $clip): void {
    $campaign = lc_one('SELECT id,max_approved_per_week FROM campaigns WHERE id=? FOR UPDATE', [(int)$clip['campaign_id']]);
    if (!$campaign) throw new RuntimeException('Campanha do clipe não encontrada.');
    $weekly = lc_campaign_limit_value($campaign['max_approved_per_week'] ?? null);
    if ($weekly === null) return;
    $approved = lc_campaign_approved_in_week((int)$clip['campaign_id'], (int)$clip['user_id'], (string)$clip['submitted_at'], (int)$clip['id']);
    if ($approved >= $weekly) {
        throw new RuntimeException('Limite semanal atingido: este clipador já tem ' . $approved . ' de ' . $weekly . ' clipes aprovados nesta semana da campanha. Reprove este envio ou aumente o limite da campanha.');
    }
}
/**
 * Data for UI badges. Never exposes participant identities.
 * @return array{max_clippers:?int,participants:int,slots_left:?int,full:bool,weekly:?int,approved_week:?int,is_participant:bool}
 */
function lc_campaign_limits_summary(array $campaign, ?int $userId = null): array {
    $campaignId = (int)($campaign['id'] ?? 0);
    $max = lc_campaign_limit_value($campaign['max_clippers'] ?? null);
    $weekly = lc_campaign_limit_value($campaign['max_approved_per_week'] ?? null);
    $participants = $campaignId > 0 && empty($campaign['is_demo']) ? lc_campaign_participant_count($campaignId) : 0;
    $isParticipant = $userId !== null && $campaignId > 0 && lc_campaign_is_participant($campaignId, $userId);
    return [
        'max_clippers' => $max,
        'participants' => $participants,
        'slots_left' => $max === null ? null : max(0, $max - $participants),
        'full' => $max !== null && $participants >= $max,
        'weekly' => $weekly,
        'approved_week' => $userId !== null && $weekly !== null && $campaignId > 0
            ? lc_campaign_approved_in_week($campaignId, $userId, lc_campaign_db_now()) : null,
        'is_participant' => $isParticipant,
    ];
}
