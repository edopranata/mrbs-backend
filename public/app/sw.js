/* Service worker MRBS — dibuat otomatis saat build (lihat plugin `mrbsServiceWorker` di vite.config.js).
 *
 * - Tampilan aplikasi (index.html, JS, CSS, ikon) disimpan di perangkat agar aplikasi terbuka
 *   cepat dan tetap tampil saat koneksi buruk.
 * - Request /api TIDAK pernah di-cache: data jadwal & booking selalu dari server, sehingga
 *   ketersediaan ruangan tidak pernah usang.
 * - Versi baru menunggu sampai pengguna menekan "Muat ulang" (pesan SKIP_WAITING).
 */
const VERSION = 'f37f5535f2c0'
const CACHE = `mrbs-${VERSION}`
const PRECACHE = [
  "/app/assets/index-C1HePcyw.js",
  "/app/assets/AuditLogView-Bx-lgsdT.js",
  "/app/assets/BookingsView-BqPl3baz.js",
  "/app/assets/DashboardView-BBuhP5Cf.js",
  "/app/assets/EmptyState-Dwtg4kcs.js",
  "/app/assets/LoginView-CW4KpmvK.js",
  "/app/assets/MyBookingsView-B2RYA8LU.js",
  "/app/assets/PaginationBar-CyJVCnox.js",
  "/app/assets/ProfileView-BJiEktQc.js",
  "/app/assets/RoomsView-D-9HFxMV.js",
  "/app/assets/ScheduleView-C2eVmSCy.js",
  "/app/assets/SettingsView-DyGnURK8.js",
  "/app/assets/UsersView-DdhGltY-.js",
  "/app/assets/api-BZzNF0bQ.js",
  "/app/assets/chevron-right-pl18Uv4J.js",
  "/app/assets/door-open-CEiA51vS.js",
  "/app/assets/info-CFrw56dK.js",
  "/app/assets/motion-CkivEe-k.js",
  "/app/assets/plus-zd4LJV_n.js",
  "/app/assets/search-CQPNrCRa.js",
  "/app/assets/index-DdLM0fPh.css",
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
