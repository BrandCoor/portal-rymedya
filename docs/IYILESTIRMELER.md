# RY Medya Portal: İnceleme Raporu ve Yapılan Düzeltmeler

Bu belgede `portal.rymedya.com.tr` üzerinde çalışan CRM/ERP portalının kod incelemesinde bulunan hatalar, eksikler ve bunlar için yapılan değişiklikler listeleniyor. En kritik maddeler en üstte.

---

## 1. Kritik güvenlik açıkları (düzeltildi)

| # | Sorun | Etki | Çözüm |
|---|-------|------|-------|
| 1 | **`sifre_sifirla.php` herkese açıktı.** Tarayıcıdan açan herkes `admin@ajansadresi.com` hesabının şifresini `Admin123!` yapabiliyordu ve şifre ekrana basılıyordu. | Yönetici hesabının tam olarak ele geçirilmesi | Dosya silindi. **Sunucudan da silinmelidir.** |
| 2 | **`modules/contacts/detail.php` sayfasında giriş kontrolü yoktu.** POST işlemleri, giriş ve yetki kontrolünden önce çalışıyordu. | Giriş yapmamış biri carileri düzenleyebilir, faturaları ve carileri silebilirdi. "Portal girişi oluştur" işlemiyle **herhangi bir kullanıcının (yönetici dahil) şifresi değiştirilebiliyordu.** | Sayfanın başına giriş ve `contacts.view` kontrolü eklendi. Her işlem kendi iznini (`contacts.edit`, `finance.invoices` vb.) istiyor. Personel hesapları ve başka cariye ait hesaplar artık portal hesabına çevrilemiyor. |
| 3 | Müşteri portalı hesapları yönetim paneline, personel hesapları da müşteri portalına giriş yapabiliyordu. Cariye bağlı olmayan bir hesap portala girince sonsuz yönlendirme döngüsü oluşuyordu. | Yetki karışıklığı | Giriş ekranları hesap türünü kontrol ediyor (`is_portal_account`). |
| 4 | Pasife alınan kullanıcı ve değiştirilen rol izinleri, kullanıcı çıkış yapana kadar geçerli kalıyordu. | Kovulan personel erişimini sürdürebiliyordu | Kullanıcı durumu ve izinleri her istekte veritabanından yenileniyor (`refresh_staff_session`). |
| 5 | Giriş ekranlarında deneme sınırı yoktu. | Kaba kuvvet (brute force) ile şifre deneme | 15 dakikada 5 hatalı denemeden sonra IP/e-posta kilitleniyor (`login_attempts` tablosu otomatik oluşuyor). |
| 6 | Yazdırma sayfalarında yetki kontrolü eksikti. Her personel bordro ve fatura görebiliyordu, müşteriler ID değiştirerek **diğer müşterilerin tekliflerini** görebiliyordu. Müşteri portalındaki "Fatura PDF" bağlantısı ise müşteriye "Yetkisiz erişim" gösteriyordu. | Veri sızıntısı ve çalışmayan özellik | Fatura, ekstre, teklif, call sheet ve bordro çıktıları izne ve sahipliğe göre açılıyor. Müşteri yalnızca kendi satış faturalarını ve taslak olmayan tekliflerini görüyor. |
| 7 | Proje detayı, teklifler ve envanter sayfalarında izin kontrolü yoktu. Proje detayında başka projenin çekim ve gider ID'leri değiştirilebiliyordu. | Yetkisiz düzenleme | `projects.view` ve `projects.edit` kontrolleri eklendi. Çekim ve gider kayıtları projeye ait mi diye kontrol ediliyor. `proposals.manage` ve `inventory.manage` izinleri otomatik oluşturuluyor ve mevcut rollere atanıyor, böylece mevcut kullanım bozulmuyor. |
| 8 | Kullanıcı yönetiminde yönetici kendi hesabını pasife alabiliyor veya rolünü düşürebiliyordu. Şifre uzunluğu kontrol edilmiyordu. | Sisteme erişimin tamamen kaybedilmesi | Kendi hesabını ve ana yönetici hesabını kilitleme engellendi. En az 8 karakterlik şifre zorunlu. |
| 9 | Müşteri çıkış yapınca aynı tarayıcıdaki yönetici oturumu da kapanıyordu (ve tersi). | Kullanım hatası | Çıkışlar yalnızca ilgili oturumu kapatıyor. |
| 10 | `config/` ve `includes/` klasörleri doğrudan erişime açıktı. | Bilgi sızıntısı riski | Bu klasörlere `.htaccess` ile erişim engellendi. |

