<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user');
            $table->boolean('active')->default(true);
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('source', 30);
            $t->string('external_id');
            $t->string('document_number')->index();
            $t->string('country')->nullable();
            $t->string('windows_version')->nullable();
            $t->string('source_status')->nullable();
            $t->dateTime('source_created_at', 6)->nullable();
            $t->dateTime('source_updated_at', 6)->nullable();
            $t->json('source_data')->nullable();
            $t->timestamps(6);
            $t->unique(['source', 'external_id']);
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->string('external_line_id');
            $t->string('product_id')->nullable();
            $t->text('description')->nullable();
            $t->unsignedInteger('quantity')->default(1);
            $t->timestamps(6);
            $t->unique(['order_id', 'external_line_id']);
        });
        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->string('serial_original');
            $t->string('serial_lookup')->collation(Schema::getConnection()->getDriverName() === 'sqlite' ? 'BINARY' : 'utf8mb4_bin')->unique();
            $t->string('manufacturer')->nullable();
            $t->string('model')->nullable();
            $t->text('description')->nullable();
            $t->string('item_number')->nullable();
            $t->timestamps(6);
        });
        Schema::create('device_identifiers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained()->restrictOnDelete();
            $t->string('type', 40);
            $t->string('value');
            $t->unique(['device_id', 'type', 'value']);
        });
        Schema::create('device_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('device_id')->constrained()->restrictOnDelete();
            $t->string('source_assignment_key')->unique();
            $t->dateTime('source_assigned_at', 6);
            $t->dateTime('first_seen_at', 6);
            $t->dateTime('withdrawn_at', 6)->nullable();
            $t->timestamps(6);
            $t->unique(['order_id', 'device_id']);
            $t->index(['device_id', 'source_assigned_at']);
        });
        Schema::create('images', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('system', 40);
            $t->string('edition', 20);
            $t->string('language', 10);
            $t->boolean('active')->default(true);
            $t->timestamps(6);
        });
        Schema::create('installation_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained('device_assignments')->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->string('source_type', 30);
            $t->string('preparation_status', 20)->default('pending')->index();
            $t->string('lifecycle_status', 20)->default('open')->index();
            $t->text('blocked_reason')->nullable();
            $t->foreignId('image_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('image_name_snapshot')->nullable();
            $t->boolean('use_dpk')->default(false);
            $t->string('rule_version')->default('legacy-v1');
            $t->string('configuration_hash', 64);
            $t->dateTime('first_valid_until', 6)->index();
            $t->dateTime('first_success_at', 6)->nullable();
            $t->dateTime('last_success_at', 6)->nullable();
            $t->dateTime('retry_until', 6)->nullable()->index();
            $t->dateTime('cancelled_at', 6)->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps(6);
            $t->unique(['assignment_id', 'revision']);
        });
        Schema::create('installation_attempts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('device_id')->constrained()->restrictOnDelete();
            $t->foreignId('job_id')->nullable()->constrained('installation_jobs')->restrictOnDelete();
            $t->string('mode', 20);
            $t->string('request_key', 100)->unique();
            $t->string('state', 20)->default('started')->index();
            $t->dateTime('started_at', 6);
            $t->dateTime('finished_at', 6)->nullable();
            $t->json('config_snapshot');
            $t->string('failure_code')->nullable();
            $t->timestamps(6);
        });
        Schema::create('device_execution_locks', function (Blueprint $t) {
            $t->foreignId('device_id')->primary()->constrained()->restrictOnDelete();
            $t->uuid('attempt_id')->unique();
            $t->foreign('attempt_id')->references('id')->on('installation_attempts')->restrictOnDelete();
            $t->dateTime('acquired_at', 6);
            $t->dateTime('timeout_at', 6);
        });
        foreach (['image_rules', 'license_rules'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name) {
                $t->id();
                $t->string('source_type', 30);
                $t->string('country')->nullable();
                $t->string('windows_version');
                $t->unsignedInteger('priority')->default(100);
                $t->string('version');
                $t->json('conditions')->nullable();
                if ($name === 'image_rules') {
                    $t->foreignId('image_id')->constrained()->restrictOnDelete();
                } else {
                    $t->boolean('use_dpk');
                    $t->boolean('requires_coa')->default(false);
                }
                $t->timestamps(6);
            });
        }
        Schema::create('device_license_data', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_id')->constrained()->restrictOnDelete();
            $t->string('source', 30);
            $t->string('kind', 30);
            $t->text('encrypted_value');
            $t->dateTime('checked_at', 6);
            $t->unique(['device_id', 'source', 'kind']);
        });
        Schema::create('product_group_mappings', function (Blueprint $t) {
            $t->id();
            $t->string('external_product_id')->unique();
            $t->string('windows_group');
            $t->timestamps(6);
        });
        Schema::create('sync_runs', function (Blueprint $t) {
            $t->id();
            $t->string('source', 30)->index();
            $t->string('state', 20);
            $t->dateTime('started_at', 6);
            $t->dateTime('finished_at', 6)->nullable();
            $t->json('counts')->nullable();
            $t->text('safe_error')->nullable();
        });
        Schema::create('sync_cursors', function (Blueprint $t) {
            $t->id();
            $t->string('source', 30);
            $t->string('scope');
            $t->json('cursor')->nullable();
            $t->dateTime('last_success_at', 6)->nullable();
            $t->unique(['source', 'scope']);
        });
        Schema::create('source_snapshots', function (Blueprint $t) {
            $t->id();
            $t->string('entity_type', 40);
            $t->unsignedBigInteger('entity_id');
            $t->string('source', 30);
            $t->unsignedInteger('schema_version')->default(1);
            $t->json('safe_payload');
            $t->dateTime('fetched_at', 6);
            $t->index(['entity_type', 'entity_id']);
        });
        Schema::create('integration_outbox', function (Blueprint $t) {
            $t->id();
            $t->string('operation_key')->unique();
            $t->string('destination', 30);
            $t->string('type');
            $t->json('safe_payload');
            $t->string('state', 20)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->dateTime('next_attempt_at', 6)->nullable();
            $t->string('external_result_id')->nullable();
            $t->timestamps(6);
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->string('actor_type', 30);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('entity_type', 40);
            $t->string('entity_id');
            $t->string('event');
            $t->json('redacted_changes');
            $t->dateTime('created_at', 6);
            $t->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'integration_outbox', 'source_snapshots', 'sync_cursors', 'sync_runs', 'product_group_mappings', 'device_license_data', 'license_rules', 'image_rules', 'device_execution_locks', 'installation_attempts', 'installation_jobs', 'images', 'device_assignments', 'device_identifiers', 'devices', 'order_items', 'orders'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['role', 'active']);
        });
    }
};
