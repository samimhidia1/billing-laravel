<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RecurringBillings\Pages;

use App\Filament\Admin\Resources\RecurringBillings\RecurringBillingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRecurringBilling extends CreateRecord
{
    #[\Override]
    protected static string $resource = RecurringBillingResource::class;
}