## 2. Finans ve veri bütünlüğü hataları (düzeltildi)

| # | Sorun | Çözüm |
|---|-------|-------|
| 11 | **Ondalıklı tutarlar 100 katına çıkıyordu.** Formlardaki sayı alanları `1500.50` gönderiyor, kod ise noktayı binlik ayracı sanıp silerek **150050** kaydediyordu. Bütçe, fatura, tahsilat, maaş ve avans dahil 26 noktada geçerliydi. | `parse_money()` hem `1500.50` hem `1.500,50` biçimini doğru okuyor. **Canlıdaki küsuratlı eski kayıtlar kontrol edilmeli** (bkz. Bölüm 5). |
| 12 | Ekipman kiralamada fatura sorgusu bozuktu (13 kolona 11 değer, müşteri `NULL`), bu yüzden kiralama faturası hiç oluşmuyordu. | Sorgu düzeltildi, fatura müşteriye kesiliyor. |
| 13 | Faturaya fazla tahsilat girilebiliyordu. Kasadan bir tahsilat silinince fatura "Ödendi" olarak kalıyordu. | Ödemeler kalan bakiyeyle sınırlandı. Fatura durumu her işlemde hareketlerden yeniden hesaplanıyor (`sync_invoice_payment`). |
| 14 | Proje veya cari silinince faturalar, tahsilatlar ve kasa bakiyeleri tutarsız kalıyordu. Proje detayından silmede faturalar hiç silinmiyordu. | Zincirleme silme fonksiyonları eklendi (`delete_project_cascade`, `delete_contact_cascade`). Cari ve kasa bakiyeleri yeniden hesaplanıyor. |
| 15 | Proje, fatura ve teklif numaraları "kayıt sayısı + 1" ile üretiliyordu. Bir kayıt silinince **aynı numara tekrar üretiliyordu** (teklifte UNIQUE hatasıyla çöküyordu). Müşteri onayıyla oluşan fatura da `RYM-yıl-projeID` biçimindeydi ve diğer numaralarla çakışabiliyordu. | Numaralar en büyük numaradan devam ediyor ve çakışma kontrolü yapılıyor. Ayarlardaki **proje öneki** artık kullanılıyor. Aynı numaralı ikinci fatura engelleniyor. |
| 16 | Kurgu/edit bedeli aynı versiyona birden fazla kez faturalanabiliyordu. Kurgu faturası kesilen projede ana proje faturası kesilemiyordu ("Faturalandırıldı" görünüyordu). | Mükerrer faturalama engellendi. Ana proje faturası, edit faturalarından ayrı değerlendiriliyor. |
| 17 | Bir set giderine otomatik alış faturası kesilince "Projeyi Tamamla & Faturalandır" butonu kayboluyordu, çünkü alış faturası da satış faturası sayılıyordu. | Yalnızca ana satış faturası dikkate alınıyor. |
| 18 | "Projeyi Tamamla" modalında KDV oranı ve tevkifat seçilemiyordu. Vade ve not alanı yoktu. Matrah formdan geldiği için değiştirilebiliyordu. | KDV, tevkifat, vade ve not alanları eklendi. Matrah sunucuda yeniden hesaplanıyor. |
| 19 | **Personel avansı kasadan düşülmüyordu.** Maaş "maaş − avans" olarak ödendiği için kasa, avans tutarı kadar fazla görünüyordu. | Avans verilirken kasa veya banka seçiliyor ve çıkış kaydı oluşuyor. Avans silinince bu kayıt da siliniyor. |
| 20 | Bordro silme ve düzenleme, kasa hareketini `LIKE '%Bordro #1%'` ile arıyordu. 1 numaralı bordro silinince **#10, #11 vb. bordroların kasa kaydı da silinebiliyordu.** Aynı aya iki kez maaş ödenebiliyordu. | Birebir eşleşme yapılıyor. Mükerrer maaş ödemesi engellendi. |
| 21 | Fatura düzenlenince cari değiştiyse eski carinin bakiyesi güncellenmiyordu. Cari ekranından düzenlenen faturanın tevkifatı sessizce sıfırlanıyordu. | Her iki cari de yeniden hesaplanıyor. Tevkifat ve stopaj korunuyor. |
| 22 | Müşteri portalında onaylanmış bir kurgu tekrar onaylanabiliyordu. Bakiye `balance + x` ile güncellendiği için kayabiliyordu. | Onay tek sefer yapılabiliyor ve bakiye hareketlerden hesaplanıyor. |
| 23 | **`contact_change_logs` tablosu yalnızca müşteri profil sayfasında oluşturuluyordu.** Hiçbir müşteri profilini açmadıysa cari detay sayfası 500 hatası veriyordu. | Tablo gereken her yerde otomatik oluşturuluyor. |
| 24 | `$user` değişkeni header'dan önce çalışan işlemlerde tanımsızdı. Bu yüzden kasa hareketlerinde "İşlemi Yapan" ve avanslarda "Onaylayan" hep boş kalıyordu. | Kullanıcı bilgisi her yerde tanımlı. |
| 25 | Header'dan sonra çalışan formlar (faturalar, proje listesi) sayfa 4 KB'yi geçince "headers already sent" hatası veriyor ve yönlendirme bozuluyordu. | `header.php` içinde çıktı tamponlama açıldı. |

