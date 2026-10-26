<?php
// Every destructive test must opt into a dedicated database, before loading the application.
if (PHP_SAPI !== 'cli' || getenv('LIBRARY_TEST_MODE') !== '1'
    || !preg_match('/^library_test_[a-zA-Z0-9_]+$/D',getenv('LIBRARY_DB_NAME') ?: '')) {
    fwrite(STDERR,"Tests refused: set LIBRARY_TEST_MODE=1 and LIBRARY_DB_NAME=library_test_<name>.\n");
    exit(1);
}
