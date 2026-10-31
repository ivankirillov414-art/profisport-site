<?php
declare(strict_types=1);

require_once __DIR__ . '/../documents/service.php';

function crm_documents_route(Closure $handler, int $successStatus = 200): Closure
{
    return static function () use ($handler, $successStatus): void {
        try {
            $data = $handler();
            crm_json(['ok' => true, 'data' => $data], $successStatus);
        } catch (CrmDocumentException $error) {
            crm_json(['ok' => false, 'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]], $error->status);
        }
        // Core handles CrmHttpException (including auth/role/session failures)
        // and unexpected errors. Do not turn an intended 401/403 into a 500.
    };
}

return [
    'GET documents' => crm_documents_route(static fn(): array => crm_documents_list($_GET)),
    'POST documents/upload' => crm_documents_route(static fn(): array => crm_documents_upload($_POST, $_FILES), 201),
    'GET documents/versions' => crm_documents_route(static fn(): array => crm_documents_versions($_GET)),
    'GET documents/download' => crm_documents_route(static fn(): never => crm_documents_download($_GET)),
];
