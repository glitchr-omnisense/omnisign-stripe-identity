<?php

namespace Omnisign\StripeIdentity\Action;

use Omnisign\Request\Identity\Fetch;
use Omnisign\Request\Request;

/** GET /identity/verification_sessions/{id}: its status, and a fresh url and client_secret while it requires input. */
final class FetchAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof Fetch;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Fetch);
        $request->setResult(self::read($request->verification, $this->api->get(self::path($request->verification))));
    }
}
