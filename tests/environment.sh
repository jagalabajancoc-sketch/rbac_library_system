# Shared by shell tests. Both the CLI client and the PHP server inherit the same database settings.
: "${LIBRARY_DB_NAME:=library_test_workflow}"
: "${LIBRARY_DB_HOST:=127.0.0.1}"
: "${LIBRARY_DB_PORT:=3306}"
: "${LIBRARY_DB_USER:=root}"
export LIBRARY_DB_NAME LIBRARY_DB_HOST LIBRARY_DB_PORT LIBRARY_DB_USER
export LIBRARY_TEST_MODE=1
php tests/guard.php || exit 1
export MYSQL_PWD="${LIBRARY_DB_PASS:-}"
DB="$LIBRARY_DB_NAME"
mariadb(){ command mariadb --host="$LIBRARY_DB_HOST" --port="$LIBRARY_DB_PORT" --user="$LIBRARY_DB_USER" "$@"; }
