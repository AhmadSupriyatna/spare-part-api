# Project Brief: Rebuild Sistem Manajemen Spare Part (v2)

> Dokumen acuan pengembangan untuk `spare-part-api` (Laravel) dan `spare-part-web` (React).
> Update bagian **Status Perkembangan** dan **Changelog** setiap ada progres baru — jangan ubah isi Bagian 1-7 (brief asli) kecuali arah proyek memang berubah.

---

## 1. Konteks

Ini adalah rebuild total dari sistem manajemen spare part yang sebelumnya dibangun dengan Laravel + Filament. Versi pertama gagal dari sisi UX/alur kerja karena Filament dirancang untuk admin CRUD generator, bukan untuk workflow custom yang dibutuhkan teknisi di lantai produksi. Versi ini membangun ulang dari nol dengan frontend React yang sepenuhnya custom, sambil mempertahankan backend Laravel sebagai API murni.

**Target pengguna:** pabrik/factory di Indonesia — teknisi, admin gudang, supervisor, superadmin (multi-tenant di masa depan).

**Skala awal:** single-tenant, satu pabrik, untuk validasi cepat sebelum ekspansi ke multi-tenant SaaS.

---

## 2. Tech Stack

### Backend
- **Laravel 11** — API-only (tidak render Blade/Filament untuk UI utama)
- **PostgreSQL** — database utama
- **Laravel Sanctum** — autentikasi token-based untuk SPA
- **Spatie Laravel-permission** — role & permission (teknisi, admin gudang, supervisor, superadmin)
- **Spatie Laravel-activitylog** — audit trail semua perubahan stok/data kritikal
- **Filament** — dipertahankan HANYA untuk panel internal superadmin (kelola tenant, user, monitoring), bukan untuk UI end-user

### Frontend
- **React 18 + TypeScript + Vite**
- **Tailwind CSS + shadcn/ui** — komponen UI, ringan dan mudah dikustomisasi
- **TanStack Query (React Query)** — data fetching, caching, sinkronisasi state server
- **Zustand** — state management lokal (ringan, bukan Redux)
- **React Hook Form + Zod** — form handling & validasi (schema Zod bisa dipakai sebagai referensi validasi backend)
- **React Router** — routing SPA

### Offline-First
- **PWA** dengan service worker (Workbox)
- **Dexie.js** (IndexedDB wrapper) — cache lokal & antrian aksi saat offline
- Sinkronisasi ke backend menggunakan pola stock ledger append-only saat kembali online

### Keamanan
- Sanctum token-based auth (hindari kerumitan CSRF session cross-domain)
- RBAC granular via Spatie permission, mapping ke role di atas
- Form Request validation di setiap endpoint — jangan percaya raw input
- Rate limiting di endpoint sensitif (login, penyesuaian stok)
- Isolasi data: `tenant_id` scoping dengan Eloquent global scope (single DB, bukan DB terpisah per tenant)
- HTTPS wajib, secrets di `.env`, backup database terjadwal
- 2FA opsional untuk role admin/superadmin (Laravel Fortify)
- XSS: andalkan escaping default React, hindari `dangerouslySetInnerHTML` tanpa sanitasi
- SQL injection: gunakan Eloquent ORM, hindari raw query tanpa binding

### Testing & Tooling
- **Pest** — testing backend PHP
- **Vitest + React Testing Library** — testing frontend

---

## 3. Modul Inti Aplikasi

1. **Task Management** — tugas terikat ke lifetime/periode maintenance part
2. **Work Orders** — library task terjadwal dan tidak terjadwal
3. **Parts Receiving & Stock Management** — penerimaan barang, update stok
4. **Crisis Stock Alerts** — notifikasi saat stok kritis
5. **Reorder Automation** — otomatisasi pemesanan ulang
6. **Supplier & Part Linking** — relasi supplier ke part
7. **Part Lifetime & Runtime Tracking** — pelacakan usia pakai part
8. **Part Retrieval** — pencarian lokasi part berdasarkan rack/bin

---

## 4. Arsitektur Data Kunci

- **Stock Ledger Pattern**: semua perubahan stok dicatat sebagai entri append-only (bukan overwrite kolom quantity langsung). Quantity saat ini dihitung dari sum ledger, atau di-cache dengan rekonsiliasi berkala.
- **Database transactions** wajib untuk setiap operasi yang mengubah stok, guna menangani concurrent multi-user writes.
- Pola ledger ini juga jadi dasar rekonsiliasi konflik saat sinkronisasi data offline dari client.

---

## 5. Struktur Proyek

Disarankan **dua repo terpisah** (bukan monorepo) agar deployment dan versioning independen:
- `spare-part-api` (Laravel)
- `spare-part-web` (React)

Komunikasi murni via REST API dengan kontrak OpenAPI/Swagger opsional untuk dokumentasi.

---

## 6. Urutan Fase Pembangunan

1. **Fase 0 — Setup**: scaffold Laravel API + React app, setup Sanctum auth, setup role/permission dasar
2. **Fase 1 — Core Data Model**: schema part, stock ledger, supplier, rack/bin location
3. **Fase 2 — Stock & Receiving**: alur penerimaan barang dan penyesuaian stok dengan ledger
4. **Fase 3 — Task & Work Order**: task management terikat lifetime part
5. **Fase 4 — Alerts & Automation**: crisis stock alert, reorder automation
6. **Fase 5 — Offline Support**: PWA, IndexedDB cache, sync queue
7. **Fase 6 — Internal Admin Panel**: Filament untuk superadmin (tenant/user management)
8. **Fase 7 — Polish & Hardening**: audit log, rate limiting, 2FA, testing menyeluruh

---

## 7. Instruksi untuk AI Coding Assistant

