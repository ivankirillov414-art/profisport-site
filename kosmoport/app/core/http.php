<?php
declare(strict_types=1);

final class CrmHttpException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422)
    { parent::__construct($message); }
}

function crm_fail(string $code, string $message, int $status = 422): never
{ throw new CrmHttpException($code, $message, $status); }

function crm_json(array $payload, int $status = 200): never
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $json;
    exit;
}

function crm_error_response(Throwable $error): never
{
    if ($error instanceof CrmHttpException) {
        crm_json(['ok' => false, 'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]], $error->status);
    }
    $reference = bin2hex(random_bytes(8));
    // Exception bodies/SQL can contain private configuration or document details.
    error_log('Kosmoport CRM error ' . $reference . ' [' . get_class($error) . ']');
    crm_json(['ok' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Сервис временно недоступен. Код обращения: ' . $reference]], 500);
}

function crm_body_limit(string $type): int
{
    $configured = crm_config($type === 'multipart/form-data' ? 'http.max_multipart_bytes' : 'http.max_json_bytes');
    $limit = is_int($configured) && $configured > 0 ? min($configured, 32 * 1024 * 1024) : ($type === 'multipart/form-data' ? 24 * 1024 * 1024 : 8 * 1024 * 1024);
    if ($type === 'multipart/form-data') {
        $ini = trim((string)ini_get('post_max_size'));
        if (preg_match('/^(\d+)\s*([KMG]?)$/i', $ini, $matches)) {
            $multiplier = match (strtoupper($matches[2])) { 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1 };
            $phpLimit = (int)$matches[1] * $multiplier; if ($phpLimit > 0) $limit = min($limit, $phpLimit);
        }
    }
    return $limit;
}

function crm_validate_body_size(): void
{
    $type = strtolower(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > crm_body_limit($type)) crm_fail('BODY_TOO_LARGE', 'Слишком большой запрос или файл.', 413);
}

function crm_input(): array
{
    static $input;
    if (isset($input)) return $input;
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $type = strtolower(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]);
    $limit = crm_body_limit($type);
    if ($length > $limit) crm_fail('BODY_TOO_LARGE', 'Слишком большой запрос или файл.', 413);
    if ($type === 'multipart/form-data') return $input = $_POST;
    if ($type !== 'application/json') crm_fail('JSON_REQUIRED', 'Отправьте данные в формате JSON', 415);
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if ($raw === false || strlen($raw) > $limit) crm_fail('BODY_TOO_LARGE', 'Слишком большой запрос', 413);
    try { $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { crm_fail('INVALID_JSON', 'Некорректный JSON'); }
    if (!$decoded instanceof stdClass) crm_fail('INVALID_JSON', 'Ожидается JSON-объект');
    return $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
}

function crm_text(mixed $value, string $label, int $max = 255, bool $required = false): string
{
    if (!is_string($value) && $value !== null) crm_fail('INVALID_FIELD', 'Проверьте поле «' . $label . '»');
    $value = trim((string)$value);
    if (preg_match('//u', $value) !== 1 || str_contains($value, "\0") || strlen($value) > $max * 4 || ($required && $value === '')) crm_fail('INVALID_FIELD', 'Проверьте поле «' . $label . '»');
    if (preg_match_all('/./us', $value) > $max) crm_fail('INVALID_FIELD', 'Слишком длинное поле «' . $label . '»');
    return $value;
}

function crm_identifier(mixed $value, bool $nullable = false): ?string
{
    if ($nullable && ($value === null || $value === '')) return null;
    if (!is_string($value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $value)) crm_fail('INVALID_ID', 'Некорректный идентификатор');
    return $value;
}

function crm_uuid(): string
{
    $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function crm_now(): string { return gmdate('Y-m-d H:i:s'); }
function crm_iso(?string $utc): ?string
{ return $utc === null || $utc === '' ? null : (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'); }

function crm_email(mixed $value, bool $required = false): string
{
    $email = strtolower(crm_text($value, 'Электронная почта', 254, $required));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) crm_fail('INVALID_EMAIL', 'Проверьте электронную почту');
    return $email;
}

function crm_choice(mixed $value, array $choices, string $label): string
{
    if (!is_string($value) || !in_array($value, $choices, true)) crm_fail('INVALID_FIELD', 'Проверьте поле «' . $label . '»');
    return $value;
}

function crm_boolean(mixed $value): bool
{
    if (!is_bool($value)) crm_fail('INVALID_FIELD', 'Ожидается логическое значение');
    return $value;
}

function crm_date(mixed $value): ?string
{
    if ($value === null || $value === '') return null;
    if (!is_string($value)) crm_fail('INVALID_DATE', 'Проверьте дату');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) crm_fail('INVALID_DATE', 'Проверьте дату');
    return $value;
}
