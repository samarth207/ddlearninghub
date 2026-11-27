<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

if (current_user() !== null) {
    redirect_to('/index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'Enter your email and password.';
        } else {
            $statement = db()->prepare(
                'SELECT id, name, email, role, password_hash
                 FROM users
                 WHERE email = :email AND is_active = 1
                 LIMIT 1'
            );
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();

            if ($user === false || !password_verify($password, $user['password_hash'])) {
                $error = 'Email or password is incorrect.';
            } else {
                session_regenerate_id(true);
                unset($_SESSION['csrf_token']);
                unset($user['password_hash']);
                $_SESSION['user'] = $user;
                redirect_to('/index.php');
            }
        }
    } catch (PDOException $exception) {
        error_log('Learning Hub login database error: ' . $exception->getMessage());
        http_response_code(503);
        $error = 'Sign-in is temporarily unavailable. Please try again later.';
    } catch (RuntimeException $exception) {
        http_response_code(400);
        $error = 'Your form session expired. Please try again.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | DegreeDrishti Learning Hub</title>
    <link rel="stylesheet" href="assets/css/theme.css">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="login-page">
    <div class="login-layout">
        <aside class="login-intro" aria-label="About the Learning Hub">
            <div>
                <p class="login-intro-label">DEGREEDRISHTI LEARNING HUB</p>
                <h2>Grow with every new skill.</h2>
                <p>Training, resources and tools to support your journey at DegreeDrishti.</p>
            </div>
            <div class="login-highlights" aria-label="Learning Hub resources">
                <span>Training</span><span>Resources</span><span>Progress</span>
            </div>
        </aside>
        <main class="login-card">
            <a class="brand login-brand" href="index.php"><span class="brand-mark" aria-hidden="true">D</span><span>DegreeDrishti<small>LEARNING HUB</small></span></a>
            <p class="eyebrow">WELCOME BACK</p>
            <h1>Sign in to learn</h1>
            <p class="muted">Use your company account to access team training.</p>
            <?php if ($error !== ''): ?>
                <p class="notice notice-error" role="alert"><?= e($error) ?></p>
            <?php endif; ?>
            <form method="post" action="login.php" class="stack-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label>Email address<input type="email" name="email" autocomplete="username" required maxlength="254" value="<?= e((string) ($_POST['email'] ?? '')) ?>"></label>
                <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
                <button class="button button-primary" type="submit">Sign in</button>
            </form>
            <p class="login-help">Need access? Contact your DegreeDrishti administrator.</p>
        </main>
    </div>
</body>
</html>
