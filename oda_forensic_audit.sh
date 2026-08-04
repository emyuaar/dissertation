#!/usr/bin/env bash
# Read-only WordPress/Ubuntu forensic collector for 03-Aug-2026.
# It does not delete, update, deactivate, or repair anything.
# Usage:
#   sudo bash oda_forensic_audit.sh
# or:
#   sudo bash oda_forensic_audit.sh /var/www/onlinedissertationadvisor \
#        "2026-08-03 00:00:00" "2026-08-04 00:00:00"

set -u
umask 077

SITE_ROOT="${1:-/var/www/onlinedissertationadvisor}"
START="${2:-2026-08-03 00:00:00}"
END="${3:-2026-08-04 00:00:00}"
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT="/root/oda_forensic_${STAMP}"

mkdir -p "$OUT"
exec > >(tee -a "$OUT/run.log") 2>&1

section() {
  printf '\n\n========== %s ==========\n' "$1"
}

read_log_files() {
  local f
  for f in "$@"; do
    [ -e "$f" ] || continue
    case "$f" in
      *.gz) zcat -- "$f" 2>/dev/null ;;
      *)    cat -- "$f" 2>/dev/null ;;
    esac
  done
}

section "AUDIT PARAMETERS"
echo "Site root : $SITE_ROOT"
echo "Start     : $START"
echo "End       : $END"
echo "Output    : $OUT"
echo "Run time  : $(date --iso-8601=seconds)"
echo
if [ ! -d "$SITE_ROOT" ]; then
  echo "ERROR: Site root does not exist: $SITE_ROOT"
  echo "Run again with the correct WordPress path."
  exit 1
fi

section "SYSTEM AND TIMEZONE"
{
  hostnamectl 2>/dev/null || true
  timedatectl 2>/dev/null || true
  uname -a
  uptime
  df -hT
  mount
} > "$OUT/system.txt" 2>&1
cat "$OUT/system.txt"

section "CURRENT NETWORK AND PROCESSES"
{
  ss -lntup 2>/dev/null || true
  ps auxf
} > "$OUT/current_processes_network.txt" 2>&1

section "FILES MODIFIED DURING TARGET WINDOW"
find "$SITE_ROOT" -xdev -type f \
  -newermt "$START" ! -newermt "$END" \
  -printf '%TY-%Tm-%Td %TH:%TM:%TS | %u:%g | %m | %s bytes | %p\n' \
  2>/dev/null | sort > "$OUT/files_modified_mtime.txt"

find "$SITE_ROOT" -xdev -type f \
  -newerct "$START" ! -newerct "$END" \
  -printf '%CY-%Cm-%Cd %CH:%CM:%CS | %u:%g | %m | %s bytes | %p\n' \
  2>/dev/null | sort > "$OUT/files_changed_ctime.txt"

echo "mtime matches: $(wc -l < "$OUT/files_modified_mtime.txt")"
echo "ctime matches: $(wc -l < "$OUT/files_changed_ctime.txt")"

find "$SITE_ROOT" -xdev -type f \
  -newermt "$START" ! -newermt "$END" -print0 2>/dev/null \
  | sort -z | xargs -0 -r sha256sum > "$OUT/files_modified_sha256.txt"

section "RECENT PHP FILES AND SUSPICIOUS CODE PATTERNS"
find "$SITE_ROOT" -xdev -type f \
  -newermt "$START" ! -newermt "$END" \
  \( -iname '*.php' -o -iname '*.phtml' -o -iname '*.php5' -o -iname '*.phar' \) \
  -print0 2>/dev/null > "$OUT/recent_php_files.null"

tr '\0' '\n' < "$OUT/recent_php_files.null" > "$OUT/recent_php_files.txt"

if [ -s "$OUT/recent_php_files.null" ]; then
  xargs -0 -r grep -InE \
    'eval[[:space:]]*\(|assert[[:space:]]*\(|base64_decode[[:space:]]*\(|gzinflate[[:space:]]*\(|gzuncompress[[:space:]]*\(|str_rot13[[:space:]]*\(|shell_exec[[:space:]]*\(|passthru[[:space:]]*\(|proc_open[[:space:]]*\(|popen[[:space:]]*\(|system[[:space:]]*\(|exec[[:space:]]*\(|preg_replace[[:space:]]*\(.*/e|create_function[[:space:]]*\(' \
    < "$OUT/recent_php_files.null" \
    > "$OUT/recent_php_suspicious_patterns.txt" 2>/dev/null || true
else
  : > "$OUT/recent_php_suspicious_patterns.txt"
fi

