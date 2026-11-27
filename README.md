# DegreeDrishti Learning Hub

Plain PHP 8, MySQL/PDO, vanilla JavaScript, and custom CSS. No Composer or Node.js setup is required.

## Local testing with Docker Desktop on Windows

Start Docker Desktop, then run `powershell -NoProfile -ExecutionPolicy Bypass -File ".\start-local.ps1"` from the project folder (the folder containing `htdocs`). The script creates an isolated copy at `%USERPROFILE%\ddlearninghub-docker-local`, a local MariaDB database, and a PHP/Apache container. Docker chooses an available loopback port; the script prints and opens the local URL and prints the local-only admin credentials.

Run the same command again to restart the environment without reimporting or resetting data. The test copy is a snapshot: later edits to the source files are not automatically copied. Existing uploaded PDFs are not copied. The local configuration and passwords are for testing only; never upload them to production.

To stop without losing test data, run `docker stop ddlearninghub-local-web ddlearninghub-local-db`. If setup fails, inspect the error and the containers before removing anything; the script will not reset a partial database. A previously blocked, never-started web container can be preserved as `ddlearninghub-local-web-blocked` while a replacement is started on an available port. To request a specific port use `-Port 8081`; omit it on subsequent runs to use the container's existing port.

### Optional local sample data

The sample installer runs **only** against the isolated Docker environment created by `start-local.ps1`. It does not seed a hosted site. Everything it adds is fictional, including training, fees, contacts, policies, acknowledgements and certificates; none is company guidance or a real compliance record. Never upload the local snapshot or restore its backup to a production database.

1. Back up your local database and test files first. Start Docker Desktop and run `powershell -NoProfile -ExecutionPolicy Bypass -File .\start-local.ps1` from the project folder. Migrations 002 through 006 and the five original category slugs must already exist in the local database. A new setup imports them automatically; existing snapshots are not upgraded automatically. See the migration instructions below if your snapshot predates them, and do not rerun an already applied migration.
2. To see the grouped navigation and refreshed styling in an **existing** snapshot, first preserve any local edits to these files, then copy the updated files from the project folder:

   ```powershell
   $dest = Join-Path $env:USERPROFILE 'ddlearninghub-docker-local'
   @('includes\header.php', 'login.php', 'verify.php', 'assets\css\theme.css',
     'assets\css\app.css', 'assets\js\app.js') | ForEach-Object {
       Copy-Item -LiteralPath (Join-Path '.\htdocs' $_) -Destination (Join-Path $dest $_) -Force
   }
   ```

   If you used a custom `-TestDirectory` with `start-local.ps1`, set `$dest` to that directory instead. This does not copy the source configuration or PDFs. Fresh snapshots already contain the new navigation.
3. Run `powershell -NoProfile -ExecutionPolicy Bypass -File .\add-sample-data.ps1` from the project folder. For a custom test directory, add `-TestDirectory "C:\your\test\directory"`. The script checks the local containers, mount and database configuration before inserting data. It does not reset or replace existing records.
4. Save the randomly generated shared demo password printed once by the installer. Sign in as `demo.sales@example.test` or `demo.hr@example.test`. If you lose the password, sign in as the local admin and use **People > Users > Reset password**. The local admin credentials are printed by `start-local.ps1` unless you changed them.

The installer adds five published and one draft lesson, a quiz, sample progress and a demo certificate, a policy with current and previous-version acknowledgements, general and Sales learning paths, checklists, fictional university fees and PDFs, contacts, holidays, and additional library examples. Migration 005 already supplies five example records per library type. The sample YouTube video is a public player example, not a training video; playback depends on YouTube. The Sales demo account has an overdue POSH quiz available to attempt; the HR demo account has a sample passed attempt.

Running the installer again checks its database marker and makes no changes. It does not repair individually edited or deleted samples. If a demo email or employee code already exists, or a required table is missing, it stops without replacing the existing record; its database transaction is rolled back and newly created PDFs are removed. An abrupt container or machine shutdown may leave unreferenced sample PDFs, so inspect the test files after an interruption. Check the error before retrying. Do not remove the marker to force a reinstall: this could create duplicate examples.

Sample and navigation test checklist:

