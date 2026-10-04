<?php
/**
 * ====================================================================
 * YASAL METİNLERİN VARSAYILAN İÇERİKLERİ
 * ====================================================================
 * Yönetici "Yasal metinler" ekranından bir metni düzenleyip kaydedene kadar
 * burada yazanlar gösterilir. {{...}} yer tutucuları Ayarlar → Şirket ve künye
 * bilgileriyle doldurulur (bkz. legal_placeholders()).
 *
 * Biçim: "## " başlık, "### " alt başlık, "- " madde, boş satır paragraf,
 * **kalın**. Düz metin; HTML yazılmaz.
 */

const LEGAL_DEFAULT_TEXTS = [

/* ================================================================== */
'kullanim-kosullari' => <<<'TXT'
Bu Kullanım Koşulları ve Üyelik Sözleşmesi ("Sözleşme"), {{site}} adresinde ve bağlı alt sayfalarında sunulan prodüksiyon platformunun ("Platform") kullanımına ilişkin şartları düzenler. Platform'a üye olan, giriş yapan veya Platform'u herhangi bir şekilde kullanan her gerçek ya da tüzel kişi ("Kullanıcı") bu Sözleşme'yi okuduğunu, anladığını ve tüm hükümleriyle bağlı olduğunu kabul eder.

## 1. Taraflar

**Hizmet sağlayıcı:** {{unvan}} ("{{marka}}" veya "Şirket")
Adres: {{adres}}
Vergi dairesi / no: {{vergi_dairesi}} / {{vergi_no}}
MERSİS / Ticaret sicil no: {{mersis}}
E-posta: {{eposta}} · Telefon: {{telefon}} · KEP: {{kep}}

**Kullanıcı:** Platform'a ajans, freelancer veya müşteri sıfatıyla kayıt olan ya da Şirket tarafından hesap tanımlanan kişi.

## 2. Tanımlar

- **Ajans:** Platform üzerinden Şirket'e prodüksiyon, kurgu, ses, renk düzenleme ve benzeri hizmet siparişi veren işletme.
- **Freelancer:** Şirket'in, Ajans'a karşı üstlendiği işlerin tamamını veya bir bölümünü Şirket adına ve hesabına ifa eden bağımsız hizmet sağlayıcı.
- **Müşteri:** Şirket'in doğrudan hizmet verdiği ve proje takibi için portal hesabı tanımlanan kişi veya işletme.
- **İş:** Platform'da kayda alınan, kapsamı, bedeli, süresi ve aşamaları iş kaydında belirtilen hizmet.
- **Aşama:** Bir İş'in ayrı ayrı teslim ve onay konusu olan bölümü.
- **İş kaydı:** Platform'da her İş için tutulan; kapsam, fiyat, tarih, teslim, revizyon, mesaj ve onay bilgilerini içeren elektronik kayıt.
- **Platform kuralları:** Ücretsiz revizyon hakkı, ek revizyon ve ham görüntü ücretleri, acil iş ücreti, otomatik onay süresi, ödeme ve hakediş süreleri gibi Platform'da ilan edilen ve Şirket tarafından güncellenebilen işleyiş kuralları.

## 3. Sözleşmenin konusu ve ek sözleşmeler

Bu Sözleşme tüm Kullanıcılar için ortak hükümleri içerir. Ajanslar için **Ajans Hizmet Sözleşmesi**, Freelancer'lar için **Freelancer Hizmet Sağlayıcı Sözleşmesi**, kartla ödemelerde **Ön Bilgilendirme Formu ve Mesafeli Hizmet Sözleşmesi** ile **İptal, İade ve Ödeme Koşulları**, kişisel verilerin işlenmesinde **KVKK Aydınlatma Metni**, **Gizlilik ve Güvenlik Politikası** ve **Çerez Politikası** bu Sözleşme'nin ayrılmaz parçasıdır. Özel sözleşme ile genel hüküm çeliştiğinde özel sözleşme; iş kaydında yazılı olarak kararlaştırılan özel şart ile her ikisi çeliştiğinde iş kaydı uygulanır.

## 4. Platform'un işleyişi

4.1. Ajans İş'i Şirket'e verir; Şirket İş'i kendi ekibiyle veya Freelancer'lar aracılığıyla yerine getirir. Ajans'ın muhatabı ve sözleşme tarafı yalnızca Şirket'tir. Freelancer'ın muhatabı ve sözleşme tarafı da yalnızca Şirket'tir.

4.2. Ajans ile Freelancer arasında doğrudan bir sözleşme ilişkisi kurulmaz. Taraflar birbirlerinin kimlik ve iletişim bilgilerini görmez; Platform bu ayrımı korumak için tasarlanmıştır. Kullanıcılar, bu ayrımı aşmaya yönelik girişimlerde bulunmayacaklarını kabul eder (bkz. madde 8).

4.3. Şirket, gelen İş'leri kendi belirlediği kurallara göre değerlendirme, onaylama, reddetme, fiyatını ve kapsamını yeniden teklif etme, uygun gördüğü kişiye atama ve atamayı değiştirme hakkına sahiptir.

## 5. Üyelik ve hesap güvenliği

5.1. Üyelik başvurusu Şirket'in onayına tabidir. Şirket gerekçe göstermeksizin başvuruyu reddedebilir veya ek bilgi ve belge isteyebilir.

5.2. Kullanıcı, kayıt sırasında ve sonrasında verdiği tüm bilgilerin (kimlik, unvan, vergi, iletişim, IBAN vb.) doğru, güncel ve kendisine ait olduğunu beyan eder. Yanlış veya yanıltıcı bilgiden doğan her türlü zarardan Kullanıcı sorumludur.

5.3. Kullanıcı adı, şifre, iki adımlı doğrulama kodları ve kurtarma kodlarının gizliliği Kullanıcı'nın sorumluluğundadır. Hesap üzerinden yapılan her işlem Kullanıcı tarafından yapılmış sayılır. Yetkisiz kullanım şüphesinde Kullanıcı derhal Şirket'e bildirimde bulunmakla yükümlüdür.

5.4. Hesap kişiye özeldir; devredilemez, kiralanamaz, başkasıyla paylaşılamaz.

5.5. Ajans hesabını kullanan kişi, temsil ettiği işletme adına işlem yapmaya yetkili olduğunu beyan eder; bu kişinin hesabı üzerinden yapılan tüm işlemler işletmeyi bağlar.

## 6. Kullanıcının genel yükümlülükleri

Kullanıcı;

- Platform'u yürürlükteki mevzuata, bu Sözleşme'ye ve genel ahlaka uygun şekilde kullanacağını,
- Platform'a yüklediği her türlü içerik (görüntü, ses, müzik, logo, metin, marka, kişi görüntüsü vb.) üzerinde gerekli tüm hak, lisans ve izinlere sahip olduğunu; üçüncü kişilerin fikri mülkiyet, kişilik ve özel hayat haklarını ihlal etmeyeceğini,
- Platform'un altyapısına zarar verecek, aşırı yük getirecek, güvenlik önlemlerini aşmaya çalışacak, otomatik araçlarla veri toplayacak (bot, scraper vb.) davranışlarda bulunmayacağını,
- Zararlı yazılım, yasa dışı, müstehcen, nefret söylemi içeren veya suç teşkil eden içerik yüklemeyeceğini,
- Platform'u Şirket'le rekabet eden bir faaliyet için kullanmayacağını,
- Diğer Kullanıcılara ve Şirket çalışanlarına karşı saygılı davranacağını

kabul ve taahhüt eder. Bu yükümlülüklerin ihlalinden doğan tüm talep, ceza ve zararlardan Kullanıcı sorumludur ve Şirket'i bu taleplerden ari tutar; Şirket'in ödemek zorunda kaldığı her tutarı, yargılama gideri ve avukatlık ücreti dahil, ilk talepte öder.

## 7. Şirket'in hakları

7.1. Şirket; Platform'un tasarımını, işleyişini, özelliklerini, Platform kurallarını ve fiyatlandırmayı dilediği zaman değiştirebilir. Değişiklikler, Platform'da yayımlandığı andan itibaren yeni işlemlere uygulanır; devam eden İş'lerde iş kaydındaki şartlar geçerliliğini korur.

7.2. Şirket, bu Sözleşme'ye veya ek sözleşmelere aykırılık, ödeme yükümlülüğünün yerine getirilmemesi, sahte veya yanıltıcı bilgi, güvenlik riski, şikayet, düşük performans ya da mevzuattan doğan bir zorunluluk halinde Kullanıcı hesabını önceden bildirimde bulunmaksızın askıya alabilir, kısıtlayabilir veya kapatabilir. Bu durumda Kullanıcı'nın Şirket'e olan muaccel borçları derhal ödenir.

7.3. Şirket, Platform'da yer alan içerikleri ve mesajları; hizmet kalitesi, uyuşmazlık çözümü, güvenlik ve yasal yükümlülükler amacıyla inceleyebilir ve saklayabilir.

## 8. Doğrudan iletişim ve iş ilişkisi yasağı

8.1. Ajans ve Freelancer, Platform üzerinden tanıştıkları veya bir İş vesilesiyle kimliğini öğrendikleri diğer tarafla; üyelik süresince ve üyeliğin herhangi bir nedenle sona ermesinden itibaren **24 (yirmi dört) ay** boyunca, Şirket'i devre dışı bırakarak doğrudan veya dolaylı olarak (aracı, yakın, başka bir şirket üzerinden dahil) iş ilişkisi kuramaz, teklif veremez, teklif kabul edemez.

8.2. Bu yasağın ihlali halinde ihlal eden taraf, Şirket'e, ihlal konusu işin bedelinden ve Şirket'in uğradığı zarardan bağımsız olarak, ihlale konu taraflar arasında Platform üzerinden son 12 ayda gerçekleşen işlerin toplam bedelinin **3 (üç) katı**, bu tutar 100.000 TL'den az ise **100.000 TL** tutarında cezai şart ödemeyi kabul eder. Cezai şartın ödenmesi, yasağa uyma yükümlülüğünü ortadan kaldırmaz; Şirket'in aşan zararını talep hakkı saklıdır.

## 9. Fikri mülkiyet

9.1. Platform'un yazılımı, tasarımı, arayüzü, veritabanı, metinleri, logoları ve markası Şirket'e aittir veya Şirket tarafından lisanslı olarak kullanılmaktadır. Kullanıcı'ya yalnızca Platform'u bu Sözleşme'ye uygun olarak kullanmak için sınırlı, devredilemez, münhasır olmayan ve geri alınabilir bir kullanım izni verilir.

9.2. Platform'un herhangi bir bölümü kopyalanamaz, çoğaltılamaz, tersine mühendislik yapılamaz, türev çalışmaya konu edilemez.

9.3. İş kapsamında üretilen eserlere ilişkin haklar ilgili ek sözleşmelerde düzenlenmiştir.

## 10. Sorumluluğun sınırlandırılması

10.1. Platform "olduğu gibi" ve "mevcut haliyle" sunulur. Şirket, Platform'un kesintisiz, hatasız veya her an erişilebilir olacağını taahhüt etmez. Bakım, güncelleme, altyapı sağlayıcı arızası, siber saldırı, internet kesintisi ve benzeri durumlarda yaşanan aksaklıklardan Şirket sorumlu tutulamaz.

10.2. Şirket; dolaylı zararlardan, kâr kaybından, itibar kaybından, veri kaybından, iş kaybından, üçüncü kişilerin taleplerinden ve yayın / kampanya tarihinin kaçırılmasından doğan zararlardan, kast veya ağır ihmali bulunmadıkça sorumlu değildir.

10.3. Şirket'in herhangi bir İş'ten veya Platform kullanımından doğan toplam sorumluluğu, her durumda, zarara konu İş için Kullanıcı'nın Şirket'e fiilen ödediği net bedel ile sınırlıdır. Bu sınırlama Türk Borçlar Kanunu'nun 115. maddesinin emredici hükümleri saklı kalmak kaydıyla uygulanır.

10.4. Kullanıcıların Platform'a yüklediği içeriklerden ve beyanlardan ilgili Kullanıcı sorumludur. Şirket bu içerikleri kontrol etmekle yükümlü değildir.

10.5. Kullanıcı, Platform'a yüklediği dosyaların kendi nezdinde yedeğini tutmakla yükümlüdür. Şirket, teslim veya proje dosyalarını iş kaydında ve Platform kurallarında belirtilen süre boyunca saklar; bu süreden sonra silinmesinden sorumlu değildir.

## 11. Mücbir sebep

Doğal afet, salgın, yangın, sel, savaş, terör, grev, lokavt, genel elektrik veya iletişim kesintisi, siber saldırı, hükümet ve resmi makam kararları, ekipman ve ulaşım engelleri, olumsuz hava koşulları (özellikle dış çekimlerde) ve tarafların kontrolü dışındaki benzeri durumlar mücbir sebep sayılır. Mücbir sebep süresince tarafların yükümlülükleri askıya alınır; bu nedenle doğan gecikmelerden Şirket sorumlu değildir. Şirket mücbir sebep halinde İş'i yeniden planlama hakkına sahiptir.

## 12. Elektronik kayıtlar, bildirim ve delil sözleşmesi

12.1. Taraflar; Platform'daki iş kayıtları, aşama ve onay kayıtları, mesajlar, teslim kayıtları, sistem ve giriş kayıtları (IP adresi, tarih-saat dahil), sözleşme onay kayıtları ile Şirket'in ticari defter ve e-posta kayıtlarının, Hukuk Muhakemeleri Kanunu'nun 193. maddesi uyarınca **kesin delil** teşkil edeceğini kabul eder.

12.2. Platform'daki "onayla", "kabul et", "gönder", "yapıldı" gibi düğmelere basılarak yapılan işlemler, ilgili Kullanıcı'nın yazılı iradesi hükmündedir.

12.3. Kullanıcı'nın Platform'a kayıtlı e-posta adresine yapılan bildirimler ile Platform içi bildirimler, gönderildiği anda Kullanıcı'ya ulaşmış sayılır. Kullanıcı iletişim bilgilerini güncel tutmakla yükümlüdür. Türk Ticaret Kanunu'nun 18/3. maddesinde sayılan ihbarlar için noter, KEP veya iadeli taahhütlü mektup kullanılır.

## 13. Sözleşmenin süresi ve sona ermesi

13.1. Bu Sözleşme, Kullanıcı'nın onayıyla yürürlüğe girer ve hesap kapatılana kadar devam eder.

13.2. Kullanıcı, devam eden İş'i ve ödenmemiş borcu bulunmamak kaydıyla, Şirket'e yazılı bildirimde bulunarak hesabının kapatılmasını isteyebilir.

13.3. Sözleşmenin sona ermesi, sona erme tarihinden önce doğmuş ödeme yükümlülüklerini, gizlilik, fikri mülkiyet, doğrudan iş ilişkisi yasağı, sorumluluk ve cezai şart hükümlerini ortadan kaldırmaz; bu hükümler sona ermeden sonra da yürürlükte kalır.

## 14. Sözleşme değişiklikleri

Şirket bu Sözleşme'yi ve ek sözleşmeleri güncelleyebilir. Güncel metin Platform'da yayımlanır; önemli değişikliklerde Kullanıcı'dan Platform'a ilk girişinde yeni metni onaylaması istenir. Kullanıcı'nın yeni metni onaylaması veya yayım tarihinden sonra Platform'u kullanmaya devam etmesi, değişikliklerin kabulü anlamına gelir. Kabul etmeyen Kullanıcı hesabını kapatabilir.

## 15. Devir

Kullanıcı bu Sözleşme'den doğan hak ve yükümlülüklerini Şirket'in yazılı onayı olmadan üçüncü kişilere devredemez. Şirket, Sözleşme'yi ve Platform'u bağlı şirketlerine veya işi devralan üçüncü kişilere devredebilir.

## 16. Bölünebilirlik ve feragat

Sözleşme'nin herhangi bir hükmünün geçersiz sayılması, diğer hükümlerin geçerliliğini etkilemez; geçersiz hüküm, amacına en yakın geçerli hükümle değiştirilmiş sayılır. Şirket'in bir hakkını kullanmaması veya geç kullanması, o haktan feragat ettiği anlamına gelmez.

## 17. Uygulanacak hukuk ve yetkili mahkeme

Bu Sözleşme Türk hukukuna tabidir. Sözleşme'den doğan uyuşmazlıklarda **{{yetkili_mahkeme}} Mahkemeleri ve İcra Daireleri** yetkilidir. Kullanıcı'nın 6502 sayılı Kanun kapsamında tüketici sayıldığı hallerde, Kanun'un tüketici hakem heyetleri ve tüketici mahkemelerine ilişkin hükümleri saklıdır.

## 18. Yürürlük

Bu Sözleşme 18 maddeden oluşur ve Kullanıcı tarafından elektronik ortamda onaylandığı anda yürürlüğe girer. Kullanıcı'nın onay tarihi, saati, IP adresi ve onayladığı metin sürümü Şirket tarafından kayıt altına alınır.
TXT,

/* ================================================================== */
'ajans-sozlesmesi' => <<<'TXT'
Bu Ajans Hizmet Sözleşmesi ("Sözleşme"), {{unvan}} ("Şirket") ile Platform'a ajans olarak kayıt olan işletme ("Ajans") arasında, Ajans'ın Platform üzerinden Şirket'e verdiği işlerin şartlarını düzenler. Bu Sözleşme, Kullanım Koşulları ve Üyelik Sözleşmesi'nin eki ve ayrılmaz parçasıdır. Ajans'ın tacir olduğu ve Sözleşme'nin ticari iş niteliğinde olduğu kabul edilir.

## 1. Sözleşmenin konusu

Şirket, Ajans'ın Platform üzerinden ilettiği ve Şirket tarafından kabul edilen prodüksiyon, çekim, kurgu, ses, renk düzenleme, grafik ve benzeri hizmetleri ("İş"), iş kaydında belirtilen kapsam, süre ve bedel karşılığında yerine getirir.

## 2. İş'in kurulması

2.1. Ajans'ın Platform'da İş girmesi bir tekliftir. İş, Şirket'in kabulüyle (İş'in "aktif" veya "atama bekliyor" durumuna geçmesiyle ya da Şirket'in onay bildirimiyle) kurulur. Şirket İş'i gerekçe göstermeden reddedebilir, kapsam ve fiyat için yeni teklif sunabilir.

2.2. İş'in kapsamı, aşamaları, teslim türü, fiyatı, tarihleri ve özel şartları iş kaydında yer alır. İş kaydında yazmayan her talep kapsam dışıdır ve ek ücrete tabidir.

2.3. Ajans, brif, senaryo, metin, logo, müzik, marka, ürün, mekan, oyuncu ve benzeri tüm girdileri eksiksiz ve zamanında sağlamakla yükümlüdür. Girdilerin eksik, hatalı veya geç sağlanmasından doğan gecikme ve ek maliyetler Ajans'a aittir.

## 3. İşin yürütülmesi

3.1. Şirket İş'i kendi personeliyle veya kendi seçtiği alt yükleniciler (Freelancer'lar) aracılığıyla yerine getirir. Kimin görevlendirileceğine ve görevlendirmenin değiştirilmesine Şirket karar verir. Ajans'ın muhatabı her durumda Şirket'tir.

