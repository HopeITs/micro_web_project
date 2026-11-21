<?php

return [
    // M4.11 web attachments: files live on the private disk (storage/app/private), never under public/.
    // Owner decisions pending (21 Nov 2026, see docs/delivery/m4-11-attachments.md): each value below is a proposed default.
    'disk' => 'local',
    'upload' => [
        // The desktop accepted jpg/jpeg/png (and opened pdf); the content is checked, not only the name.
        'types' => ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'],
        'max_kb' => 10 * 1024,
        'max_files' => 10,
    ],
    // Deleting hides the record (deleted_at) and keeps the file on disk; true would also remove the file.
    'delete_files' => false,

    // Phase 3 frozen-catalog download path (routes/legacy-web.php). Activation needs an independently verified private
    // catalog and an explicit reviewed owner policy; importing rows or granting projects.view does not enable it.
    'enabled' => false,
    'catalog_path' => null,
    'catalog_sha256' => null,
    'decision_locator' => null,
    'max_bytes' => 128 * 1024 * 1024,
    'chunk_bytes' => 64 * 1024,
    'stores' => [],
];
