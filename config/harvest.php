<?php

declare(strict_types=1);

return [
    'access_token' => env('HARVEST_ACCESS_TOKEN'),
    'account_id' => env('HARVEST_ACCOUNT_ID'),
    // The server credential must never be exposed to administrators of other organizations.
    'organization_id' => env('HARVEST_ORGANIZATION_ID'),
];
