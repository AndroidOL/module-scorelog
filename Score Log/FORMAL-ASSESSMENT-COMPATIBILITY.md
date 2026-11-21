# Formal Assessment Compatibility

Audited against Gibbon `31.0.00` on 2026-11-21.

Score Log is a workflow layer over the official Formal Assessment tables. It
does not create or alter formal-assessment schema. Its private state is limited
to module-owned `score_log_draft` and `score_log_comment` tables.

## Core column mapping

| Field | Official v31 contract | Score Log publication |
| --- | --- | --- |
| `gibbonCourseClassID` | One class belongs to the column | The draft's validated current-year class |
| `groupingID` | Groups columns created together; allocated while the column table is locked | One unique positive ID per published assessment, allocated while coordinating with the official table lock |
| `name` | Required, `varchar(30)` | Required and limited to 30 characters |
| `description` | Required by both official add/edit processes | Required and limited to 1000 characters |
| `type` | Required value from Formal Assessment settings | Required value from the same `internalAssessmentTypes` setting |
| `attachment` | Non-null string | Empty string; upload is not part of this workflow |
| `attainment` / `gibbonScaleIDAttainment` | `Y` when attainment is assessed | Always `Y`, using an active selected scale |
| `effort` / `gibbonScaleIDEffort` | May be enabled or disabled | `N` / `NULL`; Score Log does not capture effort |
| `comment` / `uploadedResponse` | Independent yes/no switches | `Y` / `N`; comments are supported, file responses are not |
| `complete` / `completeDate` | A date sets `complete=Y`; an empty date sets `complete=N` | The required assessment date produces `Y` and the same ISO date |
| `viewableStudents` / `viewableParents` | Required `Y/N` values | Required per-column `Y/N` values |
| creator / last editor IDs | Current teacher IDs | Current teacher ID for both fields |

## Core entry mapping

| Field | Official v31 contract | Score Log publication |
| --- | --- | --- |
| Column and student IDs | Existing core column plus the reportable student | Current-rostered student selected at review |
| `attainmentValue` | Exact `gibbonScaleGrade.value` | Exact matching scale value, including `%` when present in the scale |
| `attainmentDescriptor` | Descriptor resolved from `gibbonScaleGrade` | Same scale descriptor |
| `effortValue` / `effortDescriptor` | Optional | `NULL` / `NULL` |
| `comment` | Optional text | Draft comment, including a blank comment |
| `response` | Optional upload path | `NULL` |
| last editor ID | Current teacher ID | Current teacher ID |

An entered numeric mark must resolve to an existing scale-grade row. This
matches the official write path, which rejects a missing scale grade. Score Log
no longer invents an arbitrary numeric descriptor when no official row exists.

## Downstream visibility

The values map directly to the queries used by:

- Formal Assessment manage, edit and write-data screens
- Student profile internal-assessment records
- Internal Assessment and Internal Assessment by Course report data sources
- Tracking data points and graphs
- Student and parent visibility checks

Students and parents only see a column when its `viewableStudents` or
`viewableParents` value is `Y` and its completion date has arrived. Reports use
the same `complete='Y'` and `completeDate<=today` contract. A future date
therefore publishes the column for staff while keeping it hidden from students,
parents and reports until that date.

## Permissions and settings

- Users need the Score Log action.
- Creating and editing core columns requires
  `internalAssessment_manage_add.php`.
- Reading and writing scores requires
  `internalAssessment_write_data.php`.
- A teacher must hold the `Teacher` role for the selected current-year class,
  unless the deployment grants the formal-assessment all-classes permission.
- Assessment types come from the Formal Assessment
  `internalAssessmentTypes` setting.
- Scales come from active `gibbonScale` rows and grade descriptors from
  `gibbonScaleGrade`.

## Forms and gateway use

Class selection, draft discard, import, review and publish confirmation use
Gibbon's `Form` API. Class selection also uses `DatabaseFormFactory`. The
multi-column entry grid is rendered directly because it composes several
independent assessment columns in one responsive table; its controls use the
official Gibbon form classes, the server uses Gibbon's `Validator` and CSRF
tokens, and its persistence follows the same SQL shape as the official
Formal Assessment processes.

The private draft and comment library remain entirely module-owned. Reusing an
assessment structure copies only configuration fields and never copies scores
or comments. No draft is visible to Formal Assessment, reports, students or
parents until an atomic publication succeeds.

## Verification status

Focused tests cover canonical column and entry values, exact scale-grade
resolution, completion/date semantics, permission parity, unique grouping-ID
allocation and one-transaction rollback. Authenticated browser interaction and
a forced MySQL failure remain deployment smoke-test items.
