# dbdiff
Compare database structure between different environment

- only support mysql
- only support column change and table comment change

## Usage

first, edit a `.env` file

    [TEST]
    ssh="ssh name@ip mysql --default-character-set=utf8 -hhost -uname -ppass db"

    [LOCAL]
    dsn = "mysql:host=127.0.0.1;dbname=test;charset=utf8mb4"
    username = "t"
    password = "t"

then

    php diff.php from to

it will print sql.

## Price

free to use. 100 Dogecoin to get a 1 year support.