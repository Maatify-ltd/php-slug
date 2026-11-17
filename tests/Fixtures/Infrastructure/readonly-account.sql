-- Disposable Integration fixture, used only by the isolated Compose services.
CREATE USER IF NOT EXISTS 'slug_readonly_test'@'%' IDENTIFIED BY 'slug_readonly_test_password';
GRANT SELECT ON maatify_slug_test.* TO 'slug_readonly_test'@'%';
