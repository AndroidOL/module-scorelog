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
- Move through missing scores with Enter or Alt+N and optionally show only
  missing students in the selected assessment column.
- Select students explicitly, preview affected counts, then bulk fill or clear
  their score in the selected column with revision-bound undo.
- Fill only blank scores by default; overwriting existing scores requires an
  explicit checkbox and confirmation. Clear always asks for confirmation.
- Reuse a previous assessment's structure without copying scores or comments.
- Save a private draft and resume it later without losing whole-class input.
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

Bulk fill, clear and undo operate only on the live draft form. They do not
write a Formal Assessment until the normal Review and Publish flow succeeds.
Undo is discarded when the draft revision changes or a bulk-affected score is
edited manually.

Bulk actions target only visible checked students, and rows hidden by Only
Missing are automatically unchecked.

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
Apply to Draft updates only the private draft. Existing manual values are
preserved unless overwrite is explicitly selected. Formula cells are rejected
without evaluation, and rows are limited to 500 with a 2 MiB file limit.

Every import and template download targets the draft's assessment.

## Private comment library

Each teacher can maintain private reusable comments from the Score Log header.
Library records are creator-bound and can be inserted into any comment drawer
in a draft. Inserting a library comment appends it with a line break and never
replaces hand-written text. Adding, editing and deleting comments uses
CSRF-protected POST actions.

The structure-copy action copies an existing assessment's name, description,
type, date and visibility into the current draft. Scores and comments are never
copied.

The XLSX template is prefilled with the current draft scores and comments. When
overwrite is enabled, deliberately blank score or comment cells clear those
draft values; import previews show those clears before Apply.

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
