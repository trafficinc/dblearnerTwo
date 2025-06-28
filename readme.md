# DB Learner v2

This is a small tool to get to know which tables in MySQL are affected by actions in the application via database inserts, updates, and deletes. (Should work with MariaDB too, just haven't tested it.)

> Dependencies: PHP 8 (PHP 7 may work?) & PDO, MySQL Database (MariaDB may work?), a database.

### Snapshot Before with Config Settings:
`php snapshot.php --label=before`

### Snapshot After with Config Settings:
`php snapshot.php --label=after`

### Compare Snapshots:
`php compare.php`

### Override Tables from Command Line (Optional):
`php snapshot.php before --tables=users,orders`

`php snapshot.php --clear`

`php snapshot.php --clear --label=before --tables=users,orders`

`php snapshot.php --label=after --tables=users,orders`

`php compare.php --output=diff.txt --no-color --no-truncate`



