usermod -u $USER_ID www-data
umask 0000
php-fpm --nodaemonize