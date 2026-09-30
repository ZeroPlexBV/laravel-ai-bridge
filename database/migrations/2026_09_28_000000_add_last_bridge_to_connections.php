<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a bridge last said about itself at `hello`: its release
 * (`bridge_version`), its attachment caps, and which optional frames it
 * understands.
 *
 * Cached beside `last_posture` for the same reason: the live registry is in
 * the serve process's memory, and an application that shows "this machine is
 * behind, run this to update it" has to be able to say so while the machine
 * is switched off too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_bridge_connections', function (Blueprint $table) {
            $table->json('last_bridge')->nullable()->after('last_posture');
        });
    }

    public function down(): void
    {
        Schema::table('ai_bridge_connections', function (Blueprint $table) {
            $table->dropColumn('last_bridge');
        });
    }
};
