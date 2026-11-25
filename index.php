<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$user = current_user();
if ($user === null) {
    redirect_to('/login.php');
}

redirect_to($user['role'] === 'admin' ? '/admin/index.php' : '/learner/dashboard.php');
