<?php

namespace App\Support\ProjectTerminal;

use Illuminate\Http\Request;

class ProjectTerminalGatewayAuthorizer
{
    public function callbackSecret(): string
    {
        return trim((string) config('terminal.gateway_callback_secret', config('terminal.shared_secret', '')));
    }

    public function isAuthorized(Request $request): bool
    {
        $secret = $this->callbackSecret();
        if ($secret === '') {
            return false;
        }

        $headerName = (string) config('terminal.gateway_callback_header', 'X-Terminal-Gateway-Secret');
        $provided = trim((string) $request->header($headerName, ''));

        if ($provided === '') {
            return false;
        }

        return hash_equals($secret, $provided);
    }
}