section "EXECUTABLE OR PHP-LIKE FILES IN UPLOADS"
if [ -d "$SITE_ROOT/wp-content/uploads" ]; then
  find "$SITE_ROOT/wp-content/uploads" -xdev -type f \
    \( -iname '*.php' -o -iname '*.phtml' -o -iname '*.php5' -o -iname '*.phar' \
       -o -iname '*.cgi' -o -iname '*.pl' -o -iname '*.py' -o -perm /111 \) \
    -printf '%TY-%Tm-%Td %TH:%TM:%TS | %u:%g | %m | %s | %p\n' \
    2>/dev/null | sort > "$OUT/uploads_executable_or_php.txt"
fi

find "$SITE_ROOT" -xdev -type l -ls 2>/dev/null > "$OUT/symlinks.txt"
find "$SITE_ROOT" -xdev -type f \
  \( -name '.*.php' -o -name '*.php.*' -o -name '*.ico.php' -o -name '*.jpg.php' \
     -o -name '*.png.php' -o -name '*.suspected' -o -name '*.bak' \) \
  -ls 2>/dev/null > "$OUT/unusual_filenames.txt"

section "APACHE/NGINX ACCESS LOGS FOR 03-AUG-2026"
read_log_files \
  /var/log/apache2/*access*.log* \
  /var/log/nginx/*access*.log* \
  | grep -E '03/Aug/2026|2026-08-03' \
  > "$OUT/web_access_2026-08-03.log" || true

grep -Ei \
  'POST .*wp-(login|admin|cron)|POST .*xmlrpc\.php|wp-admin/(plugin-editor|theme-editor|update|plugins|options|user-new|profile|admin-ajax|async-upload)\.php|/wp-json/|/wp-content/uploads/.*\.php|cmd=|base64|eval\(' \
  "$OUT/web_access_2026-08-03.log" \
  > "$OUT/web_access_suspicious_requests.log" || true

awk '{print $1}' "$OUT/web_access_2026-08-03.log" \
  | sort | uniq -c | sort -nr > "$OUT/web_access_top_ips.txt"

awk '{print $7}' "$OUT/web_access_2026-08-03.log" \
  | sort | uniq -c | sort -nr > "$OUT/web_access_top_urls.txt"

echo "Access lines found: $(wc -l < "$OUT/web_access_2026-08-03.log")"
echo "Flagged request lines: $(wc -l < "$OUT/web_access_suspicious_requests.log")"

section "WEB SERVER ERROR LOGS"
read_log_files \
  /var/log/apache2/*error*.log* \
  /var/log/nginx/*error*.log* \
  | grep -E '03/Aug/2026|Aug[[:space:]]+0?3|2026-08-03' \
  > "$OUT/web_error_2026-08-03.log" || true

section "SSH, AUTHENTICATION, SERVICES AND SYSTEM JOURNAL"
journalctl --since "$START" --until "$END" --no-pager \
  > "$OUT/journal_all_2026-08-03.log" 2>&1 || true

journalctl --since "$START" --until "$END" \
  -u ssh -u sshd --no-pager \
  > "$OUT/journal_ssh_2026-08-03.log" 2>&1 || true

journalctl --since "$START" --until "$END" \
  -u apache2 -u nginx -u php8.1-fpm -u php8.2-fpm -u php8.3-fpm \
  -u mysql -u mariadb --no-pager \
  > "$OUT/journal_web_db_2026-08-03.log" 2>&1 || true

read_log_files /var/log/auth.log* \
  | grep -E 'Aug[[:space:]]+0?3|2026-08-03' \
  > "$OUT/auth_2026-08-03.log" || true

{
  last -Fai 2>/dev/null || true
  echo
  echo "---- failed logins ----"
  lastb -Fai 2>/dev/null || true
} > "$OUT/login_history.txt"

section "CRON, SYSTEMD AND CONFIG FILE CHANGES"
{
  find /etc/cron.d /etc/cron.daily /etc/cron.hourly /etc/cron.weekly \
       /var/spool/cron /etc/systemd/system \
       -newermt "$START" ! -newermt "$END" \
       -printf '%TY-%Tm-%Td %TH:%TM:%TS | %u:%g | %m | %s | %p\n' 2>/dev/null
  echo
  systemctl list-timers --all 2>/dev/null || true
  echo
  crontab -l 2>/dev/null || true
  echo
  for u in $(cut -d: -f1 /etc/passwd); do
    crontab -u "$u" -l 2>/dev/null | sed "s/^/[$u] /"
  done
} > "$OUT/cron_systemd.txt"

section "PACKAGE INSTALL/UPDATE HISTORY"
read_log_files /var/log/dpkg.log* \
  | grep '2026-08-03' > "$OUT/dpkg_2026-08-03.log" || true

read_log_files /var/log/apt/history.log* \
  | grep -A20 -B5 '2026-08-03' > "$OUT/apt_2026-08-03.log" || true

section "WORDPRESS INVENTORY AND CHECKSUMS"
if command -v wp >/dev/null 2>&1; then
  WP=(wp --path="$SITE_ROOT" --allow-root)

  {
    "${WP[@]}" core version
    "${WP[@]}" core verify-checksums
  } > "$OUT/wp_core_checksums.txt" 2>&1 || true

  "${WP[@]}" plugin list --fields=name,status,version,update,update_version,auto_update \
    --format=csv > "$OUT/wp_plugins.csv" 2>&1 || true

  "${WP[@]}" theme list --fields=name,status,version,update,update_version,auto_update \
    --format=csv > "$OUT/wp_themes.csv" 2>&1 || true

  "${WP[@]}" plugin verify-checksums --all --strict \
    > "$OUT/wp_plugin_checksums.txt" 2>&1 || true

  "${WP[@]}" user list \
    --fields=ID,user_login,user_email,user_registered,display_name,roles \
    --format=csv > "$OUT/wp_users.csv" 2>&1 || true

  "${WP[@]}" user list --role=administrator \
    --fields=ID,user_login,user_email,user_registered,display_name,roles \
    --format=csv > "$OUT/wp_administrators.csv" 2>&1 || true

  "${WP[@]}" cron event list --format=csv \
    > "$OUT/wp_cron_events.csv" 2>&1 || true

  "${WP[@]}" option get active_plugins --format=json \
    > "$OUT/wp_active_plugins.json" 2>&1 || true

  "${WP[@]}" config list --fields=name,type \
    > "$OUT/wp_config_keys_only.txt" 2>&1 || true

  # Preserve a database snapshot before any cleanup. Credentials come from wp-config.php.
  "${WP[@]}" db export "$OUT/database_before_cleanup.sql" \
    > "$OUT/wp_db_export.log" 2>&1 || true

  PFX="$("${WP[@]}" db prefix 2>/dev/null || true)"
  DBNAME="$("${WP[@]}" config get DB_NAME 2>/dev/null || true)"

  if [ -n "$PFX" ]; then
    "${WP[@]}" db query --skip-column-names "
      SELECT ID,post_author,post_date,post_date_gmt,post_modified,post_modified_gmt,
             post_status,post_type,post_title
      FROM ${PFX}posts
      WHERE (post_modified >= '$START' AND post_modified < '$END')
         OR (post_modified_gmt >= '$START' AND post_modified_gmt < '$END')
         OR (post_date >= '$START' AND post_date < '$END')
         OR (post_date_gmt >= '$START' AND post_date_gmt < '$END')
      ORDER BY GREATEST(post_modified,post_modified_gmt,post_date,post_date_gmt);
    " > "$OUT/db_posts_changed_2026-08-03.tsv" 2>&1 || true

    "${WP[@]}" db query --skip-column-names "
      SELECT ID,user_login,user_email,user_registered,display_name
      FROM ${PFX}users
      WHERE user_registered >= '$START' AND user_registered < '$END'
      ORDER BY user_registered;
    " > "$OUT/db_users_created_2026-08-03.tsv" 2>&1 || true

    "${WP[@]}" db query --skip-column-names "
      SELECT comment_ID,comment_post_ID,comment_author,comment_author_email,
             comment_date,comment_date_gmt,comment_approved,LEFT(comment_content,500)
      FROM ${PFX}comments
      WHERE (comment_date >= '$START' AND comment_date < '$END')
         OR (comment_date_gmt >= '$START' AND comment_date_gmt < '$END')
      ORDER BY comment_date;
    " > "$OUT/db_comments_2026-08-03.tsv" 2>&1 || true

    # These tables do not have reliable modified timestamps.
    # Highest IDs are collected only as leads, not proof of changes on the target date.
    "${WP[@]}" db query --skip-column-names "
      SELECT option_id,option_name,autoload,LENGTH(option_value)
      FROM ${PFX}options ORDER BY option_id DESC LIMIT 300;
    " > "$OUT/db_latest_option_ids_leads_only.tsv" 2>&1 || true

    "${WP[@]}" db query --skip-column-names "
      SELECT umeta_id,user_id,meta_key,LEFT(meta_value,500)
      FROM ${PFX}usermeta ORDER BY umeta_id DESC LIMIT 300;
    " > "$OUT/db_latest_usermeta_ids_leads_only.tsv" 2>&1 || true

    "${WP[@]}" db query --skip-column-names "
      SELECT meta_id,post_id,meta_key,LENGTH(meta_value)
      FROM ${PFX}postmeta ORDER BY meta_id DESC LIMIT 500;
    " > "$OUT/db_latest_postmeta_ids_leads_only.tsv" 2>&1 || true

    "${WP[@]}" db query --skip-column-names "
      SHOW TABLES;
    " | grep -Ei '(^|_)(wf|wordfence|xyz|snippet|code|insert|security|audit)' \
      > "$OUT/db_security_snippet_related_tables.txt" 2>&1 || true

    {
      echo "DB name: $DBNAME"
      echo "Prefix : $PFX"
      "${WP[@]}" db query "
        SHOW VARIABLES WHERE Variable_name IN
        ('log_bin','binlog_format','log_bin_basename',
         'binlog_expire_logs_seconds','general_log','general_log_file');
      "
      "${WP[@]}" db query "SHOW BINARY LOGS;"
    } > "$OUT/mysql_logging_status_via_wp.txt" 2>&1 || true
  fi
else
  echo "WP-CLI not found. WordPress checksum and DB queries were skipped." \
    | tee "$OUT/wp_cli_missing.txt"
fi

section "MYSQL BINARY LOG EXTRACTION"
# This uses local socket authentication through sudo. If the server does not allow
# `sudo mysql`, the status/error will be saved and you can run it manually.
if command -v mysql >/dev/null 2>&1 && command -v mysqlbinlog >/dev/null 2>&1; then
  sudo mysql -NBe "
    SHOW VARIABLES WHERE Variable_name IN
    ('log_bin','binlog_format','log_bin_basename',
     'binlog_expire_logs_seconds','general_log','general_log_file');
    SHOW BINARY LOGS;
  " > "$OUT/mysql_logging_status_root.txt" 2>&1 || true

  BINLOG_BASE="$(sudo mysql -NBe "SELECT @@global.log_bin_basename;" 2>/dev/null || true)"
  BINLOG_DIR="$(dirname "${BINLOG_BASE:-/var/lib/mysql/binlog}")"

  if sudo mysql -NBe "SELECT @@global.log_bin;" 2>/dev/null | grep -qiE '^(1|ON)$'; then
    : > "$OUT/mysql_binlog_2026-08-03.txt"
    while read -r BINFILE _REST; do
      [ -n "${BINFILE:-}" ] || continue
      if [ -f "$BINLOG_DIR/$BINFILE" ]; then
        echo "===== $BINFILE =====" >> "$OUT/mysql_binlog_2026-08-03.txt"
        sudo mysqlbinlog \
          --start-datetime="$START" \
          --stop-datetime="$END" \
          --base64-output=DECODE-ROWS -vv \
          "$BINLOG_DIR/$BINFILE" \
          >> "$OUT/mysql_binlog_2026-08-03.txt" 2>&1 || true
      fi
    done < <(sudo mysql -NBe "SHOW BINARY LOGS;" 2>/dev/null)
  else
    echo "MySQL binary logging is OFF, unavailable, or local root socket access failed." \
      > "$OUT/mysql_binlog_unavailable.txt"
  fi
else
  echo "mysql or mysqlbinlog command is unavailable." \
    > "$OUT/mysql_binlog_unavailable.txt"
fi

section "OPTIONAL AWS CLOUDTRAIL EVENT HISTORY"
if command -v aws >/dev/null 2>&1; then
  aws cloudtrail lookup-events \
    --start-time "2026-08-03T00:00:00Z" \
    --end-time "2026-08-04T00:00:00Z" \
    --max-results 50 \
    > "$OUT/aws_cloudtrail_2026-08-03.json" 2> "$OUT/aws_cloudtrail_error.txt" || true
else
  echo "AWS CLI not installed/configured; CloudTrail lookup skipped." \
    > "$OUT/aws_cloudtrail_error.txt"
fi

section "CREATE ARCHIVE"
tar -C "$(dirname "$OUT")" -czf "${OUT}.tar.gz" "$(basename "$OUT")"
sha256sum "${OUT}.tar.gz" | tee "${OUT}.tar.gz.sha256"

echo
echo "AUDIT COMPLETE"
echo "Folder : $OUT"
echo "Archive: ${OUT}.tar.gz"
echo
echo "Do not publish the archive publicly. It can contain IP addresses, emails,"
echo "database content, WordPress configuration metadata, and other sensitive evidence."
