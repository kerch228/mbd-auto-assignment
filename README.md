# MBD Auto Assignment

Laravel REST API module for automatic task assignment.

## Requirements

- PHP 8.2+
- Composer
- SQLite, MySQL, or PostgreSQL

The project has no frontend, Docker, Swagger, or auth layer because they are outside the task scope.

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

The API will be available at:

```text
http://127.0.0.1:8000
```

## Run Tests

```bash
php artisan test
```

or:

```bash
vendor/bin/phpunit
```

No queue worker is required for this project.

## API Examples

Auto-assign a task:

```bash
curl -X POST http://127.0.0.1:8000/api/tasks/1/auto-assign \
  -H "Accept: application/json"
```

Successful response:

```json
{
  "task_id": 1,
  "already_assigned": false,
  "assigned_user": { "id": 1, "name": "Anna" },
  "reason": {
    "required_skill": "PHP",
    "active_tasks": 0,
    "maximum_tasks": 5,
    "selection_rule": "lowest_workload"
  }
}
```

Get the assignment log:

```bash
curl http://127.0.0.1:8000/api/tasks/1/assignment-log \
  -H "Accept: application/json"
```

## Assignment Rules

Only a task with status `new` and no assigned user can be automatically assigned. Candidates must be active, must have the required skill, and must have active workload lower than `max_active_tasks`. Active workload is counted from tasks in `todo`, `in_progress`, and `review`.

Eligible users are sorted by:

1. lowest active workload;
2. oldest `last_auto_assigned_at`, where `NULL` wins;
3. lowest user id.

After assignment, the task status becomes `todo`, the selected user receives a fresh `last_auto_assigned_at`, and an `assignment_logs` row is created with a candidate snapshot.

## Architecture

The controller only exposes REST endpoints and delegates business rules to `TaskAutoAssignmentService`. The service evaluates all candidates, records inclusion/exclusion reasons, chooses the winner deterministically, and writes the task update, user update, and assignment log in one database transaction.

The database keeps integrity with foreign keys, a fixed task status enum, a unique skill name, a unique user-skill pivot key, and a unique `assignment_logs.task_id`. The unique log per task makes duplicate automatic assignment visible at the database level as well.

## Concurrency

The assignment flow runs inside `DB::transaction()`. It locks the task row with `lockForUpdate()` before checking status and assignment, then locks candidate users while active workload is calculated and the selected user is updated. Because all automatic assignments take the same user locks, concurrent requests are serialized around workload calculation and `last_auto_assigned_at` updates.

On MySQL/PostgreSQL this prevents two concurrent requests from assigning the same task to different users or pushing a user beyond `max_active_tasks`. SQLite is useful for regular automated tests, but it does not provide the same row-level locking behavior, so the concurrency part is explained here and should be smoke-tested on MySQL/PostgreSQL in a real environment.

## Seeded Data

The seeder creates:

- skills: `PHP`, `React`, `Design`, `Analytics`;
- candidate A: active, has PHP, available;
- candidate B: active, has PHP, already at `max_active_tasks`;
- candidate C: inactive, has PHP;
- candidate D: active, does not have PHP;
- a new PHP task without an assignee;
- a manually assigned task;
- a task without a required skill.

## AI Tools

ChatGPT/Codex was used to draft the Laravel structure, assignment service, tests, and README. The business rules were checked manually against the task description, especially candidate exclusion reasons, deterministic tie-breaks, idempotency, and transaction boundaries.

## What I Would Improve With More Time

- Add a dedicated concurrency integration test running on PostgreSQL or MySQL.
- Add API resources for cleaner response serialization.
- Add factories for larger test datasets.
- Add static analysis with Larastan/PHPStan.
