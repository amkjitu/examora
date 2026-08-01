<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lessons are generated once per (topic, exam, locale, prompt version) and
        // shared across every student. Personalisation happens at selection and
        // revision time, not at generation time — otherwise LLM cost scales with
        // users instead of with content.
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('locale', 8)->default('en');
            $table->unsignedSmallInteger('version')->default(1);
            $table->unsignedSmallInteger('estimated_minutes')->default(5);
            $table->json('key_points')->nullable();
            $table->string('status', 16)->default('published');   // draft | published | archived
            $table->string('generated_by')->nullable();           // provider:model
            $table->string('prompt_hash', 64)->nullable();
            $table->json('source_refs')->nullable();              // knowledge_chunk ids used
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['topic_id', 'exam_id', 'locale', 'version']);
            $table->index(['exam_id', 'status']);
        });

        Schema::create('lesson_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('type', 24)->default('fact');   // fact | mnemonic | timeline | comparison | pitfall
            $table->string('title')->nullable();
            $table->text('body');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['lesson_id', 'position']);
        });

        // Practice bank: past questions promoted in, plus AI-generated variants.
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('past_question_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origin', 16)->default('past');   // past | ai_generated | curated
            $table->text('stem');
            $table->json('options');
            $table->unsignedTinyInteger('correct_index');
            $table->text('explanation')->nullable();
            $table->string('difficulty', 8)->default('medium');
            $table->unsignedInteger('times_served')->default(0);
            $table->unsignedInteger('times_correct')->default(0);
            $table->decimal('p_value', 5, 4)->nullable();    // observed proportion correct
            $table->boolean('is_active')->default(true);
            $table->string('fingerprint', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['exam_id', 'topic_id', 'is_active']);
            $table->index('fingerprint');
        });

        Schema::create('quiz_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16)->default('practice'); // lesson_check | practice | revision | mock
            $table->json('question_ids');
            $table->unsignedSmallInteger('total')->default(0);
            $table->unsignedSmallInteger('correct')->default(0);
            $table->decimal('score', 6, 2)->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'exam_id', 'type']);
        });

        Schema::create('quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('selected_index')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('time_ms')->nullable();
            $table->unsignedTinyInteger('confidence')->nullable(); // 1..4, drives SM-2 quality
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['quiz_session_id', 'question_id']);
            $table->index(['user_id', 'question_id']);
        });

        // SM-2 scheduling state, one row per (user, question).
        Schema::create('memory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->string('state', 16)->default('new');   // new | learning | review | relearning
            $table->decimal('ease_factor', 4, 2)->default(2.50);
            $table->unsignedSmallInteger('interval_days')->default(0);
            $table->unsignedSmallInteger('repetitions')->default(0);
            $table->unsignedSmallInteger('lapses')->default(0);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'question_id']);
            $table->index(['user_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_items');
        Schema::dropIfExists('quiz_answers');
        Schema::dropIfExists('quiz_sessions');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('lesson_cards');
        Schema::dropIfExists('lessons');
    }
};
