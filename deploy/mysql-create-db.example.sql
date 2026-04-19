CREATE DATABASE greenloop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'greenloop_user'@'localhost' IDENTIFIED BY 'ChangeThisPasswordNow123!';
GRANT ALL PRIVILEGES ON greenloop.* TO 'greenloop_user'@'localhost';
FLUSH PRIVILEGES;
