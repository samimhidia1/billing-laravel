<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the table backing App\Models\SiteSettings. Without it the
     * Filament SiteSettingsResource 500s on open ("Table site_settings doesn't exist").
     * Columns match the model's #[Fillable] attribute.
     */
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('currency')->nullable();
            $table->string('default_language')->nullable();
            $table->string('address')->nullable();
            $table->string('country')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_01')->nullable();
            $table->string('phone_02')->nullable();
            $table->string('phone_03')->nullable();
            $table->string('facebook')->nullable();
            $table->string('twitter')->nullable();
            $table->string('github')->nullable();
            $table->string('youtube')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
