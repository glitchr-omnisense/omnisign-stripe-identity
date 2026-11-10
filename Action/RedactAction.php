<?php

namespace Omnisign\StripeIdentity\Action;

use Omnisign\Request\Identity\Redact;
use Omnisign\Request\Request;

/**
 * POST /identity/verification_sessions/{id}/redact: the session, its
 * reports, events, logs and files erased at Stripe (personal fields become
 * "[redacted]", the metadata is cleared), for good; allowed when it
 * requires input (it is then canceled) or is verified, not while
 * processing. redaction.status goes processing, then redacted
 * (identity.verification_session.redacted).
 */
final class RedactAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof Redact;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Redact);
        $request->setResult(self::read($request->verification, $this->api->post(self::path($request->verification, '/redact'))));
    }
}
