PANDUAN CLASSIFY - VERSI MYSQL
==============================

ISI PAKET
---------
1. classify.html : frontend yang sudah diarahkan ke api.php dan MySQL.
2. api.php       : backend PHP untuk login, snapshot data, peminjaman, status, dan chat.
3. config.php    : konfigurasi koneksi ke MySQL (perlu disesuaikan jika password MySQL berbeda).

CARA MEMASANG DI XAMPP
----------------------
1. Pastikan Apache dan MySQL aktif dari XAMPP Control Panel.
2. Buat folder, misalnya C:\xampp\htdocs\classify\
3. Salin classify.html, api.php, dan config.php ke folder tersebut.
4. Pastikan database bernama `classify` sudah ada di MySQL dan tabel-tabel dari database.sql sudah diimpor.
5. Buka config.php. Default yang disediakan adalah user root tanpa password (umum pada XAMPP baru).
   Jika MySQL kamu memakai password atau port berbeda, sesuaikan DB_USER, DB_PASS, dan DB_DSN.
6. Buka aplikasi melalui alamat:
   http://localhost/classify/classify.html
   Jangan membuka file HTML dengan klik dua kali (file://), karena PHP API harus dijalankan oleh Apache.

PENTING TENTANG DATABASE.SQL
----------------------------
File database.sql yang kamu kirim memiliki perintah DROP TABLE IF EXISTS.
Perintah tersebut akan menghapus tabel dan data lama sebelum membuat ulang tabel.
Karena itu, JANGAN impor ulang file database.sql jika database classify sudah berisi data yang ingin dipertahankan.

APA YANG DIUBAH
---------------
- Menghapus sql.js, kelas Database SQLite, SCHEMA_SQL, dan penyimpanan localStorage.
- Login sekarang diproses oleh api.php dan menggunakan session PHP.
- Daftar ruang, peminjaman, laporan OB, chat, dosen, dan ringkasan jumlah data diambil dari MySQL.
- Membuat peminjaman, mengubah status, mengirim chat, dan mengubah status laporan dikirim ke api.php.
- Tampilan admin tidak lagi menyediakan unduh/reset SQLite. Pengelolaan backup database dilakukan dari MySQL/phpMyAdmin.

PERSYARATAN
------------
- PHP 8.1 atau lebih baru (api.php menggunakan fitur PHP 8.1).
- Ekstensi PDO MySQL aktif.
- Database MySQL `classify` beserta tabel sesuai database.sql sudah tersedia.

CATATAN PENGUJIAN
-----------------
Kode frontend sudah diperiksa sintaks JavaScript-nya dan api.php lolos pemeriksaan sintaks PHP.
Koneksi sesungguhnya belum dapat dipastikan dari sini karena perlu dijalankan pada XAMPP/MySQL milikmu.
Setelah dipasang, uji login, peminjaman, persetujuan admin, laporan OB, dan chat.
