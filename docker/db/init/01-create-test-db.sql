-- Create test database for PHPUnit
CREATE DATABASE IF NOT EXISTS symfony_mvp_test;
GRANT ALL PRIVILEGES ON symfony_mvp_test.* TO 'app'@'%';
FLUSH PRIVILEGES;
