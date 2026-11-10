<?php

namespace Omnisign\StripeIdentity\Action;

use Omnisign\Request\Identity\Cancel;
use Omnisign\Request\Request;

/** POST /identity/verification_sessions/{id}/cancel: only while it requires input; for good. */
final class CancelAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof Cancel;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Cancel);
        $request->setResult(self::read($request->verification, $this->api->post(self::path($request->verification, '/cancel'))));
    }
}
