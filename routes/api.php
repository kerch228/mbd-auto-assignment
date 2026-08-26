<?php

use App\Http\Controllers\TaskAssignmentController;
use Illuminate\Support\Facades\Route;

Route::post('/tasks/{task}/auto-assign', [TaskAssignmentController::class, 'autoAssign']);
Route::get('/tasks/{task}/assignment-log', [TaskAssignmentController::class, 'assignmentLog']);
