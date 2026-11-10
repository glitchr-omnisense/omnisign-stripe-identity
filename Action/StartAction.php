<?php

namespace Omnisign\StripeIdentity\Action;

use Omnisign\Exception\InvalidConfigException;
use Omnisign\Model\DocumentType;
use Omnisign\Request\Identity\Start;
use Omnisign\Request\Request;

/**
 * POST /identity/verification_sessions: type document, the documents
 * accepted, a live capture, a selfie matched, the return URL, the
 * application's key as client_reference_id, the e-mail as provided_details.
 */
final class StartAction extends AbstractAction
{
    /** The family's document types Stripe reads (options[document][allowed_types]). */
    public const DOCUMENTS = [
        'passport' => DocumentType::PASSPORT,
        'id_card' => DocumentType::ID_CARD,
        'driving_license' => DocumentType::DRIVING_LICENSE,
    ];

    public function supports(Request $request): bool
    {
        return $request instanceof Start;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Start);
        $verification = $request->verification;
        $types = [];
        foreach ($verification->documents as $document) {
            $type = array_search($document, self::DOCUMENTS, true);
            $types[] = false !== $type ? $type : throw new InvalidConfigException(\sprintf('Stripe Identity reads no "%s" document: passport, id_card, driving_license.', $document->value));
        }
        $session = $this->api->post('/identity/verification_sessions', [
            'type' => 'document',
            'options' => ['document' => array_filter([
                'allowed_types' => $types ?: null,
                'require_live_capture' => $verification->liveCapture,
                'require_matching_selfie' => $verification->selfie,
            ], static fn ($v) => null !== $v)],
            'return_url' => $verification->returnUrl,
            'client_reference_id' => $verification->key,
            'provided_details' => null !== $verification->email ? ['email' => $verification->email] : null,
            'metadata' => $verification->metadata ?: null,
        ]);
        $request->setResult(self::read($verification, $session));
    }
}
