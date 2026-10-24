<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The mistake type belongs to the answer, so it is deleted with it and the retention period covers it.
     * mistake_confidence is the AI's certainty (0–100) and only filled for AI sources; mistake_source says who
     * classified (demo / claude / clef / teacher), so a teacher's correction stays recognisable.
     */
    public function up(): void
    {
        Schema::table('answers', function (Blueprint $table) {
            $table->string('mistake_type')->nullable()->after('feedback');
            $table->unsignedTinyInteger('mistake_confidence')->nullable()->after('mistake_type');
            $table->string('mistake_source')->nullable()->after('mistake_confidence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('answers', function (Blueprint $table) {
            $table->dropColumn(['mistake_type', 'mistake_confidence', 'mistake_source']);
        });
    }
};
