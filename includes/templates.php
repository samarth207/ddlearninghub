<?php
declare(strict_types=1);

function template_types(): array
{
    return [
        'call_script' => 'Call scripts',
        'objection' => 'Objections',
        'whatsapp' => 'WhatsApp',
        'email' => 'Email',
        'faq' => 'FAQ',
    ];
}

function validate_template(array $values): array
{
    foreach (['type', 'title', 'body', 'tags', 'department', 'is_published'] as $field) {
        if (!isset($values[$field]) || !is_string($values[$field])) {
            throw new InvalidArgumentException('All template fields must be text.');
        }
        $values[$field] = trim($values[$field]);
    }
    if (!isset(template_types()[$values['type']])) {
        throw new InvalidArgumentException('Choose a valid template type.');
    }
    if ($values['title'] === '' || strlen($values['title']) > 200 ||
        $values['body'] === '' || strlen($values['body']) > 60000 ||
        strlen($values['tags']) > 500 || strlen($values['department']) > 120) {
        throw new InvalidArgumentException('Enter a title (up to 200 bytes), body (up to 60000 bytes), tags (up to 500 bytes), and department (up to 120 bytes).');
    }
    if (!in_array($values['is_published'], ['0', '1'], true)) {
        throw new InvalidArgumentException('Published must be 0 or 1.');
    }
    foreach (explode(',', $values['tags']) as $tag) {
        if (strlen(trim($tag)) > 100) {
            throw new InvalidArgumentException('Each tag must be 100 bytes or fewer.');
        }
    }
    $values['department'] = $values['department'] === '' ? null : $values['department'];
    $values['is_published'] = (int) $values['is_published'];
    return $values;
}
