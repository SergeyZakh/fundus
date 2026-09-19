# Sicherungsdienst: dasselbe Image wie die Datenbank, damit mariadb-dump zur Serverversion passt,
# dazu rclone für die Kopie außer Haus (SFTP, S3, WebDAV/Nextcloud, SMB … über SICHERUNG_KOPIE).
FROM mariadb:11.8
RUN apt-get update \
 && apt-get install -y --no-install-recommends rclone ca-certificates \
 && rm -rf /var/lib/apt/lists/*
