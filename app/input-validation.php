<?php
declare(strict_types=1);

/**
 * Central server-side validation for user-typed data. The browser masks in
 * public/assets/input-masks.js and form-system.js only help the user; every
 * rule below is authoritative and must be re-checked here.
 */

/** Valid Brazilian area codes (ANATEL). */
const LC_BR_DDDS = [
    11,12,13,14,15,16,17,18,19,21,22,24,27,28,31,32,33,34,35,37,38,41,42,43,44,45,46,47,48,49,
    51,53,54,55,61,62,63,64,65,66,67,68,69,71,73,74,75,77,79,81,82,83,84,85,86,87,88,89,
    91,92,93,94,95,96,97,98,99,
];
const LC_PIX_KEY_TYPES = ['CPF'=>'CPF','CNPJ'=>'CNPJ','EMAIL'=>'E-mail','PHONE'=>'Celular','EVP'=>'Chave aleatória'];

function lc_digits(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
}
function lc_cpf_valid(string $value): bool {
    $d = lc_digits($value);
    if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) return false;
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) $sum += (int)$d[$i] * (($t + 1) - $i);
        $check = ((10 * $sum) % 11) % 10;
        if ((int)$d[$t] !== $check) return false;
    }
    return true;
}
function lc_cnpj_valid(string $value): bool {
    $d = lc_digits($value);
    if (strlen($d) !== 14 || preg_match('/^(\d)\1{13}$/', $d)) return false;
    $weights = [[5,4,3,2,9,8,7,6,5,4,3,2],[6,5,4,3,2,9,8,7,6,5,4,3,2]];
    foreach ($weights as $round => $w) {
        $sum = 0;
        foreach ($w as $i => $weight) $sum += (int)$d[$i] * $weight;
        $rest = $sum % 11;
        $check = $rest < 2 ? 0 : 11 - $rest;
        if ((int)$d[12 + $round] !== $check) return false;
    }
    return true;
}
function lc_cpf_cnpj_valid(string $value): bool {
    $d = lc_digits($value);
    return strlen($d) === 11 ? lc_cpf_valid($d) : (strlen($d) === 14 && lc_cnpj_valid($d));
}
/**
 * Brazilian phone as national digits (DDD + number), or null.
 * Accepts +55/55 prefix. Mobile = 11 digits starting with 9 after the DDD;
 * landline = 10 digits starting with 2-5.
 */
function lc_phone_br_normalize(string $value, bool $mobileOnly = false): ?string {
    $d = lc_digits($value);
    if (strlen($d) >= 12 && str_starts_with($d, '55')) $d = substr($d, 2);
    if (strlen($d) === 12 && $d[0] === '0') $d = substr($d, 1); // 0 + DDD + number (trunk prefix)
    if (!in_array(strlen($d), [10, 11], true)) return null;
    if (!in_array((int)substr($d, 0, 2), LC_BR_DDDS, true)) return null;
    $local = substr($d, 2);
    if (strlen($local) === 9) return $local[0] === '9' ? $d : null;
    if ($mobileOnly) return null;
    return preg_match('/^[2-5]/', $local) ? $d : null;
}
function lc_email_normalize(string $value): ?string {
    $email = mb_strtolower(trim($value));
    if ($email === '' || mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) return null;
    return $email;
}
function lc_instagram_normalize(string $value): ?string {
    $v = trim($value);
    if (preg_match('~instagram\.com/([A-Za-z0-9._]{1,30})~i', $v, $m)) $v = $m[1];
    $v = ltrim($v, '@');
    return preg_match('/^[A-Za-z0-9._]{1,30}$/D', $v) && !str_contains($v, '..') ? $v : null;
}
function lc_https_normalize(string $value): ?string {
    $v = trim($value);
    if ($v === '') return null;
    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $v)) $v = 'https://' . $v;
    return lc_valid_https($v) && mb_strlen($v) <= 500 ? $v : null;
}
/** Best-effort detection used only when the user did not choose a type (legacy forms). */
function lc_pix_key_detect(string $key): ?string {
    $v = trim($key);
    if ($v === '') return null;
    if (filter_var($v, FILTER_VALIDATE_EMAIL)) return 'EMAIL';
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) return 'EVP';
    if (str_starts_with($v, '+')) return 'PHONE';
    $d = lc_digits($v);
    if (strlen($d) === 14 && lc_cnpj_valid($d)) return 'CNPJ';
    if (strlen($d) === 11 && lc_cpf_valid($d)) return 'CPF';
    if (lc_phone_br_normalize($v, true) !== null) return 'PHONE';
    return null;
}
/**
 * Normalize a Pix key to the provider format (Asaas):
 * CPF/CNPJ digits only, PHONE 11 digits with DDD, EMAIL lowercase, EVP lowercase UUID.
 */