3.2. Ajans, İş'te görev alan kişilerin kimliğini öğrenmeye çalışmayacağını, öğrenmesi halinde bu kişilerle Şirket'i devre dışı bırakarak iş ilişkisi kurmayacağını kabul eder. Bu yükümlülüğün ihlalinde Kullanım Koşulları madde 8'deki cezai şart uygulanır.

3.3. Yerinde yapılan işlerde (çekim vb.) Ajans; mekan izinleri, çekim izinleri, belediye ve kamu kurumu izinleri, oyuncu ve kişi görüntüsü izinleri (muvafakatname), mekan güvenliği ve iş sağlığı güvenliği koşullarının sağlanmasından sorumludur. İzin eksikliği nedeniyle çekimin yapılamaması Ajans'tan kaynaklı iptal sayılır.

3.4. Yerinde yapılan işlerde mekanın ve ekipmanın zarar görmesi halinde, zarar Şirket ekibinin kastı veya ağır ihmalinden kaynaklanmadıkça sorumluluk Ajans'a aittir. Ekibin mesai süresini, iş kaydında belirtilen süreyi aşan bekleme ve uzamalar ek ücrete tabidir.

## 4. Teslim, onay ve otomatik onay

4.1. Teslim gerektiren aşamalarda Şirket teslimi Platform üzerinden yapar. Yerinde yapılan işler (çekim vb.) ayrıca dosya teslimi gerektirmez; iş "yapıldı" olarak işaretlendiğinde ilgili aşama ifa edilmiş sayılır. Ham görüntü teslimi İş kapsamında açıkça yer almadıkça Şirket'in ham görüntü teslim yükümlülüğü yoktur.

