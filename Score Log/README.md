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

Users also need the Formal Assessment permission to create internal assessments.

## Features

- Choose a class the user teaches and an active attainment scale.
- Enter scores or scale grades and comments for every student in one form.
- Add up to ten assessment columns with independent type, scale, date and visibility.
- Save a private draft and resume it later without losing whole-class input.
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

The class is fixed after a draft is created; assessment columns keep their own
scales and settings. Each save carries a revision number so a stale tab cannot overwrite newer work. Reopening and
publishing re-resolve the current reportable roster; added and removed students
are shown explicitly. Publishing always goes through a review page. A creator
can permanently discard their own draft with a CSRF-protected POST.

Multi-assessment drafts share one class and roster but keep separate assessment
names, types, scales, dates and visibility settings. Publish creates every
selected Formal Assessment column and entry set in one transaction or leaves no
published column behind.

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

In a multi-assessment draft, every import and template download targets one
explicitly selected assessment column.

The XLSX template is prefilled with the current draft scores and comments. When
overwrite is enabled, deliberately blank score or comment cells clear those
draft values; import previews show those clears before Apply.

## Development

Run the helper tests from the Gibbon repository root:

```sh
vendor/bin/phpunit --bootstrap vendor/autoload.php \
  "modules/Score Log/tests/ModuleFunctionsTest.php"
```

## License

GPL-3.0
