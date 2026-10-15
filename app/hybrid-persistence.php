<?php
declare(strict_types=1);
require_once __DIR__ . '/hybrid-verification.php';

/** Persistence is audit-only. Never touches clips.verified_views or ledger_entries. */
function lc_hybrid_submission(int $clipId, string $network, string $publishedUrl): void {
    if ($clipId < 1 || !in_array($network, ['instagram','tiktok','youtube'], true)) {
        throw new InvalidArgumentException('Rede ou clipe inválido.');
    }
    $link = LcHybridVerification::identify($publishedUrl);
    // Previously accepted redirect links remain supported, but are never trusted.
    if ($link !== null && $link['network'] !== $network) {
        throw new InvalidArgumentException('A publicação pertence a outra rede.');
    }
    lc_query('INSERT INTO clip_verification_state(clip_id,network,external_id,canonical_url,status)
        VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE clip_id=clip_id',
        [$clipId,$network,$link['external_id']??null,$link['canonical_url']??null,
         $link===null?LcHybridVerification::STATUS_REVIEW:LcHybridVerification::STATUS_PENDING]);
}

function lc_hybrid_source_matches_network(string $network,string $source): bool {
    return match($source) {
        'instagram_graph' => $network==='instagram',
        'tiktok_display' => $network==='tiktok',
        'youtube_data' => $network==='youtube',
        'apify','manual_evidence','unavailable' => true,
        default => false
    };
}
function lc_hybrid_observation(int $clipId,array $input,bool $ownerAuthorized = false): array {
    if ($clipId < 1) throw new InvalidArgumentException('Clipe inválido.');
    $source=(string)($input['source']??'');
    if (!in_array($source,['instagram_graph','tiktok_display','youtube_data','apify','manual_evidence','unavailable'],true))
        throw new InvalidArgumentException('Provedor inválido.');
    $result=LcHybridVerification::assess($input,$ownerAuthorized);
    $pdo=lc_db();
    $started=!$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    try {
        $state=lc_one('SELECT clip_id,network FROM clip_verification_state WHERE clip_id=? FOR UPDATE',[$clipId]);
        if (!$state) throw new RuntimeException('Clipe sem estado de verificação.');
        if (!lc_hybrid_source_matches_network((string)$state['network'],$source))
            throw new InvalidArgumentException('Fonte de métricas incompatível com a rede do clipe.');
        lc_query('INSERT INTO clip_verification_observations(clip_id,source,status,observed_views,owner_authorized,evidence_ref)
            VALUES(?,?,?,?,?,?)',[$clipId,$source,$result['status'],$result['observed_views'],
            $ownerAuthorized?1:0,null]);
        lc_query('UPDATE clip_verification_state SET status=?,last_observed_views=?,last_observed_at=UTC_TIMESTAMP() WHERE clip_id=?',
            [$result['status'],$result['observed_views'],$clipId]);
        if ($started) $pdo->commit();
    } catch(Throwable $e) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $result;
}
