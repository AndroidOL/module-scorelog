# Score Log Roadmap

## Released scope

- Whole-class internal assessment creation.
- Numeric and grade-scale entry with configurable type validation.
- Class membership, student eligibility, score and date validation.
- Formal Assessment integration and recent-assessment history.
- Private drafts, roster revalidation, review and safe publication.

## Next candidates

- Replace the global advisory lock with a dedicated grouping sequence if
  profiling shows the current serialisation is a bottleneck.
- Add configurable maximum-score metadata instead of parsing scale descriptions.
- Add acceptance tests for each supported scale type.
- Add notification and audit hooks after successful creation.
