<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Exceptions;

/**
 * A posted document cannot be edited. There is no update endpoint, on purpose.
 *
 * ---
 *
 * **This is the one people arrive looking for, so it explains itself.**
 *
 * Every integrator comes to a document API with a CRUD shape in mind and
 * reaches for `update()`. There isn't one, and adding one would be the single
 * most damaging thing this platform could do.
 *
 * A posted invoice has already hit the general ledger and has already been given
 * a statutory number. Editing it would mean:
 *
 *   - **the ledger and the document disagree.** The journal entry was posted
 *     from the old figures; changing the invoice silently makes the trial
 *     balance describe something that no longer exists;
 *   - **the number now means two things.** Rule 46(b) requires a consecutive
 *     series, and the copy the customer already has is the authoritative one.
 *     An invoice whose amount changed after it was sent is not a correction, it
 *     is a second document wearing the first one's number;
 *   - **the audit trail records a state nobody can reconstruct.** "What did we
 *     invoice on the 14th" stops having an answer.
 *
 * So there are exactly two ways to change what has been billed, and which one
 * to use is a real decision with a real consequence:
 *
 * | Situation | Do this |
 * |---|---|
 * | Raised by mistake, nothing sent, same day | `cancel()` - reverses the journal and voids the number |
 * | Customer has it, or the period is closed | a **credit note** - `notes()->credit()` |
 *
 * A credit note leaves both documents standing and records the adjustment as
 * its own event, which is what a GST return and an auditor both expect. A
 * cancellation pretends the invoice never happened, which is only honest while
 * that is still true.
 */
class ImmutableDocument extends CoreAccountingException
{
    /**
     * The explanation, when somebody calls a method that cannot exist.
     *
     * Thrown from `Invoices::update()` rather than leaving PHP to say "call to
     * undefined method", because the undefined-method error teaches nothing and
     * this is the moment the caller is actually paying attention.
     */
    public static function cannotUpdate(string $document = 'invoice'): self
    {
        return new self(
            sprintf(
                'A posted %s cannot be updated, and this platform has no endpoint that would. It has '
                .'already reached the general ledger and already carries a statutory number, so '
                .'editing it would leave the ledger describing something that no longer exists and a '
                .'number meaning two different things. Cancel it if nothing has been sent and the '
                .'period is open - $ledger->invoices()->cancel($id, reason: "...") - or raise a credit '
                .'note against it, which is what to do once the customer has a copy: '
                .'$ledger->notes()->credit(...). See ImmutableDocument for which applies when.',
                $document
            ),
            errorCode: 'immutable_record',
        );
    }
}
