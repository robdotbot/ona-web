ona-web {{VERSION}}
====================

A modern web interface for OpenNetAdmin (ONA), with the classic ONA
interface alongside it. Everything runs in Docker on this computer.

Requirements
------------
{{REQUIREMENTS}}

Start
-----
{{START}}

The first start builds the containers and sets up the database, which takes
a few minutes. Your browser then opens the web UI:

  Web UI:      http://localhost:8000
  Classic UI:  http://localhost:8080

Log in as admin / admin and change that password under Admin > Users.
The built-in "guest" / "guest" account can only read.

Stop
----
{{STOP}}

Your data is kept in a Docker volume and is there again next time.

Settings
--------
The first start creates a file called .env in this folder with random
database passwords. You can change the ports there (ONA_WEB_PORT,
ONA_CLASSIC_PORT) and the default DNS domain for a new database
(ONA_DEFAULT_DOMAIN). Don't change the passwords after the first start.
Note: the web UI's link to the classic UI always points at port 8080.

Both ports are only reachable from this computer. To share ona-web on your
network, change "127.0.0.1:" in docker-compose.yml and change the admin
password first.

Upgrading
---------
Stop ona-web, unzip the new release into a new folder, copy your .env file
into it, and start it. The data volume is shared between versions.

Starting over
-------------
To delete all data: open a terminal in this folder and run
  docker compose down -v

Problems
--------
Logs: open a terminal in this folder and run
  docker compose logs
