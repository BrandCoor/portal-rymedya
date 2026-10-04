# RY Medya İş Platformu: Ajans · RY Medya · Freelancer

Portal, iç ERP'nin yanında bir **prodüksiyon iş platformu** olarak da çalışır:

- Ajanslar **hizmet kataloğundan** sipariş verir; tutar anında hesaplanır.
- Sipariş doğrudan iş havuzuna düşer.
- İşi RY Medya ekibi veya freelancer'lar yapar.
- Süreç teslim, değerlendirme ve ödemeye kadar platform üzerinde ilerler.

## 1. Hiyerarşi ve gizlilik

```
                 ┌────────────────────────────────────┐
                 │ PLATFORM YÖNETİCİSİ (RY Medya)       │  modules/platform/*
                 │ katalog · kurallar · atama · denetim │  izinler: platform.manage / .pricing / .delete
                 └────────┬──────────────────┬────────┘
          sipariş / onay  │                  │  iş / hakediş / değerlendirme
                          ▼                  ▼
              ┌────────────────────┐  ┌────────────────────┐
              │ AJANS (iş veren)    │  │ FREELANCER (iş alan) │  /platform/*
              └────────────────────┘  └────────────────────┘
               Ajans ve freelancer birbirini görmez, doğrudan yazışamaz.
```

| Rol | Görür | Yapar |
|-----|-------|-------|
| **Platform yöneticisi** | İki fiyat ve marj, tüm yazışma başlıkları, performans karneleri | Katalog ve fiyatlar, kurallar, atama, teklif kabul/ret, kalite kontrol, iptal, ödeme, seviye, kalıcı silme |
| **Ajans** | Kendi siparişleri, kendi fiyatı, kalite kontrolden geçmiş teslimatlar, faturaları | Katalog siparişi veya özel talep, düzenleme (kapsamlı), teklif onayı, teslim onayı + puan, revizyon, iptal (atama öncesi) |
| **Freelancer** | Kendisine açılan işler, kendi hakedişi, kendi teslimatları ve yazışması, ret gerekçeleri, performans karnesi | İşi al / teklif ver, geri çek, başla, bırak (gerekçeli), teslim et, ekiple yaz |

Gizlilik kuralları:

- Freelancer ajansın ödediği fiyatı ve fiyat değişikliklerini hiçbir zaman görmez.
- Ajans freelancer'ın adını, hakedişini ve dağıtım politikasını hiçbir zaman görmez.
- Ajans adını görmek ayara bağlıdır.
- Her freelancer'ın ekiple yazışması **kendine özeldir**. Bir teklif verenin yazışmasını diğeri göremez.

## 2. Sipariş akışı (sadeleştirilmiş)

```
 AJANS                                PLATFORM                          FREELANCER
 Katalogdan hizmet seç ──► fiyat anında hesaplanır
 Sipariş ver ─────────────► [open] havuz (otomatik yayın açıksa)  ──► işi al / teklif ver
                             (kapalıysa [submitted] → "Onayla ve yayınla")
 Özel talep ──────────────► [submitted] → fiyat → [quote_sent] → ajans onayı → [open]

 [open] ─► ilk alan alır  ─┐
        ─► teklif usulü ───┼─► [assigned] → [in_progress] → teslim
        ─► doğrudan atama ─┘                                 │
        ─► ekibimize al (ERP'de proje açılır)                ▼
                                         [qa_review] kalite kontrol ─✕─► [revision]
                                                 │ ✓
                                          [delivered] ajans inceler ─ revizyon ─► [revision]
                                                 │ onay + puan (1-5)
                                          [completed] faturalar + performans puanı güncellenir
```

- Katalog siparişlerinde fiyat pazarlığı yoktur; ajans fiyatı sipariş anında görür.
- Yönetici onayı yalnızca "otomatik yayın" kapalıysa veya özel taleplerde gerekir.

## 3. Termin kuralları (aynı gün / acil iş)

Referans tarih, çekim/başlangıç tarihidir; girilmemişse teslim tarihi kullanılır. Kurallar hem tarayıcıda anında hem sunucuda uygulanır.

| Durum | Sonuç | Ayar |
|-------|-------|------|
| Geçmiş tarih | Sipariş alınmaz | — |
| Aynı gün başlangıç | Sipariş alınmaz | `Aynı gün başlayan işler alınmasın` |
| Başlangıca kalan süre < en kısa sipariş süresi | Sipariş alınmaz | `En kısa sipariş süresi` (varsayılan 24 sa); hizmete özel süre katalogdan (ör. drone 72 sa) |
| Kalan süre < acil iş eşiği | Uyarı çıkar. Ajans "acil iş koşullarını" onaylamadan gönderemez. | `Acil iş eşiği` (72 sa) |
| Acil iş | Tutara acil farkı eklenir. Farkın bir kısmı freelancer'a prim olarak geçer. | `Acil iş farkı` (%25), `freelancer payı` (%60) |

