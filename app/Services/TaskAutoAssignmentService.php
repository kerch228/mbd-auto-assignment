<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AssignmentLog;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TaskAutoAssignmentService
{
    public function assign(Task $task): array
    {
        return DB::transaction(function () use ($task): array {
            /** @var Task $lockedTask */
            $lockedTask = Task::query()
                ->with(['assignedUser', 'requiredSkill'])
                ->whereKey($task->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTask->assigned_user_id !== null) {
                return $this->alreadyAssignedResponse($lockedTask);
            }

            if ($lockedTask->status !== Task::STATUS_NEW) {
                throw new ApiException(
                    'task_is_not_new',
                    'Only a new unassigned task can be automatically assigned.',
                    409
                );
            }

            if ($lockedTask->required_skill_id === null) {
                throw new ApiException(
                    'required_skill_missing',
                    'Task required skill is missing.',
                    422
                );
            }

            $users = User::query()
                ->with('skills')
                ->withCount([
                    'assignedTasks as active_tasks_count' => fn ($query) => $query
                        ->whereIn('status', Task::ACTIVE_STATUSES),
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            [$eligible, $snapshot] = $this->evaluateCandidates($users, $lockedTask);

            if ($eligible->isEmpty()) {
                throw new ApiException(
                    'no_eligible_assignee',
                    'No eligible assignee is available for this task.',
                    409
                );
            }

            /** @var User $selected */
            $selected = $eligible
                ->sort($this->compareCandidates(...))
                ->first();

            $reason = $this->selectionRule($eligible, $selected);
            $now = Carbon::now();
            $selected->forceFill(['last_auto_assigned_at' => $now])->save();

            $lockedTask->forceFill([
                'assigned_user_id' => $selected->id,
                'status' => Task::STATUS_TODO,
            ])->save();

            AssignmentLog::query()->create([
                'task_id' => $lockedTask->id,
                'assigned_user_id' => $selected->id,
                'required_skill_id' => $lockedTask->required_skill_id,
                'reason' => $reason,
                'candidate_snapshot' => $snapshot,
            ]);

            return [
                'task_id' => $lockedTask->id,
                'already_assigned' => false,
                'assigned_user' => [
                    'id' => $selected->id,
                    'name' => $selected->name,
                ],
                'reason' => [
                    'required_skill' => $lockedTask->requiredSkill->name,
                    'active_tasks' => $selected->active_tasks_count,
                    'maximum_tasks' => $selected->max_active_tasks,
                    'selection_rule' => $reason,
                ],
            ];
        });
    }

    public function log(Task $task): array
    {
        $log = AssignmentLog::query()
            ->with(['assignedUser', 'requiredSkill'])
            ->where('task_id', $task->id)
            ->first();

        if ($log === null) {
            throw new ApiException(
                'assignment_log_not_found',
                'Assignment log was not found for this task.',
                404
            );
        }

        return [
            'task_id' => $task->id,
            'assigned_user' => [
                'id' => $log->assignedUser->id,
                'name' => $log->assignedUser->name,
            ],
            'required_skill' => $log->requiredSkill->name,
            'reason' => $log->reason,
            'candidate_snapshot' => $log->candidate_snapshot,
            'created_at' => $log->created_at?->toISOString(),
        ];
    }

    private function alreadyAssignedResponse(Task $task): array
    {
        $task->loadMissing(['assignedUser', 'requiredSkill']);

        return [
            'task_id' => $task->id,
            'already_assigned' => true,
            'assigned_user' => [
                'id' => $task->assignedUser->id,
                'name' => $task->assignedUser->name,
            ],
            'reason' => [
                'required_skill' => $task->requiredSkill?->name,
                'active_tasks' => $task->assignedUser
                    ->assignedTasks()
                    ->whereIn('status', Task::ACTIVE_STATUSES)
                    ->count(),
                'maximum_tasks' => $task->assignedUser->max_active_tasks,
                'selection_rule' => 'already_assigned',
            ],
        ];
    }

    private function evaluateCandidates(Collection $users, Task $task): array
    {
        $eligible = collect();
        $snapshot = [];

        foreach ($users as $user) {
            $hasSkill = $user->skills->contains('id', $task->required_skill_id);
            $activeTasks = (int) $user->active_tasks_count;
            $included = $user->is_active
                && $hasSkill
                && $activeTasks < $user->max_active_tasks;

            $snapshot[] = [
                'id' => $user->id,
                'name' => $user->name,
                'is_active' => $user->is_active,
                'has_required_skill' => $hasSkill,
                'active_tasks' => $activeTasks,
                'maximum_tasks' => $user->max_active_tasks,
                'included' => $included,
                'reason' => $this->candidateReason($user, $hasSkill, $activeTasks),
            ];

            if ($included) {
                $eligible->push($user);
            }
        }

        return [$eligible, $snapshot];
    }

    private function candidateReason(User $user, bool $hasSkill, int $activeTasks): string
    {
        if (! $user->is_active) {
            return 'inactive';
        }

        if (! $hasSkill) {
            return 'missing_required_skill';
        }

        if ($activeTasks >= $user->max_active_tasks) {
            return 'max_active_tasks_reached';
        }

        return 'eligible';
    }

    private function compareLastAssignment(User $left, User $right): int
    {
        if ($left->last_auto_assigned_at === null && $right->last_auto_assigned_at === null) {
            return $left->id <=> $right->id;
        }

        if ($left->last_auto_assigned_at === null) {
            return -1;
        }

        if ($right->last_auto_assigned_at === null) {
            return 1;
        }

        return $left->last_auto_assigned_at <=> $right->last_auto_assigned_at;
    }

    private function compareCandidates(User $left, User $right): int
    {
        $byWorkload = ((int) $left->active_tasks_count) <=> ((int) $right->active_tasks_count);

        if ($byWorkload !== 0) {
            return $byWorkload;
        }

        $byLastAssignment = $this->compareLastAssignment($left, $right);

        if ($byLastAssignment !== 0) {
            return $byLastAssignment;
        }

        return $left->id <=> $right->id;
    }

    private function selectionRule($eligible, User $selected): string
    {
        $lowestWorkload = $eligible->min('active_tasks_count');

        if ((int) $selected->active_tasks_count === (int) $lowestWorkload) {
            $sameWorkload = $eligible->where('active_tasks_count', $lowestWorkload);

            if ($sameWorkload->count() === 1) {
                return 'lowest_workload';
            }

            $hasNeverAssigned = $sameWorkload->contains(
                fn (User $user) => $user->last_auto_assigned_at === null
            );

            if ($selected->last_auto_assigned_at === null && $hasNeverAssigned) {
                $neverAssigned = $sameWorkload->filter(
                    fn (User $user) => $user->last_auto_assigned_at === null
                );

                return $neverAssigned->count() === 1
                    ? 'oldest_last_auto_assigned_at'
                    : 'lowest_id';
            }

            $oldest = $sameWorkload
                ->sortBy(fn (User $user) => $user->last_auto_assigned_at?->timestamp ?? PHP_INT_MIN)
                ->first();

            if ($oldest && $oldest->id === $selected->id) {
                $sameOldest = $sameWorkload->filter(
                    fn (User $user) => $user->last_auto_assigned_at?->equalTo($oldest->last_auto_assigned_at)
                );

                return $sameOldest->count() === 1
                    ? 'oldest_last_auto_assigned_at'
                    : 'lowest_id';
            }
        }

        return 'lowest_id';
    }
}
