<?php
// This file is part of Moodle Question Bank Cleaner.
//
// Moodle Question Bank Cleaner is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle Question Bank Cleaner is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program. If not, see <https://www.gnu.org/licenses/>.

/**
 * Site-wide CLI cleaner for unused Moodle question versions and empty categories.
 *
 * Tested with Moodle 5.2.x. Dry-run is the default.
 * Copy this file to <moodle_root>/admin/cli/questionbank_cleanup.php.
 *
 * @copyright 2026 Laura
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/questionlib.php');

\core\session\manager::set_user(get_admin());
\core_php_time_limit::raise();
raise_memory_limit(MEMORY_EXTRA);

[$options, $unrecognized] = cli_get_params(
    [
        'apply' => false,
        'confirm' => null,
        'reportdir' => null,
        'help' => false,
        'version' => false,
    ],
    [
        'h' => 'help',
        'v' => 'version',
    ]
);

if ($unrecognized) {
    cli_error("Unknown option(s): " . implode(', ', $unrecognized));
}

$help = <<<'TXT'
Moodle Question Bank Cleaner v1.0.0 (tested on Moodle 5.2.x)

Purpose:
  - Delete every QUESTION VERSION that Moodle itself reports as unused.
  - This includes hidden/stale versions and old versions when they are truly unused.
  - If an entire question-bank entry has no used versions, the entry disappears too.
  - Delete empty question categories bottom-up, except categories Moodle requires to exist.
  - Never alter quiz slots or question references intentionally.

Safety:
  - DRY-RUN is the default and changes nothing.
  - APPLY refuses to run unless maintenance mode is enabled.
  - APPLY refuses to run if random-question set references are found in quizzes.
  - APPLY refuses to run if a quiz slot does not have exactly one normal question reference.
  - Before and after APPLY, the exact quiz-slot -> question-bank-entry mapping is snapshotted and compared.
  - Deletion uses Moodle core APIs, never raw DELETE statements for question data.

Usage (from Moodle root):
  php admin/cli/questionbank_cleanup.php

Apply for real:
  php admin/cli/questionbank_cleanup.php --apply --confirm=DELETE-UNUSED-QUESTIONS

Optional:
  --reportdir=/absolute/path   Directory for CSV reports.
  -h, --help                  Show this help.
  -v, --version               Show tool version.

IMPORTANT:
  - This script scans the whole Moodle site, not just one course.
  - Back up the database before APPLY.
  - APPLY is intentionally blocked if quiz slots use random/set references.
TXT;

if ($options['help']) {
    echo $help . PHP_EOL;
    exit(0);
}
if ($options['version']) {
    echo 'Moodle Question Bank Cleaner 1.0.0' . PHP_EOL;
    exit(0);
}

$apply = !empty($options['apply']);

// This release has only been validated on Moodle 5.2.x.
$branch = (string)($CFG->branch ?? '');
if ($branch !== '502') {
    mtrace('WARNING: this release has only been tested on Moodle 5.2.x (branch 502).');
    mtrace('Detected Moodle branch: ' . ($branch !== '' ? $branch : 'unknown'));
    if ($apply) {
        cli_error('Refusing APPLY on an untested Moodle branch. Run DRY-RUN only, or use a release tested for your Moodle version.');
    }
}
if ($apply && $options['confirm'] !== 'DELETE-UNUSED-QUESTIONS') {
    cli_error("APPLY requires --confirm=DELETE-UNUSED-QUESTIONS");
}

if ($apply) {
    $maintenance = !empty($CFG->maintenance_enabled) || file_exists($CFG->dataroot . '/climaintenance.html');
    if (!$maintenance) {
        cli_error(
            "Refusing APPLY because Moodle maintenance mode is not enabled.\n" .
            "Run: php admin/cli/maintenance.php --enable"
        );
    }
}

$timestamp = date('Ymd_His');
$reportdir = $options['reportdir'] ?: $CFG->dataroot . '/temp/moodle_qbank_cleanup_' . $timestamp;
if (!make_writable_directory($reportdir, false)) {
    cli_error("Cannot create/write report directory: {$reportdir}");
}

mtrace('============================================================');
mtrace('MOODLE QUESTION BANK CLEANER');
mtrace('Mode: ' . ($apply ? 'APPLY (REAL DELETION)' : 'DRY-RUN (NO CHANGES)'));
mtrace('Moodle release: ' . ($CFG->release ?? 'unknown'));
mtrace('Reports: ' . $reportdir);
mtrace('============================================================');

/**
 * Write semicolon-separated UTF-8 CSV (Excel-friendly in Spanish locales).
 */
