<?php

/*
 * Reading an identity document from its scan (owner decisions 23 Nov 2026): an engine returns the printed fields, the program checks them
 * locally, and a staff member compares them with the image and approves before anything is saved.
 *
 * Engines (ID_READER_DRIVER):
 *   claude    cloud vision model (paid per reading; the scan leaves the server, only when staff press "read")
 *   ollama    a vision model running on this side through Ollama (free; the scan never leaves)
 *   tesseract Tesseract OCR on this side (free; the scan never leaves; the least accurate, so staff review matters most)
 *
 * Every reading is logged (who, when, tokens) without its content, whatever the engine. The feature is off until the chosen engine is set up.
 * The default is tesseract (owner decision 23 Nov 2026): nothing leaves the server unless someone sets another engine.
 */
return [
    'driver' => env('ID_READER_DRIVER', 'tesseract'),

    // claude
    'api_key' => env('ANTHROPIC_API_KEY'),
    'endpoint' => env('ID_READER_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
    'version' => '2023-06-01',
    'model' => env('ID_READER_MODEL', 'claude-sonnet-5-5'),
    'timeout' => (int) env('ID_READER_TIMEOUT', 90),
    'max_tokens' => 1200,

    // ollama: `ollama pull qwen2.5vl:7b` (or another vision model), then ID_READER_DRIVER=ollama
    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen2.5vl:7b'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 300),
    ],

    // tesseract: install Tesseract with the Arabic data ("ara"), then ID_READER_DRIVER=tesseract
    'tesseract' => [
        'binary' => env('TESSERACT_BIN', 'tesseract'),
        'lang' => env('TESSERACT_LANG', 'ara+eng'),
        // A folder holding ara.traineddata (and eng) when the language data is not in Tesseract's own folder (needs no administrator rights).
        'tessdata' => env('TESSERACT_DATA'),
        'timeout' => (int) env('TESSERACT_TIMEOUT', 120),
    ],

    // Poppler's pdftoppm turns a PDF scan into page images for ollama and tesseract (claude reads PDFs itself).
    'pdftoppm' => env('PDFTOPPM_BIN', 'pdftoppm'),

    // Independent readings compared field by field: a field that differs between them is marked doubtful.
    'passes' => max(1, min(3, (int) env('ID_READER_PASSES', 2))),

    // Size limits of the scan (the cloud API accepts images up to 5 MB and PDFs up to 32 MB).
    'max_image_bytes' => 5 * 1024 * 1024,
    'max_pdf_bytes' => 20 * 1024 * 1024,

    // Readings per account per day: only the paid engine is limited.
    'daily_limit' => (int) env('ID_READER_DAILY_LIMIT', 60),
];
