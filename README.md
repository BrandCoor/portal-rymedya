# RY Medya Portal: Prodüksiyon CRM / ERP

Bu portal, RY Medya'nın video prodüksiyon işlerini tek yerden yönetmek için yazıldı. Bir işin tüm adımları sistemde izlenir: fiyat teklifi, çekim planı, set maliyeti, kurgu revizyonları, müşteri onayı, faturalama ve tahsilat. Personel, ekipman ve vergi takibi de aynı sistemde yapılır.

Canlı adres: `https://platform.rymedya.com.tr`.

Portalın iki yüzü vardır:
- **İç ERP:** RY Medya personeli için.
- **İş Platformu:** Ajanslar hizmet kataloğundan iş girer (fiyat anında, aynı gün / acil iş kuralları), iş havuza düşer; işi RY Medya ekibi veya seviyesine göre kapasitesi sınırlı freelancer'lar yapar. Ayrıntılar: [docs/PLATFORM.md](docs/PLATFORM.md).

| Giriş | Adres |
|-------|-------|
| Personel | `/modules/auth/login.php` |
| Müşteri / Ajans / Freelancer | `/client/login.php` |
| Ajans kaydı / Freelancer başvurusu | `/platform/register.php?type=agency` · `?type=freelancer` |

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
| **İş Platformu (yönetici)** | `modules/platform` | İş merkezi, hizmet kataloğu, termin ve seviye kuralları, teklif kabul/ret, kişiye özel yazışma, kalite kontrol, performans karneleri, kalıcı silme |
| **Ajans & Freelancer Paneli** | `platform/` | Katalogdan iş, iş düzenleme, iş havuzu, teklif, teslim, performans karnesi, kazançlar |
| Yasal metinler | `legal/`, `modules/legal` | Sözleşmeler, KVKK, politikalar; kayıtta ve kartla ödemede onay, sürümleme, yeniden onay, onay kayıtları (CSV) |

## Kurulum

1. Dosyaları sunucuya yükleyin (PHP 8.1+, MySQL / MariaDB).
2. `config/db.example.php` dosyasını `config/db.php` adıyla kopyalayıp veritabanı bilgilerini girin. `config/db.php` GitHub'a gönderilmez.
3. Yeni özelliklerin ihtiyaç duyduğu tablolar ilk sayfa açılışında **otomatik oluşturulur**. Elle SQL çalıştırmanız gerekmez. Sürüm bilgisi `system_settings.schema_version` alanında tutulur.

## Belgeler

- [İş Platformu](docs/PLATFORM.md): hiyerarşi, iş akışı ve pazarlama politikası
- [Kullanım Kılavuzu](docs/KULLANIM_KILAVUZU.md): ekip ve müşteri için adım adım kullanım
- [İnceleme Raporu ve Düzeltmeler](docs/IYILESTIRMELER.md): güvenlik ve finans düzeltmelerinin listesi

## Zamanlanmış görev (önerilir)

Toplu e-postalar ve gönderilemeyen bildirimlerin yeniden denenmesi için cPanel → Cron Jobs'a her 5 dakikada bir şunu ekleyin:

```
php /home/KULLANICI/public_html/cron/mail-queue.php
```

İş platformu otomasyonları (otomatik aşama onayı, teslim hatırlatmaları, gecikme uyarıları) için saatte bir:

```
php /home/KULLANICI/public_html/cron/platform.php
```

Günlük veritabanı yedeği için günde bir (ör. 03:15):

```
php /home/KULLANICI/public_html/cron/backup.php
```

Yedekler varsayılan olarak web kökünün bir üstündeki `rymedya-yedek` klasörüne yazılır (Ayarlar → Güvenlik'ten değiştirilebilir).
