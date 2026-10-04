# RY Medya İş Platformu: Ajans · RY Medya · Freelancer

Portal artık iç ERP'nin yanında bir **iş pazaryeri** olarak da çalışıyor. Ajanslar iş talebi girer, RY Medya işi fiyatlar ve dağıtır, işi RY Medya ekibi veya freelancer'lar yapar. Süreç teslim ve ödemeye kadar platform üzerinde ilerler.

## 1. Hiyerarşi

```
                    ┌──────────────────────────────┐
                    │  PLATFORM YÖNETİCİSİ (RY Medya) │  modules/platform/*
                    │  fiyatlar · dağıtır · denetler  │  izin: platform.manage
                    └───────┬──────────────┬───────┘
        iş talebi / onay    │              │   iş / ücret / geri bildirim
                            ▼              ▼
              ┌──────────────────┐   ┌──────────────────┐
              │  AJANS (iş veren) │   │ FREELANCER (iş alan)│   /platform/*
              └──────────────────┘   └──────────────────┘
          Ajans ile freelancer birbirini GÖRMEZ, doğrudan yazışamaz.
```

| Rol | Görebildikleri | Yapabildikleri |
|-----|----------------|----------------|
| **Platform yöneticisi** | Her şey: iki fiyat, marj, iki mesaj kanalı | Fiyatlama, görünürlük politikası, atama, kalite kontrol, iptal, ödeme, onay/askı, seviye belirleme |
| **Ajans** | Kendi işleri, kendine verilen fiyat, onaylanmış teslimatlar, kendi faturaları | İş talebi, fiyat onayı/ret, teslim onayı/revizyon, puan, mesaj, iptal (atama öncesi) |
| **Freelancer** | Kendisine görünür kılınan işler, kendi hakedişi, kendi teslimatları | İşi alma/başvurma, başlama, bırakma (başlamadan önce), teslim, mesaj, kazanç takibi |

Gizlilik kuralları:
- Freelancer **ajansın ödediği fiyatı** hiçbir zaman görmez.
- Ajans adını görmesi bir ayara bağlıdır (varsayılan: kapalı).
- Ajans freelancer'ın adını hiçbir zaman görmez; işi yapanı "RY Medya Prodüksiyon Ekibi" olarak görür.

## 2. İş akışı

```
 AJANS              PLATFORM YÖNETİCİSİ                FREELANCER
 ─────              ───────────────────                ──────────
 İş talebi ──► [submitted] İnceleme
                    │ fiyat + freelancer ücreti
                    ├─► Teklif gönder ──► [quote_sent] ──► Ajans onaylar
                    └─► Doğrudan yayınla ───────────────┐
                                                        ▼
                                                 [open] Havuz
          ┌──────────────────────┬──────────────────────┼──────────────────────┐
          ▼                      ▼                      ▼                      ▼
   Ekibim üstlensin      Doğrudan ata        İlk alan alır (freelancer)   Başvuru → seçim
   (ERP'de proje açılır)       │                      │                      │
          │                    └──────────► [assigned] ◄──────────────────────┘
          │                                       │ freelancer "İşe Başla"
          │                                 [in_progress]
          │                                       │ teslim linki
          │                                 [qa_review] Kalite kontrol ──✕──► [revision]
          ▼                                       │ ✓
     Ekip teslim eder ─────────────────────► [delivered] Ajans inceler
                                                  │ revizyon ──► [revision] ──► tekrar teslim
                                                  │ onay + puan
                                             [completed]
                              • Ajansa KDV'li satış faturası (otomatik)
                              • Freelancer'a hakediş kaydı (alış faturası)
                              • Yönetici "Ödemeyi Kaydet" → kasadan çıkış → freelancer "Ödendi" görür
```

Her adımda ilgili kişiye bildirim gider (zil simgesi). Her işin altında "İş Geçmişi" zaman çizelgesi tutulur.

## 3. Pazarlama politikası: freelancer'lar hangi işleri görür?

Her iş için **İş Detayı → Görünürlük & Dağıtım Politikası** bölümünden ayarlanır:

