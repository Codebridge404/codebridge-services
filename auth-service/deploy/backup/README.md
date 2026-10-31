# Automated Multi-Tier Backup & Disaster Recovery System

This directory contains automated backup, health inspection, and disaster recovery scripts for the CodeBridges Auth Service and supporting infrastructure.

---

## 1. Components Backed Up

1. **MySQL Database (`codebridge_auth`)**:
   - Dumped with `--single-transaction`, `--quick`, `--routines`, and `--triggers`.
   - Compressed as `.sql.gz` with gzip level 9.
   - Automatically detects active credentials and container (`codebridge-auth-mysql` or bluegreen container).
2. **MinIO Media & S3 Uploaded Avatars**:
   - Uploaded files in `/data` (including avatars and tenant assets).
   - Streamed directly via Docker daemon tar engine and compressed as `.tar.gz`.
3. **Docker Release Images**:
   - Snapshots the currently active release Docker image (`codebridge-auth-service:blue`, `:green`, or `:latest`).
   - Compressed as `.tar.gz` for zero-downtime offline rollback capability.

---

## 2. Retention Policy (Grandfather-Father-Son)

| Tier | Schedule | Retention Window | Pruning Rule |
| :--- | :--- | :--- | :--- |
| **Daily** | Every night (02:00 AM) | 7 Days | Files older than 7 days pruned automatically |
| **Weekly** | Every Sunday (03:00 AM) | 4 Weeks (28 Days) | Files older than 28 days pruned automatically |
| **Monthly**| 1st of every month (04:00 AM) | 12 Months (365 Days) | Files older than 365 days pruned automatically |

---

## 3. Usage Guide

### A. Run a Backup
Run any tier manually or via crontab:
```bash
./backup.sh daily
./backup.sh weekly
./backup.sh monthly
```
Backups are saved under `storage/<tier>/` (e.g. `storage/daily/`).

### B. Check Backup Status & Integrity
Inspect all tiers, file counts, sizes, dates, archive checksums, and disk space:
```bash
./status.sh
```
Example output:
```text
==============================================================================
           CodeBridges Backup Health & Status Overview                        
==============================================================================

▶ Tier: [DAILY] (/path/to/storage/daily)
  Total Files : 3 | Total Size: 257M
    * [Database (MySQL)]: mysql_codebridge_auth_20261031_163108.sql.gz ( 20K) -> PASS (Valid)
    * [Media Storage (MinIO)]: minio_media_20261031_163108.tar.gz (320K) -> PASS (Valid)
    * [Docker Release Image]: docker_image_codebridge_auth_service_latest_20261031_163108.tar.gz (256M) -> PASS (Valid)

▶ Storage Disk Space:
Filesystem      Size    Used   Avail Capacity Mounted on
/dev/sda1       80G     24G    52G   32%      /
==============================================================================
```

### C. Restore from Backup
To restore a database or media archive:
```bash
# Restore MySQL database
./restore.sh ./storage/daily/mysql_codebridge_auth_YYYYMMDD_HHMMSS.sql.gz

# Restore MinIO media
./restore.sh ./storage/daily/minio_media_YYYYMMDD_HHMMSS.tar.gz

# Restore Docker release image
./restore.sh ./storage/daily/docker_image_codebridge_auth_service_latest_YYYYMMDD_HHMMSS.tar.gz
```

---

## 4. Scheduling on VPS

Copy the cron entries from `crontab.example` to your system crontab:
```bash
crontab -e
```
