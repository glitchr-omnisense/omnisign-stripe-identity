<?php

namespace Omnisign\StripeIdentity\Action;

use Omnisign\Model\IdentityEvent;
use Omnisign\Model\IdentityNotification;
use Omnisign\Request\Identity\Notify;
use Omnisign\Request\Request;

/**
 * A webhook's call: Stripe-Signature checked (Api::verify()), the event's
 * type read, its VerificationSession named. Nothing else of the payload is
 * kept: it carries the session, metadata included.
 */
final class NotifyAction extends AbstractAction
{
    private const EVENTS = [
        'identity.verification_session.created' => IdentityEvent::CREATED,
        'identity.verification_session.processing' => IdentityEvent::PROCESSING,
        'identity.verification_session.verified' => IdentityEvent::VERIFIED,
        'identity.verification_session.requires_input' => IdentityEvent::REQUIRES_INPUT,
        'identity.verification_session.canceled' => IdentityEvent::CANCELED,
        'identity.verification_session.redacted' => IdentityEvent::REDACTED,
    ];

    public function supports(Request $request): bool
    {
        return $request instanceof Notify;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Notify);
        $event = $this->api->verify($request->body, $request->header('Stripe-Signature'));
        $type = self::string($event, 'type') ?? '';
        $session = self::map(self::map($event, 'data') ?? [], 'object') ?? [];
        $request->setResult(new IdentityNotification(
            self::EVENTS[$type] ?? IdentityEvent::OTHER,
            $type,
            'identity.verification_session' === self::string($session, 'object') ? self::string($session, 'id') : null,
            self::string($session, 'client_reference_id'),
            null !== ($created = self::int($event, 'created')) ? new \DateTimeImmutable('@'.$created) : null,
            self::string($event, 'id'),
        ));
    }
}