Kamu adalah senior full-stack developer yang membantu solo developer membangun ulang sistem manajemen spare part untuk pabrik di Indonesia. Gunakan stack yang tercantum di atas persis seperti yang ditentukan — jangan mengganti library tanpa alasan kuat yang didiskusikan dulu.

Mulai dari **Fase 0**: buatkan scaffold dua repo terpisah (`spare-part-api` dengan Laravel 11 + Sanctum + Spatie permission, dan `spare-part-web` dengan Vite + React + TypeScript + Tailwind + shadcn/ui), termasuk:
- Struktur folder awal yang rapi untuk kedua repo
- Setup autentikasi dasar (login/logout) end-to-end antara API dan SPA
- Setup role dasar: teknisi, admin_gudang, supervisor, superadmin
- Migration awal untuk tabel `parts`, `stock_ledger`, `suppliers`, `locations` (rack/bin)
- Konfigurasi CORS antara API dan SPA

Setelah scaffold ini berjalan, lanjutkan ke Fase 1 sesuai urutan di atas. Konfirmasi ke saya di setiap akhir fase sebelum lanjut ke fase berikutnya.

---

## 8. Arah Menuju SaaS Multi-Tenant

Brief ini sengaja mulai **single-tenant** (satu pabrik) supaya alur kerja tervalidasi dulu di lapangan sebelum investasi ke kompleksitas multi-tenant. Jalur migrasi yang direncanakan ke SaaS:

- **Sekarang (single-tenant):** semua tabel inti (`branches`, `parts`, `suppliers`, dst.) sudah dirancang dengan konsep **multi-branch** (satu perusahaan, banyak cabang) — ini adalah fondasi yang sama dipakai nanti untuk multi-tenant, cukup naik satu level (tenant → branches → data).
- **Langkah menuju multi-tenant:**
  1. Tambah tabel `tenants` dan kolom `tenant_id` di tabel-tabel inti (atau di tabel `branches` sebagai induk, tergantung apakah 1 tenant = 1 perusahaan dengan banyak cabang).
  2. Terapkan Eloquent **global scope** otomatis berdasarkan `tenant_id` dari user yang login (single DB, row-level isolation — bukan DB terpisah per tenant, supaya operasional lebih murah di awal).
  3. Sesuaikan Sanctum agar token terikat ke tenant, dan tambahkan guard/middleware yang menolak akses lintas tenant.
  4. Bangun panel superadmin (Filament, Fase 6) untuk kelola tenant: onboarding pabrik baru, billing, monitoring pemakaian.
  5. Evaluasi kebutuhan billing (mis. Laravel Cashier) baru di fase ini, bukan lebih awal — hindari over-engineering sebelum ada tenant kedua yang nyata.
- **Prinsip:** jangan bangun infrastruktur multi-tenant penuh sebelum single-tenant benar-benar dipakai dan tervalidasi oleh pabrik pertama. Tapi desain data (terutama relasi ke `branches`) harus tetap dijaga agar tidak menyulitkan migrasi nanti.

---

## 9. Status Perkembangan

_Terakhir diperiksa: 2026-09-14, berdasarkan git log kedua repo._

### Deviasi dari brief awal (stack aktual terpasang)
| Area | Brief | Aktual terpasang |
|---|---|---|
| Laravel | 11 | **12** (`^12.0`) |
| React | 18 | **19** (`^19.2`) |
| Tailwind | — | **v4** (`^4.3`) |
| React Router | — | **v8** (`^8.3`) |
| Zod | — | **v4** |
| Offline (Dexie/Workbox) | Fase 5 | belum ada |
| Filament (admin panel) | Fase 6 | belum ada |
| Pest / Vitest | testing | belum disetup penuh (baru `phpunit/phpunit` di backend, belum ada test runner di frontend) |
| Multi-tenant (`tenant_id`) | arah masa depan | belum ada — masih single-tenant, tapi struktur `branches` sudah siap jadi fondasi (lihat Bagian 8) |

### Progres per fase

- ✅ **Fase 0 — Setup**: Laravel scaffold + Sanctum + Spatie roles/permission + activity log; React + Vite + TS scaffold; auth login/logout end-to-end jalan.
- ✅ **Fase 1 — Core Data Model**: `branches`, `parts`, `suppliers`, `locations`, arsitektur multi-branch (branch-scoped stock & suppliers).
- ✅ **Fase 2 — Stock & Receiving**: `part_stocks`, `stock_ledger` (append-only), alur receiving & adjustment stok + halaman UI.
- ✅ **Fase 3 — Task & Work Order**: hierarki Line → Machine → Equipment, `work_orders`, `tasks`, halaman Task Management & Work Order.
- ✅ **Fase 4 — Alerts & Automation**: `stock_alerts`, `reorder_requests` (backend + halaman Alerts).
- 🔶 **Ekstensi pasca-Fase 4** (belum ada di rencana fase asli, ditambah organik):
  - Part price & foto upload; rename `sku` → `item_master_no`
  - Runtime hours tracking dipindah dari Machine ke Line
  - Endpoint `GET /users`
  - Halaman Suppliers, Locations, Branches management
  - ✅ **UI selesai (2026-09-14)**: `part_suppliers` (approved-supplier list per part), `equipment_parts` (Equipment BOM), `part_installations` (part lifetime tracking) — lihat detail di bawah.
- ⬜ **Fase 5 — Offline Support**: belum dimulai.
- ⬜ **Fase 6 — Internal Admin Panel (Filament)**: belum dimulai.
- ⬜ **Fase 7 — Polish & Hardening**: belum dimulai (belum ada 2FA, test suite belum lengkap).

- ✅ **Ekstensi lanjutan (2026-09-14)**: relasi dua arah Supplier↔Part dan Location↔Part, aturan keamanan satu-part-satu-bin, halaman Part Detail dirombak jadi tab, dan riwayat pemasangan part lintas-equipment (lihat changelog).

