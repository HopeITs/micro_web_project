<?php

return [
    'title' => 'Purchases', 'open' => 'Purchases', 'back' => 'Back to the project', 'cmd' => 'Purchases of',
    'intro' => 'Items bought for this project. Rows are saved together; totals are quantity times price, per currency.',
    'read_only' => 'You can read this page; the administrator, data entry and legal roles record purchases.',
    'not_funded' => 'Purchases are recorded only for funded projects (Accepted, Financed, Finished, Canceled or Closed). This project is not in one of these states.',
    'project' => 'Project', 'project_number' => 'Project number', 'beneficiary' => 'Beneficiary', 'project_name' => 'Project name', 'profession' => 'Profession / career',
    'loan_amount' => 'Loan amount', 'loan_date' => 'Loan date', 'no_loan' => 'No loan amount has been entered for this project.',
    'items' => 'Purchased items', 'none' => 'No purchases recorded.', 'none_hint' => 'Press "Add a row" to record the first item.',
    'name' => 'Item', 'brand' => 'Brand', 'category' => 'Category', 'quantity' => 'Quantity', 'price' => 'Price', 'currency' => 'Currency',
    'invoice_date' => 'Invoice date', 'invoice_number' => 'Invoice number', 'trader' => 'Trader',
    'add' => 'Add a row', 'remove' => 'Remove the row', 'delete' => 'Delete', 'delete_confirm' => 'Delete this purchase? The deletion is recorded in the audit log.',
    'save' => 'Save purchases', 'saved' => 'The purchases are saved.', 'deleted' => 'The purchase is deleted.',
    'hint' => 'A row needs an item name. A new row left empty is not saved. Unsaved rows can be removed; saved rows are deleted after a confirmation.',
    'totals' => 'Totals per currency', 'totals_none' => 'No amounts yet.', 'totals_hint' => 'Quantity times price; amounts in different currencies are never added together.',
    'errors' => [
        'stale' => 'The purchases changed since this page was opened. Reload the page before saving.',
        'ineligible' => 'Purchases are recorded only for funded projects (Accepted, Financed, Finished, Canceled or Closed).',
        'row_missing' => 'This purchase no longer belongs to the project. Reload the page.', 'too_many' => 'Too many rows.',
        'item_name' => 'Enter the item name (at most 255 characters).', 'text' => 'The text is too long.', 'quantity' => 'Enter a whole quantity (0 or more).',
        'price' => 'Enter a price of 0 or more with at most two decimals.', 'currency' => 'Choose USD or SYP.', 'date' => 'Enter a valid date (YYYY-MM-DD).',
        'category' => 'Select a category from the list.',
    ],
];
