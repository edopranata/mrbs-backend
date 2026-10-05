/* Service worker MRBS — dibuat otomatis saat build (lihat plugin `mrbsServiceWorker` di vite.config.js).
 *
 * - Tampilan aplikasi (index.html, JS, CSS, ikon) disimpan di perangkat agar aplikasi terbuka
 *   cepat dan tetap tampil saat koneksi buruk.
 * - Request /api TIDAK pernah di-cache: data jadwal & booking selalu dari server, sehingga
 *   ketersediaan ruangan tidak pernah usang.
 * - Versi baru menunggu sampai pengguna menekan "Muat ulang" (pesan SKIP_WAITING).
 */
const VERSION = 'f74dd4f92120'
const CACHE = `mrbs-${VERSION}`
const PRECACHE = [
  "/app/assets/index-z7oFeYpB.js",
  "/app/assets/BookingsView-DFYdlGBg.js",
  "/app/assets/DashboardView-iDQGpKiV.js",
  "/app/assets/EmptyState-Dwtg4kcs.js",
  "/app/assets/LoginView-Bmenk17X.js",
  "/app/assets/MyBookingsView-XSBaBFUA.js",
  "/app/assets/PaginationBar-CyJVCnox.js",
  "/app/assets/ProfileView-CCEqJxBy.js",
  "/app/assets/RoomsView-_Lbpaxj7.js",
  "/app/assets/ScheduleView-CO9mBYNg.js",
  "/app/assets/SettingsView-Dt4xIH_C.js",
  "/app/assets/UsersView-BJS3DRoI.js",
  "/app/assets/api-BZzNF0bQ.js",
  "/app/assets/chevron-right-pl18Uv4J.js",
  "/app/assets/door-open-CEiA51vS.js",
  "/app/assets/info-CFrw56dK.js",
  "/app/assets/motion-CkivEe-k.js",
  "/app/assets/plus-zd4LJV_n.js",
  "/app/assets/search-CQPNrCRa.js",
  "/app/assets/index-D7JMvFM7.css",
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
