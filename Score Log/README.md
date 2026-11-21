# Score Log for Gibbon

Score Log provides a compact workflow for teachers to draft, review and publish
Gibbon internal assessments for a whole class on one screen.

## Requirements

- Gibbon 31.0.00 or later
- PHP 8.2 or later
- MySQL 8 compatible server

## Installation

1. Copy the `Score Log` directory into `modules/`.
2. Sign in as an administrator and open **System Admin → Manage Modules**.
3. Install or update the module.
4. Grant the action to the intended staff roles.

Users also need the Formal Assessment permissions to create internal
assessments and to enter their scores. Both permissions are checked on every
workflow that reads or changes score data.

## Features

- Choose a class the user teaches and an active attainment scale.
- Enter scores or scale grades and comments for every student in one form.
- Resolve every numeric or grade mark to an existing grade on the selected
  official Gibbon scale.
- Create one assessment per draft using Gibbon's standard form, form-table and
  grade-scale controls.
- Enter a score or grade on each student row. Enter moves to the next visible
  row and wraps to the first missing row.
- Filter the roster by All, Missing or Invalid using the same score-validity
  rules as the server review.
- Reuse a previous assessment's structure without copying scores or comments.
- Save a private draft and resume it later without losing whole-class input.
- Autosave private drafts on the server with visible saving, offline and
  revision-conflict states.
- Insert private reusable comments from a per-teacher comment library.
- Open the dedicated Saved Drafts page to resume or discard private work.
- Import scores from CSV, XLSX or a spreadsheet paste with mapping preview.
- Review current-roster changes, invalid rows and missing scores before publishing.
- Validate class membership, student eligibility, score ranges and assessment types.
- Store a complete internal assessment with student and parent visibility.
- Require explicit confirmation before publishing students without a score.
- Link directly to the full Formal Assessment mark-entry screen when permitted.

## Drafts and publishing

Drafts are stored only in the module-owned `score_log_draft` table. No Formal
Assessment column exists until publication, so drafts cannot appear in Formal
Assessment lists or be edited by other users. Draft records are accessible only
to their creator and never appear to students, parents or reports.

The class and assessment scale are fixed after a draft is created. Each save
carries a revision number so a stale tab cannot overwrite newer work. Reopening and
publishing re-resolve the current reportable roster; added and removed students
are shown explicitly. Publishing always goes through a review page. A creator
can permanently discard their own draft with a CSRF-protected POST.

## Autosave and recovery

The standard single-assessment form debounces changes and saves them to the
private server-side draft using the current revision. The editor displays
Unsaved, Saving, Saved, Offline, authentication and Conflict states. A stale
tab receives a conflict instead of overwriting a newer revision.

New drafts are protected by a per-editor key, an advisory database lock and a
monotonic sequence so duplicate requests cannot create or overwrite another
draft. After the first autosave, the draft ID is added to the browser URL.
Reconnect retries pending changes, while authentication, validation and server
errors remain distinct from network loss.

Manual Save, Review, Import, Template Export and Score Export flush any pending
autosave first.
Autosave never triggers Review or Publish, and browser storage is not used as an
authoritative score store.

## Assessment dates

The completion date controls when students, parents and reports can see the
assessment. Invalid dates are rejected rather than silently replaced.

If a current student has no score, publication requires an explicit confirmation
and the review page displays the exact missing-student count.

## Score import

Drafts support CSV, XLSX and tab-separated spreadsheet paste. Two columns are
enough when the first contains a student ID, username or exact normalized name
and the second contains the score. Optional headers and a third comment column
are detected automatically. The mapping can be corrected before applying.

Import never changes the draft during upload. The parsed preview shows matched,
unknown, ambiguous, duplicate, invalid, fill and overwrite rows first. Clicking
Apply to Draft updates only the private draft. Blank imported score or comment
cells are skipped; every nonblank imported value replaces the current draft
value. Formula cells are rejected without evaluation, and rows are limited to
500 with a 2 MiB file limit.

Every import, template export and score export targets the draft's assessment.

## Private comment library

Each teacher can maintain private reusable comments from the Score Log header.
Library records are creator-bound and can be inserted into any comment drawer
in a draft. Inserting a library comment appends it with a line break and never
replaces hand-written text. Adding, editing and deleting comments uses
CSRF-protected POST actions.

The structure-copy action copies an existing assessment's name, description,
type, date and visibility into the current draft. Scores and comments are never
copied.

The XLSX template contains the current roster plus a separate Assessment sheet
with the assessment name, description and date, while the score and comment
columns are blank for editing. Export Scores contains the same roster and
metadata with the current draft values. The import path reads only the first
Scores sheet and does not require assessment configuration fields. Blank cells
never clear a saved score or comment.

## Formal Assessment compatibility

Score Log writes the documented Gibbon v31 Formal Assessment column and entry
fields without changing official schema. A detailed field-by-field matrix,
permission contract and visibility behavior is in
[FORMAL-ASSESSMENT-COMPATIBILITY.md](FORMAL-ASSESSMENT-COMPATIBILITY.md).

## Development

Run the helper tests from the Gibbon repository root:

```sh
vendor/bin/phpunit --bootstrap vendor/autoload.php \
  "modules/Score Log/tests/ModuleFunctionsTest.php"
```

## License

GPL-3.0
