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

### Next up

- ✅ **Modul PM Task / Task Library / Kalender PM** (disepakati 2026-09-14, dikerjakan di PC rumah, di-pull & diverifikasi di sesi 2026-09-15) — lihat changelog untuk detail lengkap.
- ⬜ Label/badge "PM" vs "Corrective" eksplisit di UI Task/WO list (turunan dari `task_library_id`/`work_order_id`) — belum digarap, masih rencana.
- ⬜ Fase 5 (Offline/PWA), Fase 6 (Filament admin panel) — belum dimulai.

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
- **2026-09-15** — Pull hasil kerja dari PC rumah: 9 commit backend + 11 commit frontend, mengimplementasikan tepat rencana "Next up" dari sesi sebelumnya, lebih lengkap dari yang diminta:
  - **Modul PM Task**: `TaskLibrary` (resep kegiatan PM per equipment: aktivitas + checklist part) + `TaskLibraryPart`, Kalender PM (`/pm/calendar`) untuk menjadwalkan resep ke tanggal tertentu (jadi `Task` dengan `task_library_id` terisi = "WO PM Schedule"), Ledger WO (`/pm/ledger`) untuk riwayat semuanya, halaman cetak checklist per WO.
  - **Part Lifetime v2**: dirombak total dari sekadar tanggal pasang/lepas jadi `PartUnit` (unit fisik yang bisa dilacak lintas siklus pasang-lepas-repair) + `PartRepair` (status pending/repaired/scrapped) — ini persis yang direncanakan untuk modul refurbish/repair di changelog 14 Sept. Halaman Part Lifetime (`/part-lifetime`) menampilkan part terpasang dengan sisa umur <10%, bisa langsung dijadwalkan penggantiannya ke Kalender PM.
  - **Breakdown QR flow**: scan QR publik di equipment (`/breakdown/scan/:partId/:branchId`, tanpa login) → ajukan `PartReplacementRequest` → papan approval Engineer (`/breakdown/approvals`) → approve/reject, approve otomatis proses pergantian part. Halaman cetak QR (`/breakdown/print-qr`) dengan checklist + qty per part.
  - **Company Settings**: nama & logo perusahaan (tampil di label QR), master data Units (satuan part).
  - **UI**: dark mode (`next-themes`), sidebar collapsible dengan state tersimpan, `PageHeader`/`EmptyState` seragam di semua halaman baru, navigasi "Perawatan (PM & WO)" dan "Breakdown" jadi grup sidebar baru.
  - Migration baru (9 file, semua additive — tabel baru + kolom nullable) dijalankan bersih, `UnitSeeder` dijalankan, `composer install`/`npm install` (1 package baru: `qrcode.react`) beres, `npm run build` sukses 0 error.
