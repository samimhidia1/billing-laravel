<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentPlans\Pages;

use App\Filament\Admin\Resources\PaymentPlans\PaymentPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentPlan extends CreateRecord
{
    #[\Override]
    protected static string $resource = PaymentPlanResource::class;
}
