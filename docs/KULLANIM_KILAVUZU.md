# Kullanım Kılavuzu

Bu kılavuz, bir prodüksiyon işinin portalda baştan sona nasıl yürütüleceğini anlatır. Menüde göremediğiniz bölümler için yöneticiniz **Roller & Yetkilendirme** ekranından size izin vermelidir.

---

## 1. Teklif hazırlama ve onay alma

1. **Teklifler & Pipeline → Yeni Teklif** ekranında müşteriyi ve başlığı seçin.
2. **Fiyat Kalemleri** bölümünde hazır butonlarla kalem ekleyin ("Çekim Günü", "Kurgu", "Color Grading" gibi). Miktar ve birim fiyatı girin. Teklif tutarı kalemlerin toplamından otomatik hesaplanır.
   - Kalem eklemezseniz tek bir toplam fiyat girebilirsiniz.
3. Kaydedince teklif **Taslak** aşamasında oluşur. Taslaklar müşteriye görünmez.
4. Teklifi **"Müşteriye İletildi"** aşamasına aldığınızda müşteri onu portalda görür, PDF olarak indirir ve **Kabul Et / Reddet** butonlarıyla yanıtlar.
   - Müşteri reddederken neden yazmak zorundadır.
   - Yanıt geldiğinde size bildirim düşer ve yanıt, notuyla birlikte teklif kartında görünür.
5. Kabul edilen teklifi **"Projeye Dönüştür"** butonuyla tek tıkta projeye çevirin.

## 2. Projeyi yürütme

Proje detay sayfasındaki sekmeler:

| Sekme | Ne yapılır? |
|-------|-------------|
| **Çekim Günleri & Set Maliyetleri** | Çekim günü ekleyin, saat ve lokasyon girin, call sheet yazdırın. Her güne ekip ve ekipman giderini girin: **Bütçeye Dahil** ajansın maliyetidir, **Hariç** gider müşteriye yansıtılır. İsterseniz tedarikçiye otomatik alış faturası oluşturulur. |
| **Kurgu & Edit** | Kurgu versiyonlarını önizleme linkiyle ekleyin ve kurgucuya atayın. Kurgucuya bildirim gider. Ek kurgu bedelini ayrıca faturalandırabilirsiniz. |
| **Görevler** | "Lokasyon keşfi", "Cast seçimi", "Ses miksajı" gibi işleri kişiye ve tarihe atayın. Atanan kişiye bildirim gider; görev tamamlanınca oluşturan kişi haberdar edilir. |
| **Teslim Dosyaları** | Final videoların Drive, WeTransfer, Vimeo veya Frame.io linklerini ekleyin. "Müşteri portalında göster" işaretliyse müşteri indirme butonunu görür. |
| **Faturalar** | Projeye bağlı faturalar. Buradan yazdırabilir veya faturayı iptal edebilirsiniz. |
| **Brief & Notlar** | Proje açıklaması ve **proje geçmişi**: müşteri onayları, revizyon talepleri, faturalar ve durum değişiklikleri. |

İş bitince **"Projeyi Tamamla & Carileştir"** ile faturayı kesin. Fatura tutarı bütçe artı müşteriye yansıtılan giderlerden oluşur; KDV ve tevkifatı seçebilirsiniz.

## 3. Müşteri portalı

Müşteriye giriş vermek için: **Cariler → müşteri kartı → "Müşteriye Portal Aç"**. Bir e-posta ve en az 8 karakterlik şifre belirleyin. Giriş adresi `…/client/login.php`.

Müşteri portalda şunları yapabilir:
- Projelerini, çekim günlerini ve kurgu önizlemelerini görür.
- Kurguyu **onaylar** veya **revizyon ister**. Revizyon notu kurgucuya ve proje ekibine bildirim olarak düşer.
- Teslim dosyalarını indirir.
- Tekliflerini görür; kabul veya ret yanıtı verir.
- Faturalarını ve hesap ekstresini PDF olarak alır.

Portal erişimini cari kartından **"Erişimi Kapat"** ile durdurabilirsiniz.

## 4. Günlük kullanım

- **Kontrol Paneli:** Size atanan görevler, önümüzdeki 7 günün çekim ve teslimleri, son müşteri hareketleri, vadesi geçen alacaklar.
- **Prodüksiyon Takvimi:** Ay ve liste görünümü vardır. Üstteki renkli etiketlere tıklayarak türleri gizleyip gösterebilirsiniz. Mobilde liste görünümü otomatik açılır.
- **Görev Panosu:** "Başla" ve "Tamamla" butonlarıyla görev durumunu tek tıkla ilerletin.
- **Bildirimler (zil simgesi):** Okunmamış bildirim sayısı kırmızı rozette görünür. Yöneticiler "Tüm Aktivite" sekmesinde sistemdeki bütün işlemleri izleyebilir.
- **Arama (üst çubuk):** Proje adı, müşteri, telefon, fatura numarası, teklif numarası veya ekipman seri numarasıyla arayın.