- **2026-09-15** — Verifikasi menyeluruh dengan browser sungguhan (Playwright) menemukan **2 bug nyata** di fitur yang baru di-pull, keduanya diperbaiki dan sudah di-push:
  1. **Bug fungsional — task PM hilang dari Kalender/Ledger kalau checklist-nya kosong**: `TaskController::pmSchedule()` (`spare-part-api`) memakai `whereHas('partChecks')` sebagai penanda "ini task PM", padahal `PmSchedulingService::schedule()` cuma bikin baris `TaskPartCheck` kalau Task Library-nya punya part di checklist — Task Library tanpa part (kasus sah: tugas inspeksi murni tanpa ganti part) menghasilkan `Task` dengan `task_library_id` terisi tapi 0 `partChecks`, jadi hilang total dari Kalender PM & Ledger WO meskipun tersimpan benar di database (dikonfirmasi lewat query DB langsung sebelum fix: task ada, `task_library_id` benar, `due_date` benar, cuma `partChecks` = 0). Diperbaiki (`spare-part-api@c044efd`): filter diperluas jadi `whereNotNull('task_library_id') OR whereHas('partChecks')`, mencakup kedua jalur (Task Library maupun lifetime-worn-part) tanpa syarat checklist harus terisi.
  2. **Bug tampilan — dialog "Jadwalkan WO PM" menampilkan angka ID mentah ("1") bukan judul tugas**: terjadi tiap kali pilihan Task Library "terkunci" ke satu opsi (selalu terjadi di tombol "Jadwalkan" per-baris di halaman Equipment Detail maupun Task Library list — keduanya selalu mengirim array satu elemen — dan juga di tombol atas Kalender PM kalau cabang baru punya 1 Task Library) — dropdown-nya di-disable tapi tidak pernah diberi default value yang valid, jadi Base UI tidak bisa resolve label dan fallback nampilin angka mentah. Data yang terkirim ke backend sebenarnya sudah benar (dicek lewat network request: `POST /task-libraries/1/schedule`), jadi murni bug tampilan yang membingungkan. Diperbaiki (`spare-part-web@332085e`): ganti jadi teks statis menampilkan judul asli saat pilihan terkunci ke satu Task Library — pola yang sama dipakai di `PartSupplierFormDialog` untuk kasus serupa.
  - Diverifikasi end-to-end: buat Task Library baru tanpa part di checklist → jadwalkan ke 2 tanggal berbeda → sebelum fix, keduanya hilang dari Kalender & Ledger meski `WO PM Schedule berhasil dijadwalkan` toast muncul; setelah fix, keduanya muncul benar di kedua halaman, dan dialog menampilkan judul kegiatan dengan benar (bukan "1"). Data uji dibersihkan setelah verifikasi.
- **2026-09-15** — User: alur WO dirasa mulai berantakan, fokus dulu ke **part cycle** (pasang → repair → pasang lagi) sebelum lanjut ke Maintenance. Dua permintaan:
  1. **"Dimana lihat historical lengkapnya?"** — dijawab langsung: sudah ada `PartUnitDetailPage` (`/part-units/:id`), diakses dari tab "Unit Part" di halaman Part, dari halaman "Perbaikan Part", atau dari tabel "Riwayat Pemasangan Part" di Equipment Detail. Dibuktikan dengan simulasi siklus penuh via API (pasang → lepas → repair → selesai → pasang lagi) — satu "Unit A" yang sama terlacak lewat 2x pemasangan, jumlah pemasangan & riwayat perbaikan tampil benar di satu halaman.
  2. **QR untuk pasang/lepas tanpa login** — user ingin proses pasang/lepas part (yang sekarang cuma tombol berlogin di Equipment Detail) bisa dilakukan via scan QR tanpa login, mirip alur "ambil part karena downtime" yang sudah ada. Dikonfirmasi via pertanyaan klarifikasi: **QR per unit fisik** (bukan per-part seperti breakdown) dan **tetap perlu approval Engineer/Teknisi** sebelum status resmi berubah (konsisten dengan alur breakdown).
  - **Backend** (`spare-part-api@a1dd6db`): tabel & model baru `part_unit_action_requests` (action remove/reinstall, unit, equipment, branch_id didenormalisasi buat filter approval board, nama pengaju, catatan, status pending/approved/rejected). `PartUnitActionService` — `request()` validasi unit benar-benar terpasang (remove) atau available (reinstall) sebelum bikin request; `approve()`/`reject()` meniru pola lock-check-mutate `PartReplacementService`, approve jalan lewat `PartLifecycleService::remove()`/`install()` yang sama dipakai tombol berlogin. `PublicPartUnitController` expose GET (identitas unit) + POST (submit request) tanpa login — reuse endpoint public branches/lines/machines/equipment yang sudah ada dari alur breakdown untuk picker equipment tujuan reinstall, tidak perlu endpoint publik baru untuk itu.
  - **Keputusan desain penting**: endpoint reinstall **tidak** mensyaratkan equipment tujuan ada di BOM part tersebut (beda dari alur breakdown) — karena tombol "Pasang Part" yang sudah ada (berlogin) juga tidak mengecek BOM, dan di data nyata banyak BOM belum lengkap diisi (ketauan pas testing: part demo yang sudah pernah terpasang di suatu equipment ternyata BOM-nya kosong, kalau dipaksa cek BOM jadi tidak bisa dipasang ulang ke equipment yang sama).
  - **Frontend** (`spare-part-web@4d556bd`): halaman publik baru `/part-units/:id/scan` — kalau status unit "Terpasang" cukup isi nama+catatan (equipment tujuannya sudah diketahui sistem); kalau "Siap Dipasang" tampil cascading picker Cabang→Line→Mesin→Equipment (hierarki penuh, bukan yang difilter BOM); status lain tampil pesan read-only. Tombol "Cetak QR Unit" baru di halaman detail unit unit. Papan Approval yang sudah ada digabung jadi satu halaman dengan 2 tab besar: "Penggantian (Breakdown)" dan "Pasang/Lepas Unit", masing-masing dengan sub-tab Menunggu/Disetujui/Ditolak sendiri.
  - Diverifikasi end-to-end **lewat UI browser sungguhan tanpa sesi login sama sekali** (bukan cuma curl): scan unit yang lagi terpasang → submit lepas → muncul di Papan Approval tab baru → approve → instalasi tertutup & badge status berubah jadi "Menunggu Keputusan"; ulangi utuk reinstall setelah unit ditandai selesai diperbaiki → approve → unit kembali "Terpasang" di equipment yang dipilih. 0 console error.
  - **Pelajaran testing**: hati-hati pakai selector `has-text("Setujui")` di Playwright — cocok juga dengan teks "Disetujui" (substring case-insensitive), sempat bikin klik salah sasaran ke tab alih-alih tombol approve. Scoping ke elemen spesifik (`getByRole('button', { name, exact: true })` di dalam baris yang tepat) menyelesaikannya.
