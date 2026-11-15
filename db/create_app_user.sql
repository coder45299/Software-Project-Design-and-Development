-- Least-privilege database account for the application (defect D-8).
-- Run once as the MySQL administrator, then set PETBIO_DB_USER=petbio_app and PETBIO_DB_PASS=<the password> on the server.
CREATE USER IF NOT EXISTS 'petbio_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_before_use';
GRANT SELECT, INSERT, UPDATE, DELETE ON pet_biometrics.* TO 'petbio_app'@'localhost';
FLUSH PRIVILEGES;