| Ayar | Etki | Örnek kullanım |
|------|------|----------------|
| **Havuz** | Kurallara uyan tüm onaylı freelancer'lar görür | Standart işler |
| **Seçili freelancer'lar** | Yalnızca işaretlediğiniz kişiler görür | Büyük marka işi, güvendiğiniz 3 kişi |
| **Gizli (ekibe özel)** | Hiçbir freelancer görmez | Kendi yapacağınız veya telefonla atayacağınız işler |
| **Asgari seviye** | Örn. "Gold ve üzeri" | Yüksek bütçeli işleri deneyimlilere ayırma |
| **Öncelikli erişim** | Örn. "Elite önce görsün, 24 saat" → süre dolunca diğerlerine açılır | En iyi çalışanları ödüllendirme |
| **Uzmanlık eşleşmesi** | Sadece o iş türünde uzman olanlar | Kurgu işini kameramanlara göstermeme |
| **Şehir eşleşmesi** | Sadece aynı şehirdekiler (uzaktan işlerde geçersiz) | İstanbul çekimini İstanbul'dakilere |
| **Alma şekli** | İlk alan alır / Başvuru topla | Acil iş: ilk alan alır. Kritik iş: başvurudan seç |

İş sayfasındaki **"Bu işi kimler görüyor?"** listesi, her freelancer için görüp görmediğini ve görmüyorsa nedenini gösterir.

**Genel ayarlar** (İş Merkezi → Politika Ayarları):
- İş havuzunu tamamen açıp kapatma
- Ajans ve freelancer kayıtlarını açıp kapatma
- Freelancer'ın ajans adını görmesi
- Teslimlerin önce kalite kontrole düşmesi
- Freelancer başına eşzamanlı iş limiti
- Varsayılan marj
- Ücretsiz revizyon hakkı
- Otomatik fatura

**Seviyeler:** Standart → Silver → Gold → Elite. Freelancer Havuzu ekranından verilir; yükseltmede freelancer'a tebrik bildirimi gider.

## 4. Giriş ve kayıt

- **Personel girişi** (`/modules/auth/login.php`): altta **"Müşteri / Ajans / Freelancer Girişi"** butonu vardır.
- **Portal girişi** (`/client/login.php`): tek giriş ekranıdır. Hesap türüne göre yönlendirir:
  - Müşteri → `/client/index.php`
  - Ajans veya freelancer → `/platform/index.php`
- **Kayıt** (`/platform/register.php?type=agency|freelancer`): yeni hesap **onay bekliyor** durumunda açılır. Yöneticiye bildirim gider. Onaylanana kadar ajans iş giremez, freelancer iş havuzunu göremez.
- Askıya alınan hesap bir sonraki sayfa açılışında oturumdan atılır.

## 5. Muhasebe bağlantısı

- Her ajans ve freelancer otomatik olarak bir **cari kart** olarak açılır (tür: Ajans / Freelancer).
- İş kapanınca:
  - Ajansa satış faturası kesilir (proje bağlıysa projeye bağlanır).
  - Freelancer'a alış faturası (hakediş) oluşturulur.
  - Cari bakiyeler güncellenir.
- Freelancer ödemesi iş sayfasından kasa veya banka seçilerek yapılır. Mevcut tahsilat altyapısı kullanılır; kasa bakiyesi ve fatura durumu otomatik güncellenir.
- "Ekibim üstlensin" seçildiğinde ERP'de proje açılır. Çekim günü, set maliyeti ve kurgu takibi oradan devam eder.

## 6. Bilinen sınırlar ve sonraki adımlar

- Dosya yükleme yok; teslimatlar bağlantı (Drive, WeTransfer, Vimeo, Frame.io) olarak paylaşılır.
- E-posta/SMS bildirimi yok; bildirimler platform içi zil üzerinden. SMTP bilgisi verilirse e-posta eklenebilir.
- Ajans hesabı tek kullanıcılıdır. Bir ajansa birden çok kullanıcı açma özelliği ileride eklenebilir.
- Teslim edildikten X gün sonra otomatik onay için zamanlanmış görev (cron) gerekir.
