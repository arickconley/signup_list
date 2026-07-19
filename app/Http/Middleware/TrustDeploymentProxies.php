<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

final class TrustDeploymentProxies extends TrustProxies
{
    /** @return array<int, string> */
    protected function proxies(): array
    {
        $trustedProxies = config('deployment.https.trusted_proxies');

        return config('deployment.https.termination') === 'proxy' && is_array($trustedProxies)
            ? $trustedProxies
            : [];
    }
}