function mqbc_write_csv(string $path, array $headers, array $rows): void {
    $fh = fopen($path, 'wb');
    if ($fh === false) {
        throw new runtime_exception("Cannot open CSV for writing: {$path}");
    }
    // UTF-8 BOM for spreadsheet apps.
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, $headers, ';');
    foreach ($rows as $row) {
        fputcsv($fh, $row, ';');
    }
    fclose($fh);
}

/**
 * Load all question categories for paths and simulations.
 */
function mqbc_load_categories(): array {
    global $DB;
    return $DB->get_records('question_categories', null, 'id ASC');
}

/**
 * Human-readable category path.
 */
function mqbc_category_path(int $categoryid, array $categories): string {
    $parts = [];
    $seen = [];
    $id = $categoryid;
    while ($id && isset($categories[$id]) && !isset($seen[$id])) {
        $seen[$id] = true;
        array_unshift($parts, $categories[$id]->name);
        $id = (int)$categories[$id]->parent;
    }
    return implode(' / ', $parts);
}

/**
 * Snapshot every quiz slot and its normal question_reference(s).
 * Returns [slotMap, csvRows, anomalies, usedEntryIds].
 */
function mqbc_quiz_slot_snapshot(): array {
    global $DB;

    $slots = [];
    $sql = "SELECT qs.id AS slotid,
                   qs.quizid,
                   qs.slot,
                   q.course AS courseid,
                   q.name AS quizname,
                   qr.id AS referenceid,
                   qr.questionbankentryid,
                   qr.version
              FROM {quiz_slots} qs
              JOIN {quiz} q ON q.id = qs.quizid
         LEFT JOIN {question_references} qr
                ON qr.itemid = qs.id
               AND qr.component = 'mod_quiz'
               AND qr.questionarea = 'slot'
          ORDER BY q.course, q.id, qs.slot, qr.id";

    $rs = $DB->get_recordset_sql($sql);
    foreach ($rs as $row) {
        $slotid = (int)$row->slotid;
        if (!isset($slots[$slotid])) {
            $slots[$slotid] = [
                'slotid' => $slotid,
                'quizid' => (int)$row->quizid,
                'slot' => (int)$row->slot,
                'courseid' => (int)$row->courseid,
                'quizname' => $row->quizname,
                'refs' => [],
                'setrefs' => [],
            ];
        }
        if ($row->referenceid !== null) {
            $slots[$slotid]['refs'][] = [
                'referenceid' => (int)$row->referenceid,
                'entryid' => (int)$row->questionbankentryid,
                'version' => $row->version === null ? null : (int)$row->version,
            ];
        }
    }
    $rs->close();

    // Random/set references. The user says there are none; APPLY aborts if any exist.
    $setsql = "SELECT qsr.id,
                      qsr.itemid AS slotid,
                      qsr.filtercondition
                 FROM {question_set_references} qsr
                 JOIN {quiz_slots} qs ON qs.id = qsr.itemid
                WHERE qsr.component = 'mod_quiz'
                  AND qsr.questionarea = 'slot'
             ORDER BY qsr.id";
    $setrs = $DB->get_recordset_sql($setsql);
    foreach ($setrs as $setrow) {
        $slotid = (int)$setrow->slotid;
        if (isset($slots[$slotid])) {
            $slots[$slotid]['setrefs'][] = [
                'id' => (int)$setrow->id,
                'filtercondition' => $setrow->filtercondition,
            ];
        }
    }
    $setrs->close();

    ksort($slots);
    $csvrows = [];
    $anomalies = [];
    $usedentries = [];

    foreach ($slots as $slot) {
        $refcount = count($slot['refs']);
        $setrefcount = count($slot['setrefs']);
        $entryids = array_map(fn($r) => $r['entryid'], $slot['refs']);
        $versions = array_map(
            fn($r) => $r['version'] === null ? 'LATEST' : (string)$r['version'],
            $slot['refs']
        );
        foreach ($entryids as $entryid) {
            $usedentries[$entryid] = true;
        }
        if ($refcount !== 1 || $setrefcount !== 0) {
            $anomalies[] = [
                $slot['courseid'],
                $slot['quizid'],
                $slot['quizname'],
                $slot['slot'],
                $slot['slotid'],
                $refcount,
                $setrefcount,
                implode(',', $entryids),
                implode(',', $versions),
            ];
        }
        $csvrows[] = [
            $slot['courseid'],
            $slot['quizid'],
            $slot['quizname'],
            $slot['slot'],
            $slot['slotid'],
            $refcount,
            $setrefcount,
            implode(',', $entryids),
            implode(',', $versions),
        ];
    }

    return [$slots, $csvrows, $anomalies, $usedentries];
}