1. At desktop and mobile widths, open the grouped menus for both roles. Check every destination, the active group, keyboard Enter/Escape, the mobile menu and sign out. At short screen heights, scroll the mobile menu to reach its last links.
2. Sign in as the Sales demo employee. Check the overdue mandatory training, pending policy banner, partial path and checklist progress, and continue-learning card. Open the path and confirm its draft lesson is hidden.
3. Open the demo POSH lesson, fail the quiz, retry with both correct answers, and open the certificate. Check its public verification link. The HR demo account already has a passed attempt and a sample certificate. Test accounts and certificates are not compliance evidence.
4. Download the lesson handout, policy PDF and fictional fee PDF. Submit the policy without ticking its checkbox, then acknowledge it. Confirm the dashboard and policy report update. Replace the policy PDF as admin and confirm the new version is pending again.
5. Check the fictional university's fresh and outdated fee rows; search contacts, inspect the past/upcoming holidays, update the checklist and try library tabs, placeholder previews, copy, tags and the outdated warning.
6. As admin, check Training, Resources, People, and Reports & backups. Review the POSH and overdue reports and their CSV exports, policy acknowledgements and CSV, path ordering and assignments, draft lessons and library items, and the backup page. Add a Sales employee and verify the general and Sales paths are automatically assigned. Rerun the installer and confirm it reports that samples are already installed without duplicating them.

## InfinityFree deployment

### 1. Create the MySQL database

1. Sign in to the InfinityFree control panel.
2. Open **MySQL Databases** and create a database and database user.
3. Record the database name, username, password, and the exact MySQL hostname shown in the panel. InfinityFree database names and users are usually prefixed; do not assume the hostname is `localhost`.

### 2. Import the schema

1. Open phpMyAdmin from the database section of the control panel.
2. Select the new database.
3. Choose **Import**, select `sql/schema.sql`, and run the import.
4. If upgrading a database created before the University Fees PDF field was added, check whether `universities.fee_structure_pdf` already exists. If it does not, run `sql/migrations/001_university_fee_structure_pdf.sql` once in phpMyAdmin. Do not run that migration on a fresh database created from the current schema.
5. Back up the database, then import `sql/migrations/002_quizzes_certificates_due_dates.sql` once in the same database before deploying the updated PHP files. This is required for both new and existing installations. MySQL may commit schema changes individually, so if an import fails partway through, inspect the database before retrying instead of rerunning the whole migration blindly.
6. Import `sql/migrations/003_policy_library.sql` once in the same database before deploying the policy library. Back up first. If import fails partway through, inspect the created tables before retrying.
7. Import `sql/migrations/004_learning_paths_onboarding.sql` once before deploying the learning paths and onboarding pages. Back up first, and inspect any partially created tables if the import fails.
8. Import `sql/migrations/005_scripts_templates_faq.sql` once before deploying the scripts and templates library. Back up first; the migration also adds 25 illustrative examples that an admin should review.
9. Import `sql/migrations/006_backup_settings.sql` once before deploying the backup pages. Back up first; inspect the database before retrying a failed import.

### 3. Upload the application

1. Connect to the hosting account with FTP.
2. Upload the **contents** of this project’s `htdocs` folder into the hosting `htdocs` folder.
3. Make sure the FTP client includes hidden files, especially `.htaccess` files. Keep the directory structure intact.
4. Keep `README.md` in `htdocs` if desired; the root `.htaccess` blocks web access to it.
5. Ensure `uploads/pdfs` is writable by PHP. Its `.htaccess` blocks direct web access and script execution; PDFs are delivered through authenticated download pages.

### 4. Configure the database

Edit `includes/config.php` on the server and replace the placeholders with the values from the control panel:

```php
define('DB_HOST', 'the-hostname-from-the-control-panel');
define('DB_NAME', 'the-prefixed-database-name');
define('DB_USER', 'the-prefixed-database-user');
define('DB_PASS', 'the-database-password');
```

Keep `BASE_URL` as an empty string when the app is installed directly in `htdocs`. If it is installed in a subdirectory, use that path with a leading slash, such as `/learninghub`, and update the `.htaccess` `ErrorDocument` paths to `/learninghub/403.php` and `/learninghub/404.php`.

`SESSION_COOKIE_SECURE` is enabled by default. Configure and use the site over HTTPS; do not disable this setting on the public site. Production error display is disabled in this file, while PHP errors remain eligible for the hosting error log.

