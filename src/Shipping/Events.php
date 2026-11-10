<?php

declare(strict_types=1);

namespace Reeds\Shipping;

/** Every webhook event type the Shipping API sends (a test checks this against the spec). */
final class Events
{
    public const TYPES = [
        'delivery.created',
        'delivery.requires_action',
        'delivery.booked',
        'delivery.status_changed',
        'delivery.schedule_changed',
        'delivery.expiring',
        'delivery.cancelled',
        'wallet.funded',
        'wallet.low_balance',
        'invoice.issued',
        'invoice.paid',
        'invoice.overdue',
    ];

    private function __construct()
    {
    }
}
