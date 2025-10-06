# Maintenance scripts

## setReadingListHiddenPreference.php

Maintenance script to set the `readinglists-web-ui-enabled` hidden preference for users who meet the experiment criteria.

NOTE: The script is intended as a temporary solution to setup eligible users for the Wikimedia Reading Lists experiment, which makes ReadingLists available via an add/remove button on aritcle pages.

* [T397532 - [Hypothesis] FY2025-26 WE 3.3.4 Reading Lists on web](https://phabricator.wikimedia.org/T397532)
* [T402231 - [Reading Lists] Create hidden user preference to scope Reading Lists web UI to specific logged-in users](https://phabricator.wikimedia.org/T402231)

### Usage

```bash
php maintenance/setReadingListHiddenPreference.php users.txt
```

#### Input from stdin

```bash
echo -e "123\n456\n789" | php maintenance/setReadingListHiddenPreference.php
```

#### Example file

`users.txt`

```
2
3
11
15
18
```

### Options

- `--global-ids` - Input IDs in the file are global/central IDs instead of local wiki user IDs
- `--batch-size <number>` - Number of users to process in each batch (default: 500)
- `--start-id <number>` - Start processing from this user ID (inclusive)
- `--to-id <number>` - Stop processing at this user ID (inclusive)
- `--skip-verify` - Enable the user preference for the specified user ids without the script doing extra validation.
- `--dry-run`
- `--verbose`

### Examples

```bash
php maintenance/setReadingListHiddenPreference.php users.txt \
  --start-id 1000 \
  --to-id 2000 \
  --batch-size 100 \
  --verbose
```

```bash
php maintenance/setReadingListHiddenPreference.php users.txt \
  --skip-verify
```
