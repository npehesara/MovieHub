<?php
// --- Database Configuration Settings ---
// Use constants for configuration values to make them immutable and easier to manage.
// NOTE: For a live environment, these credentials should ideally be loaded from 
// environment variables or a file outside the web root for maximum security.

define('DB_HOST', 'localhost');
define('DB_NAME', 'movie_zone');
define('DB_USER', 'root');

// CRITICAL SECURITY WARNING: NEVER use an empty or default password in production.
// Set a strong password in your local MySQL setup and update this line.
define('DB_PASS', ''); 

// --- Database Connection Logic ---
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        // Set default fetch mode to associative arrays (recommended)
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Throw exceptions on errors (already done below, but good for redundancy)
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        // Disable emulated prepared statements for better security against SQL injection
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

} catch (PDOException $e) {
    // SECURITY IMPROVEMENT: Instead of 'die()' with the error message, 
    // we log the detailed error privately and show a generic message publicly.
    
    // Log the error detail (e.g., to a file or system log)
    error_log("Database connection error: " . $e->getMessage());
    
    // Stop execution and display a generic, user-friendly error message
    http_response_code(500);
    die("A required service is currently unavailable. Please try again later.");
}
?>