Ajans düzenlemesinde tarih değişirse aynı kurallar yeniden uygulanır. Yönetici, iş oluştururken "termin kuralını aş" seçeneğiyle istisna yapabilir.

## 4. Ajansın düzenleyebildikleri

| Aşama | Düzenlenebilir alanlar |
|-------|------------------------|
| Atama öncesi (`submitted`, `quote_sent`, `open`) | Başlık, brief, teslimatlar, tarihler, lokasyon, referanslar, ek notlar, katalog kalemleri (tutar yeniden hesaplanır) |
| Üretimde (`assigned` → `revision`) | Referanslar, ek notlar. Teslim tarihi **yalnızca ileri** alınabilir. |
| Teslim sonrası | Kapalı |

- Her değişiklik **değişiklik geçmişine** eski ve yeni değeriyle yazılır.
- Ekibe ve atanmış freelancer'a bildirim gider. Freelancer fiyat satırlarını görmez.
- Teklif aşamasındaki özel talepte kapsam değişirse teklif otomatik olarak yeniden fiyatlamaya döner.

## 5. Teklifler: ret ve kişiye özel yazışma

- Teklif usulündeki işlerde freelancer şunları gönderir:
  - ücret önerisi
  - müsaitlik tarihi
  - açıklama
- Yönetici her teklifte puanı, tamamlanan işi, zamanında teslim oranını ve **aktif iş / limit** bilgisini görür.
- **Kabul:** önerilen ücretle veya belirlenen ücretle atanır. Diğer bekleyen tekliflere "başkası seçildi" bildirimi gider.
- **Ret:** gerekçe zorunludur. Hazır gerekçeler veya serbest metin kullanılabilir. Gerekçe freelancer'a bildirim olarak gider ve iş sayfasında / "Tekliflerim" sekmesinde görünür.
- **Yaz:** her freelancer ile ayrı yazışma başlığı açılır. Yönetici, işi henüz görmeyen uygun bir freelancer'a da yazabilir; bu kişi o işi görüntüleyip yanıtlayabilir.
- Atanan kişi işi bırakırsa iş havuza döner:
  - Ücret katalog değerine geri döner.
  - "Başkasına atandı" gerekçesiyle kapanan teklif sahipleri yeniden teklif verebilir.

## 6. Freelancer performansı, seviye ve kapasite

**Puan (0–100)**, tamamlanan işlerden hesaplanır:

| Bileşen | Ağırlık |
|---------|---------|
| Değerlendirme (ekip + ajans puanı) | %35 |
| Zamanında teslim (ilk teslim ≤ teslim tarihi) | %25 |
| Kalite kontrolden ilk seferde geçme | %20 |
| Revizyon yükü | %10 |
| Güvenilirlik (bırakılan / geri alınan işler) | %10 |

