/* Service worker MRBS — dibuat otomatis saat build (lihat plugin `mrbsServiceWorker` di vite.config.js).
 *
 * - Tampilan aplikasi (index.html, JS, CSS, ikon) disimpan di perangkat agar aplikasi terbuka
 *   cepat dan tetap tampil saat koneksi buruk.
 * - Request /api TIDAK pernah di-cache: data jadwal & booking selalu dari server, sehingga
 *   ketersediaan ruangan tidak pernah usang.
 * - Versi baru menunggu sampai pengguna menekan "Muat ulang" (pesan SKIP_WAITING).
 */
const VERSION = '0dc3f57bd982'
const CACHE = `mrbs-${VERSION}`
const PRECACHE = [
  "/app/assets/index-CKd45icN.js",
  "/app/assets/AuditLogView-CgZfVBVV.js",
  "/app/assets/BookingsView-svFn5YPs.js",
  "/app/assets/DashboardView-Cmw5bHd_.js",
  "/app/assets/EmptyState-BgJhmQkh.js",
  "/app/assets/LoginView-Bzph_JtH.js",
  "/app/assets/MyBookingsView-BcDxTYTZ.js",
  "/app/assets/PaginationBar-CIdZZ3hg.js",
  "/app/assets/ProfileView-DlW-1L1d.js",
  "/app/assets/RoomsView-CB6b0B3e.js",
  "/app/assets/ScheduleView-BZ78qGQY.js",
  "/app/assets/SettingsView-D2sSmH4P.js",
  "/app/assets/UsersView-QtDOXKnq.js",
  "/app/assets/api-BXKzk9iJ.js",
  "/app/assets/chevron-right-V8p12c9O.js",
  "/app/assets/door-open-D8Ie3-aG.js",
  "/app/assets/info-BcAMMFRG.js",
  "/app/assets/motion-CkivEe-k.js",
  "/app/assets/plus-DzWSv6h-.js",
  "/app/assets/search-DpUM6lQo.js",
  "/app/assets/index-BGw_IIuT.css",
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
