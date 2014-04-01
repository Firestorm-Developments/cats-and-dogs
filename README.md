Cats and Dogs
=============

When backing up a database in the cloud, many people are forced to choose between using command-line tools, or else writing a custom script to export data.
We may not have privileges to use command-line tools in a cloud environment.
Writing your own data dump script is deceptively complex.
This is a simple PHP class I've written that  creates backups in the same format as mysqldump.
This class passes the same test suite that mysqldump itself uses.