**Seviye kuralları (İş merkezi → Freelancer'lar → Seviye kuralları):**

Silver, Gold ve Elite için koşulları yönetici belirler. Boş bırakılan koşul aranmaz; doldurulan koşulların **tamamı** sağlanmalıdır. Birden fazla seviyenin şartını sağlayan, en yüksek seviyeye geçer.

| Koşul | Örnek |
|-------|-------|
| Tamamlanan iş (en az) | 10 |
| Ortalama yıldız, ekip + müşteri (en az) | 4★ |
| Müşteri (ajans) yıldızı (en az) | 4.5★ |
| Performans puanı (en az) | 80 |
| Zamanında teslim oranı (en az) | %90 |
| Kalite kontrolden ilk seferde geçme (en az) | %80 |
| Bırakılan / geri alınan iş (en fazla) | 1 |
| Geciken teslim (en fazla) | 2 |
| Platformdaki süre (en az) | 90 gün |

Örnek: Gold için "tamamlanan iş en az 10" ve "ortalama yıldız en az 4" yazılırsa, 10 işi bitirmiş ve ortalaması 4★ olan herkes Gold olur.

- Her seviye ayrı ayrı açılıp kapatılabilir. Kapalı seviye otomatik verilmez, yalnızca elle verilir.
- Her seviyenin **eşzamanlı aktif iş limiti** aynı ekrandan ayarlanır. Varsayılanlar: Standart 1, Silver 2, Gold 3, Elite 5.
- **Otomatik uygula:** kurallar her tamamlanan iş ve değerlendirmeden sonra kontrol edilir.
- **Seviye düşürme:** kapalıysa seviye yalnızca yükselir.
- **Kaydet ve herkese uygula:** kurallar anında tüm onaylı freelancer'lara uygulanır. Ekrandaki tablo, uygulamadan önce kimin seviyesinin değişeceğini gösterir.
- Freelancer kendi Performans sayfasında bir sonraki seviyenin koşullarını ve her koşuldaki durumunu görür.

- **Aktif iş:** atanmış, üretimde, kalite kontrolde, revizyonda veya ajans onayında olan iştir.
- Limit doluysa freelancer iş alamaz ve teklif veremez. Yönetici "limiti aş" ile istisna yapabilir.
- Seviye her tamamlanan iş ve değerlendirmeden sonra **otomatik** güncellenir ve freelancer'a bildirilir.
- Yöneticinin elle verdiği seviye **sabitlenir** ve otomatik hesaplama onu değiştirmez.
- Freelancer "Performans" sayfasında puanını, bileşenlerini, seviye tablosunu ve sonraki seviyeye kalanı görür.
- Yönetici bir atamayı kaldırırken bunun güvenilirlik puanına işlenip işlenmeyeceğini seçer.

## 7. Pazarlama politikası: freelancer'lar hangi işleri görür?

Her işin sayfasındaki **Görünürlük ve dağıtım** bölümü:

| Ayar | Etki |
|------|------|
| Havuz / Seçili kişiler / Ekibe özel | Kimin görebileceği |
| En düşük seviye | Örn. Gold+ (katalog kalemindeki seviye şartı otomatik uygulanır) |
| Öncelikli seviye + süre | Örn. ilk 24 saat yalnızca Elite görür |
| Uzmanlık / şehir eşleşmesi | Kurgu işi kameramana, İstanbul çekimi Ankara'ya gösterilmez |
| İlk alan alır / Teklif usulü | Dağıtım şekli |

- "Kimler görüyor?" önizlemesi her freelancer için görüp görmediğini ve nedenini gösterir.
- Freelancer'ın havuzunda, yalnızca seviye yüzünden göremediği işlerin **sayısı** gösterilir. Bu, seviye atlamak için motivasyon sağlar.

## 8. Silme yetkisi ve roller

- `platform.delete` izni kalıcı silmeyi açar. Bu izin Roller sayfasından herhangi bir role verilebilir. Hazır **Platform Yöneticisi** rolünde platform.manage, platform.pricing ve platform.delete birlikte bulunur.
- **İş silme:** iş sayfasından, iş kodu yazılarak onaylanır. İş merkezinden toplu silme de yapılabilir. Bağlı faturaları da silmek seçimliktir.
- **Ajans silme:** işi olan ajans yalnızca "işleriyle birlikte sil" seçeneğiyle silinir.
- **Freelancer silme:** aktif işleri havuza döner.
- Faturası veya cari hareketi olan cari kart muhasebe geçmişi için korunur.
- **Katalog kalemi:** silinebilir veya pasife alınabilir. Geçmiş siparişler kendi ad ve fiyat kopyasını taşır.

## 9. Hizmet kataloğu

`İş merkezi → Hizmet kataloğu` (izin: platform.pricing). Her kalemde şunlar tanımlanır:

- ajans fiyatı ve freelancer ücreti (marj otomatik görünür)
- birim
- en düşük seviye
- en kısa sipariş süresi
- aktif / pasif durumu

Toplu yüzde güncelleme yapılabilir; sonuçlar 50 TL'ye yuvarlanır. Fiyat değişikliği yalnızca yeni siparişleri etkiler. Kurulumda örnek fiyatlar yüklenir. Fiyatlar gözden geçirilene kadar iş merkezinde uyarı görünür.

## 10. Giriş, kayıt ve muhasebe

- Personel girişinde **"Müşteri / Ajans / Freelancer Girişi"** butonu vardır. Portal girişi `/client/login.php`, hesap türüne göre yönlendirir.
- Yeni ajans ve freelancer hesapları onay bekler.
- Kayıt hız sınırı IP başına uygulanır ve personel giriş kilidini etkilemez.
- İş tamamlanınca:
  - Ajansa satış faturası kesilir.
  - Freelancer'a hakediş (alış faturası) oluşur.
  - Ödeme iş sayfasından kasa veya banka seçilerek kaydedilir.

## 11. Bilinen sınırlar

- Teslimatlar bağlantı olarak paylaşılır; dosya yükleme yoktur.
- SMS bildirimi yoktur. E-posta bildirimleri için bkz. KULLANIM_KILAVUZU → E-posta.
- Teslimden X gün sonra otomatik onay için zamanlanmış görev (cron) gerekir.
