#!/usr/bin/env bash
#
# Jadikan `php` dan `composer` di terminal SSH memakai PHP versi terbaru (atau versi tertentu).
# Cocok untuk shared hosting (mis. Hostinger) yang memasang beberapa versi PHP, tetapi `php`
# di SSH masih menunjuk ke versi lama.
#
# Pemakaian (di server, lewat SSH):
#   bash scripts/php-cli.sh          # pakai versi PHP tertinggi yang tersedia
#   bash scripts/php-cli.sh 8.5      # pakai versi tertentu
#   source ~/.bashrc                 # atau logout lalu login SSH lagi
#
# Yang dilakukan:
#   - membuat ~/bin/php (symlink ke binary PHP pilihan),
#   - membuat ~/bin/composer (wrapper yang SELALU menjalankan Composer dengan PHP tersebut),
#   - menaruh ~/bin di depan PATH lewat ~/.bashrc (dan memastikan ~/.bash_profile memuatnya).
# Aman dijalankan berulang kali. Tidak memengaruhi PHP website: atur itu di hPanel
# (Website → Tingkat Lanjut → Konfigurasi PHP).

set -euo pipefail

WANTED="${1:-}"
BIN_DIR="$HOME/bin"
MARK_START='# >>> mrbs php-cli >>>'
MARK_END='# <<< mrbs php-cli <<<'

