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

## 7. E-posta (Yönetim → E-posta)

**Kurulum (Ayarlar → E-posta):**
1. Gönderim yöntemini seçin. Hosting firmanızın verdiği SMTP bilgileri önerilir (sunucu, port 587/465, kullanıcı, şifre); alternatif olarak sunucunun `mail()` işlevi kullanılabilir.
2. Gönderen adresini girin, "E-posta gönderimi açık" anahtarını açıp kaydedin.
3. **Test gönder** ile kendinize deneme e-postası atın. Hata varsa mesajı ekranda görünür.
4. Toplu gönderimler ve tekrar denemeler için zamanlanmış görev (cron) tanımlayın: cPanel → Cron Jobs → her 5 dakikada `php /home/KULLANICI/public_html/cron/mail-queue.php`

**İşe bağlı e-postalar (otomatik):**
- **Ajans:** iş yayında, ekip atandı, üretime başlandı, teslim edildi, fiyat teklifi, tamamlandı, ekipten mesaj, hesap onayı
- **Freelancer:** atama, teklif sonucu (ret gerekçesiyle), kalite kontrol, revizyon, hakediş/ödeme, seviye değişikliği, size özel iş, ekipten mesaj
- **Müşteri:** onaya sunulan kurgu versiyonu, paylaşılan teslim dosyası, fatura, yeni teklif
- **Personel:** görev/kurgu ataması, müşteri revizyonu
- **Ekip gelen kutusu:** doldurulursa tüm platform bildirimlerinin kopyası bu adrese gider.
- Kayıt olana "başvurunuz alındı" e-postası gönderilir.
- Her kişi profilinden iş bildirimlerini ve duyuru e-postalarını ayrı ayrı kapatabilir.

**Toplu e-posta:**
- **Aboneler:** müşteri, ajans, freelancer, tedarikçi ve personel e-postaları cari kartlardan ve hesaplardan otomatik eşitlenir. Manuel ekleme, CSV içe/dışa aktarma ve etiketleme yapılabilir.
- **Yeni toplu e-posta:**
  - Konu ve içerik yazılır (başlık, liste, bağlantı, buton, görsel). `{ad}`, `{firma}` gibi etiketler alıcıya göre doldurulur.
  - Hedef kitle seçilir: tür, freelancer seviyesi, etiket, yalnızca ticari izni olanlar. Alıcı sayısı anında görünür.
  - Önizleme ve test gönderiminden sonra **Gönderimi başlat** denir.
- Gönderim parti parti ilerler. Sayfa açıkken devam eder; cron tanımlıysa arka planda sürer. Hatalılar yeniden denenebilir, gönderim durdurulabilir.
- Her toplu e-postada "Bu listeden çık" bağlantısı bulunur; çıkan kişiye bir daha toplu e-posta gitmez. İş bildirimleri bundan etkilenmez.
- Kampanya ve tanıtım içerikli e-postalar için alıcı onayı gerekir; İYS yükümlülüğünü kontrol edin. Kayıt formundaki bülten kutusunu işaretleyenler "ticari izin var" olarak işaretlenir.
- **Gönderim kayıtları:** her e-postanın durumu, hata mesajı ve içeriği görüntülenebilir.

## 8. İş platformu: günlük akış (İş merkezi)