- **2026-09-15** — User minta data dummy untuk semua modul baru (Task Library/PM, Part Lifetime/Repair, Breakdown & QR-per-unit) karena `DemoDataSeeder` yang ada cuma cover scaffold Fase 0-4 (branch/part/supplier/lokasi/stok/line/mesin/equipment/WO) — semua halaman modul baru masih kosong total di install baru.
  - `spare-part-api@709deb0`: seeder baru `LifecycleDemoSeeder`, sengaja dibangun **memakai Service asli aplikasi** (`PartLifecycleService`, `PartUnitActionService`, `PartReplacementService`, `PmSchedulingService`, `TaskService`) bukan insert Eloquent mentah — supaya data yang dihasilkan tidak mungkin melanggar invariant yang dijaga service (satu instalasi aktif per unit, request tidak mengubah apapun sebelum di-approve, dst.), dan aman dijalankan di database yang sudah ada aktivitas nyata (bukan cuma pasca `migrate:fresh`).
  - Per cabang: BOM (equipment_parts), approved-supplier list, 1 Task Library dengan checklist part + 1 jadwal PM yang sudah "Selesai" (masa lalu) dan 1 yang "Belum Mulai" (mendatang), siklus penuh pasang→lepas→repair→pasang lagi (lewat `PartUnitActionService` supaya sekalian ninggalin jejak approval di Papan Approval), satu unit "Siap Dipasang" dengan permintaan reinstall pending, satu unit di-backdate ke ~95% umur pakai (memicu halaman Part Lifetime) dengan permintaan lepas pending, satu unit "Dibuang", dan 3 permintaan breakdown (pending/disetujui/ditolak).
  - Didaftarkan ke `DatabaseSeeder` supaya ikut jalan otomatis di install baru, dan langsung dijalankan juga di database yang sedang dipakai sekarang.
  - Diverifikasi lewat browser sungguhan: Task Library terisi, Kalender PM & Ledger WO PM menampilkan jadwal selesai+mendatang, Part Lifetime menampilkan part dengan "5% tersisa", Perbaikan Part menampilkan disposisi repaired & scrapped lintas cabang, dan kedua tab Papan Approval (Breakdown & Pasang/Lepas Unit) sama-sama terisi.
  - **Catatan**: seeder ini TIDAK idempoten untuk bagian siklus part-nya (setiap dijalankan ulang akan membuat unit/task library baru lagi) — aman dijalankan sekali, tapi jangan dijalankan berulang-ulang di database yang sama kalau tidak mau datanya menumpuk.