### 5. Set the seeded admin password before activation

The schema seeds `admin@degreedrishti.com` as **inactive** with an unusable password placeholder. Do not activate that account until you have set a unique password hash.

On a trusted local machine with PHP CLI, save the following as `generate-hash.php` **outside the uploaded `htdocs` directory**:

```php
<?php
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($password === '') {
    fwrite(STDERR, "A password is required.\n");
    exit(1);
}
echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
```

Run `php generate-hash.php`, enter a strong unique password, and copy the generated hash. Delete the temporary script after use. In phpMyAdmin, select the application database and run this query, replacing the hash placeholder:

```sql
UPDATE users
SET password_hash = 'PASTE_THE_GENERATED_HASH_HERE',
    is_active = 1
WHERE email = 'admin@degreedrishti.com'
  AND role = 'admin';
```

Do not put the plaintext password in SQL, this README, or an uploaded PHP file. Sign in using the password you entered when generating the hash.

### 6. Verify the deployment

1. Visit the site using its HTTPS URL and sign in as the newly activated admin.
2. Confirm admin and learner pages load, then add a test employee and lesson.
3. Test a valid PDF upload and authenticated download. A direct URL under `/uploads/pdfs/` should be denied.
4. Visit a nonexistent URL and an access-denied resource to check the themed 404 and 403 pages.
5. Confirm errors are not displayed in the browser. Review the hosting PHP error log if the application reports a server-side problem.

InfinityFree may impose PHP upload-size or execution limits. The application rejects PDFs larger than 10 MB and requires PHP's `fileinfo` extension. To permit PDFs up to 10 MiB, configure `upload_max_filesize` to at least `10M` and `post_max_size` above that (for example `12M`); test uploads on the host before relying on them.

## Security and operations

- Keep database credentials in `includes/config.php`; never post or commit them.
- `includes/.htaccess`, `sql/.htaccess`, the root `.htaccess`, and `uploads/pdfs/.htaccess` rely on Apache access rules. If the server ignores `.htaccess`, configure equivalent restrictions before deployment.
- Create database backups from the hosting control panel regularly. Compliance CSV exports and database backups are not automatically archived by the application.

## Quizzes, certificates, and due dates

- Use the **Quiz** section on the lesson edit page to set a pass mark and optional maximum attempts, add or edit questions, or import CSV. The CSV headers, in order, are `question,option_a,option_b,option_c,option_d,correct_option,explanation,sort_order`; `correct_option` must be `a`, `b`, `c`, or `d`. Imports are all-or-nothing, with a limit of 500 questions per quiz and 2 MB per file. Add questions before asking employees to take a quiz.
- Questions and the pass mark are locked once anyone attempts the quiz; the attempt limit can still be changed. Quiz attempts and certificates prevent deleting their lesson so compliance records are not silently lost. Existing lesson completions are preserved when a quiz is added, but new completions require a passing score. Employees with an existing completion can take the quiz to earn a certificate.
- A certificate is issued only when an employee passes a quiz for a mandatory lesson; it preserves the employee name and lesson title at issuance. The employee can open it and use the browser's **Print / save as PDF** control. Public verification confirms only that a number exists; it does not independently verify the name or lesson printed on a certificate.
- Set a joining date for each employee and a due-in-days value for each mandatory lesson. Due dates are calculated from the joining date; a lesson becomes overdue the day after its due date. No deadline is shown when either value is missing. The overdue report includes active and inactive employees and respects the category filter.
- Test: create a mandatory lesson and a quiz with two questions, set a joining date in the past, then check the overdue dashboard and CSV. Fail the quiz, verify the result and retry limit, then pass and verify that completion and a certificate appear. Check that another employee cannot open the certificate, and test the public verification link. Try direct lesson completion when a quiz exists, invalid CSV, changing locked questions/pass mark, a lesson without a quiz, and an employee without a joining date.

## Policy library

