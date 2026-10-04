# RY Medya İş Platformu: Ajans · RY Medya · Freelancer

Portal, iç ERP'nin yanında bir **prodüksiyon iş platformu** olarak da çalışır:

- Ajanslar **hizmet kataloğundan** iş girer; tutar anında hesaplanır.
- İş doğrudan iş havuzuna düşer.
- İşi RY Medya ekibi veya freelancer'lar yapar.
- Süreç teslim, değerlendirme ve ödemeye kadar platform üzerinde ilerler.

## 1. Hiyerarşi ve gizlilik

```
                 ┌────────────────────────────────────┐
                 │ PLATFORM YÖNETİCİSİ (RY Medya)       │  modules/platform/*
                 │ katalog · kurallar · atama · denetim │  izinler: platform.manage / .pricing / .delete
                 └────────┬──────────────────┬────────┘
          iş / onay  │                  │  iş / hakediş / değerlendirme
                          ▼                  ▼
              ┌────────────────────┐  ┌────────────────────┐
              │ AJANS (iş veren)    │  │ FREELANCER (iş alan) │  /platform/*
              └────────────────────┘  └────────────────────┘
               Ajans ve freelancer birbirini görmez, doğrudan yazışamaz.
```

| Rol | Görür | Yapar |
|-----|-------|-------|
| **Platform yöneticisi** | İki fiyat ve marj, tüm yazışma başlıkları, performans karneleri | Katalog ve fiyatlar, kurallar, atama, teklif kabul/ret, kalite kontrol, iptal, ödeme, seviye, kalıcı silme |
| **Ajans** | Kendi işleri, kendi fiyatı, kalite kontrolden geçmiş teslimatlar, faturaları | Katalogdan iş veya özel talep, düzenleme (kapsamlı), teklif onayı, teslim onayı + puan, revizyon, iptal (atama öncesi) |
| **Freelancer** | Kendisine açılan işler, kendi hakedişi, kendi teslimatları ve yazışması, ret gerekçeleri, performans karnesi | İşi al / teklif ver, geri çek, başla, bırak (gerekçeli), teslim et, ekiple yaz |

Gizlilik kuralları:

- Freelancer ajansın ödediği fiyatı ve fiyat değişikliklerini hiçbir zaman görmez.
- Ajans freelancer'ın adını, hakedişini ve dağıtım politikasını hiçbir zaman görmez.
- Ajans adını görmek ayara bağlıdır.
- Her freelancer'ın ekiple yazışması **kendine özeldir**. Bir teklif verenin yazışmasını diğeri göremez.

## 2. İş akışı (sadeleştirilmiş)

