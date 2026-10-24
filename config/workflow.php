<?php

return [
    // Optional one-time bootstrap password for named operational accounts.
    // When absent, newly seeded accounts are SSO-only.
    'initial_password' => env('WORKFLOW_INITIAL_PASSWORD'),
    // Trial delivery redirects all workflow mail while retaining the intended recipient in the email body.
    'trial_mode' => env('WORKFLOW_TRIAL_MODE', true),
    'trial_recipient' => env('WORKFLOW_TRIAL_RECIPIENT', 'muhammad.taufik@ptmkm.co.id'),
];
