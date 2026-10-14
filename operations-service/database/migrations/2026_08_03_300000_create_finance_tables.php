<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $table) {
                $table->id();
                $table->string('expense_ref');
                $table->string('category');
                $table->string('description');
                $table->decimal('amount', 12, 2);
                $table->date('date_paid');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('incomes')) {
            Schema::create('incomes', function (Blueprint $table) {
                $table->id();
                $table->string('income_ref');
                $table->string('source');
                $table->string('description');
                $table->decimal('amount', 12, 2);
                $table->date('date_received');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('bank_accounts')) {
            Schema::create('bank_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('bank_name');
                $table->string('account_name');
                $table->string('account_number');
                $table->string('currency')->default('USD');
                $table->string('status')->default('connected');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('incomes');
        Schema::dropIfExists('expenses');
    }
};
