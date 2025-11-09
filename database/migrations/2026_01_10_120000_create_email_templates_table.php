<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('name');
            $table->string('subject');
            $table->longText('body_html')->nullable();
            $table->text('body_text')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('default_recipients')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