```
 AJANS                                PLATFORM                          FREELANCER
 Katalogdan hizmet seç ──► fiyat anında hesaplanır
 İş gir ─────────────► [open] havuz (otomatik yayın açıksa)  ──► işi al / teklif ver
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

- Katalogdan girilen işlerde fiyat pazarlığı yoktur; ajans fiyatı işi girerken görür.
- Yönetici onayı yalnızca "otomatik yayın" kapalıysa veya özel taleplerde gerekir.

### 2.1 Aşamalar (milestone)

İş, ayrı ayrı teslim edilip onaylanan aşamalara bölünür (freelancer.com mantığı; ajans ile freelancer yine birbirini görmez):

- Katalog işinde her hizmet kalemi bir aşamadır (çekim kalemleri önce, hedef tarihi başlangıç günü). Özel talepte tek aşama vardır. Ayar: Kurallar → "Katalog işlerinde her hizmet ayrı aşama olsun".
- Freelancer teslim ederken aşamayı seçer → kalite kontrol → ajans "Aşamayı onayla" der → o aşamanın hakedişi (alış faturası) anında kayda geçer, iş sıradaki aşamaya döner.
- Son aşama onaylanınca iş kapanır, ajansa toplam tutardan tek satış faturası kesilir.
- Ekip aşama adını, ücretini ve hedef tarihini düzenleyebilir, aşama ekleyip silebilir; freelancer ücreti aşama toplamına eşitlenir, ajans fiyatı değişmez.
- Ajans X gün içinde onay ya da revizyon vermezse aşama otomatik onaylanır (Kurallar → "Teslim otomatik onay süresi", 0 = kapalı).

### 2.1.1 Yerinde işler (çekim, drone, fotoğraf)

Sahada yapılan işlerde teslim bağlantısı istenmez:

- Çekim gibi aşamalar "Yerinde · teslim yok" olarak açılır; freelancer çekim gününden itibaren **Yapıldı** der (isterse not ekler).
- Onay şekli Kurallar → "Yerinde işler nasıl tamamlansın?" ayarından: *freelancer bildirince onaylansın* (varsayılan; ajansa bilgi gider, sorun varsa "Sorun bildir"), *ekip onaylasın* veya *ajans onaylasın*.
- Kurgu, renk, ses, motion gibi işler her zaman bağlantıyla teslim edilir ve kalite kontrol / ajans onayından geçer.
- Ajans iş girerken "Ham görüntü / dosya teslimi istiyorum" işaretlerse çekim aşaması da bağlantıyla teslim edilir.
- Ekip bir aşamayı aşamalar kartından "Teslim bağlantısı gerekir" anahtarıyla değiştirebilir ve yerinde aşamayı "Yapıldı" ile kendisi kapatabilir. Ek kalem eklerken "Yerinde iş" işaretlenebilir.
- Yalnızca yerinde işten oluşan işlerde kalite kontrol ve teslim adımları akıştan çıkar.

### 2.2 Ek kalemler ve prim

| Kim ekler | Ajans görür mü | Akış |
|---|---|---|
| Ajans, katalogdan ("Ek kalem ekle") | Evet, katalog fiyatıyla | Tutar işe eklenir → atanmış freelancer kabul eder |
| Ekip, "Ajansa öner" | Evet | Ajans onaylar → freelancer kabul eder |
| Ekip, "İç ek iş" | Hayır | Freelancer kabul eder; teslimini ekip onaylar |
| Ekip, "Prim" | Hayır | İş gerektirmez; doğrudan hakediş kaydı oluşur |

Freelancer ek kalemi kabul etmezse kalem iptal olur ve tutarlar geri alınır; ajansa "ekibimiz iletişime geçecek" bilgisi gider.

### 2.3 Atama kabulü ve teklifler

- Ekibin veya teklif kabulüyle yapılan atamada freelancer "Kabul et ve başla" ya da gerekçeli "Reddet" seçer. Reddetmek puanı etkilemez, iş havuza döner.
- Teklif verirken ücret, teslim süresi (gün) ve müsaitlik girilir; bekleyen teklif güncellenebilir. Freelancer bekleyen teklif sayısını ve ortalamasını görür.

### 2.4 İş kaydı ve sorun bildirimi

- Her işlem (iş girişi, yayın, teklif, atama, teslim, kalite kontrol, revizyon, aşama onayı, ek kalem, fatura, ödeme, puan, sorun, hatırlatma, alan değişiklikleri) kim / ne zaman / eski → yeni / tutar olarak "İş kaydı"na yazılır.
- Herkes yalnızca kendine açık kalemleri görür: ajans freelancer adını, tekliflerini, ücretini görmez; freelancer ajans fiyatını ve adını görmez. Kayıt CSV olarak indirilebilir.
- Ajans ve atanan freelancer "Sorun bildir" ile gerekçeli bildirim açar (gecikme, kalite, iletişim, kapsam, ödeme). İş merkezinde kırmızı uyarı çıkar; ekip çözüm notuyla kapatır, bildiren kişiye iletilir.

### 2.5 Otomasyonlar

`cron/platform.php` (saatte bir önerilir; tanımlı değilse personel paneli açıldıkça saatte bir çalışır):
otomatik aşama onayı · teslimden bir gün önce freelancer'a hatırlatma · teslim tarihi geçen işler için ekibe ve freelancer'a uyarı · başlangıcı 48 saat içinde olup atanmamış işler için ekibe uyarı. Her uyarı iş kaydına yazılır ve bir kez gönderilir.

## 3. Termin kuralları (aynı gün / acil iş)

Referans tarih, çekim/başlangıç tarihidir; girilmemişse teslim tarihi kullanılır. Kurallar hem tarayıcıda anında hem sunucuda uygulanır.

| Durum | Sonuç | Ayar |
|-------|-------|------|
| Geçmiş tarih | İş girilemez | — |
| Aynı gün başlangıç | İş girilemez | `Aynı gün başlayan işler alınmasın` |
| Başlangıca kalan süre < en kısa iş giriş süresi | İş girilemez | `En kısa iş giriş süresi` (varsayılan 24 sa); hizmete özel süre katalogdan (ör. drone 72 sa) |
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

### Otomatik yönlendirme (yeni iş nereye düşer?)

İş merkezi → Yönlendirme ekranında kurallar yukarıdan aşağı denenir; koşullarının tamamını sağlayan ilk kural uygulanır.

- **Koşullar** (boş bırakılan aranmaz): iş tutarı alt/üst sınır, freelancer ücreti, marj oranı, başlangıca kalan süre, başlangıç → teslim süresi, toplam hizmet adedi, ajansın tamamlanmış iş sayısı (yeni ajans), iş tipi (katalog/özel), acil iş, yerinde/uzaktan, şehir, başlık/brief'te geçen kelime, ajansın vadesi geçmiş borcu, iş türü, içerdiği hizmet, belirli ajanslar.
- **Sonuç:** *Onay bekle* (Aksiyon bekleyen), *Atamaya gönder* (onaysız yayın) veya *Ekibe ayır* (yayına açılır, freelancer'lar görmez).
- Kural ayrıca görünürlüğü (kim görsün, dağıtım, en düşük seviye, öncelikli seviye/süre, uzmanlık ve şehir eşleşmesi) değiştirebilir, ekibe öncelikli bildirim gönderebilir ve iş sayfasında görünen bir not bırakabilir.
- Hiçbir kurala uymayan katalog işleri için "onaysız atamaya gitsin" anahtarı geçerlidir. Özel teklif talepleri fiyat gerektirdiği için her zaman onaya düşer.
- Hangi kuralın uygulandığı iş kaydına ve iş sayfasındaki "Sıradaki adım" kartına yazılır. Ekrandaki önizleme son 20 işin bugünkü kurallarla nereye düşeceğini gösterir. "Örnek kuralları ekle" ile hazır şablonlarla başlanabilir.

### Varsayılan kurallar

Yeni girilen her işin görünürlük ve dağıtım kuralları (kim görsün, dağıtım, en düşük seviye, öncelikli seviye ve süresi, uzmanlık ve şehir eşleşmesi) Kurallar → "Yeni işlerde varsayılan görünürlük ve dağıtım" bölümünden belirlenir. Her işin kuralları sonradan iş sayfasındaki "Görünürlük ve dağıtım" kartından değiştirilebilir; "Varsayılanlara dön" ile ayarlardaki değerlere geri alınır. Hizmet kataloğunda daha yüksek seviye isteyen hizmetlerde o seviye geçerlidir.

## 7.1 Ödemeler

**Ajans → Ödeme bildirimi** (portal → Ödemeler): açık faturalar ve vadeleri, ödeme yapılabilecek banka hesapları (IBAN'lı aktif banka hesapları; Kurallar → Ödemeler'den kapatılabilir), ödeme bildirimi formu (fatura seçimi veya genel ödeme, tutar, tarih, yöntem, referans, not, PDF/görsel dekont en fazla 5 MB) ve bildirim geçmişi.

**Freelancer → Ödeme talebi** (portal → Kazanç): onaylanmış ve ödenmemiş hakedişler (aşama aşama) seçilerek talep oluşturulur. IBAN zorunludur; en düşük talep tutarı ve "X iş günü içinde ödenir" bilgisi Kurallar → Ödemeler'den ayarlanır. Aynı hakediş iki bekleyen talepte yer alamaz.

**Ekip → İş merkezi → Ödemeler** (menüde bekleyen sayısı):
- Ajans bildirimi: dekontu açın, tutarı açık faturalara dağıtın (seçilen fatura önce, kalan eskiden yeniye önerilir), kasa/banka hesabını seçip "Hesaba geçti, onayla". Faturaya dağıtılmayan kısım cari hesaba avans olarak işlenir. Ya da gerekçeyle reddedin.
- Freelancer talebi: hesap seçip "öde" → talepteki her hakediş faturası ödenir, ilgili işlerin iş kaydına ödeme satırı düşer. Ya da gerekçeyle reddedin.
- Her sonuç ilgili ajans/freelancer'a bildirim (ve e-posta) olarak gider. Dekontlar doğrudan erişime kapalı klasörde tutulur; yalnızca bildiren ajans ve ekip görür.

## 8. Silme yetkisi ve roller

- `platform.delete` izni kalıcı silmeyi açar. Bu izin Roller sayfasından herhangi bir role verilebilir. Hazır **Platform Yöneticisi** rolünde platform.manage, platform.pricing ve platform.delete birlikte bulunur.
- **İş silme:** iş sayfasından, iş kodu yazılarak onaylanır. İş merkezinden toplu silme de yapılabilir. Bağlı faturaları da silmek seçimliktir.
- **Ajans silme:** işi olan ajans yalnızca "işleriyle birlikte sil" seçeneğiyle silinir.
- **Freelancer silme:** aktif işleri havuza döner.
- Faturası veya cari hareketi olan cari kart muhasebe geçmişi için korunur.
- **Katalog kalemi:** silinebilir veya pasife alınabilir. Geçmiş işler kendi ad ve fiyat kopyasını taşır.

## 9. Hizmet kataloğu

`İş merkezi → Hizmet kataloğu` (izin: platform.pricing). Her kalemde şunlar tanımlanır:

- ajans fiyatı ve freelancer ücreti (marj otomatik görünür)
- birim
- en düşük seviye
- en kısa iş giriş süresi
- aktif / pasif durumu

Toplu yüzde güncelleme yapılabilir; sonuçlar 50 TL'ye yuvarlanır. Fiyat değişikliği yalnızca yeni işleri etkiler. Kurulumda örnek fiyatlar yüklenir. Fiyatlar gözden geçirilene kadar iş merkezinde uyarı görünür.

## 10. Giriş, kayıt ve muhasebe

- Personel girişinde **"Müşteri / Ajans / Freelancer Girişi"** butonu vardır. Portal girişi `/client/login.php`, hesap türüne göre yönlendirir.
- Yeni ajans ve freelancer hesapları onay bekler.
- Kayıt hız sınırı IP başına uygulanır ve personel giriş kilidini etkilemez.
- Her onaylanan aşama için freelancer'a hakediş (alış faturası) oluşur; ödeme iş sayfasındaki "Hakediş ödemeleri" kartından aşama aşama kaydedilir.
- İş tamamlanınca ajansa toplam tutardan satış faturası kesilir.

## 11. Bilinen sınırlar

- Teslimatlar bağlantı olarak paylaşılır; dosya yükleme yoktur.
- SMS bildirimi yoktur. E-posta bildirimleri için bkz. KULLANIM_KILAVUZU → E-posta.
