<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscriptions\Pages;

use App\Filament\Admin\Resources\Subscriptions\SubscriptionResource;
use Filament\Resources\Pages\EditRecord;

class EditSubscription extends EditRecord
{
    #[\Override]
    protected static string $resource = SubscriptionResource::class;
}
