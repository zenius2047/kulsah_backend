<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anchor_kulsan_memberships', function (Blueprint $table): void {
            $table->string('referral_code', 16)->nullable()->unique();
        });

        Schema::create('anchor_referral_attributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('anchor_membership_id')->constrained('anchor_kulsan_memberships')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['anchor_membership_id', 'created_at']);
        });

        DB::table('anchor_kulsan_memberships')->whereNull('referral_code')->orderBy('id')->get()->each(function (object $membership): void {
            do {
                $code = 'AK'.Str::upper(Str::random(10));
            } while (DB::table('anchor_kulsan_memberships')->where('referral_code', $code)->exists());

            DB::table('anchor_kulsan_memberships')->where('id', $membership->id)->update(['referral_code' => $code]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anchor_referral_attributions');
        Schema::table('anchor_kulsan_memberships', function (Blueprint $table): void {
            $table->dropUnique(['referral_code']);
            $table->dropColumn('referral_code');
        });
    }
};