## 5. Finans ve raporlar

- **Faturalar:** Arama, tarih aralığı ve **Vadesi Geçmiş** filtresi vardır. Tahsilat kalan tutarı geçemez.
- **Kasa & Banka:** Hesaplar arası **Virman**, hesap düzenleme ve pasife alma.
- **Yönetim Raporları:**
  - *Proje Kârlılığı*: Hangi proje ne kadar kazandırdı? Fatura kesilmemiş projelerde gelir "Tahmini" olarak gösterilir.
  - *Alacak Yaşlandırma*: Hangi müşterinin ne kadar borcu kaç gündür bekliyor? Gruplar: vadesi gelmemiş, 1-30, 31-60, 61-90 ve 90+ gün.
  - *Aylık Gelir-Gider*: Faturalanan tutar, kasaya giren/çıkan para ve maaşlar; grafikli.
  - *Müşteri Ciroları*: Müşteri bazında ciro, tahsilat ve açık bakiye.
  - Her rapor **Excel / CSV** butonuyla indirilebilir.

## 6. Ayarlar (Yönetim → Ayarlar)

Tüm değiştirilebilir içerik tek sayfada, bölümler halinde toplanır. Her bölüm ayrı kaydedilir ve **Varsayılana döndür** ile ilk haline alınabilir.

| Bölüm | Neleri değiştirir? |
|-------|--------------------|
| **Marka ve görünüm** | Marka adı, simge kısaltması, alt başlık, açık/koyu zemin logosu ve yüksekliği, favicon, vurgu rengi, koyu panel rengi, tarayıcı sekmesi son eki, alt bilgi metinleri |
| **Şirket ve künye** | Resmi ünvan, iletişim, adres, vergi ve sicil bilgileri |
| **Banka ve ödeme** | IBAN'lar ve müşteri portalındaki ödeme notu |
| **Finans ve kodlar** | Para birimi, KDV ve kurumlar vergisi oranı, fatura/proje kodu önekleri |
| **Personel giriş sayfası** | Başlık, alt metin, sol panel sloganı ve maddeleri, portal butonu, arka plan görseli |
| **Portal giriş sayfası** | Müşteri/ajans/freelancer giriş sayfasının tüm metinleri ve kayıt kartları |
| **Kayıt sayfaları** | Ajans ve freelancer başvuru sayfalarının başlıkları, sloganları, onay metni ve KVKK bağlantısı |
| **Portal ve iletişim** | Destek e-postası/telefonu, müşteri portalı etiketi, mesajlarda görünen ekip adı |
| **Belgeler** | Fatura/teklif/ekstre/call sheet'te logo, slogan, "Düzenleyen", alt notlar |

- Sloganlarda `*yıldızla çevrilen*` kelimeler vurgu renginde görünür.
- Madde simgeleri [lucide.dev/icons](https://lucide.dev/icons/) listesindeki adlarla yazılır (ör. `camera`, `film`).
- Giriş ve kayıt bölümlerindeki **Önizle** butonu sayfayı yeni sekmede açar.
- Görseller PNG, JPG, WEBP veya SVG olabilir; favicon ICO da olabilir. En fazla 2 MB. Dosyalar `assets/uploads/branding/` klasörüne kaydedilir; bu klasörün sunucuda yazılabilir olması gerekir. Betik içeren SVG'ler reddedilir.
- Platform kuralları, hizmet kataloğu ve roller sayfalarına ayarların sol menüsünden de ulaşılır.

## 7. Yetkiler

| İzin | Ne açar? |
|------|----------|
| `projects.view` / `projects.edit` | Projeler, takvim, görev panosu / proje üzerinde değişiklik |
| `contacts.*` | Cariler |
| `finance.view`, `finance.invoices` | Kasa ve banka, faturalar |
| `reports.view` | Yönetim raporları (ilk kurulumda finans yetkisi olan rollere otomatik verilir) |
| `proposals.manage`, `inventory.manage` | Teklifler, ekipman |
| `personnel.manage`, `settings.manage` | Personel, ayarlar ve roller |
| `platform.manage` | İş merkezi: siparişler, atama, teklifler, kalite kontrol, freelancer/ajans yönetimi |
| `platform.pricing` | Hizmet kataloğu ve fiyatlar |
| `platform.delete` | Platform kayıtlarını kalıcı silme (iş, ajans, freelancer, katalog kalemi) |

Hazır **Platform Yöneticisi** rolü üç platform iznini birlikte içerir; Roller sayfasından kişiye atanır. Ayrıntılar: [PLATFORM.md](PLATFORM.md).

Kurgucular kendilerine atanan görevleri, projeyi düzenleme yetkileri olmasa bile tamamlandı olarak işaretleyebilir.
