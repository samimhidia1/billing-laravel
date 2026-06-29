<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscriptions\Pages;

use App\Filament\Admin\Resources\Subscriptions\SubscriptionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSubscription extends CreateRecord
{
    #[\Override]
    protected static string $resource = SubscriptionResource::class;
}
