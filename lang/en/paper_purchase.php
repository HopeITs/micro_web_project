<?php

// The purchasing papers are printed in Arabic only (the owner's texts); only the project page buttons are translated.
return array_replace(require __DIR__.'/../ar/paper_purchase.php', [
    'print_request' => 'Purchase request', 'print_quote' => 'Price quote', 'print_receipt' => 'Receipt pledge', 'print_return' => 'Equipment return',
]);
