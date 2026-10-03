# MRBS Backend — Meeting Room Booking System API

REST API untuk aplikasi pemesanan ruang rapat kantor, dibangun dengan **Laravel 13** dan
**Laravel Sanctum** (autentikasi token). Frontend (Vue 3) ada di repository terpisah:
[edopranata/mrbs-vue](https://github.com/edopranata/mrbs-vue).

## Data ruangan

Repository ini hanya berisi **data ruangan fiktif** di
[`database/seeders/DummyRoomSeeder.php`](database/seeders/DummyRoomSeeder.php) (7 ruang rapat di
lantai 1–4). Data ruangan sebenarnya disimpan di `database/seeders/RoomSeeder.php` yang
**tidak ikut git** (lihat `.gitignore`).

- `php artisan db:seed` memakai `RoomSeeder` bila file tersebut ada, dan otomatis memakai
  `DummyRoomSeeder` bila tidak ada (mis. hasil clone repository).
- Di server produksi: salin `DummyRoomSeeder.php` menjadi `RoomSeeder.php`, ganti nama class menjadi
  `RoomSeeder`, isi dengan data ruangan sebenarnya, lalu jalankan
  `php artisan db:seed --class=RoomSeeder`. File ini tidak akan ter-commit.
- Ruangan juga bisa dikelola langsung dari menu **Ruangan** (admin).

## Level user

| Fitur | User | Admin | System Admin |
|---|:-:|:-:|:-:|
| Lihat jadwal semua ruangan (per hari, per lantai) | ✅ | ✅ | ✅ |
| Buat booking | ✅ (maks. 60 hari ke depan) | ✅ (tanpa batas) | ✅ (tanpa batas) |
| Ubah / batalkan booking | Milik sendiri | Semua booking | Semua booking |
| Hapus booking permanen | – | ✅ | ✅ |
| Kelola ruangan (tambah, ubah, nonaktifkan, hapus) | – | ✅ | ✅ |
| Kelola user (tambah, ubah level, nonaktifkan, hapus) | – | ✅ (kecuali akun System Admin) | ✅ (termasuk System Admin) |
| Menu **Semua Booking**: pantauan booking hari ini (sedang berlangsung & akan datang) | – | ✅ | ✅ |
| Statistik pemakaian ruangan bulanan | – | ✅ | ✅ |
| Menu **Pengaturan** aplikasi | – | – | ✅ |

### Menu Pengaturan (System Admin)

System Admin dapat mengubah nama & subjudul aplikasi, jam operasional, interval slot (15/30/60
menit), durasi minimal/maksimal, batas hari pemesanan, dan batas minggu booking berulang langsung
dari aplikasi. Nilai dari menu ini disimpan di tabel `settings` dan **menimpa** nilai `MRBS_*` di
`.env`; tombol *Kembalikan semua ke default* menghapus nilai tersimpan sehingga `.env` berlaku lagi.

## Aturan booking

- Tidak boleh bentrok dengan booking lain di ruangan yang sama. Booking berurutan boleh
  (misal 09:00–10:00 lalu 10:00–11:00). Booking yang dibatalkan tidak menghalangi.
- Hanya di jam operasional (default 07:00–20:00) dan selesai di hari yang sama.
- Jam mulai & selesai mengikuti interval 30 menit (09:00, 09:30, …), durasi 30 menit s/d 8 jam,
  tidak boleh di masa lalu, jumlah peserta ≤ kapasitas ruangan.
- Ruangan nonaktif tidak bisa dipesan.

### Booking berulang mingguan

Saat membuat booking, aktifkan **Ulangi setiap minggu** lalu pilih berapa minggu (default maks.
8 minggu, diatur `MRBS_MAX_REPEAT_WEEKS`). Booking dibuat di hari & jam yang sama setiap minggu.

- Form menampilkan status setiap tanggal. Bila ada tanggal yang bentrok (atau, untuk user biasa,
  melewati batas `MRBS_MAX_ADVANCE_DAYS`), booking ditolak, kecuali **Lewati tanggal yang
  bentrok** dicentang: tanggal tersebut dilewati dan sisanya tetap dibuat.
- Setiap minggu tersimpan sebagai booking tersendiri (dengan `series_id` yang sama), jadi bisa
  diubah satu per satu. Saat membatalkan, pilih *hanya booking ini* atau *booking ini &
  minggu-minggu berikutnya*.

Semua nilai di atas bisa diatur lewat `.env` (`MRBS_OPEN_TIME`, `MRBS_CLOSE_TIME`,
`MRBS_SLOT_MINUTES`, `MRBS_MIN_DURATION`, `MRBS_MAX_DURATION`, `MRBS_MAX_ADVANCE_DAYS`,
`MRBS_MAX_REPEAT_WEEKS`).
Grid jadwal dan pilihan jam di form otomatis mengikuti `MRBS_SLOT_MINUTES`. Pengecekan bentrok dijalankan
di dalam transaksi database dengan penguncian baris ruangan, sehingga dua orang yang memesan slot
yang sama secara bersamaan tidak akan sama-sama berhasil.

---

## Menjalankan di lokal

Kebutuhan: PHP ≥ 8.3 dan Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve           # http://127.0.0.1:8000
```

`--seed` membuat 7 ruangan, 3 akun default, dan (hanya bila `APP_ENV=local`) contoh booking untuk
beberapa hari kerja di sekitar hari ini. Untuk mengulang dari awal: `php artisan migrate:fresh --seed`.

Lalu jalankan frontend dari repository [mrbs-vue](https://github.com/edopranata/mrbs-vue); saat
development, Vite meneruskan request `/api/*` ke `http://127.0.0.1:8000`.

## Frontend dalam satu domain (public/app)

Frontend Vue bisa disajikan langsung oleh Laravel. Dari repository
[mrbs-vue](https://github.com/edopranata/mrbs-vue) (diletakkan bersebelahan dengan folder backend):

```bash
npm run build:laravel       # hasil build -> ../backend/public/app
```

Setelah itu semua URL halaman (`/`, `/jadwal`, `/admin/users`, …) menampilkan
`public/app/index.html`, sedangkan `/api/*` dan `/up` tetap ditangani Laravel:

- **Apache**: aturan di `public/.htaccess` mengarahkan URL halaman langsung ke `app/index.html`
  (tanpa PHP). `index.html` tidak di-cache, aset `app/assets/*` di-cache 1 tahun.
- **Nginx / `php artisan serve`**: ditangani `SpaController` (route `/` dan fallback di
  `routes/web.php`), tanpa membuat session/cookie.

Bila folder `public/app` belum ada, membuka halaman menampilkan pesan untuk menjalankan
`npm run build:laravel`.

## Akun default

Login memakai **username** (tidak peka huruf besar/kecil), bukan email.

| Level | Username | Password |
|---|---|---|
| System Admin | `sysadmin` | `password` |
| Admin | `admin` | `password` |
| User | `user` | `password` |

> Akun default hanya dibuat di lingkungan non-production. **Di server produksi** buat akun dengan
> `php artisan mrbs:create-sysadmin` (lihat bagian deploy). Menjalankan ulang seeder tidak menimpa
> akun yang sudah ada.

## Menjalankan test

```bash
php artisan test
```

## Memakai MySQL

Ubah `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mrbs
DB_USERNAME=root
DB_PASSWORD=
```

Lalu buat database `mrbs` dan jalankan `php artisan migrate --seed`.

## Deploy ke production (ringkas)

Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, dan koneksi database, lalu jalankan:

```bash
composer install --no-dev -o
php artisan migrate --force --seed
php artisan optimize
```

Arahkan web server ke folder `public`. Laravel secara default mengizinkan CORS untuk `api/*`; bila
frontend berada di domain lain dan ingin dibatasi, jalankan `php artisan config:publish cors` lalu
atur `allowed_origins`.

### Membuat akun System Admin di server

Di `APP_ENV=production`, seeder **tidak** membuat akun default (yang berpassword `password`).
Buat akun System Admin pertama dengan:

```bash
php artisan mrbs:create-sysadmin
```

Command akan menanyakan username, nama, email, dan password (diketik tersembunyi + konfirmasi).
Untuk otomatisasi bisa memakai opsi `--username= --name= --email= --password=` (hindari `--password`
di shell bersama karena tersimpan di riwayat). Bila username sudah ada, tambahkan `--force` untuk
menjadikannya System Admin, mengaktifkannya, dan mengganti passwordnya (sesi lamanya dicabut).

## Ringkasan API

Semua endpoint berawalan `/api` dan (kecuali login) memakai header `Authorization: Bearer <token>`.

| Method | Endpoint | Akses | Keterangan |
|---|---|---|---|
| POST | `/auth/login` | publik | `{username, password}` → `{token, user}` (maks. 10 percobaan/menit) |
| GET | `/auth/me` | login | Data user yang login |
| POST | `/auth/logout` | login | Cabut token |
| PUT | `/auth/profile` | login | Ubah nama, divisi, telepon |
| PUT | `/auth/password` | login | `{current_password, password, password_confirmation}` |
| GET | `/settings` | publik | Nama aplikasi, jam operasional & aturan booking yang berlaku |
| GET | `/settings/manage` | system admin | Nilai berlaku, nilai default (.env), dan kunci yang ditimpa |
| PUT | `/settings` | system admin | Ubah pengaturan (`app_name`, `app_subtitle`, `open_time`, `close_time`, `slot_minutes`, `min_duration`, `max_duration`, `max_advance_days`, `max_repeat_weeks`) |
| DELETE | `/settings` | system admin | Kembalikan semua pengaturan ke default |
| GET | `/dashboard` | login | Statistik & booking hari ini (+ statistik admin) |
| GET | `/schedule?date=YYYY-MM-DD` atau `?from=&to=` | login | Ruangan aktif + booking pada tanggal/rentang tsb (maks. 42 hari). Filter opsional: `floor`, `room_id` |
| GET | `/rooms` | login | Daftar ruangan (user hanya melihat yang aktif) |
| GET | `/rooms/availability?start_at=&end_at=&participants=` | login | Status tersedia/bentrok tiap ruangan |
| GET | `/rooms/{id}` | login | Detail ruangan |
| POST / PUT / DELETE | `/rooms`, `/rooms/{id}` | admin | Kelola ruangan |
| GET | `/bookings` | login | Filter: `mine`, `room_id`, `user_id`, `status`, `period=upcoming\|past`, `date_from`, `date_to`, `search`, `page` |
| POST | `/bookings` | login | `{room_id, title, start_at, end_at, type?, participants?, description?, repeat_weeks?, skip_conflicts?}`. `type`: `internal` (default) / `external`. Dengan `repeat_weeks` > 1 respons berisi daftar booking + `skipped` |
| GET | `/bookings/today` | admin | Booking hari ini yang sedang berlangsung (`ongoing`) & akan datang (`upcoming`); yang sudah selesai/dibatalkan tidak disertakan |
| GET | `/bookings/occurrences?room_id=&start_at=&end_at=&repeat_weeks=` | login | Pratinjau tanggal booking mingguan beserta status tersedia/alasan |
| GET | `/bookings/{id}` | login | Detail booking |
| PUT | `/bookings/{id}` | pemilik / admin | Ubah booking |
| POST | `/bookings/{id}/cancel` | pemilik / admin | `{reason?, scope?}`. `scope=following` membatalkan booking ini & minggu-minggu berikutnya dalam seri |
| DELETE | `/bookings/{id}` | admin | Hapus permanen |
| GET / POST / PUT / DELETE | `/users`, `/users/{id}` | admin | Kelola user |

Format waktu request: `"YYYY-MM-DD HH:mm"`. Waktu di response adalah waktu lokal kantor
(`APP_TIMEZONE`, default `Asia/Jakarta`).

## Struktur kode penting

```
app/Services/BookingService.php     # aturan bisnis, cek bentrok, booking berulang
app/Services/AppSettings.php        # pengaturan dari menu Pengaturan (menimpa config/mrbs.php)
app/Policies/BookingPolicy.php      # siapa boleh ubah/batalkan
app/Http/Controllers/Api/           # controller REST
app/Http/Middleware/                # EnsureUserIsAdmin, EnsureUserIsSystemAdmin, EnsureUserIsActive
config/mrbs.php                     # default jam operasional & aturan booking (dari .env)
database/seeders/                   # ruangan, akun default, demo booking
tests/Feature/                      # test auth, booking, booking berulang, akses admin, pengaturan
```
