<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the columns the Payment/Invoice models and the Refund/Reconciliation
     * services already reference but that were never migrated ("phantom columns"),
     * and widens two status columns whose enums were too narrow for the code
     * (refund_status needs full/partial; invoices.status needs partially_paid/refunded).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('payments', 'refunded_amount')) {
                $table->decimal('refunded_amount', 10, 2)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('payments', 'refund_reason')) {
                $table->string('refund_reason')->nullable();
            }
            if (! Schema::hasColumn('payments', 'status')) {
                $table->string('status')->nullable()->default('completed');
            }
            if (! Schema::hasColumn('payments', 'reconciliation_status')) {
                $table->string('reconciliation_status')->nullable();
            }
            if (! Schema::hasColumn('payments', 'reconciliation_notes')) {
                $table->text('reconciliation_notes')->nullable();
            }
            if (! Schema::hasColumn('payments', 'affiliate_id')) {
                $table->unsignedBigInteger('affiliate_id')->nullable();
            }
            if (! Schema::hasColumn('payments', 'affiliate_commission')) {
                $table->decimal('affiliate_commission', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('payments', 'payment_method_details')) {
                $table->json('payment_method_details')->nullable();
            }
            foreach (['stripe_token', 'square_token', 'google_pay_token'] as $col) {
                if (! Schema::hasColumn('payments', $col)) {
                    $table->string($col)->nullable();
                }
            }
        });

        // refund_status was enum('none','pending','completed'); the model writes 'none'/'partial'/'full'.
        // (Only MySQL has a narrow enum to widen; sqlite/pgsql store it as flexible text.)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payments MODIFY refund_status VARCHAR(255) NULL DEFAULT 'none'");
        }

        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'tax_amount')) {
                $table->decimal('tax_amount', 10, 2)->nullable()->default(0);
            }
            if (! Schema::hasColumn('invoices', 'is_recurring')) {
                $table->boolean('is_recurring')->default(false);
            }
            if (! Schema::hasColumn('invoices', 'invoice_template_id')) {
                $table->unsignedBigInteger('invoice_template_id')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'paid_amount')) {
                $table->decimal('paid_amount', 10, 2)->nullable()->default(0);
            }
            if (! Schema::hasColumn('invoices', 'paid_date')) {
                $table->timestamp('paid_date')->nullable();
            }
        });

        // invoices.status was enum('pending','paid','overdue'); the code uses
        // 'partially_paid' and 'refunded' too.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE invoices MODIFY status VARCHAR(255) NOT NULL DEFAULT 'pending'");
        }

        // Backfill paid_amount from existing payments (portable correlated subquery).
        DB::table('invoices')->update([
            'paid_amount' => DB::raw('(SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payments.invoice_id = invoices.id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn([
                'refunded_amount', 'refund_reason', 'status', 'reconciliation_status',
                'reconciliation_notes', 'affiliate_id', 'affiliate_commission',
                'payment_method_details', 'stripe_token', 'square_token', 'google_pay_token',
            ]);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['tax_amount', 'is_recurring', 'invoice_template_id', 'paid_amount', 'paid_date']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payments MODIFY refund_status ENUM('none','pending','completed') NOT NULL DEFAULT 'none'");
            DB::statement("ALTER TABLE invoices MODIFY status ENUM('pending','paid','overdue') NOT NULL");
        }
    }
};
