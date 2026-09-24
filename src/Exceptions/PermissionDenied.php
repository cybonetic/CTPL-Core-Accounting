<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Exceptions;

/** HTTP 403 - The application lacks a permission this call needs. */
class PermissionDenied extends CoreAccountingException
{
    /**
     * Is this a refusal to accept an unsigned request?
     *
     * The platform answers 403 both for "you were not granted that scope" and
     * for "this credential requires a signed request", and the two are fixed by
     * different people: the first by an administrator granting a permission,
     * the second by the integrator putting a value in their own `.env`. A
     * caller that cannot tell them apart shows the wrong instruction to
     * whoever is looking at the screen.
     *
     * Matched on the platform's own wording, which is stable because the header
     * name is part of its published contract - `X-Signature` cannot be reworded
     * without breaking every integration already sending it.
     */
    public function needsSignature(): bool
    {
        return str_contains($this->getMessage(), 'X-Signature')
            || str_contains($this->getMessage(), 'signed request');
    }

    /** Was a signature sent, but over different bytes than arrived? */
    public function signatureDidNotMatch(): bool
    {
        return str_contains($this->getMessage(), 'does not match the payload');
    }

    /** Was a signature sent with a timestamp too far from the platform's clock? */
    public function signatureExpired(): bool
    {
        return str_contains($this->getMessage(), 'outside the accepted window');
    }
}