- Admins can add and deactivate policies, replace their PDFs, and view or export the current-version acknowledgement report. The report includes active and inactive employee accounts. A new PDF increments the version; existing acknowledgements remain as historical records but no longer satisfy the new version. Editing other fields does not increment the version.
- Active policies are visible immediately, regardless of effective date; deactivate a policy to hide it. Employees acknowledge by checking the box. The server records its direct remote IP address, not forwarding headers; behind a proxy this may be the proxy address.
- Old PDFs are removed after replacement; acknowledgement records retain their version numbers, not archived copies of those PDFs. Back up policy PDFs separately if historical document retention is required.
- Test checklist:
  1. Add an active policy and PDF as admin. Check that two employee accounts see it as pending and that their dashboards display the pending count. A future effective date is informational.
  2. Open the PDF as an employee. Submit without ticking the box and confirm it is rejected. Tick it and submit; check the status, dashboard count, timestamp and IP in the admin report. The other employee must remain pending.
  3. Export that policy's CSV and check acknowledged and pending employees, including inactive accounts. Editing the description without replacing the PDF must not change its version.
  4. Open the policy page in another tab, replace its PDF as admin, then submit the old tab: it must be rejected. Confirm the previous acknowledgement remains in the database, the new version is pending, and its old download link no longer works. Acknowledge the new version and check the report again.
  5. Deactivate the policy and verify it disappears for employees and its PDF is inaccessible through the learner download. Confirm an admin can still see the report and download the current PDF.
  6. Try an invalid file, a non-PDF extension and a PDF over 10 MiB; none should be saved. Check an employee cannot access admin pages, and that logged-out users and direct `/uploads/pdfs/` URLs cannot download policy files.

### Updating an existing Docker test environment

The startup script imports the migration only for a **new** database; it does not upgrade existing databases or copy later source changes. Back up the local database and test files first. For the default test environment, run these commands from the project folder in PowerShell, with Docker running. Import migration 003 only if it has not already been applied; if a previous import failed partway, inspect the database first:

```powershell
Get-Content -Raw .\htdocs\sql\migrations\003_policy_library.sql | docker exec -i ddlearninghub-local-db mariadb -u root -pLocalRootTest123! ddhub_local
$dest = Join-Path $env:USERPROFILE 'ddlearninghub-docker-local'
@('admin\policies.php', 'admin\policy-report.php', 'admin\download.php',
  'learner\policies.php', 'learner\policy.php', 'learner\download.php',
  'learner\dashboard.php', 'includes\header.php') | ForEach-Object {
    Copy-Item -LiteralPath (Join-Path '.\htdocs' $_) -Destination (Join-Path $dest $_)
}
```

These commands replace those files in the test snapshot, so preserve any local edits first; they do not overwrite its config or PDFs. For older test web containers created before this change, enable the Apache protections and PDF upload limits, then restart that container:

```powershell
docker exec ddlearninghub-local-web sed -i '/AllowOverride/s/None/All/' /etc/apache2/apache2.conf
docker exec ddlearninghub-local-web sh -c 'echo upload_max_filesize=10M > /usr/local/etc/php/conf.d/ddhub-local.ini; echo post_max_size=12M >> /usr/local/etc/php/conf.d/ddhub-local.ini'
docker restart ddlearninghub-local-web
```

If you use a custom test directory or changed the local database password, adjust the commands accordingly. New containers created by the updated script already have these settings.

## Learning paths and onboarding

- Admins can create paths, add lessons with optional suggested days, use the up/down controls to reorder them, and assign each path to selected employees. An employee added individually or by CSV automatically receives paths that already exist for everyone or whose department matches their department (case-insensitive, using the database collation). Assign paths manually to existing employees or when paths are created later. Changing a department or a path's department does not automatically add or remove existing assignments.
- The dashboard shows each assigned path separately. Only published lessons count toward progress and the first incomplete published lesson is the next lesson. Suggested days are guidance, not deadlines. Unpublishing or deleting a lesson removes it from the visible count; deleting a lesson also removes it from its paths.
- Checklist items apply to everyone when their department is blank, otherwise to employees in the matching department. Items shown follow the employee's current department; completion is saved per employee. Deleting an item permanently deletes its completion records. Contacts and holidays are visible to signed-in employees; dates from today onward are highlighted as upcoming according to the database server's date.
- Test checklist:
  1. Create a general path and a department path; add multiple published lessons plus one unpublished lesson. Reorder them, change a suggested day, and verify duplicate lessons are rejected.
  2. Create employees individually and by CSV in matching and non-matching departments. Verify both receive the general path and only the appropriate department path. Manually assign a path to an existing employee and repeat the assignment; verify there is only one assignment.
  3. Sign in as an assigned employee. Check path order, suggested days, published-only counts and the first incomplete Next lesson. Complete that lesson and check the next button and count update. An unassigned employee must not open another path.
  4. Add general and department checklist items. Check each employee sees only applicable items; tick and save one, reload, untick and save it, and verify the progress bar. Test that a forged request for another department's item is rejected.
  5. Add, edit and delete contacts and holidays; search contacts, and check future and past holidays. Check that employees cannot access admin pages and that requests without a valid CSRF token are rejected.

