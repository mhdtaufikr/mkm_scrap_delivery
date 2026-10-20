<?php

return [
    // Optional one-time bootstrap password for named operational accounts.
    // When absent, newly seeded accounts are SSO-only.
    'initial_password' => env('WORKFLOW_INITIAL_PASSWORD'),
];