/**
 * Canonical quiz mapping for before/after equality checks.
 */
function mqbc_canonical_quiz_mapping(array $slots): array {
    $canonical = [];
    foreach ($slots as $slotid => $slot) {
        $refs = $slot['refs'];
        usort($refs, fn($a, $b) => $a['referenceid'] <=> $b['referenceid']);
        $refparts = [];
        foreach ($refs as $ref) {
            $refparts[] = implode(':', [
                $ref['referenceid'],
                $ref['entryid'],
                $ref['version'] === null ? 'LATEST' : $ref['version'],
            ]);
        }
        $setids = array_map(fn($s) => $s['id'], $slot['setrefs']);
        sort($setids);
        $canonical[$slotid] = implode('|', [
            $slot['quizid'],
            $slot['slot'],
            implode(',', $refparts),
            implode(',', $setids),
        ]);
    }
    ksort($canonical);
    return $canonical;
}

/**
 * Simulate which empty leaf categories would disappear after the planned question cleanup.
 * Moodle top categories and the last child under a top category are protected.
 */
function mqbc_simulate_empty_category_cleanup(array $categories, array $survivingentrycounts): array {
    $alive = [];
    foreach ($categories as $id => $cat) {
        $alive[(int)$id] = true;
    }

    $deleted = [];
    $changed = true;
    while ($changed) {
        $changed = false;

        // Build current child counts.
        $childcounts = [];
        foreach ($categories as $id => $cat) {
            $id = (int)$id;
            if (!isset($alive[$id])) {
                continue;
            }
            $parent = (int)$cat->parent;
            if ($parent && isset($alive[$parent])) {
                $childcounts[$parent] = ($childcounts[$parent] ?? 0) + 1;
            }
        }

        foreach ($categories as $id => $cat) {
            $id = (int)$id;
            if (!isset($alive[$id])) {
                continue;
            }
            if ((int)$cat->parent === 0) {
                continue; // Top category is protected by Moodle.
            }
            if (($survivingentrycounts[$id] ?? 0) > 0) {
                continue;
            }
            if (($childcounts[$id] ?? 0) > 0) {
                continue;
            }

            $parentid = (int)$cat->parent;
            $parent = $categories[$parentid] ?? null;
            if ($parent && (int)$parent->parent === 0 && ($childcounts[$parentid] ?? 0) <= 1) {
                continue; // Moodle requires one child category under the top category.
            }

            unset($alive[$id]);
            $deleted[$id] = true;
            $changed = true;
        }
    }

    $protected = [];
    // Rebuild final child counts to identify mandatory empty categories left behind.
    $childcounts = [];
    foreach ($categories as $id => $cat) {
        $id = (int)$id;
        if (!isset($alive[$id])) {
            continue;
        }
        $parent = (int)$cat->parent;
        if ($parent && isset($alive[$parent])) {
            $childcounts[$parent] = ($childcounts[$parent] ?? 0) + 1;
        }
    }
    foreach ($categories as $id => $cat) {
        $id = (int)$id;
        if (!isset($alive[$id])) {
            continue;
        }
        if (($survivingentrycounts[$id] ?? 0) > 0 || ($childcounts[$id] ?? 0) > 0) {
            continue;
        }
        if ((int)$cat->parent === 0) {
            $protected[$id] = 'TOP_CATEGORY';
            continue;
        }
        $parent = $categories[(int)$cat->parent] ?? null;
        if ($parent && (int)$parent->parent === 0 && ($childcounts[(int)$cat->parent] ?? 0) <= 1) {
            $protected[$id] = 'MANDATORY_LAST_CHILD';
        }
    }

    return [array_keys($deleted), $protected];
}