4.2. Ajans, teslimi Platform kurallarında ilan edilen süre içinde inceleyip onaylamak veya gerekçeli revizyon talep etmekle yükümlüdür. Bu süre içinde yanıt verilmezse teslim **kabul edilmiş ve onaylanmış sayılır**; bu durumda aşama bedeli muaccel olur ve ayıp iddiası ileri sürülemez (Türk Ticaret Kanunu md. 23 ve Türk Borçlar Kanunu md. 474 kapsamında gözden geçirme ve ihbar süresi bu şekilde belirlenmiştir).

4.3. Onaylanan teslim için sonradan revizyon, ayıp veya iade talebinde bulunulamaz.

4.4. Teslim edilen işin yayınlanması, kullanılması veya üçüncü kişilere gönderilmesi, onay anlamına gelir.

## 5. Revizyonlar

5.1. Her aşama için ücretsiz revizyon hakkı, iş kaydında veya Platform kurallarında belirtilen sayı kadardır. Ücretsiz hak dolduktan sonraki her revizyon talebi, Platform'da ilan edilen revizyon ücretine tabidir ve Ajans bu ücreti talep anında kabul etmiş sayılır.

5.2. Revizyon; teslim edilen işin kapsam dahilindeki düzeltmesidir. Brifin, senaryonun, konseptin, sürenin, formatın değiştirilmesi, yeni çekim veya yeni içerik eklenmesi revizyon değil yeni iş veya ek kalemdir ve ayrıca fiyatlandırılır.

5.3. Revizyon taleplerinin tek seferde, açık ve toplu iletilmesi gerekir. Parça parça iletilen talepler ayrı revizyon sayılabilir.

## 6. Ücret ve ödeme

6.1. İş bedeli iş kaydında yazan tutardır. Aksi belirtilmedikçe fiyatlara KDV dahil değildir; KDV ve yasal vergiler ayrıca faturalanır.

6.2. Acil işler, Platform kurallarında ilan edilen ek ücrete tabidir. Ek kalemler, ücretli revizyonlar, ham görüntü teslimi, bekleme ve uzama süreleri, yol, konaklama, ekipman kiralama ve iş kaydında yazmayan diğer masraflar ayrıca faturalanır.

6.3. Şirket faturasını aşama onayıyla, İş tamamlandığında veya iş kaydında belirtilen zamanda düzenler. Fatura bedeli, faturada belirtilen vade tarihinde; vade belirtilmemişse fatura tarihinden itibaren 7 (yedi) gün içinde ödenir.

6.4. Ödemeler Şirket'in Platform'da bildirdiği banka hesaplarına havale/EFT ile veya Platform üzerinden kredi/banka kartıyla yapılır. Havale/EFT ile yapılan ödemelerde Ajans, Platform'dan ödeme bildirimi göndermekle yükümlüdür; ödeme, tutar Şirket hesabına geçtiğinde yapılmış sayılır.

6.5. Ödemenin vadesinde yapılmaması halinde, ayrıca ihtara gerek kalmaksızın temerrüt oluşur ve vadeden itibaren 3095 sayılı Kanuni Faiz ve Temerrüt Faizine İlişkin Kanun'un 2/2. maddesi uyarınca **avans faizi** oranında temerrüt faizi işler. Şirket ayrıca, ödeme yapılana kadar devam eden ve yeni İş'leri durdurma, teslimleri bekletme, Ajans hesabını askıya alma ve tahsilat masraflarını (avukatlık ücreti dahil) Ajans'tan talep etme hakkına sahiptir.

6.6. Ajans, Şirket'e olan borcunu Şirket'ten olan herhangi bir alacağıyla takas edemez.

## 7. İptal ve erteleme

7.1. Ajans, kabul edilmiş bir İş'i iptal ederse, aşağıdaki iptal bedelleri uygulanır (ayrıca yapılmış masraflar ve tamamlanmış aşamaların tam bedeli ödenir):

- İş'e henüz kimse atanmamışsa: bedelsiz.
- İş atanmış ancak başlangıç tarihine 72 saatten fazla varsa: İş bedelinin %25'i.
- Başlangıç tarihine 72 saatten az kalmışsa: İş bedelinin %50'si.
- Başlangıç tarihine 24 saatten az kalmışsa veya İş başlamışsa: İş bedelinin tamamı.

7.2. Yerinde işin ertelenmesi en geç 48 saat önce bildirilmelidir. Daha geç bildirilen ertelemeler iptal hükmündedir. Şirket uygun gördüğü hallerde iptal bedelini kısmen veya tamamen yeni tarih için mahsup edebilir.

7.3. Şirket; Ajans'ın ödeme yükümlülüğünü ihlal etmesi, gerekli girdileri sağlamaması, İş'in hukuka veya genel ahlaka aykırı olduğunun anlaşılması, ekibin güvenliğini tehdit eden bir durumun ortaya çıkması veya mücbir sebep hallerinde İş'i durdurabilir veya iptal edebilir; bu durumda o ana kadar yapılan işin bedeli ve masraflar Ajans'tan talep edilir.

## 8. Fikri mülkiyet hakları

8.1. İş kapsamında üretilen ve Ajans'a teslim edilen nihai eserlerin (kurgulanmış video, ses, grafik vb.), 5846 sayılı Fikir ve Sanat Eserleri Kanunu md. 21–25'te sayılan işleme, çoğaltma, yayma, temsil ve umuma iletim mali hakları; **İş bedelinin ve ilgili tüm ek ücretlerin eksiksiz ödenmesi şartıyla**, iş kaydında aksi yazmadıkça süre ve yer sınırı olmaksızın, münhasır olmayan şekilde Ajans'a devredilir. Bedel tamamen ödenene kadar tüm haklar Şirket'te kalır ve Ajans eseri kullanamaz.

8.2. Ham görüntüler, proje dosyaları, kurgu projeleri, ara çıktılar ve kullanılmayan çekimler üzerindeki tüm haklar Şirket'e aittir. Bunlar ancak iş kaydında ham görüntü / proje dosyası teslimi yer alıyorsa ve ilgili ücret ödenmişse Ajans'a teslim edilir.