1. Ajans işi girer. İş, İş merkezi → Yönlendirme ekranındaki kurallara göre (tutar, acil, termin, ajans, hizmet vb.) "Aksiyon bekleyen"e düşer, doğrudan atamaya gider ya da ekibe ayrılır. Özel talepte fiyatı girip teklifi gönderin.
2. Atama: freelancer işi alır, teklif verir (ücret + teslim süresi) veya siz doğrudan atarsınız. Atanan kişi işi kabul eder ya da cezasız reddeder.
3. Aşamalar kartında işin parçalarını görürsünüz. "Düzenle" ile ad, ücret, hedef tarih değiştirilir; "Ek kalem / prim" ile ajansa ek iş önerilir, ajansa yansımayan iç iş ya da prim eklenir.
4. Çekim gibi yerinde işler teslim bağlantısı istemez: freelancer çekim gününden itibaren "Yapıldı" der; onay şekli Kurallar ekranından seçilir (otomatik / ekip / ajans). Ajans ham görüntü istediyse işe ücretli "Ham görüntü teslimi" aşaması eklenir ve bağlantıyla teslim edilir. Ücretsiz revizyon hakkı dolunca ajans ücretli revizyon ister (ücret Kurallar ekranından, işe özel değer iş sayfasındaki Revizyon koşullarından).
5. Kurgu, ses gibi dijital işlerin teslimi doğrudan ajansın onayına düşer (siz de iş sayfasından görür, gerekirse müdahale edersiniz). İsterseniz Kurallar → "Freelancer teslimleri önce kalite kontrolden geçsin" ile teslimler önce size düşer: "Onayla, ajansa ilet" veya "Düzeltme iste". Ajans aşamayı onaylayınca hakediş kaydı oluşur; gerekirse "Aşamayı ajans adına onayla" ya da "İşi ajans adına tamamla".
   - **Yapım süresi:** her hizmetin katalogda yapım süresi vardır (taban gün + ek birim başına gün). Ajans iş girerken teslim tarihi, başlangıçtan bu süre kadar sonrasından önce seçilemez; form en erken tarihi gösterir. Genel kurallar (en az gün, kalemler toplansın / en uzunu, teslim payı, ham görüntü süresi, özel talep süresi, hafta sonu sayılmasın) Kurallar → "Yapım süresi" bölümündedir.
   - **Düzenleme:** ajans iş kabul edilene kadar kalemleri değiştirir/siler/ekler (İşi düzenle); sonradan kendi eklediği ek kalemleri teslim başlayana kadar değiştirir veya kaldırır. Ekip, iş sayfasındaki **İş kalemleri** kartından her durumda (teslim ve tamamlandıktan sonra da) miktar, birim fiyat ve adı düzeltir, kalem siler veya katalogdan ekler; aşamaların ad/ücret/tarihi de düzeltilebilir. Fark işe ve aşamaya yansır, taraflara bildirilir. Kesilmiş faturalar otomatik değişmez.
6. Ödemeler: ajansların ödeme bildirimlerini ve freelancer ödeme taleplerini İş merkezi → Ödemeler ekranından onaylayın (tahsilat/ödeme faturaya ve kasaya işlenir). Tek tek ödeme için iş sayfasındaki Hakediş ödemeleri kartı da kullanılabilir.
7. Sorun bildirimleri iş merkezinde kırmızı uyarı olarak görünür; iş sayfasındaki "Sorun bildirimleri" kartından çözüm notuyla kapatın.
8. İş kaydı her işlemi kalem kalem tutar; CSV ile dışa aktarılabilir.
9. Kurallar ekranından aşama bölme ve otomatik onay süresini ayarlayın. Hatırlatma ve otomatik onay için cron: `php /home/KULLANICI/public_html/cron/platform.php` (saatte bir).

## 8.1 Bildirimler

- Zil menüsü açıldığında bildirimler okundu sayılır. Yeni bildirimler sayfa yenilemeden (yaklaşık 12 saniyede bir) düşer; köşede kısa bildirim çıkar ve sekme başlığında sayı görünür.
- Açık bir iş sayfasında yeni bir gelişme olursa sayfa kendini tazeler; kullanıcı o sırada form dolduruyorsa tazelemez, üstte "Sayfayı yenile" uyarısı çıkar.
- Ajans ve freelancer profil sayfaları ayrıntılıdır (firma, yetkili, muhasebe, fatura / kişisel, uzmanlık, portfolyo, çalışma koşulları, acil durum, ödeme ve vergi); IBAN ve T.C. kimlik numarası doğrulanır. Doldurulan bilgiler İş merkezi → Ajanslar / Freelancer'lar ekranında görünür.

