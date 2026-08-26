<?php

namespace Database\Seeders;

use App\Models\Skill;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $php = Skill::query()->create(['name' => 'PHP']);
        $react = Skill::query()->create(['name' => 'React']);
        $design = Skill::query()->create(['name' => 'Design']);
        $analytics = Skill::query()->create(['name' => 'Analytics']);

        $anna = User::query()->create([
            'name' => 'Anna',
            'is_active' => true,
            'max_active_tasks' => 5,
            'last_auto_assigned_at' => Carbon::now()->subDays(3),
        ]);
        $anna->skills()->attach([$php->id, $analytics->id]);

        $bob = User::query()->create([
            'name' => 'Bob',
            'is_active' => true,
            'max_active_tasks' => 2,
            'last_auto_assigned_at' => Carbon::now()->subDay(),
        ]);
        $bob->skills()->attach($php->id);

        $claire = User::query()->create([
            'name' => 'Claire',
            'is_active' => false,
            'max_active_tasks' => 5,
            'last_auto_assigned_at' => null,
        ]);
        $claire->skills()->attach($php->id);

        $den = User::query()->create([
            'name' => 'Den',
            'is_active' => true,
            'max_active_tasks' => 5,
            'last_auto_assigned_at' => null,
        ]);
        $den->skills()->attach($react->id);

        Task::query()->create([
            'title' => 'Implement billing webhook',
            'status' => Task::STATUS_NEW,
            'required_skill_id' => $php->id,
        ]);

        Task::query()->create([
            'title' => 'Manual task already assigned',
            'status' => Task::STATUS_TODO,
            'required_skill_id' => $php->id,
            'assigned_user_id' => $anna->id,
        ]);

        Task::query()->create([
            'title' => 'Task without skill',
            'status' => Task::STATUS_NEW,
        ]);

        foreach ([Task::STATUS_TODO, Task::STATUS_IN_PROGRESS] as $index => $status) {
            Task::query()->create([
                'title' => 'Bob active task '.($index + 1),
                'status' => $status,
                'required_skill_id' => $php->id,
                'assigned_user_id' => $bob->id,
            ]);
        }

        Task::query()->create([
            'title' => 'Design task',
            'status' => Task::STATUS_NEW,
            'required_skill_id' => $design->id,
        ]);
    }
}
