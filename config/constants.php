<?php
/**
 * ====================================================================
 * AJANS CRM / ERP - SİSTEM SABİTLERİ VE TANIMLARI
 * ====================================================================
 */

// Sistem Temel Bilgileri
define('APP_NAME', 'RY Medya CRM / ERP');
define('APP_VERSION', '1.0.0');

// Proje Türleri
const PROJECT_TYPES = [
    'commercial'   => 'Reklam Filmi',
    'music_video'  => 'Müzik Klibi',
    'promo'        => 'Tanıtım / Kurumsal Film',
    'documentary'  => 'Belgesel',
    'social_media' => 'Sosyal Medya İçeriği / Reels',
    'event'        => 'Etkinlik / Konser Çekimi',
    'other'        => 'Diğer Prodüksiyon'
];

// Proje Aşamaları / Durumları
const PROJECT_STATUSES = [
    'pre_production'  => ['label' => 'Pre-Prodüksiyon (Hazırlık)', 'color' => 'bg-amber-100 text-amber-800 border-amber-300'],
    'shooting'        => ['label' => 'Çekim Aşamasında',         'color' => 'bg-blue-100 text-blue-800 border-blue-300'],
    'post_production' => ['label' => 'Post-Prodüksiyon (Kurgu)',  'color' => 'bg-purple-100 text-purple-800 border-purple-300'],
    'revision'        => ['label' => 'Revizyon Bekliyor',         'color' => 'bg-orange-100 text-orange-800 border-orange-300'],
    'completed'       => ['label' => 'Tamamlandı / Teslim Edildi','color' => 'bg-emerald-100 text-emerald-800 border-emerald-300'],
    'invoiced'        => ['label' => 'Faturalandırıldı',          'color' => 'bg-cyan-100 text-cyan-800 border-cyan-300'],
    'cancelled'       => ['label' => 'İptal Edildi',              'color' => 'bg-rose-100 text-rose-800 border-rose-300']
];

// Cari Türleri
const CONTACT_TYPES = [
    'client'           => 'Müşteri',
    'agency'           => 'Ajans (Platform İş Veren)',
    'freelancer'       => 'Freelancer / Dış Ekip',
    'equipment_rental' => 'Ekipman Kiralama Şirketi',
    'studio'           => 'Stüdyo / Plato',
    'supplier'         => 'Tedarikçi / Hizmet Sağlayıcı',
    'other'            => 'Diğer'
];

// Çekim Ekip & Harcama Kategorileri
const CREW_CATEGORIES = [
    'director'         => 'Yönetmen',
    'dop'              => 'Görüntü Yönetmeni (DOP)',
    'camera_crew'      => 'Kamera Ekibi (Focus Puller / Asistan)',
    'sound'            => 'Ses Operatörü / Boom',
    'light'            => 'Işık Şefi & Ekibi (Gaffer)',
    'art'              => 'Sanat Yönetimi & Kostüm',
    'cast'             => 'Oyuncu / Model / Cast',
    'equipment_rental' => 'Kamera / Işık / Lens Kiralama',
    'location_fee'     => 'Mekan / Plato Kirası',
    'catering'         => 'Yemek & İkram (Catering)',
    'transport'        => 'Ulaşım / Karavan / Nakliye',
    'other'            => 'Diğer Set Gideri'
];

// Tevkifat Oranları (Prodüksiyon ve Danışmanlık için Türkiye Standartları)
const WITHHOLDING_RATES = [
    '0/10'  => 'Tevkifatsız (%0)',
    '2/10'  => '2/10 Oranında Tevkifat',
    '3/10'  => '3/10 Oranında Tevkifat',
    '5/10'  => '5/10 (Yarı Yarıya) Tevkifat',
    '7/10'  => '7/10 Oranında Tevkifat',
    '9/10'  => '9/10 Oranında Tevkifat',
    '10/10' => 'Tam Tevkifat (10/10)'
];

// Standart KDV Oranları
const VAT_RATES = [0, 1, 10, 20];

// Para Birimleri
const CURRENCIES = [
    'TRY' => '₺ Türk Lirası',
    'USD' => '$ Amerikan Doları',
    'EUR' => '€ Euro',
    'GBP' => '£ İngiliz Sterlini'
];

// Gelir Vergisi Tarifesi (GVK 103 - Ücret Dışı Gelirler)
// Her dilim: [üst sınır (TL, null = sınırsız), oran]
// Yeni yıl tarifesi açıklandığında buraya eklenmesi yeterlidir.
const INCOME_TAX_BRACKETS = [
    2025 => [[158000, 15], [330000, 20], [800000, 27], [4300000, 35], [null, 40]],
    2026 => [[190000, 15], [400000, 20], [1000000, 27], [5300000, 35], [null, 40]],
];

