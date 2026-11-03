<?php

declare(strict_types=1);

namespace Aja\Exception;

/**
 * The payload was rejected by AJA (code VALIDATION).
 * fields() maps each offending JSON field to its reason.
 */
final class ValidationException extends ApiException
{
    /** @return array<string,string> */
    public function fields(): array
    {
        $fields = $this->getDetails()['fields'] ?? [];

        return is_array($fields) ? $fields : [];
    }
}