- **2026-09-15** — User minta halaman Task Library dirombak: dropdown Line → pengelompokan Mesin → card Equipment → pilih card equipment menampilkan list Task terkait, di dalam Task ada part terkait.
  - `spare-part-web@7a5eb6f`: halaman lama (list flat semua Task Library se-cabang) diganti jadi browser Line→Mesin→Equipment. Pilih Line dari dropdown, equipment dikelompokkan per Mesin sebagai grid card, klik satu card langsung menampilkan panel di kanan berisi Task Library equipment itu (lengkap dengan checklist part di dalamnya, tombol Jadwalkan/Ubah/Hapus/+Part) — tanpa pindah halaman. Blok manajemen Task Library (yang sebelumnya cuma ada inline di `EquipmentDetailPage`) diekstrak jadi komponen bersama `TaskLibraryList` supaya kedua halaman (Equipment Detail dan Task Library browser baru) pakai kode & query key yang sama persis, tidak dobel.
  - **Bug sistemik ditemukan & diperbaiki sekaligus** (`spare-part-web@6dd05a3`): saat membangun dropdown Line baru, ketauan dropdown-nya menampilkan angka ID mentah ("1") bukan nama Line — investigasi ke dokumentasi Base UI mengonfirmasi ini bukan bug baru, tapi bug lama di komponen `Select` bersama yang dipakai di **seluruh aplikasi**: `<Select.Value>` cuma bisa menampilkan label yang benar kalau `Select.Root` diberi prop `items` (peta value→label) — tanpa itu, dia selalu menampilkan value mentah kecuali user baru saja memilihnya lewat klik langsung di popup yang terbuka. Ini menjelaskan kenapa **BranchSelector di header selalu menampilkan angka cabang ("1"/"2"/"3") bukan namanya** sepanjang sesi-sesi sebelumnya — tidak pernah ketahuan karena tidak pernah benar-benar mengganggu (dropdown tetap berfungsi, cuma labelnya salah tampil) dan skrip-skrip Playwright sebelumnya tidak pernah screenshot dalam kondisi baru dibuka.
    - Diperbaiki di komponen bersama `src/components/ui/select.tsx`: `Select` sekarang otomatis "menelusuri" elemen `<SelectItem>` di dalam `children` untuk membangun `items` map yang dibutuhkan Base UI — jadi SEMUA Select di aplikasi otomatis dapat label yang benar tanpa perlu diubah satu-satu di tiap pemakaian.
    - Diverifikasi: BranchSelector di header sekarang langsung menampilkan "PUSAT — Cabang Pusat" dari render pertama (tidak perlu diklik dulu), dropdown Line baru menampilkan "Line 284" dengan benar.
  - Diverifikasi lengkap dengan browser sungguhan: pilih card "Panel Kontrol" menampilkan Task Library "Inspeksi & Pelumasan Bulanan" beserta checklist "Majun Pembersih × 1"; `EquipmentDetailPage` dicek ulang tetap identik & berfungsi normal setelah ekstraksi komponen. 0 console error.
