# Score Log for Gibbon

Score Log provides a compact workflow for teachers to create a completed Gibbon
internal assessment and enter marks for a whole class on one screen.

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
- Validate class membership, student eligibility, score ranges and assessment types.
- Store a complete internal assessment with student and parent visibility.
- Link directly to the full Formal Assessment mark-entry screen when permitted.

## Assessment dates

The completion date controls when students, parents and reports can see the
assessment. Invalid dates are rejected rather than silently replaced.

## Development

Run the helper tests from the Gibbon repository root:

```sh
vendor/bin/phpunit --bootstrap vendor/autoload.php \
  "modules/Score Log/tests/ModuleFunctionsTest.php"
```

## License

GPL-3.0
