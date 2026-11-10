<?php

namespace Omnisign\StripeIdentity\Action;

use Omnisign\Model\Check;
use Omnisign\Model\CheckKind;
use Omnisign\Model\Person;
use Omnisign\Model\VerificationResult;
use Omnisign\Request\Identity\Result;
use Omnisign\Request\Request;

/**
 * GET /identity/verification_sessions/{id} with expand[]=last_verification_report
 * - and verified_outputs (the names) with the person option, verified_outputs.dob
 * with birth_date (a restricted key with the sensitive results' permission).
 * The report's document, selfie, id_number, email and phone sections become
 * the checks; its document's type and issuing country are kept, nothing
 * else of it: no number, address, images (their file ids) nor expiry.
 */
final class ResultAction extends AbstractAction
{
    private const CHECKS = ['document' => CheckKind::DOCUMENT, 'selfie' => CheckKind::SELFIE, 'id_number' => CheckKind::ID_NUMBER, 'email' => CheckKind::EMAIL, 'phone' => CheckKind::PHONE];

    public function __construct(private readonly bool $person = false, private readonly bool $birthDate = false)
    {
        parent::__construct();
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Result;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Result);
        $expand = ['last_verification_report'];
        if ($this->person) {
            $expand[] = 'verified_outputs';
        }
        if ($this->birthDate) {
            $expand[] = 'verified_outputs.dob';
        }
        $session = $this->api->get(self::path($request->verification), ['expand' => $expand]);
        $verification = self::read($request->verification, $session);
        $report = self::map($session, 'last_verification_report') ?? [];

        $checks = [];
        foreach (self::CHECKS as $section => $kind) {
            $part = self::map($report, $section);
            if (null === $part) {
                continue;
            }
            $error = self::map($part, 'error') ?? [];
            $status = self::string($part, 'status');
            $checks[] = new Check($kind, 'verified' === $status ? true : ('unverified' === $status ? false : null), self::string($error, 'code'), self::string($error, 'reason'));
        }
        $document = self::map($report, 'document') ?? [];

        $person = null;
        $outputs = self::map($session, 'verified_outputs');
        if (null !== $outputs && ($this->person || $this->birthDate)) {
            $dob = self::map($outputs, 'dob');
            $person = new Person(
                $this->person ? self::string($outputs, 'first_name') : null,
                $this->person ? self::string($outputs, 'last_name') : null,
                $dob && self::int($dob, 'year') && self::int($dob, 'month') && self::int($dob, 'day') ? new \DateTimeImmutable(\sprintf('%04d-%02d-%02d', self::int($dob, 'year'), self::int($dob, 'month'), self::int($dob, 'day'))) : null,
            );
        }

        $request->setResult(new VerificationResult(
            $verification->reference(),
            $verification->status,
            $checks,
            StartAction::DOCUMENTS[self::string($document, 'type') ?? ''] ?? null,
            self::string($document, 'issuing_country'),
            $person,
            null !== ($created = self::int($report, 'created')) ? new \DateTimeImmutable('@'.$created) : null,
            self::string($report, 'id') ?? self::string($session, 'last_verification_report'),
            $verification->live,
        ));
    }
}
