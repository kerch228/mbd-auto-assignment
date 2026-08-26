<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\TaskAutoAssignmentService;
use Illuminate\Http\JsonResponse;

class TaskAssignmentController
{
    public function __construct(
        private readonly TaskAutoAssignmentService $assignments
    ) {
    }

    public function autoAssign(Task $task): JsonResponse
    {
        return response()->json($this->assignments->assign($task));
    }

    public function assignmentLog(Task $task): JsonResponse
    {
        return response()->json($this->assignments->log($task));
    }
}