function lc_pix_key_normalize(string $type, string $key): string {
    $type = strtoupper(trim($type));
    $key = trim($key);
    switch ($type) {
        case 'CPF':
            if (!lc_cpf_valid($key)) throw new InvalidArgumentException('CPF da chave Pix inválido. Confira os 11 dígitos.');
            return lc_digits($key);
        case 'CNPJ':
            if (!lc_cnpj_valid($key)) throw new InvalidArgumentException('CNPJ da chave Pix inválido. Confira os 14 dígitos.');
            return lc_digits($key);
        case 'EMAIL':
            $email = lc_email_normalize($key);
            if ($email === null || mb_strlen($email) > 77) throw new InvalidArgumentException('E-mail da chave Pix inválido.');
            return $email;
        case 'PHONE':
            $phone = lc_phone_br_normalize($key, true);
            if ($phone === null) throw new InvalidArgumentException('Celular da chave Pix inválido. Use DDD + número com 9 dígitos.');
            return $phone;
        case 'EVP':
            $evp = strtolower($key);
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $evp))
                throw new InvalidArgumentException('Chave aleatória inválida. Ela tem 32 caracteres no formato 0000aaaa-00aa-...');
            return $evp;
    }
    throw new InvalidArgumentException('Escolha o tipo da chave Pix.');
}
/** Display-safe hint (never the full key): "CPF ***.456.789-**", "E-mail jo***@gmail.com". */
function lc_pix_key_hint(string $type, string $normalized): string {
    $label = LC_PIX_KEY_TYPES[$type] ?? 'Pix';
    switch ($type) {
        case 'CPF': return $label . ' ***.' . substr($normalized, 3, 3) . '.' . substr($normalized, 6, 3) . '-**';
        case 'CNPJ': return $label . ' **.' . substr($normalized, 2, 3) . '.' . substr($normalized, 5, 3) . '/****-**';
        case 'EMAIL':
            [$user, $domain] = explode('@', $normalized, 2) + [1 => ''];
            return $label . ' ' . mb_substr($user, 0, 2) . '***@' . $domain;
        case 'PHONE': return $label . ' (' . substr($normalized, 0, 2) . ') *****-' . substr($normalized, -4);
        case 'EVP': return $label . ' ' . substr($normalized, 0, 4) . '…' . substr($normalized, -4);
    }
    return $label;
}
/** Human formatting of a normalized key for an authorized viewer. */
function lc_pix_key_format(string $type, string $normalized): string {
    switch ($type) {
        case 'CPF': return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $normalized) ?? $normalized;
        case 'CNPJ': return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $normalized) ?? $normalized;
        case 'PHONE': return preg_replace('/^(\d{2})(\d{5})(\d{4})$/', '($1) $2-$3', $normalized) ?? $normalized;
    }
    return $normalized;
}
/**
 * Pix key typed in a free-text field (signup, account). Keeps backward
 * compatibility: the type is optional and detected; CPF/CNPJ/phone/email are
 * normalized; anything that matches no Pix format is rejected.
 * @return array{type:string,key:string}
 */
function lc_pix_key_from_form(string $key, string $type = ''): array {
    $type = strtoupper(trim($type));
    if ($type === '' || !isset(LC_PIX_KEY_TYPES[$type])) {
        $type = lc_pix_key_detect($key) ?? '';
        if ($type === '') throw new InvalidArgumentException('Chave Pix não reconhecida. Use CPF, CNPJ, e-mail, celular com DDD ou chave aleatória.');
    }
    return ['type' => $type, 'key' => lc_pix_key_normalize($type, $key)];
}
