<?php

namespace Tests\Feature;

use App\Models\AssignmentLog;
use App\Models\Skill;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TaskAutoAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_assignment_updates_task_user_and_log_atomically(): void
    {
        Carbon::setTestNow('2026-08-26 10:00:00');
        $php = Skill::query()->create(['name' => 'PHP']);
        $user = $this->user('Anna', skills: [$php]);
        $task = $this->task($php);

        $this->postJson("/api/tasks/{$task->id}/auto-assign")
            ->assertOk()
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('already_assigned', false)
            ->assertJsonPath('assigned_user.id', $user->id)
            ->assertJsonPath('reason.required_skill', 'PHP')
            ->assertJsonPath('reason.active_tasks', 0)
            ->assertJsonPath('reason.maximum_tasks', 3);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => Task::STATUS_TODO,
            'assigned_user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'last_auto_assigned_at' => '2026-08-26 10:00:00',
        ]);
        $this->assertDatabaseHas('assignment_logs', [
            'task_id' => $task->id,
            'assigned_user_id' => $user->id,
            'required_skill_id' => $php->id,
        ]);
    }

    public function test_inactive_user_is_excluded_from_snapshot(): void
    {
        $php = Skill::query()->create(['name' => 'PHP']);
        $inactive = $this->user('Inactive', active: false, skills: [$php]);
        $active = $this->user('Active', skills: [$php]);
        $task = $this->task($php);

        $this->postJson("/api/tasks/{$task->id}/auto-assign")->assertOk();

        $snapshot = AssignmentLog::query()->firstOrFail()->candidate_snapshot;
        $inactiveRow = collect($snapshot)->firstWhere('id', $inactive->id);
        $activeRow = collect($snapshot)->firstWhere('id', $active->id);

        $this->assertFalse($inactiveRow['included']);
        $this->assertSame('inactive', $inactiveRow['reason']);
        $this->assertTrue($activeRow['included']);
    }

    public function test_user_without_required_skill_is_excluded(): void
    {
        $php = Skill::query()->create(['name' => 'PHP']);
        $react = Skill::query()->create(['name' => 'React']);
        $withoutSkill = $this->user('React dev', skills: [$react]);
        $withSkill = $this->user('PHP dev', skills: [$php]);
        $task = $this->task($php);

        $this->postJson("/api/tasks/{$task->id}/auto-assign")
            ->assertOk()
            ->assertJsonPath('assigned_user.id', $withSkill->id);

        $snapshot = AssignmentLog::query()->firstOrFail()->candidate_snapshot;
        $row = collect($snapshot)->firstWhere('id', $withoutSkill->id);

        $this->assertFalse($row['included']);
        $this->assertSame('missing_required_skill', $row['reason']);
    }

    public function test_max_active_tasks_is_respected(): void
    {
        $php = Skill::query()->create(['name' => 'PHP']);
        $busy = $this->user('Busy', maxTasks: 1, skills: [$php]);
        $free = $this->user('Free', maxTasks: 2, skills: [$php]);
        $this->task($php, status: Task::STATUS_IN_PROGRESS, assignedUser: $busy);
        $task = $this->task($php);

        $this->postJson("/api/tasks/{$task->id}/auto-assign")
            ->assertOk()
            ->assertJsonPath('assigned_user.id', $free->id);

        $snapshot = AssignmentLog::query()->firstOrFail()->candidate_snapshot;
        $busyRow = collect($snapshot)->firstWhere('id', $busy->id);

        $this->assertFalse($busyRow['included']);
        $this->assertSame('max_active_tasks_reached', $busyRow['reason']);
    }

    public function test_candidate_with_lowest_workload_is_selected(): void
    {
        $php = Skill::query()->create(['name' => 'PHP']);
        $busy = $this->user('Busy', maxTasks: 5, skills: [$php]);
        $free = $this->user('Free', maxTasks: 5, skills: [$php]);
        $this->task($php, status: Task::STATUS_TODO, assignedUser: $busy);
        $task = $this->task($php);

        $this->postJson("/api/tasks/{$task->id}/auto-assign")
            ->assertOk()
            ->assertJsonPath('assigned_user.id', $free->id)
            ->assertJsonPath('reason.selection_rule', 'lowest_workload');
    }

    public function test_tie_break_uses_null_then_oldest_last_auto_assigned_at(): void
    {
        $php = Skill::query()->create(['name' => 'PHP']);
        $assignedYesterday = $this->user('Yesterday', lastAssignedAt: '2026-08-25 10:00:00', skills: [$php]);
        $neverAssigned = $this->user('Never', lastAssignedAt: null, skills: [$php]);
        $task = $this->task($php);

        $this->postJson("/api/tasks/{$task->id}/auto-assign")
            ->assertOk()
            ->assertJsonPath('assigned_user.id', $neverAssigned->id)
            ->assertJsonPath('reason.selection_rule', 'oldest_last_auto_assigned_at');

        $taskTwo = $this->task($php);
        $older = $this->user('Older', lastAssignedAt: '2026-08-20 10:00:00', skills: [$php]);
        $newer = $this->user('Newer', lastAssignedAt: '2026-08-24 10:00:00', skills: [$php]);

        $this->postJson("/api/tasks/{$taskTwo->id}/auto-assign")
            ->assertOk()
            ->assertJsonPath('assigned_user.id', $older->id)
            ->assertJsonPath('reason.selection_rule', 'oldest_last_auto_assigned_at');

        $this->assertNotSame($assignedYesterday->id, $newer->id);
    }

    public function test_tie_break_uses_lowest_id_when_candidates_are_equal(): void
    {
        $php = Skill::query()->create(['name' => 'PHP']);
        $first = $this->user('First', lastAssignedAt: '2026-08-20 10:00:00', skills: [$php]);
        $second = $this->user('Second', lastAssignedAt: '2026-08-20 10:00:00', skills: [$php]);
        $task = $this->task($php);

        $this->postJson("/api/tasks/{$task->id}/auto-assign")
            ->assertOk()
            ->assertJsonPath('assigned_user.id', $first->id)
            ->assertJsonPath('reason.selection_rule', 'lowest_id');

        $this->assertLessThan($second->id, $first->id);
    }

    public function test_no_available_candidates_returns_409(): void
    {
        $php = Skill::query()->create(['name' => 'PHP']);
        $busy = $this->user('Busy', maxTasks: 1, skills: [$php]);
        $this->task($php, status: Task::STATUS_TODO, assignedUser: $busy);
        $task = $this->task($php);

        $this->postJson("/api/tasks/{$task->id}/auto-assign")
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_eligible_assignee');
    }

    public function test_unassigned_task_that_is_not_new_returns_409(): void
    {
        $php = Skill::query()->create(['name' => 'PHP']);
        $task = $this->task($php, status: Task::STATUS_REVIEW);

        $this->postJson("/api/tasks/{$task->id}/auto-assign")
            ->assertStatus(409)
            ->assertJsonPath('code', 'task_is_not_new');
    }

    public function test_repeated_request_is_idempotent_and_does_not_create_second_log(): void
    {
        $php = Skill::query()->create(['name' => 'PHP']);
        $user = $this->user('Anna', skills: [$php]);
        $task = $this->task($php);

        $this->postJson("/api/tasks/{$task->id}/auto-assign")->assertOk();
        $this->postJson("/api/tasks/{$task->id}/auto-assign")
            ->assertOk()
            ->assertJsonPath('already_assigned', true)
            ->assertJsonPath('assigned_user.id', $user->id);

        $this->assertSame(1, AssignmentLog::query()->where('task_id', $task->id)->count());
    }

    public function test_manually_assigned_task_is_returned_without_creating_log(): void
    {
        $php = Skill::query()->create(['name' => 'PHP']);
        $user = $this->user('Manual assignee', skills: [$php]);
        $task = $this->task($php, status: Task::STATUS_IN_PROGRESS, assignedUser: $user);

        $this->postJson("/api/tasks/{$task->id}/auto-assign")
            ->assertOk()
            ->assertJsonPath('already_assigned', true)
            ->assertJsonPath('assigned_user.id', $user->id)
            ->assertJsonPath('reason.selection_rule', 'already_assigned');

        $this->assertSame(0, AssignmentLog::query()->where('task_id', $task->id)->count());
    }

    public function test_task_without_required_skill_returns_422(): void
    {
        $task = Task::query()->create(['title' => 'No skill', 'status' => Task::STATUS_NEW]);

        $this->postJson("/api/tasks/{$task->id}/auto-assign")
            ->assertStatus(422)
            ->assertJsonPath('code', 'required_skill_missing');
    }

    public function test_assignment_log_endpoint_returns_snapshot(): void
    {
        $php = Skill::query()->create(['name' => 'PHP']);
        $user = $this->user('Anna', skills: [$php]);
        $task = $this->task($php);

        $this->postJson("/api/tasks/{$task->id}/auto-assign")->assertOk();

        $this->getJson("/api/tasks/{$task->id}/assignment-log")
            ->assertOk()
            ->assertJsonPath('task_id', $task->id)
            ->assertJsonPath('assigned_user.id', $user->id)
            ->assertJsonPath('required_skill', 'PHP')
            ->assertJsonStructure(['candidate_snapshot']);
    }

    public function test_assignment_log_not_found_returns_404(): void
    {
        $php = Skill::query()->create(['name' => 'PHP']);
        $task = $this->task($php);

        $this->getJson("/api/tasks/{$task->id}/assignment-log")
            ->assertStatus(404)
            ->assertJsonPath('code', 'assignment_log_not_found');
    }

    public function test_missing_task_returns_404(): void
    {
        $this->postJson('/api/tasks/999/auto-assign')
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');
    }

    private function user(
        string $name,
        bool $active = true,
        int $maxTasks = 3,
        ?string $lastAssignedAt = null,
        array $skills = []
    ): User {
        $user = User::query()->create([
            'name' => $name,
            'is_active' => $active,
            'max_active_tasks' => $maxTasks,
            'last_auto_assigned_at' => $lastAssignedAt,
        ]);

        foreach ($skills as $skill) {
            $user->skills()->attach($skill->id);
        }

        return $user;
    }

    private function task(
        ?Skill $skill,
        string $status = Task::STATUS_NEW,
        ?User $assignedUser = null
    ): Task {
        return Task::query()->create([
            'title' => 'Test task',
            'status' => $status,
            'required_skill_id' => $skill?->id,
            'assigned_user_id' => $assignedUser?->id,
        ]);
    }
}