- **2026-09-15** — User bahas otorisasi: Superadmin = super user (bisa semua), di bawahnya ada Admin Cabang/Admin Spare Part, Supervisor, Engineer. Dicek dulu kondisi nyata: 4 dari 5 role itu **sudah ada** (`teknisi`, `engineer`, `admin_gudang`, `supervisor`, `superadmin`), tapi cuma ada **2 tingkat otorisasi kasar** di seluruh API (satu grup besar "admin_gudang|supervisor|superadmin" untuk semua CRUD, satu grup approval "teknisi|engineer|supervisor|superadmin") dan **tidak ada pembatasan cabang di server sama sekali** — `user->branches` cuma dipakai UI, siapapun bisa panggil API cabang manapun asal role-nya lolos.
  - Dikonfirmasi via pertanyaan klarifikasi: rename `admin_gudang` → **`admin_spare_part`**; Admin Spare Part **dibatasi ke cabang sendiri** di server (403 kalau coba akses cabang lain), Superadmin & Supervisor tetap bebas lintas cabang; hierarki Admin Spare Part (luas, 1 cabang) > Supervisor (approve + lihat semua, tanpa CRUD master data) > Engineer (approve lapangan + BOM/Task Library) > Teknisi (paling sempit, cuma kerjakan & selesaikan tugas).
  - **Backend** (`spare-part-api@6b2960b`):
    - Migration rename role Spatie di tempat (`admin_gudang` → `admin_spare_part`, id sama, assignment user existing ikut terbawa, bukan bikin role baru yang orphan).
    - `EnsureBranchAccess` middleware baru (alias `branch.access`, dipasang blanket ke seluruh grup `auth:sanctum`) — no-op untuk Superadmin/Supervisor dan untuk route yang modelnya tidak resolve ke cabang manapun (list global), jadi aman dipasang luas tanpa perlu utak-atik tiap sub-grup satu-satu.
    - `BranchScopeResolver` — memetakan model yang di-bind ke route (Branch, Equipment, WorkOrder, Task, TaskLibrary, PartUnit, PartRepair, PartReplacementRequest, PartUnitActionRequest, Supplier, Location, PartStock, dst.) balik ke `branch_id`-nya lewat relasi yang sudah ada di tiap model; 2 kasus lewat body request (`supplier_id`/`location_id` yang dipilih tanpa route ter-scope cabang) dicek terpisah.
    - Role routes direstrukturisasi jadi 4 grup: `admin_spare_part|superadmin` (master data & inventory, Supervisor sengaja dikeluarkan), `admin_spare_part|engineer|superadmin` (BOM + Task Library, wilayah baru Engineer), `admin_spare_part|supervisor|superadmin` (approve reorder-request, Supervisor tetap pegang ini), `engineer|supervisor|superadmin` (papan approval breakdown & pasang/lepas unit, Teknisi dikeluarkan).
    - 3 user demo baru: Admin Spare Part Demo & Engineer Demo (sengaja cuma di-attach **1 cabang saja**, bukan semua, supaya pembatasan cabangnya benar-benar teruji), Supervisor Demo (semua cabang, karena memang bebas lintas cabang).
  - **Frontend** (`spare-part-web@4179d19`): `useCanManage()` sekarang `admin_spare_part|superadmin` saja (Supervisor dikeluarkan dari tombol Tambah/Ubah/Hapus di semua halaman master data); `useCanManageEngineering()` baru (`admin_spare_part|engineer|superadmin`) dipakai khusus di Task Library dan bagian BOM Equipment Detail; `useCanApprove()` baru (`engineer|supervisor|superadmin`) menyembunyikan tombol Setujui/Tolak dari Teknisi di kedua papan approval. Kartu "Breakdown Menunggu" di Dashboard juga dibenerin supaya tidak diam-diam gagal query untuk role yang sudah tidak boleh akses (sebelumnya nunjukin "0" yang menyesatkan alih-alih disembunyikan).
  - Diverifikasi lengkap dengan login sungguhan per role: Admin Spare Part 200 di cabang sendiri, 403 di cabang lain; Supervisor 403 bikin supplier tapi 200 baca cabang manapun; Engineer 201 bikin Task Library tapi 403 bikin supplier; Teknisi 403 approve breakdown. Semua terverifikasi juga secara visual di browser (tombol kelola cuma muncul sesuai role, 0 console error di keempat role).
