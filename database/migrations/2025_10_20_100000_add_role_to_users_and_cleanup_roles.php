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
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('merchant')->after('email_verified_at');
        });

        $rolePriority = [
            'admin' => 3,
            'agent' => 2,
            'merchant' => 1,
            'seller' => 1,
            'user' => 0,
            'viewer' => 0,
        ];

        $roleMappings = [
            'admin' => 'admin',
            'agent' => 'agent',
            'merchant' => 'merchant',
            'seller' => 'merchant',
            'user' => 'merchant',
            'viewer' => 'merchant',
        ];

        if (Schema::hasTable('role_user') && Schema::hasTable('roles')) {
            $assignments = DB::table('role_user')
                ->join('roles', 'role_user.role_id', '=', 'roles.id')
                ->select('role_user.user_id', 'roles.name')
                ->orderBy('role_user.user_id')
                ->get()
                ->groupBy('user_id');

            foreach ($assignments as $userId => $roles) {
                $selected = 'merchant';
                $selectedPriority = -1;

                foreach ($roles as $role) {
                    $name = $role->name;
                    $targetName = $roleMappings[$name] ?? 'merchant';
                    $priority = $rolePriority[$name] ?? 0;

                    if ($priority > $selectedPriority) {
                        $selected = $targetName;
                        $selectedPriority = $priority;
                    }
                }

                DB::table('users')->where('id', $userId)->update(['role' => $selected]);
            }
        }

        // Ensure at least one admin remains if roles tables were empty
        DB::table('users')
            ->whereNull('role')
            ->update(['role' => 'merchant']);

        if (!DB::table('users')->where('role', 'admin')->exists()) {
            $firstUser = DB::table('users')->orderBy('id')->first();
            if ($firstUser) {
                DB::table('users')->where('id', $firstUser->id)->update(['role' => 'admin']);
            }
        }

        if (Schema::hasTable('role_user')) {
            Schema::drop('role_user');
        }

        if (Schema::hasTable('roles')) {
            Schema::drop('roles');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });

        $uniqueRoles = DB::table('users')
            ->select('role')
            ->whereNotNull('role')
            ->distinct()
            ->pluck('role');

        foreach ($uniqueRoles as $roleName) {
            DB::table('roles')->insert([
                'name' => $roleName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleIdsByName = DB::table('roles')->pluck('id', 'name');

        $users = DB::table('users')->select('id', 'role')->get();
        foreach ($users as $user) {
            $roleName = $user->role ?? 'merchant';
            $roleId = $roleIdsByName[$roleName] ?? null;
            if ($roleId) {
                DB::table('role_user')->insert([
                    'user_id' => $user->id,
                    'role_id' => $roleId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
