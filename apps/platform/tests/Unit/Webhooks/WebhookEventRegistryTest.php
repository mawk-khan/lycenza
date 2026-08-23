<?php

namespace Tests\Unit\Webhooks;

use App\Support\Webhooks\WebhookEventRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebhookEventRegistryTest extends TestCase
{
    #[Test]
    public function a_registered_externally_visible_event_type_is_subscribable(): void
    {
        $this->assertTrue((new WebhookEventRegistry)->isSubscribable('school.setting.changed.v1'));
    }

    #[Test]
    public function an_unregistered_event_type_is_not_subscribable(): void
    {
        $this->assertFalse((new WebhookEventRegistry)->isSubscribable('some.internal.security.event.v1'));
    }

    #[Test]
    public function the_synthetic_test_event_is_registered_and_subscribable(): void
    {
        $this->assertTrue((new WebhookEventRegistry)->isSubscribable('platform.webhook_test.v1'));
    }
}
