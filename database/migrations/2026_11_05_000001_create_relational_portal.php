<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->string('code')->primary();
            $t->string('name');
        });
        DB::table('roles')->insert([
            ['code' => 'admin', 'name' => 'Administrator'],
            ['code' => 'manager', 'name' => 'Manager'],
            ['code' => 'client', 'name' => 'Client'],
        ]);
        Schema::table('users', fn (Blueprint $t) => $t->foreign('role')->references('code')->on('roles')->restrictOnDelete());
        Schema::create('client_profiles', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('client_manager_assignments', function (Blueprint $t) {
            $t->foreignId('client_id')->constrained('client_profiles', 'user_id')->restrictOnDelete();
            $t->foreignId('manager_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->primary(['client_id', 'manager_id']);
            $t->index(['manager_id', 'client_id']);
        });
        // Stable identifiers for cross-domain dashboard rows and existing URLs.
        // This index contains no business payload or JSON column.
        Schema::create('portal_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $t->enum('kind', ['holding', 'document', 'deal', 'request', 'profile', 'onboarding_ack', 'direct_signature_demo']);
            $t->string('status', 40)->nullable();
            $t->timestamps();
            $t->unique(['id', 'client_id']);
            $t->index(['client_id', 'kind', 'status']);
            $t->index(['kind', 'status']);
        });
        Schema::create('holdings', function (Blueprint $t) {
            $this->entry($t);
            $t->enum('type', ['whisky', 'wine']);
            $t->string('name', 160);
            $t->string('reference', 80)->index();
            $t->string('producer', 100);
            $t->unsignedInteger('quantity');
            $t->string('storage', 150);
            $t->date('acquired');
            $t->text('notes')->nullable();
        });
        Schema::create('whisky_holdings', function (Blueprint $t) {
            $t->foreignId('holding_id')->primary()->constrained('holdings')->restrictOnDelete();
            $t->date('fill_date')->nullable();
            $t->string('cask_type', 100)->nullable();
            $t->decimal('abv', 8, 4)->nullable();
            $t->decimal('litres', 14, 4)->nullable();
        });
        Schema::create('wine_holdings', function (Blueprint $t) {
            $t->foreignId('holding_id')->primary()->constrained('holdings')->restrictOnDelete();
            $t->unsignedSmallInteger('vintage')->nullable();
            $t->string('format', 80)->nullable();
            $t->decimal('bottle_ml', 12, 4)->nullable();
            $t->unsignedSmallInteger('bottles_per_case')->nullable();
        });
        Schema::create('document_types', function (Blueprint $t) {
            $t->string('code', 40)->primary();
            $t->boolean('staff_only')->default(false);
        });
        foreach (['Ownership', 'Storage', 'Regauge', 'Evaluation', 'Onboarding', 'KYC', 'Agreement'] as $code) {
            DB::table('document_types')->insert(['code' => $code, 'staff_only' => $code === 'KYC']);
        }
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained('client_profiles', 'user_id')->restrictOnDelete();
            $t->string('series', 100);
            $t->string('category', 40);
            $t->foreign('category')->references('code')->on('document_types')->restrictOnDelete();
            $t->enum('visibility', ['client', 'staff']);
            $t->unique(['client_id', 'series']);
            $t->unique(['id', 'client_id']);
            $t->index(['category', 'visibility']);
        });
        Schema::create('deals', function (Blueprint $t) {
            $this->entry($t);
            $t->string('client_name');
            $t->string('client_email');
            $t->text('client_address');
            $t->string('created_by')->nullable();
            $t->foreignId('created_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('template_version', 80);
            $t->char('currency', 3)->default('GBP');
            $t->date('balance_due');
            $t->bigInteger('stock_minor');
            $t->bigInteger('total_minor');
            $t->bigInteger('balance_minor');
            $t->text('review_note')->nullable();
            $t->dateTime('acknowledged_at')->nullable();
            $t->json('agreement_snapshot');
        });
        Schema::create('deal_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deal_id')->constrained('deals')->restrictOnDelete();
            $t->unsignedInteger('position')->default(1);
            $t->enum('type', ['whisky', 'wine']);
            $t->string('name', 160);
            $t->string('reference', 80);
            $t->string('producer', 100);
            $t->string('storage', 150);
            $t->unsignedInteger('quantity');
            $t->bigInteger('unit_price_minor');
            $t->string('cask_type', 100)->nullable();
            foreach (['cask_size', 'ola', 'rla', 'abv'] as $field) {
                $t->decimal($field, 14, 4)->nullable();
            }
            $t->date('measurement_date')->nullable();
            $t->unsignedSmallInteger('vintage')->nullable();
            $t->unsignedInteger('bottle_ml')->nullable();
            $t->unique(['deal_id', 'position']);
        });
        Schema::create('deal_fees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deal_id')->constrained('deals')->restrictOnDelete();
            $t->enum('type', ['markup', 'management', 'brokerage']);
            $t->decimal('rate', 8, 4);
            $t->string('basis', 40)->default('stock_subtotal');
            $t->bigInteger('amount_minor');
            $t->unique(['deal_id', 'type']);
        });
        Schema::create('external_deposits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deal_id')->constrained('deals')->restrictOnDelete();
            $t->unsignedInteger('position')->default(1);
            $t->bigInteger('amount_minor');
            $t->string('reference', 100);
            $t->date('recorded_date');
            $t->unique(['deal_id', 'position']);
        });
        Schema::create('document_versions', function (Blueprint $t) {
            $this->entry($t);
            $t->unsignedBigInteger('document_id');
            $this->ownedReference($t, 'document_id', 'documents');
            $t->string('title', 150);
            $t->date('document_date');
            $t->string('path')->nullable();
            $t->string('original_name')->nullable();
            $t->unsignedBigInteger('holding_id')->nullable();
            $this->ownedReference($t, 'holding_id', 'holdings');
            $t->unsignedBigInteger('deal_id')->nullable();
            $this->ownedReference($t, 'deal_id', 'deals');
            $t->unsignedBigInteger('replaces_id')->nullable();
            $this->ownedReference($t, 'replaces_id', 'document_versions');
            $t->string('uploaded_by')->nullable();
            $t->foreignId('uploaded_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('notes')->nullable();
            $t->json('signing_hotspots')->nullable();
            $t->string('sha256', 64)->nullable();
        });
        Schema::create('document_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_version_id')->constrained('document_versions')->restrictOnDelete();
            $t->enum('decision', ['approved', 'rejected']);
            $t->foreignId('reviewed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('reviewed_by');
            $t->dateTime('reviewed_at');
            $t->text('review_note')->nullable();
            $t->index(['document_version_id', 'id']);
        });
        Schema::table('deals', function (Blueprint $t) {
            foreach (['document_id', 'signed_document_id'] as $field) {
                $t->unsignedBigInteger($field)->nullable();
                $this->ownedReference($t, $field, 'document_versions');
            }
        });
        Schema::create('signing_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('deal_id')->constrained('deals')->restrictOnDelete();
            $t->foreignId('document_version_id')->constrained('document_versions')->restrictOnDelete();
            $t->string('provider', 40)->default('docusign_sandbox');
            $t->string('envelope_id', 100);
            $t->string('status', 40);
            $t->timestamps();
            $t->unique(['provider', 'envelope_id']);
            $t->unique(['deal_id', 'document_version_id']);
        });
        Schema::create('service_requests', function (Blueprint $t) {
            $this->entry($t);
            $t->unsignedBigInteger('holding_id');
            $this->ownedReference($t, 'holding_id', 'holdings');
            $t->enum('type', ['Evaluation', 'Regauge', 'Inspection', 'Photographs']);
            $t->string('holding_name');
            $t->text('notes');
            $t->text('staff_note')->nullable();
        });
        Schema::create('request_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('request_id')->constrained('service_requests')->restrictOnDelete();
            $t->unsignedInteger('position');
            $t->string('status', 40);
            $t->text('staff_note')->nullable();
            $t->string('by');
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('at');
            $t->unique(['request_id', 'position']);
        });
        Schema::create('profile_changes', function (Blueprint $t) {
            $this->entry($t);
            $t->string('name');
            $t->text('notes');
            $t->string('uploaded_by');
            $t->foreignId('uploaded_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('reviewed_by')->nullable();
            $t->foreignId('reviewed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
        });
        Schema::create('document_acknowledgements', function (Blueprint $t) {
            $this->entry($t);
            $t->unsignedBigInteger('document_id');
            $this->ownedReference($t, 'document_id', 'document_versions');
            $t->dateTime('acknowledged_at');
            $t->unique(['client_id', 'document_id']);
        });
        Schema::create('direct_signatures', function (Blueprint $t) {
            $this->entry($t, false);
            $t->uuid('token')->unique();
            $t->string('name');
            $t->enum('method', ['type', 'draw', 'upload']);
            $t->dateTime('signed_at');
            $t->string('path');
            $t->string('source_sha256', 64);
            $t->string('signed_sha256', 64);
            $t->text('consent');
            $t->json('signing_hotspots')->nullable();
            $t->json('placement')->nullable();
            $t->string('variant', 40)->nullable();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('entry_id')->nullable()->constrained('portal_entries')->restrictOnDelete();
            $t->foreignId('subject_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 80);
            $t->string('previous_status', 40)->nullable();
            $t->string('new_status', 40)->nullable();
            $t->dateTime('occurred_at');
            $t->index(['entry_id', 'occurred_at']);
        });
    }

    private function entry(Blueprint $t, bool $client = true): void
    {
        $t->unsignedBigInteger('id')->primary();
        $t->unsignedBigInteger('client_id');
        $t->unique(['id', 'client_id']);
        $t->foreign(['id', 'client_id'])->references(['id', 'client_id'])->on('portal_entries')->restrictOnDelete();
        if ($client) {
            $t->foreign('client_id')->references('user_id')->on('client_profiles')->restrictOnDelete();
        }
    }

    private function ownedReference(Blueprint $t, string $field, string $table): void
    {
        $t->foreign([$field, 'client_id'])->references(['id', 'client_id'])->on($table)->restrictOnDelete();
    }

    public function down(): void
    {
        throw new RuntimeException('Relational portal rollback requires a verified backup restore; automatic dropping could lose client history.');
    }
};
