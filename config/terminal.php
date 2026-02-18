<?php

return [
    'enabled' => (bool) env('TERMINAL_ENABLED', true),

    // WebSocket endpoint used by SPA to connect into terminal gateway.
    'gateway_ws_url' => env('TERMINAL_GATEWAY_WS_URL', 'ws://127.0.0.1:8090/terminal'),

    // Shared HMAC secret used for short-lived terminal tickets.
    'shared_secret' => env('TERMINAL_SHARED_SECRET', ''),

    // Secret/header pair used by terminal-gateway -> backend lifecycle callbacks.
    // Keep this separate from TERMINAL_SHARED_SECRET (ticket signing secret).
    'gateway_callback_secret' => env('TERMINAL_GATEWAY_CALLBACK_SECRET', ''),
    'gateway_callback_header' => env('TERMINAL_GATEWAY_CALLBACK_HEADER', 'X-Terminal-Gateway-Secret'),

    'ticket_ttl_seconds' => (int) env('TERMINAL_TICKET_TTL_SECONDS', 30),

    'max_open_sessions_per_user' => (int) env('TERMINAL_MAX_OPEN_SESSIONS_PER_USER', 4),

    // Closed sessions older than this retention window are deleted by scheduler.
    'closed_session_retention_hours' => (int) env('TERMINAL_CLOSED_SESSION_RETENTION_HOURS', 168),

    // If false, only project owners may create shared terminal sessions.
    'allow_collaborator_shared_sessions' => (bool) env('TERMINAL_ALLOW_COLLABORATOR_SHARED_SESSIONS', false),

    'allowed_shells' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'TERMINAL_ALLOWED_SHELLS',
        PHP_OS_FAMILY === 'Windows' ? 'powershell.exe,pwsh.exe,cmd.exe' : '/bin/bash,/bin/sh'
    ))))),

    'max_name_length' => (int) env('TERMINAL_MAX_NAME_LENGTH', 120),
    'max_cwd_length' => (int) env('TERMINAL_MAX_CWD_LENGTH', 2048),
    'max_path_depth' => (int) env('TERMINAL_MAX_PATH_DEPTH', 12),
];
