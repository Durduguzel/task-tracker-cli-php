# Task Tracker CLI (Pure PHP)

This project implements the **Task Tracker** project requirements as a **CLI application** in **pure PHP**, persisting tasks in a **JSON file**.

## Requirements covered

- Add, update, delete tasks
- Mark tasks as `in_progress` or `done`
- List all tasks
- List tasks filtered by status (`done`, `todo`, `in-progress`)
- JSON file persistence (`tasks.json`)

## Prerequisites

- PHP 8.1+ (should also work on 8.0+, but tested target is 8.1+)

## Setup

Clone/download the project, then run commands from the project root.

The first run will create `tasks.json` if it does not exist.

## Usage

All commands use the form:

```bash
php task-cli.php <command> [arguments...]
```

### Add

```bash
php task-cli.php add "Buy milk"
```

### Update

```bash
php task-cli.php update 1 "Buy almond milk"
```

### Delete

```bash
php task-cli.php delete 1
```

### Mark in progress

```bash
php task-cli.php mark-in-progress 1
```

### Mark done

```bash
php task-cli.php mark-done 1
```

### List

List all tasks:

```bash
php task-cli.php list
```

List by filter:

```bash
php task-cli.php list done
php task-cli.php list todo
php task-cli.php list in-progress
```

> Note: "not done" is effectively `todo + in_progress`. If you want that view, run:
>
> ```bash
> php task-cli.php list todo
> php task-cli.php list in-progress
> ```

## Data format

Tasks are stored in `tasks.json` as an array of objects:

```json
[
  {
    "id": 1,
    "description": "Buy milk",
    "status": "todo",
    "createdAt": "2026-01-19T12:00:00+00:00",
    "updatedAt": "2026-01-19T12:00:00+00:00"
  }
]
```

## Notes

- IDs are generated as `max(id) + 1`.
- Storage is file-based; no database required.
- Uses atomic writes (write temp file then rename) for safer persistence.

## Project Scope

This project is inspired by a backend practice idea from roadmap.sh.
All implementation details, architectural decisions, and extensions are independently designed and implemented.

### Reference
- https://roadmap.sh/projects/task-tracker