- **2026-09-15** — User minta benahi halaman "Line, Mesin & Equipment": (1) tombol hapus belum ada padahal section ini kritikal (cascade hapus semua di bawahnya) — perlu konfirmasi password ulang saat hapus; (2) pencatatan Running Hours harus jadi record per tanggal (terbaru di atas), teknisnya: teknisi input reading meteran SAAT INI (bukan delta), sistem yang hitung selisih dari reading sebelumnya; (3) layout diubah — Line horizontal kiri-ke-kanan di atas, Mesin & Equipment tetap di bawahnya, ditambah panel di kanan berisi part terpasang beserta lifetime % dan info line/mesin, dan di paling bawah tabel riwayat jam operasi per tanggal.
  - **Backend** (`spare-part-api@9d8e760`): `DestroyWithPasswordRequest` baru (dipakai bersama oleh destroy Line/Machine/Equipment) — mewajibkan field `password`, divalidasi lewat `Hash::check` ke password user yang sedang login, ditolak dengan pesan jelas kalau salah. Tabel & model baru `line_runtime_logs` (ledger append-only: `previous_hours`, `new_hours`, `hours_added`, `recorded_by`, `notes`) — `LineController::addRuntime()` dirombak total: sekarang terima `current_reading` (reading absolut, bukan delta), validasi tidak boleh lebih rendah dari `runtime_hours` saat ini, hitung `hours_added` di dalam transaction+lock, simpan sebagai baris ledger baru, baru update `line.runtime_hours`. Endpoint baru `GET /lines/{line}/runtime-logs` (newest first, otomatis lewat relasi `->latest()`).
  - **Frontend** (`spare-part-web@c53d30b`): tombol hapus (dengan `DeleteWithPasswordDialog` baru — komponen generik re-usable, minta password sebelum submit, tampilkan pesan salah password inline) dipasang di baris Line/Mesin/Equipment. `AddRuntimeDialog` diganti total — sekarang minta "Reading Meteran Saat Ini" (pre-filled dengan nilai sekarang) bukan "Jumlah Jam" delta. `LineRuntimeLogTable` baru menampilkan riwayat di bawah halaman. `InstalledPartsPanel` baru — begitu equipment dipilih, panel di kanan Mesin/Equipment langsung menampilkan part yang terpasang beserta badge %, nama line & mesin, tanpa pindah halaman. Layout diubah dari 3 kolom vertikal (Line|Mesin|Equipment) jadi: strip Line horizontal di atas → grid 3 kolom (Mesin | Equipment | Part Terpasang) di bawahnya → tabel Catatan Jam Operasi paling bawah.
  - Diverifikasi lengkap lewat browser sungguhan: reading lebih rendah dari saat ini ditolak dengan pesan jelas; reading valid menghitung selisih benar & tercatat newest-first; hapus tanpa password ditolak, password salah menampilkan toast error tanpa menutup dialog, password benar berhasil menghapus permanen & memperbarui daftar; pilih equipment menampilkan part terpasang dengan % dan info line/mesin yang benar. 0 console error (setelah 1 warning form uncontrolled-value diperbaiki di tengah jalan).
- **2026-09-15** — Feedback lanjutan untuk halaman Line/Mesin/Equipment: hapus deskripsi di bawah judul, perbesar strip Line, ubah rasio lebar kolom jadi Mesin 30% / Equipment 30% / Part Terpasang 40% (karena nanti panel Part akan memuat info lebih banyak — status baru atau hasil repair), dan perkecil font di panel Part supaya baris yang banyak tetap terlihat tanpa perlu scroll berlebihan.
  - `spare-part-web@430a219`: deskripsi PageHeader dihapus; pill Line diperbesar (teks lebih besar, padding lebih lega); grid kolom diganti dari rata 3-kolom jadi `grid-cols-[3fr_3fr_4fr]` (pakai satuan fr, bukan persen mentah, supaya `gap-4` tetap dihitung benar); batas tinggi scroll ketiga kolom dinaikkan dari 32rem ke 36rem; `InstalledPartsPanel` dirapatkan (padding & gap diperkecil, font judul part/detail/badge diperkecil) supaya lebih banyak baris muat sebelum perlu discroll.
  - Diverifikasi lebar kolom via pengukuran piksel langsung (336px : 336px : 448px dari total 1120px = persis 30% : 30% : 40%).
