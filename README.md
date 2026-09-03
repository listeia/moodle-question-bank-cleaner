# Moodle Question Bank Cleaner

> 🇪🇸 [Guía paso a paso en español](README.es.md)

A conservative-but-thorough **CLI maintenance tool for Moodle 5.2.x** that removes question-version clutter without intentionally changing quiz contents.

It is **not a Moodle plugin**. It is a temporary command-line administration script that you copy to `<moodle_root>/admin/cli/`, run, verify, and then remove.

## What it does

- Scans the **entire Moodle site**.
- Uses Moodle core APIs to determine whether each **question version** is in use.
- Deletes unused old/hidden/stale question versions in `--apply` mode.
- Removes question-bank entries that end up with zero versions.
- Deletes empty question categories from the bottom up, while respecting categories Moodle requires to keep.
- Creates CSV reports for review.
- Snapshots the exact quiz-slot → question-bank-entry mapping before and after deletion and aborts if that mapping changes.
- Refuses real deletion if quiz slots use random/set references or have an unexpected reference structure.

## What it does **not** do

- It does not reorganise question categories.
- It does not edit question text, answers, feedback, scores, files, or quiz settings.
- It does not use raw SQL `DELETE` statements for question data.
- It does not support real deletion when quiz random/set references are present.

## Compatibility

**Version 1.0.0 has been tested on Moodle 5.2.x.**

Dry-run may be useful for inspection on other branches, but `--apply` intentionally refuses to run outside Moodle branch `502` in this release.

## Safety model

The default command is a **dry-run** and changes nothing.

Real deletion requires all of the following:

1. `--apply`
2. the exact confirmation string `--confirm=DELETE-UNUSED-QUESTIONS`
3. Moodle maintenance mode enabled
4. a clean quiz-slot pre-flight (no random/set references and exactly one normal reference per slot)

After deletion, the tool compares the complete quiz-slot mapping from before and after. If it changes, the command exits with a critical error and tells you to keep Moodle in maintenance mode.

## Installation

Copy:

```text
questionbank_cleanup.php
```

to:

```text
<moodle_root>/admin/cli/questionbank_cleanup.php
```

`<moodle_root>` means the directory that contains Moodle's `config.php` and `admin/` directory. On modern installations with a separate `public/` document root, do **not** put this script inside `public/` unless that is also where your Moodle code root actually lives.

## Dry-run

From the Moodle root:

```bash
php admin/cli/questionbank_cleanup.php
```

On Debian/Ubuntu, it is usually better to run CLI maintenance commands as the web-server user:

```bash
runuser -u www-data -- php admin/cli/questionbank_cleanup.php
```

The output tells you where CSV reports were written. By default they go to a timestamped directory under:

```text
<moodledata>/temp/moodle_qbank_cleanup_YYYYMMDD_HHMMSS/
```

Review at least:

- `summary.csv`
- `question_versions.csv`
- `categories.csv`
- `quiz_slots_before.csv`
- `quiz_slot_anomalies.csv` if it exists

## Real deletion

**Back up the database first.** Then enable maintenance mode:

```bash
runuser -u www-data -- php admin/cli/maintenance.php --enable
```

Run:

```bash
runuser -u www-data -- php admin/cli/questionbank_cleanup.php --apply --confirm=DELETE-UNUSED-QUESTIONS
```

Do **not** disable maintenance mode yet.

Check that the output says:

```text
Exact quiz-slot mapping unchanged: YES
```

You can also independently compare the generated CSV snapshots:

```bash
cmp -s /path/to/report/quiz_slots_before.csv /path/to/report/quiz_slots_after.csv \
  && echo "QUIZ SLOTS IDENTICAL" \
  || echo "WARNING: DIFFERENCES FOUND"
```

Only after the checks pass should you disable maintenance mode:

```bash
runuser -u www-data -- php admin/cli/maintenance.php --disable
```

Then remove the temporary tool from production if you no longer need it:

```bash
rm admin/cli/questionbank_cleanup.php
```

## Reports

### `question_versions.csv`

Shows each Moodle question version and the action/decision, such as:

- `KEEP_IN_USE`
- `WOULD_DELETE`
- `DELETED`
- `RETAINED_BY_MOODLE_AFTER_DELETE_CALL`

### `categories.csv`

Shows empty categories that would be/deleted and empty categories Moodle protects or retains.

### `quiz_slots_before.csv` / `quiz_slots_after.csv`

Snapshots quiz slots and their normal/set references so the tool can verify that deletion did not change the quiz mapping.

## Important distinction: question vs question version

A Moodle question-bank entry can have several historical versions. Seeing thousands of `WOULD_DELETE` rows does **not** necessarily mean thousands of current questions will disappear. The tool asks Moodle whether each specific version is still in use. Used versions are kept.

## Known limitation: random questions

This release intentionally refuses `--apply` if a quiz slot contains a `question_set_reference` (for example, a random-question set). Dry-run still reports those slots in `quiz_slot_anomalies.csv` so you can investigate them safely.

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).
