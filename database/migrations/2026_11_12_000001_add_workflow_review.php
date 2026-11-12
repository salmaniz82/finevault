<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_profiles', function (Blueprint $t) {
            $t->string('phone', 60)->nullable();
            $t->text('address')->nullable();
            $t->string('onboarding_status', 30)->default('draft');
            $t->dateTime('submitted_at')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->foreignId('reviewed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_note')->nullable();
        });
        Schema::table('holdings', function (Blueprint $t) {
            $t->unsignedBigInteger('agreement_id')->nullable();
            $t->foreign(['agreement_id', 'client_id'])->references(['id', 'client_id'])->on('deals')->restrictOnDelete();
            $t->dateTime('released_at')->nullable();
        });
        Schema::table('document_versions', function (Blueprint $t) {
            $t->string('onboarding_purpose', 30)->nullable();
        });
        Schema::create('holding_updates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('holding_id');
            $t->foreign(['holding_id', 'client_id'])->references(['id', 'client_id'])->on('holdings')->restrictOnDelete();
            $t->unsignedBigInteger('request_id')->nullable();
            $t->foreign(['request_id', 'client_id'])->references(['id', 'client_id'])->on('service_requests')->restrictOnDelete();
            $t->string('title', 150);
            $t->text('notes');
            $t->string('status', 30)->default('pending review');
            $t->foreignId('submitted_by_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_note')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            // Immutable physical holding snapshot; searchable fields remain in typed holding tables.
            $t->json('snapshot');
            $t->timestamps();
        });
        Schema::create('holding_update_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('update_id')->constrained('holding_updates')->restrictOnDelete();
            $t->string('path');
            $t->string('original_name');
            $t->string('sha256', 64);
        });
        Schema::create('holding_publications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('holding_id')->constrained('holdings')->restrictOnDelete();
            $t->foreignId('update_id')->constrained('holding_updates')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->text('reason')->nullable();
            $t->dateTime('published_at');
        });
        Schema::create('portal_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('message');
            $t->string('url');
            $t->dateTime('read_at')->nullable();
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a verified backup to roll back workflow history.');
    }
};
