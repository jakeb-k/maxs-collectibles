<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historical purchases cannot be reconstructed truthfully from mutable products.
        // This installation imports no business data. A populated target needs an audited migration.
        if (DB::table('orders')->exists() || DB::table('order_products')->exists()) {
            throw new RuntimeException('Existing purchase data requires an approved snapshot backfill before migration.');
        }
        if (DB::table('products')->where('price', '<', 0)->exists()
            || DB::table('products')->whereNotNull('discount')->where(fn ($q) => $q->where('discount', '<', 0)->orWhere('discount', '>', 1))->exists()) {
            throw new RuntimeException('Invalid legacy money requires an audited backfill.');
        }
        // Validate all option rows before any MySQL DDL (which commits implicitly).
        $options = DB::table('product_options')->get()->mapWithKeys(function ($row) {
            $values = json_decode($row->values, true);
            if (!is_array($values) || !array_is_list($values) || !$values
                || count(array_filter($values, fn ($value) => is_string($value) && trim($value) !== '')) !== count($values)) {
                throw new RuntimeException('Legacy option values require an explicit JSON-list backfill.');
            }
            return [$row->id => json_encode($values, JSON_THROW_ON_ERROR)];
        });
        Schema::table('users', fn (Blueprint $table) => $table->string('mobile')->nullable()->change());
        Schema::table('products', function (Blueprint $table) {
            $table->text('url')->nullable()->change();
            $table->integer('delivery_date')->nullable()->change();
            $table->float('price')->nullable()->change();
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->unsignedSmallInteger('discount_bps')->default(0);
            $table->char('currency', 3)->default('AUD');
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->json('facts')->default(DB::raw('(JSON_ARRAY())'));
        });
        // Legacy discounts are fractions (controller wrote percentage / 100).
        // Cast to decimal BEFORE ROUND to avoid binary-float tie rounding; positive half-up.
        DB::statement('UPDATE products SET price_minor = ROUND(CAST(price AS DECIMAL(20,4)) * 100, 0), discount_bps = ROUND(CAST(COALESCE(discount, 0) AS DECIMAL(12,8)) * 10000, 0), facts = JSON_ARRAY()');
        foreach ($options as $id => $values) {
            DB::table('product_options')->where('id', $id)->update(['values' => $values]);
        }
        Schema::table('product_options', fn (Blueprint $table) => $table->json('values')->change());
        Schema::table('images', function (Blueprint $table) {
            $table->string('disk')->default('public');
            $table->unsignedInteger('position')->default(0);
            $table->json('derivatives')->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('total')->nullable()->change();
            $table->integer('expected_delivery_range')->nullable()->change();
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->unique('code');
            $table->unique('session_id');
            foreach (['subtotal_minor', 'discount_minor', 'tax_minor', 'delivery_minor', 'total_minor'] as $column) {
                $table->unsignedBigInteger($column)->default(0);
            }
            $table->char('currency', 3)->default('AUD');
            $table->string('rule_version');
            $table->json('contact_snapshot');
            $table->json('delivery_snapshot');
            $table->string('guest_token_hash', 64)->nullable()->unique();
            $table->text('guest_token_encrypted')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
        Schema::table('order_products', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('facts');
            $table->json('selected_options');
            foreach (['original_unit_minor', 'net_unit_minor', 'tax_unit_minor', 'payable_unit_minor'] as $column) {
                $table->unsignedBigInteger($column);
            }
            $table->text('image_path')->nullable();
            $table->string('image_disk')->nullable();
            $table->text('image_alt')->nullable();
        });
        Schema::create('checkout_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('key')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('owner_hash', 64);
            $table->string('request_hash', 64);
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->json('provider_payload');
            $table->string('idempotency_key')->unique();
            $table->timestamp('remote_expires_at');
            $table->timestamp('create_cutoff_at');
            $table->string('state')->default('ready')->index();
            $table->string('session_id')->nullable()->unique();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->boolean('ambiguous')->default(false);
            $table->text('last_error')->nullable();
            $table->json('reconciliation_evidence')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_attempt_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->enum('state', ['held', 'consumed', 'released'])->default('held');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['checkout_attempt_id', 'product_id']);
            $table->index(['product_id', 'state']);
        });
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_event_id')->unique();
            $table->string('type');
            $table->string('session_id')->nullable()->index();
            $table->foreignId('checkout_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->enum('state', ['pending', 'processing', 'processed', 'failed', 'mismatch'])->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('recipient');
            $table->json('payload');
            $table->enum('state', ['pending', 'sending', 'sent', 'failed'])->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'type']);
        });
        // MySQL 8 enforces these constraints even for writes bypassing model validation.
        foreach ([
            'products' => ['products_discount_bounds' => 'discount_bps <= 10000', 'products_currency_aud' => "currency = 'AUD'", 'products_facts_array' => 'facts IS NULL OR JSON_TYPE(facts) = \'ARRAY\''],
            'product_options' => ['product_options_values_array' => "JSON_TYPE(`values`) = 'ARRAY' AND JSON_LENGTH(`values`) > 0"],
            'order_products' => ['order_products_quantity_positive' => 'quantity > 0', 'order_products_facts_array' => "JSON_TYPE(facts) = 'ARRAY'"],
            'stock_allocations' => ['stock_allocations_quantity_positive' => 'quantity > 0'],
            'orders' => ['orders_currency_aud' => "currency = 'AUD'"],
        ] as $table => $constraints) {
            foreach ($constraints as $name => $expression) {
                DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `$name` CHECK ($expression)");
            }
        }
    }

    public function down(): void
    {
        // Destructive rollback would erase immutable purchase evidence. Restore a verified backup.
        throw new RuntimeException('Commerce schema rollback requires a verified backup restoration; automatic data loss is refused.');
    }
};