// -----------------------------------------------------------------------------
// PRE-FLIGHT: snapshot quizzes and reject random/broken slot mappings on APPLY.
// -----------------------------------------------------------------------------
[$beforeSlots, $beforeCsv, $slotAnomalies, $quizUsedEntries] = mqbc_quiz_slot_snapshot();
mqbc_write_csv(
    $reportdir . '/quiz_slots_before.csv',
    ['courseid', 'quizid', 'quizname', 'slot', 'slotid', 'normal_refs', 'set_refs', 'qbe_ids', 'versions'],
    $beforeCsv
);

if ($slotAnomalies) {
    mqbc_write_csv(
        $reportdir . '/quiz_slot_anomalies.csv',
        ['courseid', 'quizid', 'quizname', 'slot', 'slotid', 'normal_refs', 'set_refs', 'qbe_ids', 'versions'],
        $slotAnomalies
    );
    mtrace('WARNING: Found ' . count($slotAnomalies) . ' quiz slot(s) that are random or do not have exactly one normal reference.');
    mtrace('See: ' . $reportdir . '/quiz_slot_anomalies.csv');
    if ($apply) {
        cli_error('APPLY aborted before any deletion because quiz-slot preflight is not clean.');
    }
}

mtrace('Quiz slots snapshotted: ' . count($beforeSlots));
mtrace('Question-bank entries currently referenced by quiz slots: ' . count($quizUsedEntries));

// -----------------------------------------------------------------------------
// PLAN / DELETE UNUSED QUESTION VERSIONS.
// -----------------------------------------------------------------------------
$categoriesBefore = mqbc_load_categories();

$versionSql = "SELECT qv.id AS versionrowid,
                      qv.questionbankentryid,
                      qv.questionid,
                      qv.version,
                      qv.status,
                      q.name,
                      q.qtype,
                      qbe.questioncategoryid,
                      qc.contextid
                 FROM {question_versions} qv
                 JOIN {question} q ON q.id = qv.questionid
                 JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                 JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
             ORDER BY qbe.questioncategoryid, qv.questionbankentryid, qv.version";

$questionReport = [];
$entryHasUsedVersion = [];
$entrySeen = [];
$wouldDeleteVersions = 0;
$deletedVersions = 0;
$keptVersions = 0;
$unexpectedRetained = 0;

$rs = $DB->get_recordset_sql($versionSql);
foreach ($rs as $row) {
    $entryid = (int)$row->questionbankentryid;
    $qid = (int)$row->questionid;
    $entrySeen[$entryid] = true;

    // Core Moodle decision: references, attempts/question engine, plugin callbacks, etc.
    $inuse = questions_in_use([$qid]);
    if ($inuse) {
        $entryHasUsedVersion[$entryid] = true;
        $keptVersions++;
        $action = 'KEEP_IN_USE';
    } else {
        $wouldDeleteVersions++;
        if ($apply) {
            question_delete_question($qid);
            if ($DB->record_exists('question', ['id' => $qid])) {
                $unexpectedRetained++;
                $action = 'RETAINED_BY_MOODLE_AFTER_DELETE_CALL';
            } else {
                $deletedVersions++;
                $action = 'DELETED';
            }
        } else {
            $action = 'WOULD_DELETE';
        }
    }

    $questionReport[] = [
        $entryid,
        $qid,
        (int)$row->version,
        $row->status,
        $row->qtype,
        $row->name,
        (int)$row->questioncategoryid,
        mqbc_category_path((int)$row->questioncategoryid, $categoriesBefore),
        isset($quizUsedEntries[$entryid]) ? 'YES' : 'NO',
        $inuse ? 'YES' : 'NO',
        $action,
    ];
}
$rs->close();

