<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Webimpian\Sso\Sso;

return new class extends Migration
{
    public function up(): void
    {
        $users = $this->users();

        Schema::connection($users->getConnectionName())->table($users->getTable(), function (Blueprint $table) {
            $table->string('sso_sub')->nullable()->unique();
            $table->boolean('sso_pending')->default(false);
        });
    }

    public function down(): void
    {
        $users = $this->users();

        Schema::connection($users->getConnectionName())->table($users->getTable(), function (Blueprint $table) {
            $table->dropUnique(['sso_sub']);
            $table->dropColumn(['sso_sub', 'sso_pending']);
        });
    }

    private function users(): Model
    {
        $model = Sso::userModel();

        return new $model;
    }
};
