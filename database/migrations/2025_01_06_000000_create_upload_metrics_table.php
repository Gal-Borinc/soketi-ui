<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('upload_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('upload_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('video_id')->nullable()->index();
            $table->string('event_type'); // prepared, completed, failed
            $table->string('status'); // ready, completed, failed
            
            // File metadata
            $table->bigInteger('file_size')->nullable();
            $table->string('file_name')->nullable();
            $table->integer('chunk_count')->nullable();
            $table->integer('chunk_size')->nullable();
            $table->integer('chunks_completed')->nullable();
            
            // Progress tracking
            $table->decimal('percentage_completed', 5, 2)->default(0);
            $table->bigInteger('bytes_uploaded')->default(0);
            
            // Timing metrics
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->integer('upload_duration')->nullable(); // seconds
            $table->integer('processing_time')->nullable(); // milliseconds
            $table->integer('estimated_duration')->nullable(); // seconds
            
            // Error tracking
            $table->string('error_message')->nullable();
            $table->string('error_code')->nullable();
            $table->string('error_stage')->nullable(); // preparation, upload, completion
            $table->boolean('retryable')->default(false);
            $table->integer('attempt_number')->default(1);
            
            // Performance metrics
            $table->decimal('upload_speed', 12, 2)->nullable(); // bytes per second
            $table->integer('connection_quality')->nullable(); // 1-5 scale
            
            $table->timestamps();
            
            // Composite indexes for efficient queries
            $table->index(['user_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['upload_id', 'event_type']);
        });
        
        // Aggregated metrics table for performance
        Schema::create('upload_metrics_hourly', function (Blueprint $table) {
            $table->id();
            $table->timestamp('hour')->index();
            $table->integer('total_uploads')->default(0);
            $table->integer('completed_uploads')->default(0);
            $table->integer('failed_uploads')->default(0);
            $table->bigInteger('total_bytes')->default(0);
            $table->decimal('avg_duration', 10, 2)->nullable();
            $table->decimal('avg_speed', 12, 2)->nullable();
            $table->decimal('completion_rate', 5, 2)->default(0);
            $table->json('duration_distribution')->nullable();
            $table->json('size_distribution')->nullable();
            $table->json('error_distribution')->nullable();
            $table->timestamps();
            
            $table->unique('hour');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('upload_metrics_hourly');
        Schema::dropIfExists('upload_metrics');
    }
};