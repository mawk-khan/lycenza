<?php

namespace App\Support\Email\Events;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * Which provider-event adapter is active, if any. `none` (default): the
 * webhook route answers 404. The fake adapter is never resolved outside
 * local/testing. No vendor adapter exists until a vendor is selected.
 */
final class EmailEventAdapterResolver
{
    public const ADAPTERS = ['none', 'fake'];

    public function __construct(
        private readonly Container $app,
        private readonly Repository $config,
    ) {}

    public function active(): ?EmailEventAdapter
    {
        return match ((string) $this->config->get('email.events.adapter')) {
            'fake' => $this->app->environment(['local', 'testing']) ? $this->app->make(FakeEmailEventAdapter::class) : null,
            default => null,
        };
    }
}