## 3. Eksik özellikler (eklendi)

- **Proje detayı:** Çekim günü düzenleme ve silme (başlangıç/bitiş saati dahil). Daha önce butonlar yoktu. Set gideri düzenleme modalı eklendi. Daha önce "Düzenle" butonu hiçbir şey yapmıyordu. Faturalar sekmesine yazdırma, iptal ve ödeme durumu eklendi.
- **Teklifler:** Teklif düzenleme. Daha önce yalnızca oluşturma ve silme vardı.
- **Müşteri portalı:** Müşteriye iletilen teklifler listeleniyor ve PDF olarak indirilebiliyor.
- **Kasa ve banka:** Hesaplar arası virman, hesap düzenleme ve pasife alma, hesap filtresi ve 30/100/250/500 kayıt seçimi.
- **Faturalar:** Arama, tarih aralığı ve ödeme durumu filtreleri ("Vadesi Geçmiş" dahil). Kısmi ödeme ve vade geçti etiketleri.
- **Cari detay:** Müşteri portalı erişimini kapatma ve açma. Portal giriş adresi gösteriliyor.
- **Kontrol paneli:** Muhasebe gibi özel rollerde ekran boş geliyordu. Artık yetkiye göre uygun görünüm açılıyor. Grafik ve tarihlerde Türkçe ay adları kullanılıyor. Ayın 31'inde aynı ayın iki kez görünmesi hatası düzeltildi.
- **Vergi motoru:** Gelir vergisi dilimleri yanlış ve karışıktı (158.000 / 380.000 / 900.000 / 4.300.000). Yıla göre resmi tarifeler eklendi: 2025 için 158.000 / 330.000 / 800.000 / 4.300.000, 2026 için 190.000 / 400.000 / 1.000.000 / 5.300.000 TL. Hesap artık yıllık kümülatif kâr üzerinden yapılıyor; önceden yıllık dilimler aylık kâra uygulanıyordu. Kurumlar vergisi oranı ayarlardan okunuyor. `modules/finance/index.php` aynı sayfanın eski bir kopyasıydı ve artık vergi paneline yönlendiriyor.
- **Ayarların kullanılması:** Fatura, ekstre ve call sheet çıktılarında sabit yazılmış "RY MEDYA" ve "+90 555 000 00 00" bilgileri yerine ayarlardaki şirket adı, telefon, IBAN ve call sheet notu kullanılıyor.
- `config/db.example.php` eklendi. Güvenli oturum çerezi ayarlarını içeren örnek yapılandırma.