## 9. Güvenlik (yalnızca süper yönetici)

- **Ayarlar → Güvenlik:** iki adımlı doğrulama (personel ve portal için ayrı ayrı: Kapalı / İsteğe bağlı / Zorunlu), hareketsizlikte otomatik çıkış süreleri, yeni cihazdan giriş e-postası, şifre sıfırlama bağlantısı süresi, otomatik yedek ve yedek klasörü. Bu bölümü yalnızca süper yönetici görür ve değiştirebilir.
- **İki adımlı doğrulama:** kullanıcı sağ üst menüden (portalda profil menüsünden) "İki adımlı doğrulama"ya girer, Google/Microsoft Authenticator ile QR kodu okutur, 6 haneli kodu onaylar ve 8 kurtarma kodunu saklar. "Zorunlu" seçilirse kurmayan kullanıcı girişten sonra kuruluma yönlendirilir. Telefonunu kaybeden kullanıcı için Güvenlik → İki adımlı doğrulama sekmesinden "Sıfırla".
- **Şifremi unuttum:** giriş ekranlarındaki bağlantıyla e-postaya tek kullanımlık, süreli bağlantı gider (E-posta ayarlarının açık olması gerekir). Şifre en az 8 karakter, harf ve rakam içermeli.
- **Güvenlik merkezi** (menü → Güvenlik): durum kontrolü, giriş kayıtları (başarılı/başarısız, IP, tarayıcı), kullanıcıların iki adımlı doğrulama durumu ve yedekler (şimdi al, indir, sil). Yedek phpMyAdmin → İçe aktar ile geri yüklenir.
- **Kart ödemesi mutabakatı:** ajans kartla ödeyip iyzico'dan dönmeden tarayıcıyı kapatırsa saatlik görev ödemeyi iyzico'dan sorgular ve faturaya işler.
- Sunucuda kök `.htaccess` HTTPS'e yönlendirir, gizli/yedek dosyalara erişimi kapatır ve güvenlik başlıklarını ekler.

## 9.1 Yasal metinler ve sözleşme onayları (yalnızca süper yönetici)

