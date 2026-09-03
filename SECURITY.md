# Security notes

This tool is intentionally destructive in `--apply` mode.

## Before publishing or contributing

Never commit any of the following to this repository:

- `config.php`
- database dumps (`*.sql`, `*.sql.gz`)
- `moodledata`
- passwords, API keys, SSH keys, tokens, or hosting credentials
- CSV reports containing private course/question names unless you have reviewed and anonymised them

## Before running `--apply`

1. Make a fresh full database backup.
2. Run the default dry-run first and review the CSV reports.
3. Ensure Moodle maintenance mode is enabled.
4. Confirm that the pre-flight reports no quiz-slot anomalies.
5. Keep maintenance mode enabled after the run until `quiz_mapping_unchanged` is `YES` and `quiz_slots_before.csv` and `quiz_slots_after.csv` are identical.

## Reporting a security issue

If you discover a security problem, avoid posting credentials, private Moodle data, or a production database dump in a public GitHub issue. Describe the problem with anonymised details instead.
