# GeoChat Server

This is a server for GeoChat applications, written for OpenStreetMap, but usable in other environments.

## Installation

Just copy the `geochat.php` file anywhere accessible from the web and point the clients to it. It stores messages in a MySQL database: write user credentials and database name in the `geochat.php` and run it from a command line to initialize tables.

## Operation

Add a task to crontab or other scheduling application to run the script for a command line at least once an hour. It will clean expired messages and user accounts.

## License

This script was written by Ilya Zverev and licensed WTFPL.

