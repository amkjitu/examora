<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->string('target_session')->nullable();     // "BCS 48"
            $table->date('target_date')->nullable();
            $table->unsignedSmallInteger('daily_minutes_goal')->default(20);
            $table->boolean('is_active')->default(true);
            $table->timestamp('started_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'exam_id']);
        });

        Schema::create('topic_mastery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('correct')->default(0);
            $table->decimal('accuracy', 5, 4)->default(0);
            $table->decimal('mastery', 5, 4)->default(0);       // 0..1, blends accuracy + coverage + retention
            $table->decimal('coverage', 5, 4)->default(0);      // share of the topic's questions seen
            $table->boolean('lesson_completed')->default(false);
            $table->timestamp('lesson_completed_at')->nullable();
            $table->timestamp('last_studied_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'topic_id']);
            $table->index(['user_id', 'exam_id', 'mastery']);
        });

        Schema::create('study_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->date('plan_date');
            $table->unsignedSmallInteger('target_minutes')->default(20);
            $table->unsignedSmallInteger('completed_minutes')->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'exam_id', 'plan_date']);
        });

        Schema::create('study_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);                        // lesson | practice | revision | mock
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedSmallInteger('estimated_minutes')->default(5);
            $table->string('status', 16)->default('pending');  // pending | completed | skipped
            $table->string('reason')->nullable();              // why the planner chose this
            $table->json('payload')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['study_plan_id', 'position']);
        });

        Schema::create('learner_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('xp')->default(0);
            $table->unsignedSmallInteger('level')->default(1);
            $table->unsignedInteger('coins')->default(0);
            $table->unsignedSmallInteger('streak_days')->default(0);
            $table->unsignedSmallInteger('longest_streak')->default(0);
            $table->date('last_active_on')->nullable();
            $table->decimal('accuracy', 5, 4)->default(0);
            $table->decimal('readiness', 5, 2)->default(0);     // 0..100 estimated exam readiness
            $table->unsignedSmallInteger('estimated_score')->nullable();
            $table->timestamp('readiness_computed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'exam_id']);
        });

        Schema::create('learning_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);       // lesson_completed | quiz_completed | revision_done | streak_kept
            $table->integer('xp')->default(0);
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
        });

        // Cost + latency accounting for every LLM call. Without this you cannot
        // price the subscription tiers honestly.
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('task', 48);            // lesson.generate | question.generate | tutor.answer | embed
            $table->string('provider', 32);
            $table->string('model', 64);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('status', 16)->default('ok');   // ok | error | fallback
            $table->boolean('cache_hit')->default(false);
            $table->text('error')->nullable();
            $table->string('prompt_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['task', 'created_at']);
            $table->index('prompt_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
        Schema::dropIfExists('learning_events');
        Schema::dropIfExists('learner_profiles');
        Schema::dropIfExists('study_plan_items');
        Schema::dropIfExists('study_plans');
        Schema::dropIfExists('topic_mastery');
        Schema::dropIfExists('exam_enrollments');
    }
};
