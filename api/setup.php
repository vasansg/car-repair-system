<?php
ob_start();

require_once __DIR__ . '/includes/config.php';

/*
 * NOTE: This setup script originally contained MySQL-specific DDL
 * (CREATE TABLE with ENGINE=InnoDB, AUTO_INCREMENT, ENUM types, etc.)
 * which is not compatible with PostgreSQL.
 *
 * To set up the PostgreSQL database schema, please run the file:
 *   schema_postgres.sql
 *
 * You can execute it using psql:
 *   psql -U <your_user> -d <your_database> -f schema_postgres.sql
 *
 * Or paste the contents into your PostgreSQL admin tool (e.g., pgAdmin).
 *
 * The $pdo connection is available above via includes/config.php
 * if you need to run any additional PHP-based seed data after the schema is applied.
 */

echo "<hr><h3>Setup Information</h3>";
echo "<p>This application has been migrated to PostgreSQL.</p>";
echo "<p>Please run <strong>schema_postgres.sql</strong> to create the database schema.</p>";
echo "<a href='login.php' class='btn btn-primary'>Go to Login Page</a>";
?>
