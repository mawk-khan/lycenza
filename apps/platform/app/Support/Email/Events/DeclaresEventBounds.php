<?php

namespace App\Support\Email\Events;

/**
 * ADR 0060 section 12 (amending ADR 0055 section 11.2): an event adapter
 * may declare its own request bounds; the controller reads them. Adapters
 * that do not implement this keep the global `email.events` bounds.
 */
interface DeclaresEventBounds
{
    public function maxBodyBytes(): int;

    public function maxEvents(): int;
}
