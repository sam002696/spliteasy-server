<?php

use Illuminate\Database\Migrations\Migration;
//use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
//        DB::table('activity_logs')
//            ->where('type', 'balance.reminder.sent')
//            ->orderBy('id')
//            ->each(function ($activity): void {
//                $metadata = json_decode($activity->metadata ?? '{}', true);
//
//                DB::table('activity_logs')
//                    ->where('id', $activity->id)
//                    ->update([
//                        'title' => sprintf(
//                            '%s reminded you to settle %s %s',
//                            $metadata['reminder_from_name'] ?? 'Someone',
//                            $metadata['currency'] ?? '',
//                            number_format((float) ($metadata['amount'] ?? 0), 2)
//                        ),
//                    ]);
//            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
