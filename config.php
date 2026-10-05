<?php
/**
 * Project settings. Set real values on the server before production use.
 * Never commit real API credentials, passwords, or database secrets.
 */
return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'DBNAME',
        'user' => 'DBUSER',
        'password' => 'DBPASSWORD',
        'charset' => 'utf8mb4',
    ],
    'sms' => [
        // Official MeliPayamak REST SendByBaseNumber endpoint (shared service / pattern).
        'endpoint' => 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber',
        // Account username plus API key; do not put the panel password here.
        'username' => 'USERNAME',
        'api_key' => 'APIKEY',
        'admin_mobile' => '09121234567',
        'admin_pattern_id' => 550993, // Approved admin pattern: {0}=full name, {1}=bidder mobile.
        'user_pattern_id' => 550991,
    ],
    'app' => [
        'domain' => 'l-ka.com',
        'currency' => 'تومان',
    ],
    'auction' => [
        'duration_days' => 90,
        'start_at' => '2026-10-05T14:30:00+03:30', // Set this once to the auction opening time; countdown stays fixed across visits.
    ],
];