8.3. Eserlerde kullanılan lisanslı müzik, font, stok görüntü ve benzeri üçüncü kişi materyallerinin lisansları, lisans sahibinin şartlarıyla sınırlıdır. Ajans tarafından sağlanan materyallerin lisansından Ajans sorumludur.

8.4. Ajans, teslim edilen eserlerin üzerinde değişiklik yapılmasından doğan sonuçlardan Şirket'in sorumlu olmadığını kabul eder.

8.5. Ajans aksini yazılı olarak bildirmedikçe Şirket, tamamlanan işleri, Ajans'ın ve nihai müşterinin ticari sırlarını açıklamamak kaydıyla, kendi portfolyosunda, web sitesinde ve sosyal medya hesaplarında referans olarak kullanabilir.

## 9. Ajans'ın beyan ve taahhütleri

Ajans; İş'in konusunun ve sağladığı tüm materyallerin hukuka uygun olduğunu, üçüncü kişilerin fikri mülkiyet, marka, kişilik, özel hayat ve kişisel veri haklarını ihlal etmediğini, görüntülenecek kişilerden gerekli izinlerin alındığını, reklam içeriğinin Ticari Reklam ve Haksız Ticari Uygulamalar Yönetmeliği'ne ve RTÜK mevzuatına uygun olduğunu beyan eder. Bu beyanların aksine bir durumdan doğan her türlü talep, idari para cezası ve tazminattan Ajans sorumludur; Şirket'in ödemek zorunda kaldığı tutarları faiz ve masraflarıyla birlikte ilk talepte öder.

## 10. Gizlilik

Taraflar, Sözleşme kapsamında öğrendikleri diğer tarafa ait ticari sırları, fiyatları, müşteri bilgilerini, iş süreçlerini ve teknik bilgileri gizli tutacak, üçüncü kişilere açıklamayacaktır. Bu yükümlülük Sözleşme'nin sona ermesinden sonra 5 (beş) yıl süreyle devam eder. Kanunen yetkili makamlara yapılması zorunlu açıklamalar bu yükümlülüğün istisnasıdır.

## 11. Kişisel veriler

Ajans, İş kapsamında Şirket'e aktardığı kişisel verileri (çekilecek kişiler, müşteri çalışanları vb.) 6698 sayılı Kanun'a uygun şekilde elde ettiğini, ilgili kişileri aydınlattığını ve gerekli hallerde açık rızalarını aldığını beyan eder. Bu verilere ilişkin veri sorumluluğu Ajans'tadır; Şirket bu veriler bakımından yalnızca İş'in ifası için veri işleyen sıfatıyla hareket eder.

## 12. Sorumluluk sınırı

Şirket'in bu Sözleşme'den doğan toplam sorumluluğu, kast ve ağır ihmal halleri hariç, zarara konu İş için Ajans'ın Şirket'e fiilen ödediği net bedeli aşamaz. Şirket; dolaylı zarar, kâr kaybı, yayın tarihinin kaçırılması, kampanya veya reklam alanı kaybı, Ajans'ın kendi müşterisine karşı ödeyeceği cezalar ve itibar kaybından sorumlu değildir. Dış çekimlerde hava koşulları, ışık ve mekan koşulları nedeniyle yaşanan aksaklıklar ile Ajans'tan kaynaklanan gecikmeler Şirket'in sorumluluğunda değildir.

## 13. Uyuşmazlıklar

Bu Sözleşme Türk hukukuna tabidir. Uyuşmazlıklarda **{{yetkili_mahkeme}} Mahkemeleri ve İcra Daireleri** yetkilidir. Kullanım Koşulları'nın elektronik kayıtların delil niteliğine ilişkin 12. maddesi bu Sözleşme için de geçerlidir.

## 14. Yürürlük

Bu Sözleşme, Ajans'ın Platform'da elektronik onayıyla yürürlüğe girer ve Ajans hesabı açık kaldığı sürece verilen tüm İş'lere uygulanır. Onay tarihi, saati, IP adresi ve metin sürümü Şirket tarafından kayıt altına alınır.
TXT,

/* ================================================================== */
'freelancer-sozlesmesi' => <<<'TXT'
Bu Freelancer Hizmet Sağlayıcı Sözleşmesi ("Sözleşme"), {{unvan}} ("Şirket") ile Platform'a freelancer olarak kayıt olan gerçek veya tüzel kişi ("Freelancer") arasında, Şirket'in Freelancer'a verdiği işlerin şartlarını düzenler. Bu Sözleşme, Kullanım Koşulları ve Üyelik Sözleşmesi'nin eki ve ayrılmaz parçasıdır.

## 1. Sözleşmenin niteliği

1.1. Bu Sözleşme bir eser ve hizmet sözleşmesidir. Freelancer, Şirket'in **bağımsız alt yüklenicisi** olarak çalışır. Taraflar arasında iş sözleşmesi, işçi-işveren ilişkisi, ortaklık, acentelik, temsil veya vekalet ilişkisi kurulmaz.

1.2. Freelancer, çalışma saatlerini, yöntemini ve ekipmanını kendisi belirler; İş'i iş kaydında belirtilen kapsam, kalite ve süreye uygun şekilde teslim etmekle yükümlüdür.

1.3. Freelancer'ın vergi, SGK (Bağ-Kur dahil), sigorta, oda kaydı ve diğer tüm yasal yükümlülükleri kendisine aittir. Freelancer'ın bu yükümlülükleri yerine getirmemesinden doğan her türlü ceza, prim ve talepten Freelancer sorumludur; bu nedenle Şirket'e bir talep yöneltilirse Freelancer Şirket'in uğradığı tüm zararı ilk talepte öder.

1.4. Freelancer, Şirket adına beyanda bulunamaz, Şirket'i borç altına sokamaz, Şirket'in markasını izinsiz kullanamaz.

## 2. İşlerin alınması

2.1. Şirket, Freelancer'a uzmanlık alanına, seviyesine ve bulunduğu ile uygun İş'leri Platform üzerinden sunar. Şirket'in Freelancer'a iş verme veya belirli sayıda iş garanti etme yükümlülüğü yoktur.

2.2. Freelancer'ın bir İş'i Platform'da kabul etmesiyle o İş için bu Sözleşme kapsamında bağlayıcı bir hizmet ilişkisi kurulur. İş kaydında yazan kapsam, tarih, teslim şekli ve hakediş tutarı Freelancer bakımından bağlayıcıdır.

2.3. Freelancer, kabul ettiği İş'i bizzat ifa eder; Şirket'in yazılı onayı olmadan başkasına devredemez veya yaptıramaz.

2.4. Şirket, Freelancer'ın seviyesini, puanını ve alabileceği iş sayısını Platform kurallarına göre belirler ve değiştirir.

## 3. Ajans ve müşteri ile ilişki

3.1. Freelancer'ın muhatabı yalnızca Şirket'tir. İş'in son kullanıcısı olan ajans veya müşteri ile Freelancer arasında hiçbir sözleşme ilişkisi yoktur.

3.2. Freelancer, İş vesilesiyle öğrendiği ajans, müşteri, marka veya kişilerle; Şirket'i devre dışı bırakarak doğrudan veya dolaylı olarak iletişime geçemez, kendini veya başka bir firmayı tanıtamaz, iş teklif edemez, iş alamaz. Bu yasak Sözleşme süresince ve Sözleşme'nin sona ermesinden itibaren 24 (yirmi dört) ay boyunca geçerlidir. İhlal halinde Kullanım Koşulları madde 8'deki cezai şart uygulanır.

3.3. Yerinde yapılan işlerde Freelancer, Şirket'i temsil ettiğini bilerek profesyonel davranır; müşteriye kendi iletişim bilgisini, kartvizitini veya sosyal medya hesabını vermez.

## 4. Teslim, kalite ve süre

4.1. Freelancer, İş'i iş kaydında belirtilen tarihte ve kalitede teslim eder. Teslimler yalnızca Platform üzerinden yapılır. Yerinde yapılan işlerde Freelancer belirtilen saatte, belirtilen yerde ve gerekli ekipmanla hazır bulunur.

4.2. Freelancer, kabul ettiği bir İş'i yapamayacağını öğrenirse bunu derhal ve en geç İş başlangıcından 48 saat önce Şirket'e bildirir.

