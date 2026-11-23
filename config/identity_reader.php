<?php

/*
 * Reading an identity document from its scan (owner decision 23 Nov 2026): a cloud vision model returns the printed fields, the
 * program checks them locally, and a staff member compares them with the image and approves before anything is saved.
 *
 * Privacy: the image is sent only when staff press "read", it is never stored by the program anywhere but the private attachment disk,
 * and every reading is logged (who, when, which attachment, tokens) without its content. The feature is off without an API key.
 */
return [
    'api_key' => env('ANTHROPIC_API_KEY'),
    'endpoint' => env('ID_READER_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
    'version' => '2023-06-01',
    'model' => env('ID_READER_MODEL', 'claude-sonnet-5-5'),
    // Independent readings compared field by field: a field that differs between them is marked doubtful.
    'passes' => max(1, min(3, (int) env('ID_READER_PASSES', 2))),
    'timeout' => (int) env('ID_READER_TIMEOUT', 90),
    'max_tokens' => 1200,
    // The vision API accepts images up to 5 MB and PDFs up to 32 MB.
    'max_image_bytes' => 5 * 1024 * 1024,
    'max_pdf_bytes' => 20 * 1024 * 1024,
    'daily_limit' => (int) env('ID_READER_DAILY_LIMIT', 60),
];
