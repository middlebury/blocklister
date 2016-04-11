About Blacklister
==================

This small program looks at data in data sources (such as Elasticsearch) and can generate
an IP blacklist suitable for ingestion into a firewall, such as the Dynamic Block List
(DBL) supported by Palo Alto Networks' PAN-OS 5.0.

Author and Copyright
---------------------

 * Created by: Adam Franco
 * Date: 2014-04-10
 * Copyright: Middlebury College (2014)
 * License: http://www.gnu.org/copyleft/gpl.html GNU General Public License (GPL)

Installation
=============

Overview
---------

This program is composed of a bash script, `blacklist_cron.sh` that triggers the
fetching and writes the blacklist output. This script can be run manually or by cron. 
In turn, the `blacklist_cron.sh` calls two PHP scripts, `update_list.php` and
`get_list.php` which do the heavy lifting of querying Elasticsearch, filtering the
results, and writing the blacklist to a working database. This working database can be
either a local Sqlite database file stored in the `data/` subdirectory, or a MySQL
database.

Environment
------------

 * bash
 
 * cron
 
 * PHP 5.3+  with...
   
   * `PECL HTTP` >= 0.1.0, < 2.0  (for the `http_post_data()` function).  
     http://us.php.net/manual/en/book.http.php
     
   * `PDO_SQLITE` or `PDO_MYSQL` (for working database storage).  
     http://us.php.net/manual/en/ref.pdo-sqlite.php  
     http://us.php.net/manual/en/ref.pdo-mysql.php

 * A webserver or other means to serve your blacklist file to your firewall

Installation process
---------------------

 1. Put the directory containing this README and the scripts (including the `lib/` and
    `data/` directories) somewhere on your machine.

 2. Copy `config.php.example` to `config.php`.

 3. Edit `config.php` to choose your database (it defaults to using a Sqlite file stored
    at `data/blacklist.sq3`) and configure where your Elasticsearch datasource lives
    and what behavior signatures it should match.

Usage
======

Normally, the program would be run every minute or few minutes from cron with a line like
the following:

    * * * * * /path/to/blacklister/blacklist_cron.sh -o /var/www/html/blacklist.txt | logger -t blacklister -p local0.info

You may want to log to different syslog facilities or change the output file location.
