<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscriptions;

use App\Models\Products_Service;
use App\Models\Subscription;
use App\Services\BillingService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubscriptionResource extends Resource
{
    #[\Override]
    protected static ?string $model = Subscription::class;

    #[\Override]
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_id')
                    ->relationship('customer', 'name')
                    ->required(),
                Select::make('product_service_id')
                    ->relationship('productService', 'name')
                    ->required(),
                DatePicker::make('start_date')
                    ->required(),
                DatePicker::make('end_date')
                    ->required(),
                Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'cancelled' => 'Cancelled',
                        'suspended' => 'Suspended',
                    ])
                    ->required(),
                Toggle::make('auto_renew')
                    ->required(),
            ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('customer.name'),
                TextColumn::make('productService.name'),
                TextColumn::make('status'),
                TextColumn::make('start_date'),
                TextColumn::make('end_date'),
                IconColumn::make('auto_renew')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'cancelled' => 'Cancelled',
                        'suspended' => 'Suspended',
                    ]),
            ])
            ->recordActions([
                Action::make('upgrade')
                    ->label('Upgrade')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('success')
                    ->schema([
                        Select::make('product_service_id')
                            ->label('New product')
                            ->options(fn (Subscription $record) => Products_Service::query()
                                ->where('base_price', '>=', $record->price)
                                ->where('id', '!=', $record->product_service_id)
                                ->pluck('name', 'id'))
                            ->required(),
                    ])
                    ->action(function (array $data, Subscription $record): void {
                        $invoice = app(BillingService::class)
                            ->upgradeSubscription($record, Products_Service::findOrFail($data['product_service_id']));
                        Notification::make()
                            ->title('Subscription upgraded')
                            ->body("Prorated invoice {$invoice->invoice_number}: {$invoice->total_amount} {$invoice->currency}")
                            ->success()->send();
                    }),
                Action::make('downgrade')
                    ->label('Downgrade')
                    ->icon('heroicon-o-arrow-down-circle')
                    ->color('warning')
                    ->schema([
                        Select::make('product_service_id')
                            ->label('New product (applied at renewal)')
                            ->options(fn (Subscription $record) => Products_Service::query()
                                ->where('base_price', '<=', $record->price)
                                ->where('id', '!=', $record->product_service_id)
                                ->pluck('name', 'id'))
                            ->required(),
                    ])
                    ->action(function (array $data, Subscription $record): void {
                        app(BillingService::class)
                            ->scheduleDowngrade($record, Products_Service::findOrFail($data['product_service_id']));
                        Notification::make()
                            ->title('Downgrade scheduled')
                            ->body('It will be applied at the next renewal.')
                            ->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
            'create' => Pages\CreateSubscription::route('/create'),
            'edit' => Pages\EditSubscription::route('/{record}/edit'),
        ];
    }
}
