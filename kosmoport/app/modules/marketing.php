<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/marketing/service.php';
require_once dirname(__DIR__) . '/marketing/bundle.php';
return [
    'GET marketing/workspace' => static fn(): array => mk_workspace_view(),
    'POST marketing/record' => static fn(): array => mk_record_save(crm_input()),
    'POST marketing/dataset-row' => static fn(): array => mk_dataset_row_save(crm_input()),
    'POST marketing/members' => static fn(): array => mk_members_save(crm_input()),
    'GET marketing/member-candidates' => static fn(): array => mk_member_candidates(),
    'POST marketing/initialize' => static fn(): array => mk_initialize(crm_input()),
    'POST marketing/upload-bundle' => static fn(): array => mk_upload_bundle($_FILES,$_POST),
    'GET marketing/download' => static fn(): never => mk_download(),
];
