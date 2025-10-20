<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('merchant_sites')) {
            return;
        }

        $merchantForeignKeys = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'merchant_sites'
                AND COLUMN_NAME = 'merchant_id'
                AND REFERENCED_TABLE_NAME IS NOT NULL"
        );

        Schema::table('merchant_sites', function (Blueprint $table) use ($merchantForeignKeys) {
            if (Schema::hasColumn('merchant_sites', 'merchant_id')) {
                foreach ($merchantForeignKeys as $foreignKey) {
                    $table->dropForeign($foreignKey->CONSTRAINT_NAME);
                }

                $table->dropUnique('merchant_sites_merchant_id_site_url_unique');
            }

            if (!Schema::hasColumn('merchant_sites', 'user_id')) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->after('id');
            }
        });

        Schema::table('merchant_sites', function (Blueprint $table) {
            if (Schema::hasColumn('merchant_sites', 'user_id')) {
                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
            }
        });

        if (Schema::hasColumns('merchant_sites', ['merchant_id', 'user_id'])) {
            DB::statement("
                UPDATE merchant_sites ms
                INNER JOIN merchants m ON ms.merchant_id = m.id
                SET ms.user_id = m.user_id
                WHERE ms.merchant_id IS NOT NULL AND ms.user_id IS NULL
            ");
        }

        Schema::table('merchant_sites', function (Blueprint $table) {
            if (Schema::hasColumn('merchant_sites', 'merchant_id')) {
                $table->dropColumn('merchant_id');
            }

            if (Schema::hasColumn('merchant_sites', 'user_id')) {
                $table->unique(['user_id', 'site_url']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('merchant_sites')) {
            return;
        }

        $userForeignKeys = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'merchant_sites'
                AND COLUMN_NAME = 'user_id'
                AND REFERENCED_TABLE_NAME IS NOT NULL"
        );

        Schema::table('merchant_sites', function (Blueprint $table) {
            $table->dropUnique('merchant_sites_user_id_site_url_unique');
        });

        Schema::table('merchant_sites', function (Blueprint $table) use ($userForeignKeys) {
            foreach ($userForeignKeys as $foreignKey) {
                $table->dropForeign($foreignKey->CONSTRAINT_NAME);
            }

            $table->foreignId('merchant_id')
                ->nullable()
                ->after('id')
                ->constrained('merchants')
                ->cascadeOnDelete();
        });

        if (Schema::hasColumns('merchant_sites', ['user_id', 'merchant_id'])) {
            DB::statement("
                UPDATE merchant_sites ms
                INNER JOIN merchants m ON ms.user_id = m.user_id
                SET ms.merchant_id = m.id
                WHERE ms.user_id IS NOT NULL AND ms.merchant_id IS NULL
            ");
        }

        Schema::table('merchant_sites', function (Blueprint $table) {
            $table->dropColumn('user_id');
            $table->unique(['merchant_id', 'site_url']);
        });
    }
};
