<?php

return [
    'ca_bundle' => env('DOCUSIGN_CA_BUNDLE'),
    // Sandbox only. Production activation is a separate decision.
    'integration_key' => env('DOCUSIGN_INTEGRATION_KEY'),
    'user_id' => env('DOCUSIGN_USER_ID'),
    'account_id' => env('DOCUSIGN_ACCOUNT_ID'),
    'private_key_path' => env('DOCUSIGN_PRIVATE_KEY_PATH', storage_path('app/private/docusign.key')),
    'redirect_uri' => env('DOCUSIGN_REDIRECT_URI', 'http://127.0.0.1:8000/signing-demo/consent'),
];
