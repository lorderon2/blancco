<?php

return [
    'bitrix' => ['enabled' => env('BITRIX_ENABLED', false), 'url' => env('BITRIX_WEBHOOK_URL')],
    'timezone' => 'Europe/Warsaw',
    'sync_enabled' => env('BLANCCO_SYNC_ENABLED', false),
    'import_from' => env('BLANCCO_IMPORT_FROM'),
    'bc' => ['source' => env('BC_SOURCE', 'sql'), 'api' => ['base_url' => env('BC_API_BASE_URL'), 'token_url' => env('BC_TOKEN_URL'), 'client_id' => env('BC_CLIENT_ID'), 'client_secret' => env('BC_CLIENT_SECRET')], 'enabled' => env('BC_ENABLED', false), 'connection' => 'bc', 'table' => env('BC_LEDGER_TABLE'),
        'date_column' => env('BC_ASSIGNMENT_DATE_COLUMN', 'Posting Date'), 'sequence_column' => env('BC_SEQUENCE_COLUMN', 'Entry No_')],
    'aiken' => ['enabled' => env('AIKEN_ENABLED', false)],
    'departments' => ['enabled' => env('DEPARTMENTS_ENABLED', false), 'connection' => 'departments'],
    'idosell' => ['enabled' => env('IDOSELL_ENABLED', false), 'url' => env('IDOSELL_URL'), 'key' => env('IDOSELL_API_KEY'), 'max_pages' => 100],
    'cloud' => ['serial_field' => env('BLANCCO_CLOUD_SERIAL_FIELD', '@system_serial'), 'report_url' => env('BLANCCO_CLOUD_REPORT_URL'), 'enabled' => env('BLANCCO_CLOUD_ENABLED', false), 'url' => env('BLANCCO_CLOUD_URL'), 'key' => env('BLANCCO_CLOUD_API_KEY')],
];