### Updating an existing Docker snapshot for onboarding

Back up the test database and snapshot first. Run migration 004 only if it has not already been applied; the startup script automatically imports it only for a new database. From the project folder:

```powershell
Get-Content -Raw .\htdocs\sql\migrations\004_learning_paths_onboarding.sql | docker exec -i ddlearninghub-local-db mariadb -u root -pLocalRootTest123! ddhub_local
$dest = Join-Path $env:USERPROFILE 'ddlearninghub-docker-local'
@('admin\users.php', 'admin\paths.php', 'admin\onboarding.php',
  'learner\dashboard.php', 'learner\path.php', 'learner\checklist.php',
  'learner\contacts.php', 'includes\header.php', 'includes\learning_paths.php') | ForEach-Object {
    Copy-Item -LiteralPath (Join-Path '.\htdocs' $_) -Destination (Join-Path $dest $_)
}
```

Preserve any edits to those test files before copying. The commands do not overwrite the test config or uploaded PDFs. Use your actual test folder and credentials if they differ; also apply earlier missing migrations and application updates first.

## Scripts, templates and FAQ

- Admins can add, edit, publish, unpublish and delete items in the **Templates** area. A blank department makes an item visible to all employees; otherwise, published items are visible only to employees whose department matches using the database collation.
- Migration 005 adds five published examples per type, clearly marked **Example**. These are illustrative, not approved advice: review, edit or unpublish them before staff use. Check current programme and fee details before sharing.
- Write placeholders in the body as `{student_name}`, `{university}`, `{program}`, `{counselor_name}`, or another name beginning with a letter and containing up to 50 ASCII letters, numbers or underscores. Repeated names use one input. Blank inputs leave the placeholder in the preview and copied text. Entered values stay in the browser and are not saved.
- CSV import is all-or-nothing, with a 2 MB and 500-record limit. Required headers are `type,title,body,tags,department,is_published`; types are `call_script`, `objection`, `whatsapp`, `email`, or `faq`, and published must be `0` or `1`. Quote fields containing commas or newlines. Tags are comma-separated; tag filtering matches the complete tag exactly.
- Items more than 90 calendar days old are marked **May be outdated**, using the database server's date. Saving changes or changing publication status updates the timestamp. Clipboard access requires browser permission and normally HTTPS; if unavailable, the page tries the older copy mechanism and reports failure so users can copy manually.
- Test checklist:
  1. Confirm five example items in each tab. Add a draft with repeated and different placeholders, publish it, edit it and unpublish it. Verify only published items appear to employees. Delete a test item.
  2. Create items for everyone, Sales and another department. Sign in as employees in different departments and verify both items and tag chips respect visibility. Search by title, body and tag; combine a search and an exact tag filter.
  3. Fill multiple placeholders including a repeated one. Verify the preview and copied text contain the values without treating markup as HTML. Test the copy confirmation and fallback, and confirm a failed copy gives a manual-copy message.
  4. Open and close FAQ answers, fill their placeholders, and check each item shows its updated date. Check an item exactly 90 days old is not flagged and one 91 days old is flagged.
  5. Import a valid quoted, multiline CSV. Try an invalid type, invalid publication value, malformed header, oversized file and a batch with a valid row followed by an invalid row: none of an invalid batch should be inserted. Verify invalid CSRF and employee access to the admin page are rejected.

### Updating an existing Docker snapshot for templates

Back up the test database and snapshot first. Run migration 005 only if it has not already been applied; the startup script imports it automatically only for a new database. From the project folder:

