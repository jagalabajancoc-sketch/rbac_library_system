<?php
// Preserve existing bookmarks and release submissions while using the new public name.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $query = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: my_borrowed_books.php' . ($query !== '' ? '?' . $query : ''), true, 302);
    exit;
}
require __DIR__ . '/my_borrowed_books.php';
