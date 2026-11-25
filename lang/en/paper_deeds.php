<?php

// The deeds and contracts are printed in Arabic only (the owner's texts); only the project page buttons are translated.
return array_replace(require __DIR__.'/../ar/paper_deeds.php', ['open_deeds' => 'Pledge deeds', 'open_sales' => 'Sale contracts']);