### Next up — Modul Pengeluaran Part / PM vs Corrective (disepakati 2026-09-14, lanjut di PC rumah)

User mengonfirmasi model mental berikut untuk Work Order & Task (cocok dengan struktur data yang sudah ada, tapi belum semuanya kepakai/kelihatan di UI):

- **"Library Task"** = `WorkOrder` (sudah ada sebagai model): definisi kegiatan maintenance yang reusable — Line → Mesin → Equipment (`equipment_id`, tinggal ikuti relasi ke Machine/Line), kegiatan apa (`title`/`description`), part terkait (`part_id`, dipakai BOM `equipment_parts.quantity_required` sebagai default qty), interval maintenance (`schedule_type`: calendar/runtime + `interval_days`/`interval_hours`).
- **WO Preventive Maintenance** = `Task` yang lahir dari sebuah `WorkOrder` (`task.work_order_id` terisi) — regenerate otomatis tiap kali task-nya selesai (`TaskService::generateNextTask()`), sesuai interval kalender atau jam operasi line.
- **WO Corrective** = `Task` ad-hoc tanpa `WorkOrder` (`task.work_order_id` NULL) — dipakai untuk breakdown/pergantian part mendadak saat produksi berhenti; field `cause` sudah ada untuk mencatat penyebabnya.
- Konsep ini **sudah didukung strukturnya** oleh model yang ada, tapi belum eksplisit di UI (tidak ada label "PM"/"Corrective" yang kelihatan, WO dan Task ad-hoc dibuat dari form terpisah tanpa penjelasan bedanya).

**Yang diminta user, belum dikerjakan (PR berikutnya)**:
1. **Kalender untuk jadwal WO PM** — tampilan kalender yang menunjukkan semua WO terjadwal (calendar-based) beserta task yang akan jatuh tempo, supaya bisa direncanakan dari satu tempat, bukan cuma daftar per-equipment seperti sekarang.
2. **Trigger otomatis dari life time part yang akan habis** — modul part lifetime tracking (`part_installations`) sudah ada datanya (kapan part dipasang, life time-nya), tapi belum ada yang memunculkan/menyarankan WO PM otomatis saat life time part di suatu equipment mendekati habis. Perlu job/scheduler yang membandingkan life time part terpasang vs threshold, lalu memunculkan sebagai kandidat WO/Task (mirip pola `stock_alerts` yang sudah ada untuk stok menipis).
3. Pertimbangkan menambahkan label/badge "PM" vs "Corrective" yang eksplisit di UI Task/WO list (turunan dari ada/tidaknya `work_order_id`), supaya user tidak perlu menebak dari konteks.

**Catatan**: user akan lanjut kerjakan ini dari PC rumah (bukan PC ini) — pastikan branch/commit sudah ter-push sebelum pindah kalau perlu diakses dari sana.

---

## Changelog