// Remove truly orphaned bank entries (zero versions) using Moodle's own helper function.
$allEntriesBeforeOrphanCleanup = $DB->get_records('question_bank_entries', null, 'id ASC', 'id, questioncategoryid');
$orphanEntries = [];
foreach ($allEntriesBeforeOrphanCleanup as $entry) {
    $entryid = (int)$entry->id;
    if (!$DB->record_exists('question_versions', ['questionbankentryid' => $entryid])) {
        $orphanEntries[] = [
            $entryid,
            (int)$entry->questioncategoryid,
            mqbc_category_path((int)$entry->questioncategoryid, $categoriesBefore),
            $apply ? 'DELETED_ORPHAN_ENTRY' : 'WOULD_DELETE_ORPHAN_ENTRY',
        ];
        if ($apply) {
            delete_question_bank_entry($entryid);
        }
    }
}

mqbc_write_csv(
    $reportdir . '/question_versions.csv',
    [
        'qbe_id', 'question_id', 'version', 'status', 'qtype', 'name', 'category_id', 'category_path',
        'entry_in_current_quiz', 'moodle_reports_version_in_use', 'action'
    ],
    $questionReport
);
mqbc_write_csv(
    $reportdir . '/orphan_entries.csv',
    ['qbe_id', 'category_id', 'category_path', 'action'],
    $orphanEntries
);

// -----------------------------------------------------------------------------
// CATEGORY CLEANUP.
// -----------------------------------------------------------------------------
$categoryReport = [];
$categoriesDeletedOrWouldDelete = 0;
$categoriesProtected = 0;

if (!$apply) {
    // Simulate surviving entries after all unused versions are removed.
    $survivingEntryCounts = [];
    foreach ($allEntriesBeforeOrphanCleanup as $entry) {
        $entryid = (int)$entry->id;
        if (isset($entryHasUsedVersion[$entryid])) {
            $catid = (int)$entry->questioncategoryid;
            $survivingEntryCounts[$catid] = ($survivingEntryCounts[$catid] ?? 0) + 1;
        }
    }

    [$wouldDeleteCategories, $protectedCategories] = mqbc_simulate_empty_category_cleanup(
        $categoriesBefore,
        $survivingEntryCounts
    );

    $categoriesDeletedOrWouldDelete = count($wouldDeleteCategories);
    $categoriesProtected = count($protectedCategories);

    foreach ($wouldDeleteCategories as $catid) {
        $categoryReport[] = [
            $catid,
            mqbc_category_path($catid, $categoriesBefore),
            'WOULD_DELETE_EMPTY_CATEGORY',
        ];
    }
    foreach ($protectedCategories as $catid => $reason) {
        $categoryReport[] = [
            $catid,
            mqbc_category_path((int)$catid, $categoriesBefore),
            'KEEP_PROTECTED_' . $reason,
        ];
    }
} else {
    $manager = new \core_question\category_manager();
    $deletedCategoryIds = [];
    $protectedCategoryIds = [];

    do {
        $deletedThisPass = 0;
        $categoriesNow = mqbc_load_categories();

        foreach ($categoriesNow as $catid => $cat) {
            $catid = (int)$catid;
            if ((int)$cat->parent === 0) {
                continue; // Never try to delete top categories.
            }
            if ($DB->record_exists('question_bank_entries', ['questioncategoryid' => $catid])) {
                continue;
            }
            if ($DB->record_exists('question_categories', ['parent' => $catid])) {
                continue;
            }

            try {
                $manager->delete_category($catid);
                $deletedCategoryIds[$catid] = true;
                $deletedThisPass++;
            } catch (Throwable $e) {
                // Normally the mandatory last child under a top category.
                $protectedCategoryIds[$catid] = $e->getMessage();
            }
        }
    } while ($deletedThisPass > 0);

    $categoriesDeletedOrWouldDelete = count($deletedCategoryIds);
    $categoriesProtected = count($protectedCategoryIds);

    foreach (array_keys($deletedCategoryIds) as $catid) {
        $categoryReport[] = [
            $catid,
            mqbc_category_path((int)$catid, $categoriesBefore),
            'DELETED_EMPTY_CATEGORY',
        ];
    }
    foreach ($protectedCategoryIds as $catid => $message) {
        // Only report it as protected if it still exists and is still empty.
        if ($DB->record_exists('question_categories', ['id' => $catid])
                && !$DB->record_exists('question_bank_entries', ['questioncategoryid' => $catid])
                && !$DB->record_exists('question_categories', ['parent' => $catid])) {
            $categoryReport[] = [
                $catid,
                mqbc_category_path((int)$catid, $categoriesBefore),
                'KEEP_PROTECTED_BY_MOODLE: ' . $message,
            ];
        }
    }
}