## 4. Arayüz hataları (düzeltildi)

- Not, açıklama ve başlıklarda **satır sonu veya özel karakter** varsa düzenleme butonları JavaScript hatası verip çalışmıyordu (`addslashes` kullanımı). 32 yerde güvenli `js_val()` kullanılıyor.
- Kurgu versiyonu eklerken durum alanı gönderilmiyordu, bu yüzden kayıt `NULL` oluyordu. Varsayılan değer artık "Kurgu Sürüyor".
- Durum, kategori ve para birimi alanları sunucu tarafında doğrulanıyor.

## 5. Canlıya alma sırasında yapılması gerekenler

1. **`sifre_sifirla.php` dosyasını sunucudan silin.** `git pull` ile güncelliyorsanız otomatik silinir. FTP ile yüklüyorsanız elle silin.
2. Yönetici şifresi `Admin123!` ise **hemen değiştirin.** Dosya herkese açık olduğu için şifre bilinmiş olabilir.
3. `config/db.php` dosyanıza `config/db.example.php` içindeki güvenli oturum çerezi ayarlarını (`httponly`, `secure`, `samesite`) eklemeniz önerilir.
4. **Küsuratlı eski tutarları kontrol edin:** Ondalıklı girilmiş bütçe, fatura ve tahsilat tutarları 100 katı kaydedilmiş olabilir. phpMyAdmin'de örnek sorgu:
   ```sql
   SELECT id, invoice_number, subtotal, grand_total FROM invoices ORDER BY subtotal DESC LIMIT 50;
   SELECT id, project_code, agreed_budget FROM projects ORDER BY agreed_budget DESC LIMIT 50;
   ```
5. Bu güncellemeden önce verilmiş avanslar için kasa çıkışı oluşmamıştı. Kasa bakiyesi gerçek tutardan fazla görünüyorsa, "Kasa & Banka > Manuel Hareket Girişi" ile düzeltme kaydı girilebilir.
6. Teklifler ve Envanter için yeni izinler (`proposals.manage`, `inventory.manage`) ilk açılışta otomatik oluşur ve mevcut tüm rollere verilir. İsterseniz "Roller & Yetkilendirme" ekranından kısıtlayabilirsiniz.

## 6. Önerilen sonraki adımlar (bu sürümde yapılmadı)

- **Veritabanı şeması depoda yok.** phpMyAdmin'den yapı (structure) dışa aktarımı alınıp `database/schema.sql` olarak eklenmeli. Böylece yedekten veya sıfırdan kurulum yapılabilir.
- Tailwind, Alpine ve Lucide CDN üzerinden `@latest` sürümüyle yükleniyor. Bir CDN güncellemesi arayüzü bozabilir. Sürümler sabitlenmeli ve tercihen dosyalar sunucuya alınmalı. `cdn.tailwindcss.com` üretim kullanımı için önerilmiyor.
- Para hareketlerinde veritabanı transaction'ları (`beginTransaction` / `commit`) kullanılmalı.
- Döviz faturaları TL toplamlarına kur dönüşümü yapılmadan ekleniyor. Kur alanı eklenmeli.
- Şifremi unuttum (e-posta ile güvenli sıfırlama) ve bildirim e-postaları (fatura, revizyon talebi) eklenebilir.
- Uzun listelerde (cariler, projeler, faturalar) sayfalama eklenmeli.
- İşlem günlüğü (audit log) yalnızca müşteri profilinde var. Personel işlemleri için de tutulmalı.
