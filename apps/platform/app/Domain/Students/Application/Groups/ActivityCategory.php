<?php

namespace App\Domain\Students\Application\Groups;

/**
 * SIS 11.8: an optional, closed, broad Activity category (one level, never
 * a taxonomy). It groups the Activity directory and filters the roster; it
 * says nothing about any Student.
 */
enum ActivityCategory: string
{
    case SPORTS = 'sports';
    case PERFORMING_ARTS = 'performing_arts';
    case CREATIVE_ARTS = 'creative_arts';
    case ACADEMIC_ENRICHMENT = 'academic_enrichment';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SPORTS => 'Sports',
            self::PERFORMING_ARTS => 'Performing arts',
            self::CREATIVE_ARTS => 'Creative arts',
            self::ACADEMIC_ENRICHMENT => 'Academic / enrichment',
            self::OTHER => 'Other',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
