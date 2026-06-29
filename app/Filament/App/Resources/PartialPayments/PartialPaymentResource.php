<?php

namespace App\Filament\App\Resources\PartialPayments;

use App\Filament\App\Resources\PartialPayments\Pages\ListPartialPayments;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentGateway;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PartialPaymentResource extends Resource
{
    #[\Override]
    protected static ?string $model = Invoice::class;

    #[\Override]
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    // Read-only in the demo: recording a payment writes Payment columns that
    // don't exist yet. Listing invoices with their balances is safe.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('invoice_id')
                    ->label('Invoice')
                    ->options(Invoice::where('status', 'pending')->orWhere('status', 'partially_paid')->pluck('invoice_number', 'id'))
                    ->required()
                    ->searchable(),
                TextInput::make('amount')
                    ->required()
                    ->numeric()
                    ->label('Payment Amount'),
                Select::make('payment_gateway_id')
                    ->label('Payment Gateway')
                    ->options(PaymentGateway::where('is_active', true)->pluck('name', 'id'))
                    ->required()
                    ->searchable(),
            ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')->sortable(),
                TextColumn::make('total_amount')->sortable(),
                TextColumn::make('paid_amount')->sortable(),
                TextColumn::make('remaining_amount')->sortable(),
                TextColumn::make('status')->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('recordPayment')
                    ->label('Record payment')
                    ->icon('heroicon-o-banknotes')
                    ->visible(fn (Invoice $record): bool => in_array($record->status, ['pending', 'partially_paid', 'overdue'], true))
                    ->schema([
                        TextInput::make('amount')
                            ->numeric()
                            ->required()
                            ->default(fn (Invoice $record): float => max(0, round((float) $record->total_amount - (float) $record->payments()->sum('amount'), 2)))
                            ->rules(['numeric', 'min:0.01']),
                        Select::make('payment_gateway_id')
                            ->label('Gateway')
                            ->options(PaymentGateway::where('is_active', true)->pluck('name', 'id'))
                            ->required(),
                    ])
                    ->action(function (array $data, Invoice $record): void {
                        // Offline/manual payment: record it directly (no external gateway call).
                        Payment::create([
                            'invoice_id' => $record->id,
                            'payment_gateway_id' => $data['payment_gateway_id'],
                            'payment_date' => now(),
                            'amount' => $data['amount'],
                            'currency' => $record->currency,
                            'payment_method' => 'bank transfer',
                            'transaction_id' => 'TXN-'.$record->id.'-'.uniqid(),
                        ]);

                        $paid = (float) $record->payments()->sum('amount');
                        $fullyPaid = $paid + 0.01 >= (float) $record->total_amount;
                        $record->update([
                            'paid_amount' => $paid,
                            'status' => $fullyPaid ? 'paid' : 'partially_paid',
                            'paid_date' => $fullyPaid ? now() : null,
                        ]);

                        Notification::make()->title('Payment recorded')->success()->send();
                    }),
            ])
            ->toolbarActions([]);
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
            'index' => ListPartialPayments::route('/'),
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(function ($query): void {
                $query->where('status', 'pending')
                    ->orWhere('status', 'partially_paid');
            })
            ->latest();
    }
}