say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31mGagal:\033[0m %s\n' "$*" >&2; exit 1; }

php_version() { "$1" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION.".".PHP_RELEASE_VERSION;' 2>/dev/null; }

# ---- 1. Kumpulkan semua binary PHP yang ada di server --------------------------------------
candidates=()
for path in /opt/alt/php*/usr/bin/php /opt/cpanel/ea-php*/root/usr/bin/php /opt/remi/php*/root/usr/bin/php \
    /usr/local/bin/php* /usr/bin/php* /opt/homebrew/opt/php*/bin/php; do
    name="${path##*/}"
    # Lewati php-cgi, php-config, phpize, dll.
    [[ -x "$path" && ! -d "$path" && "$name" != *-* && "$name" != *config* && "$name" != *ize* ]] && candidates+=("$path")
done
for path in $(type -ap php 2>/dev/null || true); do
    [[ "$path" != "$BIN_DIR/"* ]] && candidates+=("$path")
done

seen=$'\n'
best="" best_ver="" chosen="" chosen_ver=""
say "PHP yang ditemukan:"
for path in "${candidates[@]}"; do
    real="$(readlink -f "$path" 2>/dev/null || echo "$path")"
    [[ "$seen" == *$'\n'"$real"$'\n'* ]] && continue
    seen+="$real"$'\n'
    ver="$(php_version "$path")" || continue
    [[ -z "$ver" ]] && continue
    printf '      %-8s %s\n' "$ver" "$path"
    if [[ -z "$best_ver" ]] || [[ "$(printf '%s\n%s\n' "$best_ver" "$ver" | sort -V | tail -1)" == "$ver" && "$ver" != "$best_ver" ]]; then
        best="$path" best_ver="$ver"
    fi
    if [[ -n "$WANTED" && "$ver" == "$WANTED".* && -z "$chosen" ]]; then
        chosen="$path" chosen_ver="$ver"
    fi
done

if [[ -n "$WANTED" ]]; then
    [[ -n "$chosen" ]] || fail "PHP $WANTED tidak ditemukan. Pilih salah satu versi di atas, atau aktifkan dulu di hPanel."
else
    [[ -n "$best" ]] || fail "Tidak ada binary PHP yang ditemukan."
    chosen="$best" chosen_ver="$best_ver"
fi
say "Dipakai: PHP $chosen_ver ($chosen)"

# ---- 2. Cari Composer bawaan server (bukan wrapper kita sendiri) ---------------------------
composer_src=""
for path in $(type -ap composer composer2 2>/dev/null || true) /usr/local/bin/composer /usr/bin/composer \
    /opt/cpanel/composer/bin/composer "$HOME/.local/lib/composer.phar"; do
    [[ -f "$path" && "$path" != "$BIN_DIR/composer" ]] || continue
    # Hanya Composer berbentuk skrip PHP / phar yang bisa dijalankan dengan PHP pilihan.
    if head -c 2048 "$path" | grep -qaE '^#!.*php|<\?php|__HALT_COMPILER'; then
        composer_src="$path"
        break
    fi
done
if [[ -z "$composer_src" ]]; then
    say "Composer berbasis PHP tidak ditemukan, mengunduh composer.phar resmi..."
    mkdir -p "$HOME/.local/lib"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    "$chosen" /tmp/composer-setup.php --quiet --install-dir="$HOME/.local/lib" --filename=composer.phar
    rm -f /tmp/composer-setup.php
    composer_src="$HOME/.local/lib/composer.phar"
fi
say "Composer: $composer_src"

# ---- 3. Pasang ~/bin/php dan ~/bin/composer -----------------------------------------------
mkdir -p "$BIN_DIR"
ln -sfn "$chosen" "$BIN_DIR/php"
cat > "$BIN_DIR/composer" <<EOF
#!/usr/bin/env bash
# Dibuat oleh scripts/php-cli.sh: jalankan Composer dengan PHP $chosen_ver.
exec "$chosen" "$composer_src" "\$@"
EOF
chmod +x "$BIN_DIR/composer"

# ---- 4. PATH permanen (blok ditandai, diganti bila script dijalankan ulang) ----------------
block="$MARK_START
export PATH=\"\$HOME/bin:\$PATH\"
# Alias php/composer (dari sistem atau pengaturan lama) akan menimpa ~/bin; hapus.
unalias php composer 2>/dev/null || true
$MARK_END"
touch "$HOME/.bashrc"
if grep -qE '^[[:space:]]*alias (php|composer)=' "$HOME/.bashrc"; then
    say "Menghapus alias php/composer lama di ~/.bashrc (cadangan: ~/.bashrc.bak-php-cli)"
    cp "$HOME/.bashrc" "$HOME/.bashrc.bak-php-cli"
    grep -vE '^[[:space:]]*alias (php|composer)=' "$HOME/.bashrc.bak-php-cli" > "$HOME/.bashrc"
fi
if grep -qF "$MARK_START" "$HOME/.bashrc"; then
    awk -v s="$MARK_START" -v e="$MARK_END" '$0==s{skip=1} !skip{print} $0==e{skip=0}' "$HOME/.bashrc" > "$HOME/.bashrc.mrbs-tmp"
    mv "$HOME/.bashrc.mrbs-tmp" "$HOME/.bashrc"
fi
printf '\n%s\n' "$block" >> "$HOME/.bashrc"
# Shell login SSH membaca ~/.bash_profile; pastikan ia memuat ~/.bashrc.
if ! grep -qs 'bashrc' "$HOME/.bash_profile"; then
    printf '\n[ -f ~/.bashrc ] && . ~/.bashrc\n' >> "$HOME/.bash_profile"
fi

# ---- 5. Verifikasi -----------------------------------------------------------------------
export PATH="$BIN_DIR:$PATH"
hash -r
say "Hasil:"
printf '      php      -> %s\n' "$(php -r 'echo PHP_VERSION;')"
composer --version 2>/dev/null | sed 's/^/      composer -> /'

if [[ -f composer.json ]]; then
    say "Memeriksa kebutuhan PHP & ekstensi proyek ini (composer check-platform-reqs):"
    composer check-platform-reqs --no-dev 2>/dev/null | grep -vE ' success$' || true
    composer check-platform-reqs --no-dev >/dev/null 2>&1 && echo "      Semua kebutuhan terpenuhi." || echo "      Ada kebutuhan yang belum terpenuhi (lihat di atas)."
fi

say "Selesai. Jalankan:  source ~/.bashrc   (atau logout lalu login SSH lagi)"
