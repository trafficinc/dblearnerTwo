DB Leaner v2

Snapshot Before with Config Settings:
php snapshot.php --label=before

Snapshot After with Config Settings:
php snapshot.php --label=after

Compare Snapshots:
php compare.php

Override Tables from Command Line (Optional):
php snapshot.php before --tables=users,orders

php snapshot.php --clear

php snapshot.php --clear --label=before --tables=users,orders

php snapshot.php --label=after --tables=users,orders

php compare.php --output=diff.txt --no-color --no-truncate



