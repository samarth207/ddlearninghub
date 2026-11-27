<?php
declare(strict_types=1);

function assign_matching_paths(PDO $connection, int $userId): void
{
    $query = $connection->prepare(
        "INSERT INTO user_paths (user_id, path_id)
         SELECT u.id, p.id FROM users u CROSS JOIN paths p
         WHERE u.id = :user_id AND u.role = 'employee'
           AND (p.department IS NULL OR TRIM(p.department) = '' OR p.department = u.department)"
    );
    $query->execute(['user_id' => $userId]);
}
