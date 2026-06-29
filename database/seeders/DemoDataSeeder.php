<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Credit;
use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\Invoice_Item;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PaymentPlan;
use App\Models\Products_Service;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Realistic Zeop (Réunion ISP) demo data so the Filament panels and API have
 * something to show. Only writes columns that exist in the DB (no phantom
 * columns). Idempotent-ish: skips if demo data is already present.
 *
 * Model events are disabled during the bulk insert so the Invoice audit-log
 * hooks (which expect an authenticated user) don't fire during seeding.
 */
class DemoDataSeeder extends Seeder
{
    private const PRODUCTS = [
        ['ZeFix Fibre 1 Gbit/s', 'Internet fibre tres haut debit', 39.99, 'fixe'],
        ['ZeFix Fibre 2 Gbit/s', 'Internet fibre 2 Gbit/s symetrique', 49.99, 'fixe'],
        ['ZeDuo Fibre + Mobile', 'Pack fibre et forfait mobile', 54.99, 'triple_play'],
        ['ZeTrio Fibre + TV + Mobile', 'Pack complet fibre, TV et mobile', 64.99, 'triple_play'],
        ['FTTR Premium', 'Fibre jusqu a la chambre, Wi-Fi 7', 79.99, 'fixe'],
        ['Forfait Mobile 50 Go', 'Forfait mobile 50 Go 4G/5G', 19.99, 'mobile'],
        ['Forfait Mobile Illimite', 'Appels, SMS et data illimites', 29.99, 'mobile'],
        ['Box TV 90 chaines', 'Bouquet TV 90 chaines + replay', 14.99, 'tv'],
        ['Zeop Entreprise Pro', 'Lien pro garanti, SLA 4h', 149.99, 'entreprise'],
        ['Zeop Entreprise SLA+', 'Lien pro redonde, SLA 1h', 299.99, 'entreprise'],
        ['Hebergement Web Pro', 'Hebergement mutualise pro', 9.99, 'hosting'],
        ['Nom de domaine .re', 'Enregistrement nom de domaine .re', 12.00, 'domain'],
    ];