- **Metinler:** Kullanım Koşulları ve Üyelik Sözleşmesi, Ajans Hizmet Sözleşmesi, Freelancer Hizmet Sağlayıcı Sözleşmesi, KVKK Aydınlatma Metni, Açık Rıza Metni, Ticari Elektronik İleti Onayı, Gizlilik ve Güvenlik Politikası, Çerez Politikası, Ön Bilgilendirme Formu ve Mesafeli Hizmet Sözleşmesi, İptal-İade ve Ödeme Koşulları, İletişim ve Künye. Herkese açık adres: `/legal/index.php`. Tüm giriş, kayıt ve portal sayfalarının altında bağlantıları vardır.
- **Önce şirket bilgilerini doldurun:** Ayarlar → Şirket ve künye (ünvan, adres, vergi, MERSİS, KEP, KVKK e-postası, yetkili mahkeme). Metinlerdeki `{{unvan}}` gibi yer tutucular buradan dolar. Eksik bilgi metinde "[… — Ayarlar → Şirket ve künye]" olarak görünür.
- **Kayıtta onay:** hesap türünün zorunlu tüm metinleri ayrı ayrı onaylanmadan kayıt olunamaz. Ajans: Kullanım Koşulları, Ajans Hizmet Sözleşmesi, İptal-İade ve Ödeme Koşulları, KVKK Aydınlatma. Freelancer: Kullanım Koşulları, Freelancer Hizmet Sağlayıcı Sözleşmesi, KVKK Aydınlatma. Açık rıza ve ticari ileti izni isteğe bağlıdır.
- **Hesap → Sözleşmeler ve onaylar:** kullanıcı zorunlu metinleri, onay tarihini ve sürümünü burada görür; onaylamadıklarını (veya güncellenen sürümü) buradan onaylar. Onayı eksik kullanıcı sayfaları görebilir ama hiçbir işlem (iş girme, iş alma, teslim, ödeme, mesaj, profil kaydı vb.) yapamaz; her sayfanın altında uyarı çıkar ve işlem denemesi bu bölüme yönlendirilir.
- **Metni düzenleme:** menü → Yasal metinler → Düzenle. Yazım düzeltmesinde kutuyu işaretlemeden kaydedin. Önemli değişiklikte **"yeni sürüm yayımla ve yeniden onay iste"** kutusunu işaretleyin: ilgili kullanıcılar bir sonraki girişte yeni metni onaylar. "Varsayılan metne dön" ilk metni yeni sürüm olarak yayımlar.
- **Kartla ödeme:** ajans, ön bilgilendirme formu + mesafeli hizmet sözleşmesi + iptal/iade koşullarını onaylamadan kartla ödeyemez; onay faturayla birlikte kaydedilir.
- **Onay kayıtları:** kim, hangi metni, hangi sürümü, ne zaman, hangi IP ve tarayıcıyla onayladı veya geri aldı; onaylanan metnin SHA-256 özetiyle. CSV olarak indirilebilir (uyuşmazlıkta delil).
- Kullanıcılar profil sayfasında onayladıkları metinleri görür, açık rızayı verir veya geri alır; bülten tercihi değiştiğinde ileti izni kaydı da tutulur.
- **Çerez onayı:** ilk ziyarette çerez penceresi açılır ("Tümünü kabul et" / "Yalnızca zorunlu" / "Tercihleri yönet"); seçim 180 gün hatırlanır ve tarih, IP ve tarayıcıyla kaydedilir. Sayfaların altındaki "Çerez tercihleri" bağlantısıyla değiştirilebilir.
- Metinler genel bir şablondur; yayına almadan önce bir avukata kontrol ettirmeniz önerilir.

## 9.2 Alan adı değişikliği

Sistemin adresi `config/db.php` içindeki `BASE_URL` satırıdır. Adres değiştirildiğinde eski alt alan adına gelen sayfa istekleri aynı yolla yeni adrese kalıcı (301) yönlendirilir; ayarlarda, yasal metinlerde ve e-posta kampanyalarında kayıtlı eski tam adresler bir kez otomatik güncellenir. Ödeme (iyzico) dönüşleri ve arka plan istekleri hangi adresten gelirse orada işlenir. Eski alt alan adı silinmemeli; yönlendirme için aynı klasörü göstermeye devam etmelidir.

## 10. Yetkiler

| İzin | Ne açar? |
|------|----------|
| `projects.view` / `projects.edit` | Projeler, takvim, görev panosu / proje üzerinde değişiklik |
| `contacts.*` | Cariler |
| `finance.view`, `finance.invoices` | Kasa ve banka, faturalar |
| `reports.view` | Yönetim raporları (ilk kurulumda finans yetkisi olan rollere otomatik verilir) |
| `proposals.manage`, `inventory.manage` | Teklifler, ekipman |
| `personnel.manage`, `settings.manage` | Personel, ayarlar ve roller |
| `platform.manage` | İş merkezi: işler, atama, teklifler, kalite kontrol, freelancer/ajans yönetimi |
| `platform.pricing` | Hizmet kataloğu ve fiyatlar |
| `platform.delete` | Platform kayıtlarını kalıcı silme (iş, ajans, freelancer, katalog kalemi) |
| `mail.manage` | E-posta merkezi: aboneler, toplu gönderim, gönderim kayıtları |

Hazır **Platform Yöneticisi** rolü üç platform iznini birlikte içerir; Roller sayfasından kişiye atanır. Ayrıntılar: [PLATFORM.md](PLATFORM.md).

Kurgucular kendilerine atanan görevleri, projeyi düzenleme yetkileri olmasa bile tamamlandı olarak işaretleyebilir.
