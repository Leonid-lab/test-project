SELECT format(
    'CREATE DATABASE %I OWNER %I',
    'product_report_test',
    current_user
)
WHERE NOT EXISTS (
    SELECT 1
    FROM pg_database
    WHERE datname = 'product_report_test'
)
\gexec