    public function run(): void
    {
        if (Customer::count() > 0) {
            $this->command?->warn('DemoDataSeeder: customers already exist — skipping.');

            return;
        }

        Model::withoutEvents(function (): void {
            $gateways = collect([
                'Carte bancaire (Stripe)', 'Virement SEPA', 'PayPal', 'Prelevement automatique',
            ])->map(fn (string $name): PaymentGateway => PaymentGateway::create([
                'team_id' => 1,
                'name' => $name,
                'api_key' => 'demo_'.fake()->bothify('??????##'),
                'secret_key' => 'demo_'.fake()->bothify('????????####'),
                'is_active' => true,
            ]));

            $products = collect(self::PRODUCTS)->map(fn (array $p): Products_Service => Products_Service::create([
                'team_id' => 1,
                'name' => $p[0],
                'description' => $p[1],
                'base_price' => $p[2],
                'type' => $p[3],
                'pricing_model' => 'flat',
            ]));

            $invoiceSeq = 1;
            $customers = Customer::factory()->count(25)->create(['team_id' => 1]);

            foreach ($customers as $customer) {
                $subCount = fake()->numberBetween(1, 2);

                for ($s = 0; $s < $subCount; $s++) {
                    $product = $products->random();
                    $start = fake()->dateTimeBetween('-14 months', '-1 month');
                    $status = fake()->randomElement(['active', 'active', 'active', 'cancelled', 'suspended', 'pending_renewal']);

                    $subscription = Subscription::create([
                        'team_id' => 1,
                        'customer_id' => $customer->id,
                        'product_service_id' => $product->id,
                        'start_date' => $start,
                        'end_date' => (clone $start)->modify('+1 year'),
                        'renewal_period' => 'monthly',
                        'status' => $status,
                        'price' => $product->base_price,
                        'currency' => 'EUR',
                        'auto_renew' => $status !== 'cancelled',
                        'last_billed_at' => fake()->dateTimeBetween($start, 'now'),
                    ]);

                    // 2–4 monthly invoices per subscription
                    foreach (range(1, fake()->numberBetween(2, 4)) as $m) {
                        $issue = fake()->dateTimeBetween('-6 months', 'now');
                        $invStatus = fake()->randomElement(['paid', 'paid', 'paid', 'pending', 'overdue']);

                        $invoice = Invoice::create([
                            'team_id' => 1,
                            'customer_id' => $customer->id,
                            'subscription_id' => $subscription->id,
                            'invoice_number' => 'INV-'.str_pad((string) $invoiceSeq++, 6, '0', STR_PAD_LEFT),
                            'issue_date' => $issue,
                            'due_date' => (clone $issue)->modify('+15 days'),
                            'total_amount' => 0, // set from items below
                            'currency' => 'EUR',
                            'status' => $invStatus,
                            'paid_at' => $invStatus === 'paid' ? $issue : null,
                        ]);

                        $lineTotal = $product->base_price;
                        Invoice_Item::create([
                            'invoice_id' => $invoice->id,
                            'product_service_id' => $product->id,
                            'quantity' => 1,
                            'unit_price' => $product->base_price,
                            'total_price' => $product->base_price,
                            'currency' => 'EUR',
                        ]);

                        $invoice->update(['total_amount' => $lineTotal]);

                        if ($invStatus === 'paid') {
                            Payment::create([
                                'team_id' => 1,
                                'invoice_id' => $invoice->id,
                                'payment_gateway_id' => $gateways->random()->id,
                                'payment_date' => $issue,
                                'amount' => $lineTotal,
                                'currency' => 'EUR',
                                'payment_method' => fake()->randomElement(['credit card', 'bank transfer', 'PayPal']),
                                'transaction_id' => 'TXN-'.fake()->unique()->numerify('########'),
                                'refund_status' => 'none',
                            ]);
                            $invoice->update(['paid_amount' => $lineTotal, 'paid_date' => $issue]);
                        }
                    }
                }

                // Some customers have a credit (avoir)
                if (fake()->boolean(30)) {
                    Credit::create([
                        'team_id' => 1,
                        'customer_id' => $customer->id,
                        'amount' => fake()->randomFloat(2, 5, 80),
                        'description' => fake()->randomElement(['Geste commercial', 'Avoir sur facture', 'Parrainage', 'Dedommagement panne']),
                        'expiry_date' => now()->addMonths(6),
                    ]);
                }
            }

            // A handful of quotes
            foreach ($customers->random(8) as $customer) {
                $quote = Quote::create([
                    'team_id' => 1,
                    'customer_id' => $customer->id,
                    'quote_number' => 'QUO-'.fake()->unique()->bothify('????####'),
                    'title' => fake()->randomElement(['Migration fibre pro', 'Pack entreprise multi-sites', 'Upgrade FTTR', 'Etude raccordement']),
                    'status' => fake()->randomElement(['draft', 'sent', 'accepted', 'declined']),
                    'currency' => 'EUR',
                    'valid_until' => now()->addDays(30),
                    'subtotal' => 0,
                    'tax_amount' => 0,
                    'total' => 0,
                ]);
                $total = 0;
                foreach (range(1, fake()->numberBetween(1, 3)) as $qi) {
                    $unit = fake()->randomFloat(2, 20, 300);
                    $qty = fake()->numberBetween(1, 3);
                    QuoteItem::create([
                        'quote_id' => $quote->id,
                        'description' => fake()->randomElement(['Frais de raccordement', 'Abonnement mensuel', 'Installation sur site', 'Equipement']),
                        'quantity' => $qty,
                        'unit_price' => $unit,
                        'total' => $unit * $qty,
                        'sort_order' => $qi,
                    ]);
                    $total += $unit * $qty;
                }
                $quote->update(['subtotal' => $total, 'total' => $total]);
            }

            // Payment plans on a few unpaid invoices
            Invoice::where('status', '!=', 'paid')->inRandomOrder()->take(5)->get()
                ->each(function (Invoice $invoice): void {
                    $installments = fake()->numberBetween(3, 6);
                    PaymentPlan::create([
                        'invoice_id' => $invoice->id,
                        'total_installments' => $installments,
                        'installment_amount' => round((float) $invoice->total_amount / $installments, 2),
                        'frequency' => 'monthly',
                        'start_date' => now(),
                        'next_due_date' => now()->addMonth(),
                        'status' => 'active',
                    ]);
                });

            // Hosting accounts (App panel resource) — needs customer + subscription
            Subscription::inRandomOrder()->take(8)->get()->each(function (Subscription $sub): void {
                HostingAccount::create([
                    'team_id' => 1,
                    'customer_id' => $sub->customer_id,
                    'subscription_id' => $sub->id,
                    'username' => fake()->unique()->userName(),
                    'domain' => fake()->unique()->domainName(),
                    'package' => fake()->randomElement(['Web Pro', 'Cloud Omega 1', 'Starter']),
                    'status' => fake()->randomElement(['active', 'suspended', 'pending']),
                    'price' => fake()->randomFloat(2, 5, 50),
                ]);
            });
        });

        $this->command?->info(sprintf(
            'DemoDataSeeder: %d customers, %d subscriptions, %d invoices, %d payments seeded.',
            Customer::count(), Subscription::count(), Invoice::count(), Payment::count()
        ));
    }
}
