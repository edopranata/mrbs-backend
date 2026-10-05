#!/usr/bin/env bash
#
# Penjalan Laravel scheduler untuk cron hosting (mis. hPanel Hostinger → Cron Jobs → Kustom).
# Jadwalkan SETIAP MENIT:
#
#   /bin/bash /home/<user>/domains/<domain>/public_html/scripts/cron-schedule.sh
#
# Yang dilakukan:
#   - pindah ke folder aplikasi (folder induk skrip ini), jadi path apa pun aman;
#   - memakai PHP yang sesuai kebutuhan aplikasi (≥ 8.4), bukan /usr/bin/php bawaan yang bisa lebih lama;
#   - menyimpan keluaran putaran terakhir di storage/logs/cron-schedule.log (ditimpa setiap menit,
#     jadi tidak membesar). Log tugas terjadwal sendiri, mis. sinkronisasi MRBS lama, ada di
#     storage/logs/legacy-sync.log.
#
# PHP bisa dipaksa lewat variabel PHP_BIN, mis.:  PHP_BIN=/opt/alt/php85/usr/bin/php /bin/bash cron-schedule.sh

set -u

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG="$APP_DIR/storage/logs/cron-schedule.log"
MIN_VERSION=80400 # PHP 8.4 (composer.lock membutuhkan ≥ 8.4)

php_ok() { [ -x "$1" ] && [ "$("$1" -r 'echo PHP_VERSION_ID;' 2>/dev/null || echo 0)" -ge "$MIN_VERSION" ]; }

find_php() {
    if [ -n "${PHP_BIN:-}" ]; then
        php_ok "$PHP_BIN" && echo "$PHP_BIN"
        return
    fi
    # Urutan: ~/bin/php (dari scripts/php-cli.sh), PHP terbaru di /opt/alt (Hostinger/CloudLinux), lalu php di PATH.
    local candidate
    for candidate in "$HOME/bin/php" $(ls -d /opt/alt/php*/usr/bin/php 2>/dev/null | sort -rV) "$(command -v php 2>/dev/null)" /usr/bin/php; do
        if [ -n "$candidate" ] && php_ok "$candidate"; then
            echo "$candidate"
            return
        fi
    done
}

cd "$APP_DIR" || exit 1
PHP="$(find_php)"

(
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $APP_DIR"
    if [ -z "$PHP" ]; then
        echo "Gagal: PHP >= 8.4 tidak ditemukan${PHP_BIN:+ (PHP_BIN=$PHP_BIN tidak ada atau versinya terlalu lama)}."
        echo "Set PHP_BIN, mis. PHP_BIN=/opt/alt/php84/usr/bin/php. Versi yang tersedia:"
        ls -d /opt/alt/php*/usr/bin/php 2>/dev/null
        exit 1
    fi
    echo "PHP: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"
    "$PHP" artisan schedule:run --no-interaction
) > "$LOG" 2>&1
STATUS=$?

# Tampilkan juga di "Lihat Output" hPanel bila gagal.
[ $STATUS -ne 0 ] && cat "$LOG"
exit $STATUS
