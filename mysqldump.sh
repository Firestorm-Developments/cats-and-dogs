#!/bin/sh

php -f mysqldump_client.php -- "$@" < /dev/null
exit $?
