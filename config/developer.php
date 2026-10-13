<?php

return [
    'plan_name' => env('DEVELOPER_PLAN_NAME', 'Developer'),
    'billing_enabled' => false,
    'log_retention_days' => 30,
    'webhook_events' => ['application.created', 'application.updated', 'application.deleted', 'application.secret_rotated', 'token.revoked', 'webhook.test'],
];