mqbc_write_csv(
    $reportdir . '/categories.csv',
    ['category_id', 'category_path', 'action'],
    $categoryReport
);

// -----------------------------------------------------------------------------
// POST-FLIGHT: verify exact quiz mapping did not change.
// -----------------------------------------------------------------------------
[$afterSlots, $afterCsv, $afterAnomalies] = mqbc_quiz_slot_snapshot();
mqbc_write_csv(
    $reportdir . '/quiz_slots_after.csv',
    ['courseid', 'quizid', 'quizname', 'slot', 'slotid', 'normal_refs', 'set_refs', 'qbe_ids', 'versions'],
    $afterCsv
);

$beforeCanonical = mqbc_canonical_quiz_mapping($beforeSlots);
$afterCanonical = mqbc_canonical_quiz_mapping($afterSlots);
$quizMappingUnchanged = ($beforeCanonical === $afterCanonical);

$summary = [];
$summary[] = ['mode', $apply ? 'APPLY' : 'DRY_RUN'];
$summary[] = ['moodle_release', $CFG->release ?? 'unknown'];
$summary[] = ['quiz_slots_before', count($beforeSlots)];
$summary[] = ['quiz_slot_preflight_anomalies', count($slotAnomalies)];
$summary[] = ['question_versions_would_delete', $wouldDeleteVersions];
$summary[] = ['question_versions_deleted', $deletedVersions];
$summary[] = ['question_versions_kept_in_use', $keptVersions];
$summary[] = ['question_versions_unexpectedly_retained_after_api_call', $unexpectedRetained];
$summary[] = ['orphan_bank_entries', count($orphanEntries)];
$summary[] = [$apply ? 'categories_deleted' : 'categories_would_delete', $categoriesDeletedOrWouldDelete];
$summary[] = ['categories_protected_or_retained', $categoriesProtected];
$summary[] = ['category_actions_reported', count($categoryReport)];
$summary[] = ['quiz_mapping_unchanged', $quizMappingUnchanged ? 'YES' : 'NO'];
$summary[] = ['report_directory', $reportdir];

mqbc_write_csv($reportdir . '/summary.csv', ['key', 'value'], $summary);

mtrace('------------------------------------------------------------');
mtrace('SUMMARY');
mtrace('Question versions that are unused and eligible for deletion: ' . $wouldDeleteVersions);
mtrace('Question versions currently in use and kept: ' . $keptVersions);
if ($apply) {
    mtrace('Question versions actually deleted: ' . $deletedVersions);
    mtrace('Unexpectedly retained after Moodle delete API: ' . $unexpectedRetained);
}
mtrace('Orphan question-bank entries: ' . count($orphanEntries));
mtrace(($apply ? 'Empty categories deleted: ' : 'Empty categories eligible for deletion: ') . $categoriesDeletedOrWouldDelete);
mtrace('Empty categories protected/retained: ' . $categoriesProtected);
mtrace('Category actions reported: ' . count($categoryReport));
mtrace('Exact quiz-slot mapping unchanged: ' . ($quizMappingUnchanged ? 'YES' : 'NO'));
mtrace('Reports written to: ' . $reportdir);
mtrace('------------------------------------------------------------');

if (!$quizMappingUnchanged) {
    cli_error(
        "CRITICAL: quiz-slot mapping changed. Leave Moodle in maintenance mode and restore/investigate before reopening the site.\n" .
        "Compare quiz_slots_before.csv and quiz_slots_after.csv in {$reportdir}",
        2
    );
}

if ($afterAnomalies && !$slotAnomalies) {
    cli_error('CRITICAL: new quiz-slot anomalies appeared during the run.', 2);
}

if ($apply) {
    mtrace('APPLY completed. Keep maintenance mode enabled until you review summary.csv and quiz_slots_after.csv.');
} else {
    mtrace('DRY-RUN completed. Nothing was changed. Review question_versions.csv, categories.csv and summary.csv.');
}

exit(0);
