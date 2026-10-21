-- Runs once, when the MySQL volume is created. The test suite uses its own
-- database so `php artisan test` never touches the demo data.
CREATE DATABASE IF NOT EXISTS ledger_test;
GRANT ALL PRIVILEGES ON ledger_test.* TO 'ledger'@'%';
