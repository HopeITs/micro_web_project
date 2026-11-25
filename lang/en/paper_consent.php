<?php

// The consents are printed in their own languages (the owner's texts); only the project page buttons are translated.
return array_replace(require __DIR__.'/../ar/paper_consent.php', [
    'print_photo_adult' => 'Photo consent (adult)', 'print_photo_child' => 'Photo consent (child)', 'print_photo_informed' => 'Informed photo consent',
]);