4.3. Gecikme, işe gelmeme (no-show), kalitesiz teslim veya iş kaydına aykırılık halinde Şirket; İş'i başka birine aktarma, hakedişten makul kesinti yapma, Freelancer'ın seviyesini düşürme, hesabını askıya alma ve uğradığı zararı (İş'i başka birine yaptırmanın ek maliyeti ve ajansa ödenen tazminatlar dahil) Freelancer'dan talep etme hakkına sahiptir. Mazeretsiz işe gelmeme halinde Freelancer, ilgili İş'in hakediş tutarı kadar cezai şart öder.

4.4. Freelancer, ücretsiz revizyon hakkı kapsamında Şirket'in ilettiği revizyonları ek ücret talep etmeden yapar. Ücretli revizyon ve ek kalemlerde Freelancer'a, Platform kurallarında ilan edilen pay ödenir.

## 5. Hakediş ve ödeme

5.1. Freelancer'ın hakedişi iş kaydında gösterilen tutardır. Ajansa uygulanan fiyat ile Freelancer hakedişi arasındaki fark Şirket'in hizmet ve organizasyon bedelidir; Freelancer bu fark üzerinde hak iddia edemez.

5.2. Hakediş, ilgili aşamanın onaylanmasıyla (otomatik onay dahil) ödeme talebine açılır. Freelancer, Platform'dan ödeme talebinde bulunur; Şirket ödemeyi Platform kurallarında belirtilen süre içinde, Freelancer'ın Platform'a kayıtlı ve **kendi adına** açılmış IBAN'ına yapar.

5.3. Freelancer, ödeme için yasal belgeyi (fatura, serbest meslek makbuzu veya Şirket'in düzenleyeceği gider pusulası) düzenlemekle / düzenlenmesine onay vermekle yükümlüdür. Yasal kesintiler (gelir vergisi stopajı vb.) hakedişten kesilerek ilgili kuruma ödenir.

5.4. Şirket; Freelancer'ın Şirket'e olan borçlarını, cezai şartları ve zarar tutarlarını hakedişinden mahsup etme hakkına sahiptir.

5.5. Hatalı IBAN veya eksik bilgi nedeniyle yapılamayan ya da yanlış hesaba yapılan ödemelerden Freelancer sorumludur.

## 6. Fikri mülkiyet hakları

6.1. Freelancer'ın İş kapsamında ürettiği tüm eserlerin (çekilen görüntüler, ham dosyalar, ses kayıtları, kurgu projeleri, grafikler, ara ve nihai çıktılar), 5846 sayılı Fikir ve Sanat Eserleri Kanunu'nun 21–25. maddelerinde sayılan **işleme, çoğaltma, yayma, temsil ve umuma iletim dahil tüm mali hakları**, eser meydana geldiği anda, süre, yer ve mecra sınırı olmaksızın, **münhasır olarak ve üçüncü kişilere devir ve lisans verme yetkisiyle birlikte** Şirket'e devredilmiştir. Hakediş tutarı bu devrin bedelini de kapsar; Freelancer ayrıca bir bedel talep etmez.

6.2. Freelancer, eserler üzerindeki manevi haklarını (adının belirtilmesi dahil) Şirket ve Şirket'in devrettiği kişiler aleyhine, kanunun izin verdiği ölçüde kullanmayacağını; eserin işlenmesine, değiştirilmesine ve adı belirtilmeden yayımlanmasına izin verdiğini kabul eder.

6.3. Freelancer, İş kapsamında ürettiği görüntü ve dosyaların kopyasını saklayamaz, kendi portfolyosunda, sosyal medyasında veya başka bir işte Şirket'in yazılı onayı olmadan kullanamaz. İş tamamlandıktan sonra Şirket'in talebi halinde elindeki tüm kopyaları siler.

6.4. Freelancer, ürettiği eserlerin özgün olduğunu, üçüncü kişilerin haklarını ihlal etmediğini; kullandığı yazılım, font, müzik ve materyallerin lisanslı olduğunu beyan ve taahhüt eder.

## 7. Ekipman ve sigorta

Freelancer, kendi ekipmanının bakımından, güvenliğinden ve sigortasından sorumludur. Ekipman arızası, kaybı veya hasarı nedeniyle Şirket'ten talepte bulunamaz; arıza nedeniyle İş'in aksaması Freelancer'ın sorumluluğundadır. Freelancer, yerinde yapılan işlerde iş sağlığı ve güvenliği kurallarına uyar; kendi kusurundan doğan kaza ve zararlardan kendisi sorumludur.

## 8. Gizlilik

Freelancer; İş'ler, ajanslar, müşteriler, markalar, fiyatlar, yayınlanmamış içerikler, çekim mekanları ve Şirket'in iş süreçleri hakkında öğrendiği tüm bilgileri gizli tutar; yayın tarihinden önce hiçbir içeriği paylaşmaz. Bu yükümlülük Sözleşme'nin sona ermesinden sonra da süresiz devam eder. Gizlilik ihlali halinde Freelancer, ihlale konu İş'in ajans bedelinin 3 (üç) katı tutarında cezai şart öder; Şirket'in aşan zararını talep hakkı saklıdır.

## 9. Kişisel veriler

Freelancer, İş kapsamında eriştiği kişisel verileri (çekilen kişilerin görüntüleri, iletişim bilgileri vb.) yalnızca İş'in ifası için ve Şirket'in talimatları doğrultusunda işler; başka amaçla kullanmaz, saklamaz, paylaşmaz ve gerekli teknik ve idari güvenlik önlemlerini alır. Freelancer bu veriler bakımından Şirket adına veri işleyen sıfatıyla hareket eder.

## 10. Fesih

10.1. Her iki taraf da Sözleşme'yi devam eden İş bulunmaması kaydıyla dilediği zaman feshedebilir.

10.2. Şirket; Sözleşme'ye aykırılık, kalite sorunları, mazeretsiz işe gelmeme, ajans veya müşteri şikayeti, gizlilik ihlali veya doğrudan iş ilişkisi yasağının ihlali hallerinde Sözleşme'yi derhal feshedebilir ve Freelancer'ın devam eden İş'lerini başka kişilere aktarabilir. Bu durumda Freelancer'a yalnızca onaylanmış aşamaların hakedişi, Şirket'in mahsup hakkı saklı kalmak kaydıyla ödenir.

## 11. Sorumluluk sınırı

Şirket'in Freelancer'a karşı toplam sorumluluğu, kast ve ağır ihmal halleri hariç, ilgili İş için Freelancer'a ödenmesi gereken hakediş tutarı ile sınırlıdır. Şirket, Freelancer'a iş verilmemesi veya verilen işin iptali nedeniyle kâr kaybından sorumlu değildir. Freelancer'ın kabul ettiği bir yerinde iş, başlangıcına 24 saatten az kala ve Freelancer'dan kaynaklanmayan bir sebeple iptal edilirse, Şirket ajanstan iptal bedeli tahsil edebildiği ölçüde Freelancer'a makul bir iptal payı ödeyebilir.

## 12. Uyuşmazlıklar

Bu Sözleşme Türk hukukuna tabidir. Uyuşmazlıklarda **{{yetkili_mahkeme}} Mahkemeleri ve İcra Daireleri** yetkilidir. Kullanım Koşulları'nın elektronik kayıtların delil niteliğine ilişkin 12. maddesi bu Sözleşme için de geçerlidir.

## 13. Yürürlük

Bu Sözleşme, Freelancer'ın Platform'da elektronik onayıyla yürürlüğe girer ve Freelancer hesabı açık kaldığı sürece kabul edilen tüm İş'lere uygulanır. Onay tarihi, saati, IP adresi ve metin sürümü Şirket tarafından kayıt altına alınır.
TXT,

/* ================================================================== */
'kvkk' => <<<'TXT'
Bu aydınlatma metni, 6698 sayılı Kişisel Verilerin Korunması Kanunu ("KVKK") md. 10 ve Aydınlatma Yükümlülüğünün Yerine Getirilmesinde Uyulacak Usul ve Esaslar Hakkında Tebliğ uyarınca, veri sorumlusu sıfatıyla {{unvan}} ("Şirket") tarafından hazırlanmıştır.

## 1. Veri sorumlusu

{{unvan}}
Adres: {{adres}}
E-posta: {{kvkk_eposta}} · Telefon: {{telefon}} · KEP: {{kep}}
MERSİS: {{mersis}}

## 2. İşlenen kişisel veriler

İlişkinize göre aşağıdaki veri kategorileri işlenebilir:

- **Kimlik:** ad soyad, unvan, (gerektiğinde) T.C. kimlik numarası.
- **İletişim:** e-posta adresi, telefon numarası, adres, il.
- **Müşteri / iş ilişkisi:** firma adı, vergi dairesi ve numarası, iş kayıtları, teklifler, mesajlar, teslim ve onay kayıtları, değerlendirme ve puanlar.
- **Finans:** IBAN, banka bilgisi, fatura, ödeme, hakediş ve tahsilat kayıtları. Kart ile ödemelerde kart bilgileri Şirket tarafından görülmez ve saklanmaz; doğrudan ödeme kuruluşu iyzico tarafından işlenir.
- **Mesleki deneyim:** uzmanlık alanları, portfolyo bağlantıları, ekipman, günlük ücret beklentisi, deneyim açıklaması, performans ve seviye bilgileri (Freelancer'lar için).
- **Görsel ve işitsel kayıtlar:** İş kapsamında çekilen veya Platform'a yüklenen fotoğraf, video ve ses kayıtları.
- **İşlem güvenliği:** IP adresi, giriş-çıkış kayıtları, tarayıcı ve cihaz bilgisi, şifre özeti (şifreler okunamaz şekilde saklanır), iki adımlı doğrulama bilgisi, sözleşme onay kayıtları.
- **Pazarlama:** yalnızca izin vermeniz halinde, ticari elektronik ileti tercihleri.

## 3. İşleme amaçları

Kişisel verileriniz;

- üyelik başvurusunun alınması, değerlendirilmesi ve hesabın yönetilmesi,
- sözleşmenin kurulması ve ifası; iş kayıtlarının oluşturulması, işlerin atanması, yürütülmesi, teslimi ve onayı,
- ajans, freelancer ve müşteri eşleştirmesinin yapılması (taraflar birbirinin kimliğini görmeden),
- faturalama, tahsilat, hakediş ve ödeme süreçlerinin yürütülmesi; muhasebe ve finans işlemleri,
- iş ve işlemlerle ilgili bildirim ve e-postaların gönderilmesi,
- freelancer performansının ölçülmesi ve seviye belirlenmesi,
- bilgi güvenliği süreçlerinin yürütülmesi; yetkisiz erişim ve dolandırıcılığın önlenmesi,
- hukuki uyuşmazlıklarda delil olarak kullanılması ve hukuki süreçlerin takibi,
- yetkili kurum ve kuruluşlara mevzuattan kaynaklanan bilgi verilmesi, vergi ve ticaret mevzuatından doğan yükümlülüklerin yerine getirilmesi,
- açık rıza / ileti izni vermeniz halinde duyuru ve kampanya iletilerinin gönderilmesi

amaçlarıyla işlenir.

## 4. Hukuki sebepler

Kişisel verileriniz KVKK md. 5/2 uyarınca;

- (a) kanunlarda açıkça öngörülmesi (Vergi Usul Kanunu, Türk Ticaret Kanunu, 5651 sayılı Kanun vb.),
- (c) bir sözleşmenin kurulması veya ifasıyla doğrudan ilgili olması,
- (ç) Şirket'in hukuki yükümlülüğünü yerine getirmesi,
- (e) bir hakkın tesisi, kullanılması veya korunması,
- (f) ilgili kişinin temel hak ve özgürlüklerine zarar vermemek kaydıyla Şirket'in meşru menfaati (bilgi güvenliği, performans ölçümü, hizmet kalitesi)

hukuki sebeplerine dayanılarak; ticari elektronik ileti gönderimi ve md. 5/2 kapsamına girmeyen işlemler ise yalnızca **açık rızanıza** (md. 5/1) dayanılarak işlenir.

## 5. Kişisel verilerin aktarılması

Kişisel verileriniz yukarıdaki amaçlarla sınırlı olarak;

- iş ortaklarımıza ve alt yüklenicilerimize (ilgili İş'in ifası için gerekli olduğu ölçüde ve ajans-freelancer ayrımı korunarak),
- barındırma (hosting), e-posta, yedekleme ve yazılım hizmeti aldığımız tedarikçilere,
- ödeme kuruluşu iyzico'ya ve bankalara (ödeme ve tahsilat işlemleri için),
- mali müşavir, bağımsız denetçi, avukat ve danışmanlarımıza,
- talep halinde yetkili kamu kurum ve kuruluşlarına ve yargı mercilerine

KVKK md. 8 ve 9'daki şartlara uygun olarak aktarılabilir. Yurt dışında sunucusu bulunan hizmet sağlayıcılara (ör. e-posta, içerik dağıtım ağı) yapılan aktarımlar, KVKK md. 9'da öngörülen uygun güvencelerle veya açık rızanız ile gerçekleştirilir.

## 6. Toplama yöntemi

Kişisel verileriniz; Platform'daki kayıt, profil, iş, ödeme ve iletişim formları, Platform kullanımınız sırasında oluşan elektronik kayıtlar, e-posta ve telefon yazışmaları ile çerezler aracılığıyla, kısmen veya tamamen otomatik yollarla toplanır.

## 7. Saklama süresi

Kişisel verileriniz işleme amacının gerektirdiği süre ve ilgili mevzuatta öngörülen süreler boyunca saklanır. Ticari defter ve belgelere ilişkin veriler 10 yıl, sözleşmeye ilişkin veriler genel zamanaşımı süresi olan 10 yıl, trafik ve giriş kayıtları en az 2 yıl saklanır. Süre sonunda veriler silinir, yok edilir veya anonim hale getirilir.

## 8. Haklarınız

KVKK md. 11 uyarınca Şirket'e başvurarak;

- kişisel verinizin işlenip işlenmediğini öğrenme, işlenmişse bilgi talep etme,
- işlenme amacını ve amacına uygun kullanılıp kullanılmadığını öğrenme,
- yurt içinde veya yurt dışında aktarıldığı üçüncü kişileri bilme,
- eksik veya yanlış işlenmişse düzeltilmesini isteme,
- KVKK md. 7'deki şartlar çerçevesinde silinmesini veya yok edilmesini isteme,
- düzeltme, silme ve yok etme işlemlerinin aktarıldığı üçüncü kişilere bildirilmesini isteme,
- münhasıran otomatik sistemlerle analiz edilmesi sonucu aleyhinize bir sonucun ortaya çıkmasına itiraz etme,
- kanuna aykırı işleme nedeniyle zarara uğramanız halinde zararın giderilmesini talep etme

haklarına sahipsiniz.

## 9. Başvuru yöntemi

Başvurularınızı, Veri Sorumlusuna Başvuru Usul ve Esasları Hakkında Tebliğ'e uygun olarak; kimliğinizi tespit edici bilgilerle birlikte yazılı olarak {{adres}} adresine şahsen veya noter aracılığıyla, KEP adresimize ({{kep}}) güvenli elektronik imzalı olarak ya da Platform'da kayıtlı e-posta adresinizden {{kvkk_eposta}} adresine iletebilirsiniz. Başvurunuz en geç 30 gün içinde ücretsiz olarak sonuçlandırılır; işlemin ayrıca bir maliyet gerektirmesi halinde Kişisel Verileri Koruma Kurulu'nca belirlenen tarifedeki ücret alınabilir.
TXT,

/* ================================================================== */
'acik-riza' => <<<'TXT'
KVKK Aydınlatma Metni'ni okudum ve anladım. Bu metni onaylayarak, {{unvan}} ("Şirket") tarafından, aşağıda belirtilen ve sözleşmenin ifası veya kanuni yükümlülük gibi başka bir hukuki sebebe dayanmayan işlemler için kişisel verilerimin işlenmesine **özgür irademle ve açık rızamla** onay veriyorum:

## 1. Rızaya dayanan işlemler

- **Yurt dışına aktarım:** Kimlik, iletişim ve işlem güvenliği verilerimin; e-posta gönderimi, içerik dağıtımı, yedekleme ve yazılım hizmetleri için sunucusu yurt dışında bulunan hizmet sağlayıcılara, KVKK md. 9'da öngörülen uygun güvencelerin bulunmadığı hallerde aktarılması.
- **Tanıtım ve pazarlama:** İletişim bilgilerimin; Şirket'in hizmetleri, kampanyaları, yeni iş fırsatları ve etkinlikleri hakkında bilgilendirme yapılması, memnuniyet anketleri düzenlenmesi ve bu amaçla segmentasyon yapılması amacıyla işlenmesi.
- **Referans kullanımı (Freelancer'lar için):** Profil bilgilerimin ve performans puanımın, kimliğim açıklanmadan, Şirket'in tanıtım materyallerinde istatistiksel olarak kullanılması.

## 2. Rızanın niteliği

Bu rıza isteğe bağlıdır. Vermemeniz halinde Platform'a üye olmanız ve hizmetlerden yararlanmanız engellenmez; yalnızca yukarıdaki işlemler yapılmaz.

## 3. Rızanın geri alınması

Rızanızı dilediğiniz zaman, gerekçe göstermeksizin {{kvkk_eposta}} adresine e-posta göndererek veya Platform'daki profil ayarlarınızdan geri alabilirsiniz. Geri alma, geri alma tarihinden sonraki işlemler için geçerlidir; önceki işlemlerin hukuka uygunluğunu etkilemez.
TXT,

/* ================================================================== */
'ticari-ileti' => <<<'TXT'
6563 sayılı Elektronik Ticaretin Düzenlenmesi Hakkında Kanun ve Ticari İletişim ve Ticari Elektronik İletiler Hakkında Yönetmelik uyarınca;

{{unvan}} ("Şirket") tarafından; hizmetler, kampanyalar, indirimler, yeni iş fırsatları, etkinlikler, duyurular ve kutlamalar hakkında, Platform'a kayıtlı **e-posta adresime, telefon numarama SMS ve arama yoluyla** ticari elektronik ileti gönderilmesine onay veriyorum.

## Bilgilendirme

- Bu onay isteğe bağlıdır; vermemeniz Platform'u kullanmanıza engel değildir.
- İş, ödeme, güvenlik ve hesabınızla ilgili bilgilendirme iletileri ticari elektronik ileti değildir ve bu onaydan bağımsız olarak gönderilir.
- Onayınız İleti Yönetim Sistemi'ne (İYS) kaydedilebilir.
- İletileri almak istemezseniz, gönderilen her iletideki "abonelikten çık" bağlantısıyla, İYS üzerinden (iys.org.tr) veya {{eposta}} adresine yazarak dilediğiniz zaman ücretsiz olarak ret hakkınızı kullanabilirsiniz. Ret bildiriminiz en geç 3 iş günü içinde işleme alınır.
TXT,

/* ================================================================== */
'gizlilik' => <<<'TXT'
{{unvan}} ("Şirket") olarak, {{site}} adresinde sunulan Platform'u kullanan ziyaretçi ve üyelerimizin gizliliğine önem veriyoruz. Bu politika, hangi bilgilerin nasıl korunduğunu açıklar. Kişisel verilerin işlenmesine ilişkin ayrıntılar **KVKK Aydınlatma Metni**'nde yer alır.

## 1. Toplanan bilgiler

Platform'a üye olurken ve kullanırken verdiğiniz bilgiler (kimlik, iletişim, firma, vergi, IBAN, portfolyo vb.), iş ve ödeme kayıtları, mesajlar, yüklediğiniz dosyalar ile IP adresi, giriş zamanı, tarayıcı ve cihaz bilgileri gibi teknik kayıtlar toplanır.

## 2. Kullanım amacı

Bu bilgiler yalnızca hizmetin sunulması, işlerin yürütülmesi, ödeme ve faturalama, güvenlik, yasal yükümlülükler ve (izin verdiyseniz) bilgilendirme amacıyla kullanılır. Bilgileriniz satılmaz, kiralanmaz ve izniniz olmadan pazarlama amacıyla üçüncü kişilerle paylaşılmaz.

## 3. Ajans ve freelancer ayrımı

Platform'un işleyişi gereği ajansların ve freelancer'ların kimlik ve iletişim bilgileri birbirleriyle paylaşılmaz. Her iki taraf da yalnızca Şirket ile iletişim kurar.

## 4. Güvenlik önlemleri

- Tüm bağlantılar SSL/TLS şifrelemesi (https) ile korunur.
- Şifreler geri döndürülemez şekilde özetlenerek (hash) saklanır; Şirket çalışanları dahil kimse şifrenizi göremez.
- İki adımlı doğrulama (Google Authenticator vb.) desteklenir.
- Hatalı giriş denemeleri sınırlandırılır; şüpheli girişler kaydedilir ve yeni cihazdan girişte e-posta ile bildirim yapılır.
- Hareketsiz oturumlar belirli bir süre sonra otomatik kapatılır.
- Hassas ayarlar (ör. ödeme kuruluşu anahtarları) şifrelenerek saklanır.
- Veritabanı düzenli olarak yedeklenir; yedekler internetten erişilemeyen bir konumda tutulur.
- Platform'a erişim yetkileri rol bazında sınırlandırılmıştır.

## 5. Kartla ödemeler

Kartla ödemeler, Türkiye Cumhuriyet Merkez Bankası lisanslı ödeme kuruluşu **iyzico** altyapısında, 3D Secure ile gerçekleştirilir. Kart numarası, son kullanma tarihi ve güvenlik kodu Platform'a girilmez, Şirket sunucularından geçmez ve Şirket tarafından saklanmaz.

## 6. Kullanıcının sorumluluğu

Hesap güvenliğiniz için güçlü ve başka sitelerde kullanmadığınız bir şifre seçmeniz, iki adımlı doğrulamayı açmanız ve ortak kullanılan cihazlarda oturumunuzu kapatmanız önerilir. Şifrenizin sizin kusurunuzla üçüncü kişilerce öğrenilmesinden doğan sonuçlardan Şirket sorumlu değildir.

## 7. Üçüncü taraf bağlantılar

Platform'da yer alan üçüncü taraf bağlantılarının (ör. portfolyo, video platformları) gizlilik uygulamalarından Şirket sorumlu değildir.

## 8. Değişiklikler

Bu politika güncellenebilir; güncel metin her zaman bu sayfada yayımlanır.

## 9. İletişim

Gizlilik ile ilgili sorularınız için: {{eposta}}
TXT,

/* ================================================================== */
'cerez' => <<<'TXT'
Bu Çerez Politikası, {{unvan}} ("Şirket") tarafından işletilen {{site}} Platform'unda kullanılan çerezler ve benzeri teknolojiler hakkında sizi bilgilendirmek amacıyla hazırlanmıştır.

## 1. Çerez nedir?

Çerezler, ziyaret ettiğiniz internet siteleri tarafından tarayıcınıza kaydedilen küçük metin dosyalarıdır. Tarayıcının yerel depolama alanı (localStorage) da benzer amaçlarla kullanılabilir.

## 2. Kullandığımız çerezler

Platform yalnızca **zorunlu** çerezleri ve yerel depolamayı kullanır. Reklam, profil oluşturma veya üçüncü taraf takip çerezi kullanılmaz.

- **Oturum çerezi (PHPSESSID):** Giriş yaptığınızı hatırlamak ve oturumunuzu güvenli şekilde sürdürmek için kullanılır. Tarayıcı kapatıldığında veya oturum kapatıldığında silinir. Yalnızca https üzerinden gönderilir ve JavaScript tarafından okunamaz.
- **Güvenlik (CSRF) bilgisi:** Formların sizin tarafınızdan gönderildiğini doğrulamak için oturum içinde tutulur.
- **Yerel depolama:** Arayüz tercihleriniz (ör. kenar çubuğu durumu, çerez bilgilendirmesinin kapatılması) yalnızca kendi tarayıcınızda saklanır.

## 3. Hukuki sebep

Zorunlu çerezler, hizmetin sunulması için gerekli olduğundan KVKK md. 5/2-(c) "sözleşmenin ifası" ve (f) "meşru menfaat" hukuki sebeplerine dayanır; açık rıza gerektirmez. Zorunlu olmayan bir çerez kullanılmaya başlanırsa önce açık rızanız alınır.

## 4. Üçüncü taraf hizmetler

Sayfaların görüntülenmesi için bazı yazı tipi, simge ve kütüphane dosyaları içerik dağıtım ağlarından (CDN) yüklenir. Bu sağlayıcılar teknik zorunluluk gereği IP adresinizi görebilir; Şirket bu sağlayıcılar aracılığıyla çerez yerleştirmez. Kartla ödeme sırasında iyzico ödeme sayfası kendi çerezlerini kullanabilir; bu çerezler iyzico'nun politikalarına tabidir.

## 5. Çerezleri yönetme

Tarayıcınızın ayarlarından çerezleri silebilir veya engelleyebilirsiniz. Zorunlu çerezleri engellerseniz Platform'a giriş yapamazsınız.

## 6. İletişim

Sorularınız için: {{eposta}}
TXT,

/* ================================================================== */
'mesafeli' => <<<'TXT'
## BÖLÜM A — ÖN BİLGİLENDİRME FORMU

6502 sayılı Tüketicinin Korunması Hakkında Kanun ve Mesafeli Sözleşmeler Yönetmeliği uyarınca, sözleşme kurulmadan önce aşağıdaki bilgiler sunulmaktadır.

### Hizmet sağlayıcı

Unvan: {{unvan}}
Adres: {{adres}}
Telefon: {{telefon}} · E-posta: {{eposta}} · KEP: {{kep}}
Vergi dairesi / no: {{vergi_dairesi}} / {{vergi_no}}
MERSİS: {{mersis}}

### Hizmetin temel nitelikleri

Hizmet; Platform'daki iş kaydında kapsamı, aşamaları, teslim şekli ve tarihleri belirtilen prodüksiyon, çekim, kurgu, ses, renk düzenleme, grafik ve benzeri dijital/yerinde hizmetlerdir. Ödemesi yapılan faturanın kapsadığı iş(ler) ve tutarları ödeme ekranında ve faturada gösterilir.

### Fiyat ve ödeme

Toplam bedel, vergiler dahil olarak ödeme ekranında gösterilir. Ödeme kredi/banka kartıyla, iyzico ödeme altyapısı üzerinden 3D Secure ile tek çekim veya izin verilen taksit seçenekleriyle yapılır. Taksitli ödemelerde vade farkı, kartı veren bankanın ve iyzico'nun koşullarına göre ödeme sayfasında gösterilir. Havale/EFT ile de ödeme yapılabilir.

### İfa

Hizmet iş kaydında belirtilen tarih ve şekilde ifa edilir. Dijital teslimler Platform üzerinden yapılır; yerinde yapılan işler belirtilen yer ve tarihte gerçekleştirilir.

### Cayma hakkı

Mesafeli Sözleşmeler Yönetmeliği'nin 15. maddesi uyarınca; **cayma hakkı süresi sona ermeden önce tüketicinin onayı ile ifasına başlanan hizmetlere**, **tüketicinin istekleri veya kişisel ihtiyaçları doğrultusunda hazırlanan** hizmet ve ürünlere ve **belirli bir tarihte veya dönemde yapılması gereken** hizmetlere ilişkin sözleşmelerde cayma hakkı kullanılamaz. Platform'daki işler kişiye özel hazırlanan ve çoğunlukla belirli tarihte ifa edilen hizmetler olduğundan ve ödeme genellikle hizmetin ifasına başlandıktan veya tamamlandıktan sonra yapıldığından, **ödemesi yapılan hizmetler için cayma hakkı bulunmamaktadır.** Henüz ifasına başlanmamış hizmetler için iptal koşulları **İptal, İade ve Ödeme Koşulları**'nda yer alır.

### Şikayet ve itiraz

Şikayetlerinizi yukarıdaki iletişim bilgilerinden iletebilirsiniz. Tüketici sıfatına sahip alıcılar, Ticaret Bakanlığı'nca ilan edilen parasal sınırlar dahilinde tüketici hakem heyetine veya tüketici mahkemesine başvurabilir.

## BÖLÜM B — MESAFELİ HİZMET SÖZLEŞMESİ

### Madde 1 — Taraflar

**Hizmet sağlayıcı:** {{unvan}}, {{adres}} ("Şirket")
**Alıcı:** Platform'da ödemeyi yapan hesap sahibi ve fatura bilgilerinde yer alan kişi veya işletme ("Alıcı").

### Madde 2 — Konu

Bu sözleşmenin konusu, Alıcı'nın Platform üzerinden kartla ödemesini yaptığı faturada yer alan hizmetlerin bedelinin ödenmesine ve hizmetin ifasına ilişkin tarafların hak ve yükümlülüklerinin belirlenmesidir.

### Madde 3 — Hizmet ve bedel

Hizmetin kapsamı, aşamaları ve bedeli ilgili iş kaydında ve faturada belirtildiği gibidir. Ödeme ekranında gösterilen toplam tutar, vergiler dahil ödenecek tutardır.

### Madde 4 — Genel hükümler

4.1. Alıcı, ödeme öncesinde Ön Bilgilendirme Formu'nu okuduğunu, hizmetin temel nitelikleri, bedeli, ödeme şekli ve ifasına ilişkin bilgileri edindiğini elektronik ortamda onaylar.

4.2. Kart ile ödemede, kartın Alıcı'ya ait olması veya kart sahibinin izninin bulunması gerekir. Kartın yetkisiz kullanımından doğan sorumluluk Alıcı'ya aittir. Banka veya iyzico tarafından ödemenin reddedilmesi, iptal edilmesi veya ters ibraz (chargeback) edilmesi halinde Şirket'in fatura alacağı doğrudan Alıcı'dan talep edilir; Şirket bu durumda hizmeti durdurma hakkına sahiptir.

4.3. Haklı bir sebep olmaksızın yapılan ters ibraz talepleri, ödeme yükümlülüğünü ortadan kaldırmaz; ters ibraz nedeniyle Şirket'in uğradığı masraf ve zararlar Alıcı'dan tahsil edilir.

4.4. Bu sözleşme, Ajans Hizmet Sözleşmesi ve Kullanım Koşulları ile birlikte uygulanır.

### Madde 5 — Cayma hakkı

Ön Bilgilendirme Formu'ndaki açıklamalar uyarınca, ifasına başlanmış, kişiye özel hazırlanan veya belirli tarihte yapılan hizmetlerde cayma hakkı bulunmamaktadır. Alıcı bunu bilerek ödemeyi yaptığını kabul eder.

### Madde 6 — İade

Fazla veya mükerrer çekilen tutarlar ile Şirket'in kabul ettiği iadeler, ödemenin yapıldığı karta iyzico aracılığıyla iade edilir. İadenin karta yansıma süresi bankaya bağlı olarak 2–10 iş günüdür.

### Madde 7 — Uyuşmazlık

Alıcı'nın tüketici olduğu hallerde Ticaret Bakanlığı'nca ilan edilen parasal sınırlar dahilinde Alıcı'nın veya Şirket'in yerleşim yerindeki tüketici hakem heyeti ve tüketici mahkemeleri; Alıcı'nın tacir olduğu hallerde **{{yetkili_mahkeme}} Mahkemeleri ve İcra Daireleri** yetkilidir.

### Madde 8 — Yürürlük

Alıcı, bu sözleşmeyi ödeme ekranında elektronik ortamda onayladığı anda sözleşme kurulmuş sayılır. Onay kaydı, ödeme bilgileri ve IP adresi Şirket tarafından saklanır; sözleşmenin bir örneği Platform'da her zaman erişilebilir durumdadır.
TXT,

/* ================================================================== */
'iptal-iade' => <<<'TXT'
Bu koşullar {{unvan}} ("Şirket") tarafından Platform üzerinden sunulan hizmetlerin ödeme, iptal ve iadesine ilişkin kuralları açıklar ve Ajans Hizmet Sözleşmesi ile birlikte uygulanır.

## 1. Ödeme yöntemleri

- **Havale / EFT:** Ödemeler sayfasında gösterilen Şirket banka hesaplarına. Açıklama kısmına fatura veya iş numarası yazılmalı ve Platform'dan ödeme bildirimi (dekont) gönderilmelidir.
- **Kredi / banka kartı:** iyzico güvenli ödeme altyapısıyla, 3D Secure doğrulamasıyla. Kart bilgileri Şirket tarafından görülmez ve saklanmaz.

Ödeme, tutar Şirket hesabına geçtiği veya iyzico tarafından onaylandığı anda yapılmış sayılır ve ilgili faturaya otomatik olarak işlenir.

## 2. Vade ve gecikme

Faturalar faturada belirtilen vadede ödenir. Geciken ödemelere, Ajans Hizmet Sözleşmesi uyarınca avans faizi oranında temerrüt faizi uygulanır; ödeme yapılana kadar yeni işler ve teslimler bekletilebilir.

## 3. İş iptali

Henüz ifasına başlanmamış bir işin iptali Ajans Hizmet Sözleşmesi madde 7'deki iptal bedellerine tabidir:

- İşe henüz kimse atanmamışsa: bedelsiz.
- İş atanmış, başlangıca 72 saatten fazla varsa: iş bedelinin %25'i.
- Başlangıca 72 saatten az kalmışsa: iş bedelinin %50'si.
- Başlangıca 24 saatten az kalmışsa veya iş başlamışsa: iş bedelinin tamamı.

Tamamlanmış ve onaylanmış (otomatik onay dahil) aşamaların bedeli iade edilmez.

## 4. Cayma hakkı

Kişiye özel hazırlanan, belirli bir tarihte ifa edilen ve ifasına başlanmış hizmetlerde Mesafeli Sözleşmeler Yönetmeliği md. 15 uyarınca cayma hakkı yoktur.

## 5. İade halleri ve süreci

İade yalnızca şu hallerde yapılır:

- Mükerrer veya fazla ödeme yapılması,
- İşin Şirket tarafından iptal edilmesi (yalnızca ifa edilmemiş kısım için),
- Ödeme yapılmış bir iptalde, iptal bedeli düşüldükten sonra kalan tutar.

İade talepleri {{eposta}} adresine, fatura ve ödeme bilgisiyle birlikte yazılı olarak iletilir. Şirket talebi 10 iş günü içinde sonuçlandırır. Kartla yapılan ödemeler aynı karta, havale ile yapılan ödemeler ödemeyi yapan firmanın kendi adına kayıtlı IBAN'a iade edilir. Kart iadesinin hesaba yansıması bankaya bağlı olarak 2–10 iş günü sürebilir. Taksitli ödemelerin iadesi, bankanın uygulamasına göre taksitler halinde yansıyabilir.

Şirket'in alacağı bulunan durumlarda iade tutarı bu alacağa mahsup edilebilir.

## 6. Revizyon, ek kalem ve ham görüntü ücretleri

Ücretsiz revizyon hakkını aşan revizyonlar, ham görüntü teslimi ve sonradan eklenen kalemler ayrıca ücretlendirilir; bu ücretler talep edildiği anda kabul edilmiş sayılır ve talep yerine getirildikten sonra iade edilmez.

## 7. İletişim

Ödeme ve iade ile ilgili tüm sorularınız için: {{eposta}} · {{telefon}}
TXT,

/* ================================================================== */
'iletisim' => <<<'TXT'
## Şirket bilgileri

**Unvan:** {{unvan}}
**Adres:** {{adres}}
**Vergi dairesi / numarası:** {{vergi_dairesi}} / {{vergi_no}}
**MERSİS / Ticaret sicil no:** {{mersis}}

## İletişim

**E-posta:** {{eposta}}
**Telefon:** {{telefon}}
**KEP adresi:** {{kep}}
**Web:** {{web}}

## Kişisel veriler

KVKK kapsamındaki başvurularınız için: {{kvkk_eposta}}

## Ödeme

Kartla ödemeler iyzico güvenli ödeme altyapısı ile alınmaktadır. Banka hesap bilgilerimiz Platform'daki Ödemeler sayfasında yer alır.
TXT,
];