```powershell
Get-Content -Raw .\htdocs\sql\migrations\005_scripts_templates_faq.sql | docker exec -i ddlearninghub-local-db mariadb -u root -pLocalRootTest123! ddhub_local
$dest = Join-Path $env:USERPROFILE 'ddlearninghub-docker-local'
@('admin\templates.php', 'learner\library.php', 'includes\templates.php',
  'includes\header.php', 'assets\css\app.css', 'assets\js\library.js') | ForEach-Object {
    Copy-Item -LiteralPath (Join-Path '.\htdocs' $_) -Destination (Join-Path $dest $_)
}
```

Preserve any test edits to those files first. The commands do not overwrite the local config or PDFs. Use your actual test folder and credentials if different, and apply earlier missing migrations and application updates first.

## Database and uploads backups

1. Sign in as an admin, open **Backups**, and download the SQL database backup. Download the uploads ZIP separately if available; otherwise use FTP or the hosting file manager to copy the entire `uploads` folder, including hidden `.htaccess` files.
2. Store both files together in a secure, preferably encrypted location outside the website. SQL backups include employee information and password hashes; ZIP backups include private uploaded files. Neither download is encrypted by the application. The application code and `includes/config.php` are not included; back those up separately and protect the credentials.
3. Check that the downloads finished and periodically test a restore. The displayed time is when SQL generation completed (UTC), not proof that the browser saved the file. The dashboard warns when no SQL backup was generated in the last seven days; a ZIP alone does not clear the warning.

The database account needs permission to list tables, read their definitions and select their rows. The backup writes each table definition and its rows in batches using unbuffered queries. InnoDB data is read from a consistent snapshot; avoid schema changes and writes to non-InnoDB tables while backing up. It includes base tables, not views, triggers, stored routines or scheduled events. The backup timestamp inside the SQL is the value from before that backup completed.

The server needs enough private temporary disk space outside the web root to build the SQL or ZIP. Temporary files are removed at the end of the request, but a forcibly killed process can leave files in the server temporary directory; hosting administrators should protect and periodically clean it. PHP execution, proxy and phpMyAdmin upload limits may still prevent very large backups or restores; use your hosting provider's backup tools if that happens. Do not rely on an incomplete download.

### Test a restore safely in phpMyAdmin

1. Create a **new, empty test database** on a compatible MySQL/MariaDB server. Do not select your live database: the SQL contains `DROP TABLE IF EXISTS` and overwrites matching tables.
2. In phpMyAdmin, select the empty test database, choose **Import**, select the downloaded `.sql` file, and run it. Do not import `schema.sql` or the migrations first.
3. Check that all expected tables exist, row counts match, and representative records (including multilingual text and completion records) are intact. If your host cannot import the file because of a size or time limit, use a hosting-supported import method.
4. For a full recovery test, set up a separate test copy of the application pointing to the restored database. Restore the trusted uploads ZIP or FTP copy into that test copy, preserving `uploads/pdfs/.htaccess`; verify authenticated downloads work and direct PDF access is denied. Do not overwrite live files during testing.

Test checklist: check the initial and older-than-seven-days warning; reject employee access and invalid CSRF; generate a database backup and verify the warning clears; test a fresh-database restore as above; verify the ZIP contains PDFs and hidden protection files or that its absence is explained; and confirm a failed generation does not update the database-backup time. Test large tables and hosting limits on your actual host.

### Updating an existing Docker snapshot for backups

Back up the test database and snapshot first. Run migration 006 only if it has not already been applied; the startup script imports it automatically only for a new database. From the project folder:

```powershell
Get-Content -Raw .\htdocs\sql\migrations\006_backup_settings.sql | docker exec -i ddlearninghub-local-db mariadb -u root -pLocalRootTest123! ddhub_local
$dest = Join-Path $env:USERPROFILE 'ddlearninghub-docker-local'
@('admin\backup.php', 'admin\index.php', 'includes\backup.php', 'includes\header.php',
  'assets\css\app.css') | ForEach-Object {
    Copy-Item -LiteralPath (Join-Path '.\htdocs' $_) -Destination (Join-Path $dest $_)
}
```

Preserve any test edits to those files before copying. Use your actual test folder and credentials if different, and apply earlier missing migrations and application updates first. The standard Docker PHP image may not include ZipArchive; that is expected and does not prevent SQL backups.
