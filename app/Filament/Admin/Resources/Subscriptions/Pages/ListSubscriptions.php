<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscriptions\Pages;

use App\Filament\Admin\Resources\Subscriptions\SubscriptionResource;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptions extends ListRecords
{
    #[\Override]
    protected static string $resource = SubscriptionResource::class;
}
