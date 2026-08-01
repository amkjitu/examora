<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isPostgres = DB::connection()->getDriverName() === 'pgsql';

        if ($isPostgres) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('knowledge_chunks', function (Blueprint $table) use ($isPostgres) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 32);            // past_question | syllabus | book | note | user_upload
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('title')->nullable();
            $table->longText('content');
            $table->unsignedInteger('token_count')->default(0);
            $table->string('embedding_model')->nullable();

            // pgvector when available, portable JSON fallback otherwise (sqlite tests,
            // CI without a database service). The Retriever reads whichever exists.
            if ($isPostgres) {
                $table->addColumn('vector', 'embedding', ['dimensions' => 1536])->nullable();
            } else {
                $table->json('embedding')->nullable();
            }

            $table->string('checksum', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['exam_id', 'topic_id']);
            $table->index(['source_type', 'source_id']);
            $table->index('checksum');
        });

        if ($isPostgres) {
            // IVFFlat needs data before it helps; HNSW works from empty and is the
            // better default for a corpus that grows continuously.
            DB::statement('CREATE INDEX knowledge_chunks_embedding_idx ON knowledge_chunks USING hnsw (embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
