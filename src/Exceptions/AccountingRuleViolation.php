<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Exceptions;

/**
 * HTTP 422, `accounting_rule_violation` - a rule of accounting, not of this API.
 *
 * ---
 *
 * **Show this message to a person. Do not swallow it.**
 *
 * This is the one error class where the sentence is worth more than the code.
 * The platform writes these to be read:
 *
 *   > Line 1 posts to 4200 Service Revenue, which requires a Cost Centre.
 *   > A receipt must say where the money landed: a bank, cash or gateway
 *   > clearing account.
 *   > 2026-09-20 is more than 0 day(s) in the future.
 *
 * Each names the thing to fix. Replacing that with "Could not save invoice"
 * turns a two-minute correction into a support ticket, and the commonest of
 * them - the cost centre - is not even the integrator's to fix: it is an
 * administrator's, in the back office, once.
 *
 * Retrying is pointless. The request was understood and refused.
 */
class AccountingRuleViolation extends CoreAccountingException
{
    /**
     * Is this the cost-centre refusal a freshly seeded company always gives?
     *
     * Worth its own question because it is the single most likely reason a first
     * integration fails, and because the fix belongs to somebody who is not
     * reading your stack trace. An application that recognises it can say
     * "ask your accounts team to set up a cost centre" instead of showing a
     * developer's error to a receptionist.
     */
    public function needsCostCentre(): bool
    {
        return ($this->details['dimension'] ?? null) === 'COST_CENTRE'
            || str_contains(strtolower($this->getMessage()), 'cost centre');
    }

    /** The line number the platform blamed, when it blamed one. */
    public function line(): ?int
    {
        $line = $this->details['line'] ?? null;

        return is_int($line) ? $line : null;
    }
}