- **2026-09-14** — Dokumen dibuat. Project di-clone ulang ke PC baru (`C:\Users\ADM_1\Herd\sms\`), environment lokal disiapkan (Postgres, Herd, dependency), status perkembangan direkonsiliasi dengan git log aktual. Ditambahkan Bagian 8 (arah SaaS multi-tenant) dan Bagian 9 (status perkembangan) atas permintaan user.
- **2026-09-14** — Selesai bangun UI untuk 3 fitur yang backend & API layer-nya sudah siap (lanjutan `a109f9a`):
  - `PartSupplierFormDialog` + section "Supplier yang Disetujui" di `PartDetailPage` (tambah/ubah/hapus supplier per part, tandai preferred)
  - `EquipmentPartFormDialog` + section "Bill of Material (BOM)" di `EquipmentDetailPage` (tambah/hapus part sebagai komponen equipment)
  - `PartInstallationFormDialog` + section "Riwayat Pemasangan Part" di `EquipmentDetailPage` (pasang/lepas part, tampilkan usia & persen pemakaian)
  - `PartDetailPage` juga dapat section baru "Digunakan di Equipment (BOM)" — reverse lookup read-only.
  - Diverifikasi: `tsc --noEmit` bersih, `oxlint` bersih (tanpa warning baru), `npm run build` sukses, dan ketiga endpoint dites langsung end-to-end via curl (GET + POST) dengan token superadmin — semua response cocok dengan tipe TypeScript yang sudah ada.
- **2026-09-14** — User minta relasi dua arah yang sebelumnya belum ada: dari halaman **Supplier** bisa lihat/tambah part yang disuplai, dan dari halaman **Location (rak/bin)** bisa lihat/tambah part yang ditempatkan di situ.
  - Backend (`spare-part-api`, commit `d9e0bfe`): endpoint baru `GET /suppliers/{supplier}/parts` (reverse dari `PartSupplierController`), `GET /locations/{location}/part-stocks` dan `PUT /part-stocks/{partStock}/location` (baru — sebelumnya location sebuah part_stock tidak bisa diubah sama sekali lewat API, cuma keisi random waktu seed). Validasi cross-branch ditambahkan (lokasi harus satu cabang dengan part_stock-nya), mengikuti pola yang sudah ada di `ReceiveStockRequest` untuk supplier.
  - Frontend (`spare-part-web`, commit `283981c`): halaman baru `SupplierDetailPage` (`/suppliers/:id`) dan `LocationDetailPage` (`/locations/:id`), plus dialog `AddPartToSupplierDialog` dan `AssignPartToLocationDialog`. Nama supplier/lokasi di halaman list sekarang jadi link ke detailnya.
  - Diverifikasi: build + type-check + lint bersih, dan seluruh endpoint baru dites end-to-end via curl (termasuk validasi cross-branch).
  - Catatan desain: satu part hanya bisa berada di **satu lokasi per cabang** (constraint unique `part_id+branch_id` di tabel `part_stocks` sudah ada dari awal) — jadi "tambah part ke lokasi" sebenarnya adalah **memindahkan** part_stock yang sudah ada ke lokasi baru, bukan membuat penempatan kedua.
- **2026-09-14** — Dua permintaan lanjutan dari user:
  1. **Aturan keamanan satu-part-satu-bin**: part yang sudah terdaftar di suatu lokasi **tidak bisa langsung dipindah** ke lokasi lain lewat dropdown "Tambah Part" — harus "Lepas" dulu dari lokasi lama (endpoint baru `DELETE /part-stocks/{id}/location`) sebelum bisa didaftarkan ke lokasi baru. Divalidasi di backend (`UpdatePartStockLocationRequest`, commit `41d660a`) sekaligus difilter di frontend (dropdown hanya menampilkan part yang belum punya lokasi, commit `798a5bf`) — defense in depth. Diuji end-to-end via curl termasuk kasus penolakannya.
  2. **Part Detail page dirombak jadi "part passport" lengkap**: sekarang pakai tab (Stok & Lokasi, Supplier, Digunakan di Equipment, Riwayat Pemasangan) alih-alih satu halaman panjang. Baris stok sekarang link ke lokasi rak/bin-nya. Ditambahkan reverse-lookup baru `GET /parts/{part}/part-installations` (lintas semua equipment, bukan cuma satu equipment) dengan breadcrumb Line → Machine → Equipment (field `machine_name`/`line_name` baru di `EquipmentPartResource` dan `PartInstallationResource`). Halaman list Part juga dapat action "Lihat" eksplisit yang terlihat oleh semua role, bukan cuma yang bisa manage.
  - Diverifikasi: type-check, lint, build bersih; semua endpoint baru/berubah dites end-to-end via curl (termasuk kasus gagal yang disengaja untuk safety rule).
- **2026-09-14** — User minta simplifikasi: aturan "harus lepas dulu sebelum pindah bin" dianggap kurang perlu, cukup ada peringatan konfirmasi saja. Backend (`94ed78f`) kembali mengizinkan pindah langsung (endpoint `DELETE /part-stocks/{id}/location` dihapus karena sudah tidak dipakai). Frontend (`f821c6c`): dropdown "Tambah Part ke Lokasi" sekarang menampilkan semua part; kalau yang dipilih sudah ada di lokasi lain, muncul catatan + dialog konfirmasi "Pindahkan part ke lokasi ini?" sebelum benar-benar memindahkan. Tombol "Lepas" di halaman Location Detail dihapus karena sudah tidak relevan.
- **2026-09-14** — Polish UI/UX fondasi (disepakati: mulai dari layout/navigasi/warna/tipografi, dengan refresh tema warna, bukan cuma rapi-rapi). Commit `235689e`:
  - **Tema warna**: `src/index.css` diganti dari default shadcn grayscale (OKLCH chroma 0) ke palet biru industrial (primary/ring/sidebar/chart), + token `--warning` baru (amber) untuk kondisi "hampir kritis tapi belum kritis" (stok Rendah, part yang pemakaiannya ≥80%) — sebelumnya sama-sama abu-abu dengan status "nonaktif", sekarang beda warna.
  - **`badge.tsx`**: variant baru `warning`, dipakai gantikan `secondary` di 7 tempat yang secara semantik memang peringatan (bukan "nonaktif"/"off").
  - **`AppLayout.tsx`**: sidebar dirombak — ikon `lucide-react` (sudah terpasang duluan, tidak perlu install), navigasi dikelompokkan (Inventaris / Aset & Produksi / Kerja Saya), logo lockup, avatar inisial di header, active-state jelas pakai warna primary.
  - Ketauan & dibenerin sekalian 2 bug kecil pas verifikasi visual: warning aksesibilitas dari tombol "Lihat" yang di-render sebagai Link (`nativeButton={false}`), dan `BranchSelector` yang berpindah dari uncontrolled ke controlled Select (bug lama, bukan dari sesi ini).
  - **Cara verifikasi baru yang dipakai**: karena tidak ada `chromium-cli`/Playwright terpasang, browser Chromium di-install on-demand via `npx playwright install chromium` (~port kerja), lalu discreenshot beneran (bukan cuma baca kode) untuk login, dashboard, list & detail Part (4 tab), stock list, alerts — dicek juga console error-nya nol. Chromium & script dibersihkan lagi setelah selesai, tidak masuk ke project.
- **2026-09-14** — Dua feedback lanjutan dari user setelah lihat hasil polish di atas (commit `20b6661` web + `c3143a9` api):
  1. **Action jadi ikon**: tombol "Lihat/Ubah/Hapus" (teks) di semua tabel list (Part, Supplier, Lokasi, Cabang, dan tabel relasi di Part/Supplier/Equipment detail) diganti jadi ikon (`Eye`/`Pencil`/`Trash2` dari lucide-react) + `aria-label`/`title`. Kolom nama/kode yang tadinya jadi link ganda ke halaman yang sama sekarang teks biasa — cukup satu titik aksi "lihat" yang jelas.
  2. **Breadcrumb Line > Machine > Equipment belum ada**: sebelumnya `LineDetailPage`, `MachineDetailPage`, `EquipmentDetailPage` adalah "dead end" — tidak ada cara naik kembali ke induknya. Ditambahkan komponen `Breadcrumb` baru (`src/components/Breadcrumb.tsx`) dan dipasang di ketiga halaman itu, plus di tab "Digunakan di Equipment" & "Riwayat Pemasangan" pada Part Detail (yang sebelumnya cuma teks abu-abu "Line → Machine", sekarang breadcrumb yang bisa diklik). Backend perlu diubah supaya `MachineResource`/`EquipmentResource`/`EquipmentPartResource`/`PartInstallationResource` ikut expose `line_id`/`line_name`/`machine_id`/`machine_name` induknya (sebelumnya cuma expose punya sendiri, tidak ada info parent).
  - Diverifikasi ulang dengan browser sungguhan (Playwright): 0 console error di semua halaman list + rantai navigasi Line→Machine→Equipment→tab usage Part.
- **2026-09-14** — User: "belum ada cara akses UI ke Mesin dan Equipment" — benar, sebelumnya Mesin & Equipment cuma bisa diakses dengan drill-down manual dari Line (klik Line → klik Mesin → klik Equipment), tidak ada entri sidebar atau halaman list langsung untuk semua Mesin/Equipment di satu cabang.
  - Backend (`spare-part-api@6adc195`): endpoint baru `GET /branches/{branch}/machines` dan `GET /branches/{branch}/equipment` — list flat lintas semua line/mesin di cabang tsb, sekalian eager-load parent (line/machine) biar breadcrumb tidak perlu request tambahan.
  - Frontend (`spare-part-web@9a30837`): halaman baru `MachinesPage` (`/machines`) dan `EquipmentPage` (`/equipment`), masing-masing tabel datar dengan breadcrumb Line (atau Line→Mesin) dan action Lihat. Ditambahkan ke sidebar "Aset & Produksi" (nav "Line & Mesin" diganti nama jadi "Line Produksi" biar tidak rancu dengan menu "Mesin" yang baru).
  - Diverifikasi: build/lint/type-check bersih, browser sungguhan 0 console error di kedua halaman baru.
- **2026-09-14** — User kasih feedback: "layoutnya kurang, gabung aja jadi satu tab Line > Machine > Equipment" — 3 halaman terpisah (Line, Mesin, Equipment) yang baru dibuat dianggap kurang nyaman. Direkomendasikan (dan dipilih user) layout **master-detail 3 kolom** dibanding accordion.
  - Frontend (`spare-part-web@176d946`): `LinesPage`, `LineDetailPage`, `MachineDetailPage`, `MachinesPage`, `EquipmentPage` semua dihapus, diganti satu halaman baru `LineHierarchyPage` (`/lines`) — 3 kolom (Line | Mesin | Equipment) dalam satu layar, klik kolom kiri memfilter kolom tengah, klik tengah memfilter kanan. State pilihan disimpan di URL (`?line=&machine=`) supaya bisa di-share dan supaya breadcrumb dari halaman lain (Equipment Detail, tab Part) bisa deep-link langsung ke cabang hierarki yang tepat. Equipment tetap navigasi ke `EquipmentDetailPage` yang sudah ada (Work Order/BOM/Riwayat Pemasangan tidak berubah) — yang digabung cuma lapisan browsing/navigasinya.
  - Backend (`spare-part-api@65d7dee`): endpoint `forBranch` yang baru ditambahkan sesi sebelumnya di-revert karena sudah tidak ada pemanggilnya lagi.
  - Sidebar kembali jadi satu item "Line, Mesin & Equipment" (bukan 3 item terpisah).
  - Diverifikasi dengan browser sungguhan: cascading selection jalan, klik equipment row -> detail page, klik breadcrumb dari detail page -> balik ke hierarchy page dengan line/mesin ke-preselect otomatis lewat URL. 0 console error.
- **2026-09-14** — Bug: tombol "+" (Tambah Line/Mesin/Equipment) di halaman gabungan tidak merespon klik sama sekali. Root cause: tombolnya dibungkus komponen helper `ColumnAddButton` yang dipakai sebagai `trigger` dialog — mekanisme render-prop Base UI (dipakai `DialogTrigger`) meng-clone elemen `trigger` untuk menyuntikkan `onClick`/`ref`, tapi `ColumnAddButton` tidak meneruskan props tambahan itu ke `<Button>` di dalamnya, jadi klik-nya "ketelan" begitu saja. Diperbaiki dengan inline `<Button>` langsung sebagai trigger (pola yang konsisten dipakai di semua dialog lain di app ini) — commit `spare-part-web@b120333`. Diverifikasi: ketiga dialog (Tambah Line/Mesin/Equipment) beneran kebuka saat diklik, dicek pakai browser sungguhan.
  - **Pelajaran untuk ke depan**: jangan bungkus elemen `trigger` dialog dalam komponen wrapper custom kecuali wrapper itu eksplisit menerima & menyebarkan (`{...props}`) semua props tambahan ke elemen anak-nya — kalau tidak, klik/keyboard event dari Base UI tidak akan tersambung.
- **2026-09-14** — Tambahan: tombol edit (ikon pensil) untuk tiap baris Line/Mesin/Equipment di halaman gabungan (`spare-part-web@6f9e4fe`) — sebelumnya cuma bisa "Tambah", belum ada cara ubah data yang sudah ada dari halaman ini (`LineFormDialog`/`MachineFormDialog`/`EquipmentFormDialog` sebenarnya sudah mendukung mode edit sejak awal, cuma belum dipasang di sini). `ColumnRow` direstrukturisasi supaya tombol edit jadi elemen sibling dari tombol pilih-baris (bukan nested di dalamnya, karena button di dalam button tidak valid HTML) — begitu juga baris Equipment yang tadinya cuma `<Link>` polos. Diverifikasi: ketiga dialog edit terbuka ter-prefill dengan data yang benar, klik edit di baris Equipment tidak ikut memicu navigasi.
- **2026-09-14** — User: input di tab Supplier & Lokasi (halaman Part) dirasa menyulitkan. Root cause ketauan dua hal berbeda (`spare-part-web@d26c2ce`):
  1. **Tab "Stok & Lokasi" murni read-only** — tidak ada sama sekali cara atur lokasi part dari halaman Part; harus keluar ke halaman Lokasi lalu cari part-nya dari daftar semua part di cabang itu (tidak difilter). Diperbaiki: tiap baris cabang sekarang punya aksi "Atur Lokasi" (ikon `MapPin`) yang buka `SetPartLocationDialog` baru — dropdown lokasi-nya sudah otomatis ter-scope ke cabang itu saja, jadi tinggal pilih, tidak perlu pindah halaman sama sekali.
  2. **Dialog Tambah Supplier terikat ke branch selector global** — kalau part-nya sebenarnya relevan di cabang lain dari yang lagi aktif di selector atas, daftar supplier yang muncul jadi salah/kosong dan bikin bingung. Diperbaiki: `PartSupplierFormDialog` sekarang punya picker "Cabang → Supplier" mandiri di dalam dialog itu sendiri (branch aktif cuma jadi default awal, bukan keharusan). Mode edit disederhanakan juga — nama supplier & cabang ditampilkan sebagai info statis, bukan dropdown ter-disable yang keliatan seperti bisa diklik padahal tidak.
  - Diverifikasi dengan browser sungguhan: dialog lokasi terbuka ter-prefill lokasi saat ini, dialog supplier cascading branch->supplier berfungsi (pilih cabang -> daftar supplier langsung muncul sesuai cabang itu). 0 console error.
- **2026-09-14** — User minta fitur penentuan harga otomatis saat Stock In (Terima Barang): input harga TOTAL sesuai qty, sistem hitung rata-rata tertimbang dengan harga lama — mengantisipasi kenaikan harga tanpa menghapus basis harga stok lama. Rencana ke depan ada alur Purchase Order penuh, tapi ini jalur stock-in langsung untuk sekarang.
  - **Keputusan penting** (dikonfirmasi user via pertanyaan klarifikasi): target field adalah **`PartStock.unit_cost`** (harga modal **per cabang**, bukan `Part.price` di katalog) — user tegaskan "part ini pengelolaannya terpisah antar cabang". Field "Harga Total" saat Stock In **wajib diisi** (tidak opsional).
  - **Formula**: `unit_cost_baru = (unit_cost_lama * qty_lama + harga_total_baru) / (qty_lama + qty_diterima)` — rata-rata tertimbang standar (moving average cost), otomatis benar juga untuk stok pertama kali (qty_lama=0).
  - Backend (`spare-part-api@be0099e`): `StockMovementService::record()` dapat parameter baru `additionalUpdates` (closure) yang jalan DI DALAM transaction+lock yang sama dengan perubahan quantity — supaya baca `unit_cost`/`quantity_on_hand` lama itu aman dari race condition saat ada concurrent stock-in. `ReceiveStockRequest.unit_cost` (opsional, sebelumnya ada di skema tapi TIDAK PERNAH dipakai di UI manapun) diganti `total_price` (wajib).
  - Frontend (`spare-part-web@3d3cb6e`): `ReceiveStockDialog` sekarang minta "Harga Total Pembelian" (bukan harga per unit), nampilin harga modal & stok saat ini di atas form, dan **live preview** hasil rata-rata baru saat user masih ngetik (dihitung ulang di client, harus selalu sinkron sama formula backend). `unit_cost` juga baru pertama kali ditampilkan ke user lewat kartu "Harga Modal" di halaman Stock Detail — sebelumnya field ini ada di database & response API tapi tidak pernah dirender di UI manapun.
  - Diverifikasi: kalkulasi manual dicocokkan ke response API (Rp247.954 @ 53 unit + Rp3.000.000 utk 10 unit → Rp256.215 @ 63 unit, persis), validasi "wajib diisi" dicoba dan ditolak backend dengan benar, live preview di browser menampilkan angka yang sama persis dengan backend. 0 console error.
- **2026-09-14** — Dua feedback lanjutan (`spare-part-web@71df44c`):
  1. **"Harga Modal" belum muncul**: fitur harga rata-rata tertimbang dari sesi sebelumnya cuma ditambahkan di halaman Stock Detail terpisah (`/stock/:id`), padahal user lihatnya di tab "Stok & Lokasi" pada halaman Part sendiri — kolomnya belum ada di situ. Ditambahkan kolom "Harga Modal" per baris cabang di tab itu.
  2. **Hapus input harga di form Part**: sekarang harga cuma boleh masuk lewat Stock In (hasil rata-rata tertimbang), bukan diketik manual saat tambah/ubah Part. Field "Harga" dihapus dari `PartFormDialog`, kolom "Harga" dihapus dari list Part, dan baris harga di header Part Detail juga dihapus (karena `Part.price` global sudah tidak dipakai/tidak sinkron dengan `unit_cost` per cabang yang sebenarnya). Backend tidak perlu diubah — validasi `price` di `StorePartRequest`/`UpdatePartRequest` memang sudah opsional dari awal (default DB: 0).
  - Diverifikasi dengan browser sungguhan: form Tambah/Ubah Part tidak ada field harga, list Part tidak ada kolom Harga, tab Stok & Lokasi menampilkan Harga Modal berbeda-beda per cabang (mis. Cibitung Rp232.020, Surabaya Rp5.801, Pusat Rp247.954). 0 console error.
- **2026-09-14** — User tanya: "tab Part dan Stock redundan ga?" dan "tombol stock in belum ketemu". Kedua hal ini satu akar masalah: aksi "Terima Barang"/"Sesuaikan Stok" cuma ada di halaman `Stock Detail` (`/stock/:id`) — halaman paling dalam & paling jarang dibuka (harus lewat Stok list → klik baris → baru kelihatan tombolnya), bukan di tab "Stok & Lokasi" pada halaman Part yang justru sering dilihat user.
  - **Keputusan**: bukan hapus/gabung halaman Part vs Stok (keduanya masih punya peran beda — Part = detail satu part, Stok = ringkasan semua part per cabang buat lihat mana yang butuh perhatian, Stock Detail = riwayat ledger lengkap satu kombinasi part+cabang) — cukup pindahkan *aksi*-nya ke tempat yang tepat.
  - `spare-part-web@bb48602`: `ReceiveStockDialog` dan `AdjustStockDialog` sekarang terima prop `trigger` opsional (default tetap tombol teks lama, jadi halaman Stock Detail tidak berubah). Dipasang sebagai ikon (`PackagePlus`, `SlidersHorizontal`) di tiap baris cabang pada tab "Stok & Lokasi" halaman Part, di samping ikon "Atur Lokasi" yang sudah ada — jadi 3 aksi (Terima Barang, Sesuaikan Stok, Atur Lokasi) langsung di satu tempat, tanpa pindah halaman.
  - Diverifikasi dengan browser sungguhan: klik "Terima barang" dari halaman Part langsung buka dialog yang sama persis (dengan live preview rata-rata harga) seperti di halaman Stock Detail. 0 console error.
- **2026-09-14** — Lanjutan: user masih bilang "belum ada" walau sudah ditambahkan ke tab Part. Ternyata dari screenshot yang dikirim user, ketauan akar masalah sebenarnya: fix sebelumnya cuma nambah aksi di **tab Part** (`/parts/:id`), tapi user secara natural buka menu **"Stok"** di sidebar (`/stock`, `PartStocksPage`) — halaman itu sama sekali belum punya kolom Aksi. Screenshot user juga sekaligus membuktikan `canManage`/role tidak bermasalah (tombol "Tambah Part" & ikon Aksi di List Part terbukti normal).
  - `spare-part-web@b4daaea`: tambahkan 3 aksi yang sama (Terima Barang, Sesuaikan Stok, Atur Lokasi) ke `PartStocksPage` (`/stock`) — jadi sekarang ada di 2 tempat: tab Part *dan* halaman Stok, mana pun yang user buka duluan.
  - Diverifikasi: 15 tombol "Terima Barang" muncul (satu per baris) di halaman Stok, dialog terbuka normal, 0 console error.
  - **Pelajaran**: kalau user bilang "belum muncul" padahal sudah diverifikasi kerja di server, jangan cuma sarankan hard-refresh — minta screenshot/tanya halaman persis yang dibuka, karena bisa jadi fix-nya dipasang di halaman yang benar secara teknis tapi salah dari sisi alur navigasi yang natural bagi user.
- **2026-09-14** — User tanya lagi "kenapa Part & Stock tidak digabung aja?" — kali ini aku sempat mulai eksekusi (nambah backend endpoint `POST /branches/{branch}/part-stocks` buat inisialisasi stok part baru di suatu cabang, prasyarat teknis buat gabung halaman), tapi **user interupsi dan membatalkan**: rencananya akan ada **modul refurbish/repair** ke depan (part yang dilepas dari equipment untuk diperbaiki, lalu dipasang lagi) — ini butuh state tersendiri yang tidak cocok kalau Part (katalog) dan Stok (inventori aktif) dipaksa jadi satu tabel. Keputusan final: **tetap dipisah**. Perubahan backend yang sempat dibuat untuk rencana gabung itu di-revert bersih (`git checkout` + hapus file baru), repo `spare-part-api` kembali ke commit `be0099e` tanpa sisa.
  - Yang tetap dikerjakan sesuai permintaan awal: **search di halaman Stok** (`spare-part-web@63ecf35`) — filter client-side berdasarkan nama part, Item Master No, atau kode lokasi, pola yang sama dengan search di halaman Part.
  - Diverifikasi: cari "gear" di halaman Stok menyaring 15 baris jadi 2 yang cocok. 0 console error. Repo API dicek `git status` bersih setelah revert.
  - **Catatan buat nanti**: kalau modul refurbish/repair mulai dikerjakan, kemungkinan perlu status/tabel baru (mis. `part_repairs` atau status `is_active`/`condition` di `part_installations`) untuk melacak part yang sedang dalam proses perbaikan — belum digarap, baru rencana user.
- **2026-09-14** — User minta mulai "modul pengeluaran part" (schedule/unschedule, WO dari jadwal PM, library task terkait pergantian part, interval kalender/jam operasi line). Setelah dicek, ternyata **fondasinya sudah ada semua sejak scaffold awal** (`WorkOrder.schedule_type` calendar/runtime/unscheduled + `interval_days`/`interval_hours` + `part_id`; `Task.part_stock_id`/`quantity_used`; `TaskService::complete()` sudah bikin ledger 'issue' buat kurangi stok) — tapi 3 sambungannya putus:
  1. Task hasil generate otomatis dari Work Order **tidak pernah kebawa info part**-nya sama sekali (cuma task manual/ad-hoc yang bisa pilih part).
  2. Saat "Selesaikan Tugas", **tidak ada cara konfirmasi/ubah part & qty** — kalau task auto-generate tidak punya part, selamanya tidak bisa diisi.
  3. Part yang terpasang di task **tidak ditampilkan** di UI manapun (cuma ID mentah kalau ada).
  - Backend (`spare-part-api@23743cd`): `TaskService::generateNextTask()` sekarang resolve `WorkOrder.part_id` → `PartStock` di cabang equipment itu, dan default `quantity_used` dari BOM (`equipment_parts.quantity_required`) kalau ada entrinya — jadi begitu WO dibuat dengan Part & jadwal, Task yang di-generate (baik manual "Buat Tugas" maupun otomatis rekursif setelah selesai) langsung bawa rencana part+qty-nya. `CompleteTaskRequest`/`TaskService::complete()` sekarang terima override `part_stock_id`+`quantity_used` opsional, supaya teknisi bisa konfirmasi/koreksi qty aktual di titik penyelesaian (bukan cuma pas dibuat). `TaskResource` sekarang expose `part_name`/`item_master_no`.
  - Frontend (`spare-part-web@16a2921`): `CompleteTaskDialog` sekarang punya field Part+Qty (pre-filled dari task, dengan catatan "Rencana dari jadwal: X × Y" kalau berasal dari WO); `TaskRow` tampilkan info part di bawah judul task; tabel Work Order di halaman Equipment dapat kolom "Part"; bug tampilan `TaskFormDialog` yang nunjukin "Part #3" (bukan nama asli) dibenerin.
  - Diverifikasi full end-to-end lewat curl (WO+part → generate task → part_stock_id & quantity_used ke-resolve dari BOM → start → complete dengan override qty → stok berkurang sesuai + ledger 'issue' tercatat → task recurring berikutnya tetap bawa info part) **dan** lewat browser sungguhan (screenshot: kolom Part di tabel WO, baris task nunjukin "Part: V-Belt A-42 × 2", dialog Complete ter-prefill). 0 console error di kedua repo.
- **2026-09-14** — User: halaman "Tugas Saya" kosong sama sekali ("belom ada apapun di Tugas kak"). Dicek langsung ke database dulu sebelum ubah apapun — ternyata bukan bug UI: ketiga task demo dari `DemoDataSeeder` memang `assigned_to = NULL`, dan cuma ada **1 user** di seluruh sistem (Superadmin). Jadi "Tugas Saya" (filter `assigned_to = user login`) memang benar kosong — belum pernah ada akun teknisi sama sekali untuk dites.
  - `spare-part-api@b142638`: `DatabaseSeeder` direstrukturisasi — user (Superadmin **dan** user baru "Teknisi Demo" / `teknisi@spareparts.test`) sekarang dibuat **sebelum** `DemoDataSeeder` dipanggil (urutan lama: demo data dulu baru user, jadi task yang di-generate tidak pernah punya kandidat assignee). Setelah demo data jalan, task yang masih `assigned_to = NULL` otomatis di-assign ke Teknisi Demo, dan kedua user di-attach ke semua cabang.
  - Database yang sudah berjalan (bukan fresh install) diperbaiki langsung tanpa reset destruktif: `php artisan tinker --execute=...` ternyata gagal total secara silent (tidak ada output, tidak ada perubahan DB — dicek ulang lewat query manual), jadi dipakai script PHP mandiri yang boot Laravel kernel sendiri lalu jalankan Eloquent langsung. Berhasil: user Teknisi Demo dibuat (id=2), ketiga task di-assign ke user itu — dikonfirmasi lewat query ulang.
  - **Login teknisi untuk testing**: `teknisi@spareparts.test` / `password`.
  - **Pelajaran**: `php artisan tinker --execute="..."` lewat shell script bisa gagal 100% silent (exit sukses, tanpa error, tanpa efek) — kalau perlu jalankan operasi Eloquent sekali-pakai di luar seeder/command resmi, lebih aman bootstrap Laravel manual (`require bootstrap/app.php` + `$kernel->bootstrap()`) dalam script `.php` berdiri sendiri dan jalankan langsung dengan `php script.php`, lalu verifikasi hasilnya dengan query terpisah — jangan percaya "tidak ada error" sebagai tanda sukses.
- **2026-09-14** — User: "superadmin belom bisa buat WO". Investigasi (bukan dugaan langsung) — dicek role/permission/policy Superadmin lewat curl langsung ke API: **tidak ada masalah otorisasi sama sekali**, `POST /equipment/{id}/work-orders` sukses (HTTP 201) untuk role manapun yang diizinkan middleware (`admin_gudang|supervisor|superadmin`). Akar masalah ternyata **validasi frontend tidak sinkron dengan backend**: backend mewajibkan `interval_days` saat `schedule_type=calendar` (`required_if` di `StoreWorkOrderRequest`), tapi skema Zod di form (`WorkOrderFormDialog.tsx`) tidak punya aturan yang sama — dan `schedule_type` default-nya memang `calendar` dengan field interval kosong. Kalau user isi judul lalu langsung klik Simpan tanpa sadar field interval belum diisi, validasi client-side lolos, request dikirim, backend menolak (422), tapi ditangkap `onError` yang cuma tampilkan toast generik "Gagal menyimpan work order." tanpa detail apapun — dari sisi user kelihatannya form-nya "rusak total", bukan cuma kurang satu field. Bug ini menimpa semua role, bukan spesifik Superadmin — kebetulan yang tes Superadmin.
  - `spare-part-web@734c2cc`: tambah `.refine()` di skema Zod yang meniru persis aturan backend (`interval_days` wajib untuk `calendar`, `interval_hours` wajib untuk `runtime`), pesan error tampil langsung di bawah field terkait; `onError` toast sekarang menampilkan pesan validasi asli dari server (bukan teks generik) sebagai fallback untuk kasus-kasus lain yang belum tercover di frontend.
  - Diverifikasi dengan browser sungguhan (Playwright): submit tanpa isi interval → muncul pesan merah "Interval hari wajib diisi untuk jadwal berdasarkan kalender" tepat di bawah field, dialog tidak tertutup; isi interval lalu submit ulang → toast "Work order berhasil ditambahkan.", WO baru muncul di tabel. Data uji dihapus lagi setelah verifikasi. 0 console error.
  - Sekaligus mengonfirmasi & mendiskusikan model **Library Task / WO Preventive vs Corrective** dengan user — lihat subbagian "Next up" di atas untuk detail rencana kalender PM & trigger dari part life time yang akan dikerjakan di sesi berikutnya (PC rumah).
- **2026-09-14** — `PROJECT_BRIEF.md` dipindahkan ke dalam repo `spare-part-api` (sebelumnya di `C:\Users\ADM_1\Herd\sms\PROJECT_BRIEF.md`, di luar kedua repo git — jadi tidak ikut ter-push/tersinkron sama sekali). Dipindah supaya ikut ter-commit & ter-push ke GitHub, sehingga tersedia begitu repo di-clone/pull di PC rumah.
