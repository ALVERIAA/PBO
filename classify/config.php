<?php
// ===== Pengaturan koneksi database =====
// Sesuaikan dengan MySQL/MariaDB Anda (default XAMPP: user root, password kosong).
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'classify';
const DB_USER = 'root';
const DB_PASS = '';

const DB_DSN = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
