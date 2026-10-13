<?php

namespace App\Support\Email\Events;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * Which provider-event adapter is active, if any. `none` (default): the
 * webhook route answers 404. The fake adapter is never resolved outside
 * local/testing. `sendgrid` (ADR 0060 E18, section 28) is development /
 * non-production only: never resolved in production (the boot guard also
 * refuses it there), so the route answers 404.
 */
final class EmailEventAdapterResolver
{
    public const ADAPTERS = ['none', 'fake', 'sendgrid'];

    public function __construct(
        private readonly Container $app,
        private readonly Repository $config,
    ) {}

    public function active(): ?EmailEventAdapter
    {
        return match ((string) $this->config->get('email.events.adapter')) {
            'fake' => $this->app->environment(['local', 'testing']) ? $this->app->make(FakeEmailEventAdapter::class) : null,
            'sendgrid' => $this->app->environment('production') ? null : $this->app->make(SendGridEmailEventAdapter::class),
            default => null,
        };
    }
}
