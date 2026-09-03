# Changelog

## 1.0.0 - 2026-09-03

- Initial public release.
- Dry-run by default.
- Deletes unused Moodle question versions through Moodle core APIs.
- Deletes orphan question-bank entries and empty categories.
- Pre/post quiz-slot mapping verification.
- Refuses real deletion when quiz random/set references or malformed slot references are present.
- Real deletion requires maintenance mode and an explicit confirmation string.
- Tested on Moodle 5.2.x.
