<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->enum('status', ['new', 'todo', 'in_progress', 'review', 'done'])->default('new');
            $table->foreignId('required_skill_id')->nullable()->constrained('skills')->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'assigned_user_id']);
            $table->index('required_skill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
