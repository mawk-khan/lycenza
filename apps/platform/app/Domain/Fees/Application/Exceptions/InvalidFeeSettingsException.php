<?php

namespace App\Domain\Fees\Application\Exceptions;

class InvalidFeeSettingsException extends FeesException
{
    public function __construct(private readonly string $field, string $message)
    {
        parent::__construct(422, 'FEE_SETTINGS_INVALID', $message);
    }

    public function field(): string
    {
        return $this->field;
    }
}
