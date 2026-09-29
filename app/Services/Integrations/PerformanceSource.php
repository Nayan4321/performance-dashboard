<?php

namespace App\Services\Integrations;

use App\Models\WebhookEvent;

/**
 * Every external performance source (Zenoti, CallGear, ...) implements this so
 * the scheduler, webhooks and the Integrations screen treat them the same way.
 */
interface PerformanceSource
{
    public function key(): string;

    public function label(): string;

    public function isConfigured(): bool;

    /** Entities this source can sync, e.g. ['centers', 'employees', ...]. */
    public function entities(): array;

    /** Run a sync for one entity, or all of them when null. Returns SyncRun models. */
    public function sync(?string $entity = null): array;

    /** Apply a webhook payload that has already been stored. */
    public function handleWebhook(WebhookEvent $event): void;

    /** Validate the shared secret on an incoming webhook request. */
    public function verifyWebhookToken(?string $token): bool;
}
