<?php

/** Reading an identity document from an attachment (owner decision 23 Nov 2026). */
return [
    'title' => 'Read the identity document', 'button' => 'Read identity document', 'attach_button' => 'Read ID',
    'intro' => 'The identity details are read from the attachment\'s image and shown beside it as proposals. Compare each value with the image, edit it if needed, then save the ticked ones. Nothing changes on the person before you save.',
    'privacy' => 'When you press "Read", the image of the document is sent to a cloud vision service only to be read. Every reading is logged (who, when, for which person) without its content.',
    'scan' => 'Attachment', 'is_id' => 'ID', 'no_scans' => 'This person has no image or PDF attachments. Add the identity document under attachments first.',
    'disabled_title' => 'Reading is not enabled',     'disabled' => [
        'claude' => 'Reading needs the vision service key (ANTHROPIC_API_KEY) in the server settings. Ask the administrator to add it, then reload the page.',
        'ollama' => 'The local Ollama engine is not set up. Ask the administrator to install Ollama and pull a vision model (see docs/delivery/identity-reading.md).',
        'tesseract' => 'Tesseract is not installed on the server. Ask the administrator to install it with Arabic (see docs/delivery/identity-reading.md).',
    ],
    'engines' => ['claude' => 'Engine: cloud vision model (Claude)', 'ollama' => 'Engine: local vision model (Ollama)', 'tesseract' => 'Engine: local Tesseract'],
    'privacy_local' => 'The image is read on this server itself and never leaves it. Every reading is logged (who, when, for which person) without its content.',
    'remaining' => 'Readings left for you today: :count', 'read' => 'Read the document', 'reading' => 'Reading… it can take half a minute.', 'read_again' => 'Read again',
    'document_type' => 'Document read', 'types' => ['national_id' => 'National ID card', 'family_book' => 'Family book', 'passport' => 'Passport', 'residence_permit' => 'Residence permit', 'other' => 'Other', 'not_an_identity_document' => 'Not an identity document'],
    'check_hint' => 'Compare every value with the image before saving. Doubtful values are unticked: tick them once you have checked them.',
    'col_field' => 'Field', 'col_now' => 'Saved now', 'col_read' => 'Read (editable)', 'col_conf' => 'Confidence', 'col_use' => 'Apply',
    'confidence' => ['high' => 'Sure', 'medium' => 'Fair', 'low' => 'Needs review'],
    'warnings' => [
        'national_number_length' => 'The national number is not 11 digits', 'birth_date_format' => 'The date of birth format is not understood', 'birth_date_invalid' => 'The date of birth is not valid',
        'name_symbols' => 'Stray symbols were removed from the name', 'readings_differ' => 'The two readings differ',
    ],
    'alternatives' => 'Other readings', 'notes' => 'Reader notes',
    'save' => 'Save the ticked values on the person', 'save_anyway' => 'Save anyway', 'nothing_chosen' => 'No value is ticked to save.', 'saved' => 'The identity details were saved.',
    'errors' => [
        'disabled' => 'Reading is not enabled on this server.', 'unreachable' => 'The vision service could not be reached. Check the connection and try again.',
        'http' => 'The vision service refused the request. Check the key and try again.', 'no_result' => 'The service returned no usable result. Try a clearer image.',
        'too_large' => 'The file is larger than reading accepts. Compress the image and upload it again.', 'limit' => 'You reached today\'s reading limit.',
        'pdf_unsupported' => 'The PDF could not be turned into images: Poppler (pdftoppm) must be installed on the server, or upload the document as an image.',
        'language_missing' => 'Arabic is not installed in Tesseract (ara). Ask the administrator to install it.',
        'engine_failed' => 'The local reading engine failed. Try a clearer image or ask the administrator.',
        'model_missing' => 'The vision model is not pulled in Ollama yet. Ask the administrator to pull it.',
    ],
];
