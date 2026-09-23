<?php

declare(strict_types=1);

namespace Ctpl\CoreAccounting\Exceptions;

/**
 * HTTP 422, `validation_failed` - the payload was wrong.
 *
 * Your bug, not a rule of accounting. `errors()` is keyed by field exactly as
 * Laravel's own validator would key it, so it drops straight into whatever your
 * application already does with `$e->errors()` from a `ValidationException`.
 */
class ValidationFailed extends CoreAccountingException
{
    /**
     * @return array<string, array<int,string>>
     */
    public function errors(): array
    {
        $errors = [];

        foreach ($this->details as $field => $messages) {
            $errors[(string) $field] = is_array($messages) ? array_values($messages) : [(string) $messages];
        }

        return $errors;
    }

    /** The first message, for a flash notice that has room for one line. */
    public function firstError(): ?string
    {
        foreach ($this->errors() as $messages) {
            if ($messages !== []) {
                return $messages[0];
            }
        }

        return null;
    }
}
