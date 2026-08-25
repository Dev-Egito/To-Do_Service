<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('column_id')->default('backlog')->after('position');
            $table->integer('priority')->default(0)->after('column_id');
            $table->string('assignee')->nullable()->after('priority');
            $table->json('tags')->nullable()->after('assignee');
            $table->string('source_id')->nullable()->after('tags');
            $table->unique('source_id');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropUnique(['source_id']);
            $table->dropColumn(['column_id', 'priority', 'assignee', 'tags', 'source_id']);
        });
    }
};
