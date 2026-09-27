<?php

namespace App\Support\Email;

/** The registered EmailSource per source type (EmailServiceProvider). */
final class EmailSources
{
    /** @var array<string, EmailSource> */
    private array $sources = [];

    public function register(EmailSource $source): void
    {
        $this->sources[$source->sourceType()] = $source;
    }

    public function for(string $sourceType): ?EmailSource
    {
        return $this->sources[$sourceType] ?? null;
    }
}
