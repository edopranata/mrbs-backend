/* Service worker MRBS — dibuat otomatis saat build (lihat plugin `mrbsServiceWorker` di vite.config.js).
 *
 * - Tampilan aplikasi (index.html, JS, CSS, ikon) disimpan di perangkat agar aplikasi terbuka
 *   cepat dan tetap tampil saat koneksi buruk.
 * - Request /api TIDAK pernah di-cache: data jadwal & booking selalu dari server, sehingga
 *   ketersediaan ruangan tidak pernah usang.
 * - Versi baru menunggu sampai pengguna menekan "Muat ulang" (pesan SKIP_WAITING).
 */
const VERSION = 'd6bb59f5de93'
const CACHE = `mrbs-${VERSION}`
const PRECACHE = [
  "/app/assets/index-BdL-hj_m.js",
  "/app/assets/BookingsView-C2_xVwjK.js",
  "/app/assets/DashboardView-RkefT7U_.js",
  "/app/assets/EmptyState-BrEaPteO.js",
  "/app/assets/LoginView-iGy6UUe4.js",
  "/app/assets/MyBookingsView-DBkh5eYi.js",
  "/app/assets/PaginationBar-KoIkY0J7.js",
  "/app/assets/ProfileView-AnbWuITk.js",
  "/app/assets/RoomsView-CGEccRpX.js",
  "/app/assets/ScheduleView-Blt3iCzM.js",
  "/app/assets/SettingsView-QuGpz4Ax.js",
  "/app/assets/UsersView-lIOGqfqU.js",
  "/app/assets/api-CfmDWInb.js",
  "/app/assets/chevron-right-3qYwRT4w.js",
  "/app/assets/door-open-CC7_q3CE.js",
  "/app/assets/info-BP-YaESo.js",
  "/app/assets/motion-CkivEe-k.js",
  "/app/assets/plus-DLRpyJBg.js",
  "/app/assets/search-DaePIXcQ.js",
  "/app/assets/index-By0jUr2W.css",
  "/app/index.html",
  "/app/favicon.svg",
  "/app/icons/apple-touch-icon.png",
  "/app/icons/icon-192.png",
  "/app/icons/icon-512.png",
  "/app/icons/maskable-512.png"
]
const INDEX = '/app/index.html'

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)))
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('mrbs-') && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') self.skipWaiting()
})

self.addEventListener('fetch', (event) => {
  const request = event.request
  if (request.method !== 'GET') return

  const url = new URL(request.url)
  if (url.origin !== self.location.origin) return

  // Data & endpoint server selalu langsung ke jaringan.
  if (url.pathname.startsWith('/api/') || ['/up', '/sw.js', '/manifest.webmanifest'].includes(url.pathname)) return

  // Halaman: utamakan jaringan (selalu versi terbaru); saat offline pakai tampilan tersimpan.
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.match(INDEX)))
    return
  }

  // Aset hasil build: dari cache (nama file ber-hash, aman disimpan lama).
  if (PRECACHE.includes(url.pathname)) {
    event.respondWith(caches.match(url.pathname).then((cached) => cached || fetch(request)))
  }
})