- **2026-09-15** — User minta review UI halaman Line/Mesin/Equipment ("sudah bagus belum, ada rekomendasi?"). Diberikan beberapa rekomendasi polish (aksi baris Equipment terlalu padat, strip Line belum ada isyarat scroll, heading antar-section belum konsisten, timestamp di ledger, dan menyiapkan badge status di panel Part untuk fitur repair ke depan) — belum dikerjakan, hanya masukan.
  - Lanjutan konkret yang disepakati & dikerjakan: (1) tombol **Pasang Part** langsung di section ini (sebelumnya di section "Part Terpasang" cuma read-only, harus ke Equipment Detail dulu); (2) redesign section itu — di-rename jadi **"Part"**, dikelompokkan **per identitas Part** (bukan per instalasi) — kalau part yang sama terpasang lebih dari 1 unit di equipment itu, nama part cukup ditulis sekali, lalu tiap unit ditampilkan sebagai badge kecil (huruf unit + %) berdampingan yang mengikuti isi (bukan lebar tetap 75/25) supaya kasus 1-unit (paling umum) tidak boros ruang dan kasus banyak-unit wrap rapi ke bawah.
  - `spare-part-web@55a26b9`: `InstalledPartsPanel` dirombak dengan logic grouping by `part_id`; `LineHierarchyPage` dapat tombol "+" baru di header panel Part yang membuka `PartInstallationFormDialog` yang sudah ada (dipakai ulang dari EquipmentDetailPage, konsisten dengan pola yang sama untuk Task Library sebelumnya).
  - Diverifikasi dengan browser sungguhan: pasang part baru dari tombol "+" langsung muncul di panel tanpa reload; pasang unit kedua dari part yang sudah ada menghasilkan 2 badge (mis. "C" dan "B") berdampingan di bawah satu baris identitas yang sama, bukan kartu duplikat. Data uji dibersihkan (dilepas) setelah verifikasi.
- **2026-09-15** — User minta search universal di tengah-atas header sebelum pulang kerja, supaya bisa lanjut dari rumah.
  - **Backend** (`spare-part-api@06fed52`): endpoint baru `GET /search?q=&branch_id=` — `SearchController` mengembalikan 4 kategori (`parts`, `equipment`, `suppliers`, `locations`), dibatasi 6 hasil per kategori, minimal 2 karakter. Part dicari global (katalog part tidak terikat cabang); Equipment/Supplier/Location cuma dicari kalau `branch_id` dikirim, dan `branch_id` itu di-clamp balik ke cabang milik user (kalau bukan Superadmin/Supervisor) — endpoint ini tidak dibind ke model apapun lewat route jadi `EnsureBranchAccess` tidak otomatis memeriksa branch_id yang cuma lewat query string, jadi pengecekannya ditulis manual di controller meniru logika yang sama.
  - **Frontend** (`spare-part-web@1e012de`): `GlobalSearch` baru diletakkan di tengah header (`AppLayout` diubah dari 2 kolom `justify-between` jadi 3 kolom flex-1 rata kiri/tengah/kanan). Input di-debounce 300ms, dropdown hasil dikelompokkan per kategori dengan ikon, klik satu hasil langsung navigasi (Part → `/parts/:id`, Equipment → `/lines?line=&machine=&equipment=` memakai pola query param yang sudah ada di halaman Line/Mesin/Equipment, Supplier → `/suppliers/:id`, Lokasi → `/locations/:id`) sekaligus menutup dropdown & mengosongkan input.
  - Diverifikasi lewat browser sungguhan (superadmin, cabang Pusat aktif): ketik "gear" menampilkan 2 hasil Part (equipment di cabang lain ikut tersaring, sesuai ekspektasi karena beda cabang aktif), klik hasil pertama berhasil navigasi ke `/parts/7`; ketik 1 karakter ("g") dropdown tidak muncul sama sekali (di bawah ambang batas 2 karakter); dicek juga via curl bahwa role Admin Spare Part (dibatasi 1 cabang) tidak mendapat hasil Equipment ketika memaksa `branch_id` cabang lain, walau hasil Part tetap muncul (memang global).
