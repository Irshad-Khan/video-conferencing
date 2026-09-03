<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_participants', function (Blueprint $table) {
            // Set once, the first time the host admits this participant past the
            // waiting room — distinct from joined_at/left_at, which flip on every
            // reconnect. Lets a reconnecting participant skip the waiting room a
            // second time once they've already been vetted (see MeetingJoinController).
            $table->timestamp('admitted_at')->nullable()->after('left_at');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_participants', function (Blueprint $table) {
            $table->dropColumn('admitted_at');
        });
    }
};
