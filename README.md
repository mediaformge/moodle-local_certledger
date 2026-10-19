# Certificate Ledger

**Sequential numbers and a permanent ledger for the certificates issued with
[Custom certificate](https://moodle.org/plugins/mod_customcert).**

Many organisations must be able to say, at any time, which certificates they issued, to whom, when, and whether
each one is still valid: training providers, professional bodies, safety and compliance courses, accredited
programmes. Custom certificate prints a random code on every certificate. Certificate Ledger replaces it with a
sequential number such as `CERT-2026-000042` and records every certificate in a ledger that managers can search
and export.

## Features

- **Sequential numbering when the certificate is issued**, before the PDF is generated. The number is printed by
  the standard **Code** and **QR code** elements of the template. It is also checked on the Custom certificate
  verification page.
- **Codes that cannot be guessed.** A few random check characters (`CERT-2026-000042-K7QXM`) stop anyone from
  finding other people's certificates by trying consecutive numbers on the public verification page.
- **Configurable format**:
  - prefix;
  - yearly series, restarting from 1 every year;
  - number of digits;
  - number of check characters.
- **A ledger that keeps everything.** Every certificate is recorded with a snapshot of the learner, their cohorts,
  the course and the certificate as they were at issue time. A number is never reused: annulled and deleted
  certificates stay in the ledger with their status.
- **Search and export.** Filter by year, course and status. Search by number, code, name or email. Export in every
  enabled format (CSV, Excel, ODS, PDF, ...).
- **Annulment with a reason.**
  - Annulling deletes the certificate and any copy made by a course restore, so it no longer verifies.
  - An optional block stops Custom certificate from issuing a new one to the same learner.
- **Self-healing.** A scheduled task (every 10 minutes) reconciles the ledger with the issued certificates. It numbers
  anything that was missed, tracks deletions, re-links restored activities and puts back changed codes.
- **Safe under load.** Numbers are assigned under a Moodle lock and protected by unique database indexes.
- **Privacy API.**
  - Personal data export covers the ledger.
  - Deletion requests anonymise the learner by default and keep the numbering complete.
- **Audit trail.** Number assigned, ledger viewed, ledger exported and certificate annulled are logged as Moodle
  events.
- **No changes to Custom certificate.** The plugin only uses its public events and its issues table.

## Requirements

- Moodle 5.0 or later.
- Custom certificate (`mod_customcert`) for the same Moodle version.
- PostgreSQL, MariaDB or MySQL (the test suite runs on PostgreSQL and MariaDB).

## Installation

Install it like any other plugin:

- **From the Moodle plugins directory:** upload the ZIP in *Site administration > Plugins > Install plugins*.
- **From Git:** clone into `local/certledger`, or `public/local/certledger` on Moodle 5.1 and later:

  ```
  git clone https://github.com/mediaformge/moodle-local_certledger.git public/local/certledger
  ```

Then complete the upgrade from *Site administration > Notifications*, or run `php admin/cli/upgrade.php`.

Certificates issued from the moment of installation are numbered automatically. Certificates issued before can be
numbered with the command line script, see [Command line](#command-line).

## Getting started

1. **Settings:** *Site administration > Plugins > Local plugins > Certificate Ledger*.
   - Choose the prefix, the yearly series, the digits and the check characters.
   - Changes only apply to the certificates issued afterwards.
2. **Template:** add the **Code** element to your certificate templates, and the **QR code** element if you want it.
   They print the number.
3. **Ledger:** *Site administration > Reports > Certificate ledger* (`/local/certledger/index.php`).

Custom certificate issues a certificate when the learner downloads it from the activity. It can also issue it
automatically: when emailing students is enabled, or with the "issue automatically" option of recent versions.
The number is assigned in every case.

## Numbers and codes

| Setting | Example number | Example printed code |
|---|---|---|
| Default: prefix `CERT`, yearly series, 6 digits, 5 check characters | `CERT-2026-000042` | `CERT-2026-000042-K7QXM` |
| Without yearly series | `CERT-000042` | `CERT-000042-K7QXM` |
| No check characters (not recommended) | `CERT-2026-000042` | `CERT-2026-000042` |

- The check characters never contain `0/O` or `1/I/L`, so the code can be read aloud or typed from paper without
  mistakes.
- Changing the prefix or the yearly series starts a new series. The numbers already assigned stay valid.
- **Numbers are never reused.** Before assigning a number, the plugin also looks at the codes already printed on
  issued certificates. A printed number is therefore never assigned twice, even if the ledger table was lost or
  restored from an older backup.

## Certificate statuses

| Status | Meaning |
|---|---|
| **Valid** | The certificate exists and can be downloaded and verified. |
| **Annulled** | Annulled from the ledger, with a reason. The certificate was deleted from the site. |
| **Removed from the site** | Deleted in some other way: from the activity report, by deleting the activity or the course, by a course reset. |

### Annulment and block

Annulling a certificate deletes it from the site, together with any copy created by a course restore. A verification
of its code then fails, exactly as for a code that never existed. The reason is only visible in the ledger.

Custom certificate would then issue a new certificate, with a new number:

- as soon as the learner opens the activity again;
- or within a minute, when emailing students or automatic issuing is on.

The annulment form therefore offers a **block**, on by default. The block is a technical role, *Certificate blocked
(Certificate Ledger)* (shortname `certledger_blocked`):

- it is assigned to the learner in the activity context, and it prohibits `mod/customcert:receiveissue`;
- it is created automatically the first time it is needed;
- it is also recorded in the ledger, so if Moodle drops the role assignment (for example when the learner is
  unenrolled and enrolled again), the scheduled task puts it back;
- it is lifted from the ledger (**Lift the block**);
- uninstalling the plugin removes the role and its assignments.

## Capabilities

| Capability | Default | Allows |
|---|---|---|
| `local/certledger:view` | Manager | Viewing and exporting the ledger (it contains personal data) |
| `local/certledger:annul` | Manager | Annulling certificates and lifting blocks |

The **PDF** link in the ledger is shown only to users who can see the issued certificates report of the activity
(`mod/customcert:viewreport`).

## Scheduled reconciliation

The *Certificate ledger reconciliation* task runs every 10 minutes. It is safe to run at any time. In order, it:

1. **Re-links** a certificate whose issue disappeared and came back with the same code (activity restored from the
   recycle bin or from a backup). It becomes valid again, keeping its number, date and snapshot.
2. **Deletes copies of annulled certificates**, so an annulled certificate never becomes valid again.
3. **Restores blocks** that Moodle dropped.
4. **Records certificates that already carry one of its numbers** but are missing from the ledger (plugin
   reinstalled, table restored from an older backup). Their printed code never changes.
5. **Numbers certificates** that, for any reason, were not numbered when issued.
6. **Marks as removed** certificates deleted without an event (course reset, privacy deletion).
7. **Marks as valid again** a certificate marked as removed whose deletion did not complete.
8. **Puts the number back** on a certificate whose code was changed.

**Copies of valid certificates**, for example made by restoring a course with user data, are not numbered again and
are not recorded. They keep the original code. The task counts them ("copies left").

Problems found by the task are written to the task log. They are also shown on the ledger page until a run without
errors. The page also warns when issued certificates are still waiting for a number.

## Command line

From the Moodle directory (`public/` exists from Moodle 5.1):

```
# List the certificates that are not in the ledger, without changing anything
php public/local/certledger/cli/backfill.php --all

# Number them. Those that already carry one of our numbers are recorded with that number.
php public/local/certledger/cli/backfill.php --all --run

# Only the certificates issued from a date
php public/local/certledger/cli/backfill.php --since=2026-01-01 --run
```

## Privacy

The plugin implements the Moodle Privacy API.

- **Export.** A user's data export includes their certificates in the ledger and the annulments they made.
- **Deletion (default).** An approved deletion request anonymises the learner in the ledger: name, email, cohorts
  and annulment reasons are removed. Number, course, dates and status stay, so the numbering remains complete.
- **Keeping the ledger as a record.** A site that must keep the ledger, for example by law, can disable
  *Anonymise on deletion requests* in the settings. It should state this in its privacy notice.

The ledger lives in the system context. With the data registry configured, Moodle does not include the system
context in deletion requests (standard Moodle behaviour).

## How it works

Custom certificate has no API to supply its own codes, so the plugin writes the number into the `code` column of
`customcert_issues`. This happens in the observer of the `\mod_customcert\event\issue_created` event, which runs as
soon as the issue is created:

- **Outside a database transaction**, which is the normal case, the observer runs immediately, before Custom
  certificate generates the PDF. If the event is triggered inside a transaction, the observer runs right after the
  commit, still in the same request.
- **Why the `code` column.** It is the value that the Code and QR code elements print and that the verification page
  looks up.
- **The original random code** is kept in the ledger (`origcode`) for reference.

Custom certificate itself is never modified, so it can be upgraded independently.

**Known limitation.** The numbering is deferred to the scheduled task in two rare cases: the numbering lock cannot be
obtained, or the observer runs inside another transaction. A PDF downloaded before the task runs then carries the
random code of Custom certificate. The task replaces it with the number within 10 minutes, and downloading the
certificate again gives the numbered PDF.

## Backup, restore and uninstall

- **Backups.** The ledger is site data: it is not part of course backups, so include it in your database backups.
  After restoring the whole database from an older backup, the scheduled task records again, with their numbers,
  the certificates issued after that backup.
- **Uninstalling** saves the whole ledger as `moodledata/local_certledger/ledger-<date>.csv` before dropping the
  table. The numbers already printed stay on the certificates, and a later reinstallation records them again.

## Development

The plugin ships with PHPUnit and Behat tests. GitHub Actions runs them on PostgreSQL and MariaDB with
[moodle-plugin-ci](https://moodlehq.github.io/moodle-plugin-ci/), together with the Moodle coding style checks.

```
vendor/bin/phpunit --testsuite local_certledger_testsuite
vendor/bin/behat --tags=@local_certledger
```

Bug reports and pull requests are welcome on
[GitHub](https://github.com/mediaformge/moodle-local_certledger/issues).

## License

Copyright 2026 [Mediaform](https://www.media-form.it).

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later
version. This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the
implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the [GNU General Public
License](LICENSE) for more details.
