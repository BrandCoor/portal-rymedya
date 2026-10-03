# RY Medya Portal: Prodüksiyon CRM / ERP

Bu portal, RY Medya'nın video prodüksiyon işlerini tek yerden yönetmek için yazıldı. Bir işin tüm adımları sistemde izlenir: fiyat teklifi, çekim planı, set maliyeti, kurgu revizyonları, müşteri onayı, faturalama ve tahsilat. Personel, ekipman ve vergi takibi de aynı sistemde yapılır.

Canlı adres: `https://portal.rymedya.com.tr`. Müşteri portalı `/client/login.php` adresindedir.

## İş akışı

```
Teklif ──(müşteri portaldan kabul eder)──► Proje
  │                                          ├─ Çekim günleri + set/ekip giderleri (bütçeye dahil / müşteriye yansıtılan)
  │                                          ├─ Görevler (ekibe atanır, bildirim gider)
  │                                          ├─ Kurgu versiyonları ──► müşteri portalda izler ──► onay / revizyon talebi
  │                                          ├─ Teslim dosyaları (Drive / Vimeo linkleri, portalda görünür)
  │                                          └─ Fatura ──► tahsilat ──► kasa / banka
  └─ Raporlar: proje kârlılığı, alacak yaşlandırma, aylık nakit akışı, müşteri ciroları
```

## Modüller

| Modül | Yol | Açıklama |
|-------|-----|----------|
| Kontrol Paneli | `modules/dashboard` | Role göre özet. Herkes için "Görevlerim", 7 günlük ajanda ve müşteri hareketleri |
| Teklifler | `modules/proposals` | Kanban akışı, kalem bazlı fiyatlandırma, PDF çıktı, projeye dönüştürme |
| Projeler | `modules/projects` | Çekimler, set maliyetleri, kurgu revizyonları, görevler, teslim dosyaları, faturalama |
| Prodüksiyon Takvimi | `modules/calendar` | Çekimler, teslim tarihleri, görevler, fatura vadeleri, ekipman iadeleri |
| Görev Panosu | `modules/tasks` | Tüm projelerdeki görevler. "Bana atananlar" ve "Tüm ekip" görünümleri |
| Cariler | `modules/contacts` | Müşteri ve tedarikçi kartları, ekstre, manuel dekont, portal erişimi |
| Faturalar / Kasa | `modules/finance` | Satış ve alış faturaları, tahsilat, virman, hesap hareketleri |
| Yönetim Raporları | `modules/reports` | Kârlılık, alacak yaşlandırma, aylık gelir-gider, müşteri cirosu, CSV çıktı |
| Vergi Motoru | `modules/taxes` | KDV, stopaj ve yıla göre gelir vergisi dilimleri |
| Personel | `modules/personnel` | Maaş, bordro, avans |
| Ekipman | `modules/inventory` | Envanter, sete çıkış, dışarıya kiralama |
| Bildirimler | `modules/notifications` | Müşteri hareketleri, görev atamaları, tüm aktivite günlüğü |
| Müşteri Portalı | `client/` | Projeler, kurgu onayı, teslim dosyaları, teklif kabul/ret, ekstre |

## Kurulum

1. Dosyaları sunucuya yükleyin (PHP 8.1+, MySQL / MariaDB).
2. `config/db.example.php` dosyasını `config/db.php` adıyla kopyalayıp veritabanı bilgilerini girin. `config/db.php` GitHub'a gönderilmez.
3. Yeni özelliklerin ihtiyaç duyduğu tablolar ilk sayfa açılışında **otomatik oluşturulur**. Elle SQL çalıştırmanız gerekmez. Sürüm bilgisi `system_settings.schema_version` alanında tutulur.

## Belgeler

- [Kullanım Kılavuzu](docs/KULLANIM_KILAVUZU.md): ekip ve müşteri için adım adım kullanım
- [İnceleme Raporu ve Düzeltmeler](docs/IYILESTIRMELER.md): güvenlik ve finans düzeltmelerinin listesi
