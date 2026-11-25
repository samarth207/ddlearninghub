# DegreeDrishti Learning Hub

Plain PHP 8, MySQL/PDO, vanilla JavaScript, and custom CSS. No Composer or Node.js setup is required.

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

InfinityFree may impose PHP upload-size or execution limits. The application rejects PDFs larger than 10 MB and requires PHP's `fileinfo` extension; test uploads on the host before relying on them.

## Security and operations

- Keep database credentials in `includes/config.php`; never post or commit them.
- `includes/.htaccess`, `sql/.htaccess`, the root `.htaccess`, and `uploads/pdfs/.htaccess` rely on Apache access rules. If the server ignores `.htaccess`, configure equivalent restrictions before deployment.
- Create database backups from the hosting control panel regularly. Compliance CSV exports and database backups are not automatically archived by the application.
