<?php
/**
 * ====================================================================
 * AYAR ŞEMASI
 * ====================================================================
 * Ayarlar sayfası bu şemadan otomatik oluşur; her alanın varsayılanı
 * buradadır. Kodda site_setting('anahtar') ile okunur (boşsa varsayılan).
 *
 * Alan: [tip, etiket, varsayılan, açıklama, ek]
 *   tip: text | textarea | email | url | color | number | select | bool | image | points | password
 *   ek : number için [min, max, birim], select için seçenekler,
 *        image için 'logo' | 'favicon' | 'photo', points için satır sayısı
 */

const SETTINGS_SCHEMA = [
    'brand' => [
        'title' => 'Marka ve görünüm', 'icon' => 'palette',
        'desc'  => 'Logo, favicon, renkler ve tüm sayfalarda görünen marka adı.',
        'fields' => [
            'company_brand_name'   => ['text', 'Marka adı', 'RY Medya', 'Kenar çubuğu, giriş sayfaları ve portal üst barında görünür.'],
            'brand_mark_text'      => ['text', 'Simge kısaltması', 'RY', 'Logo yüklenmediğinde renkli kare simgenin içindeki 1-3 harf.'],
            'brand_tagline'        => ['text', 'Alt başlık', 'Prodüksiyon yönetimi', 'Personel panelinde marka adının altında görünür.'],
            'brand_logo'           => ['image', 'Logo (açık zemin)', '', 'Portal üst barı, mobil giriş ekranı ve yazdırılan belgelerde kullanılır. PNG, SVG veya WEBP; yatay logo önerilir.', 'logo'],
            'brand_logo_dark'      => ['image', 'Logo (koyu zemin)', '', 'Personel kenar çubuğu ve giriş sayfasının koyu panelinde kullanılır. Boşsa açık zemin logosu kullanılır.', 'logo'],
            'brand_logo_height'    => ['number', 'Logo yüksekliği', '28', '', [16, 72, 'px']],
            'brand_logo_with_name' => ['bool', 'Logonun yanında marka adını da göster', '0', 'Logo yalnızca simgeyse açın.'],
            'brand_favicon'        => ['image', 'Favicon', '', 'Tarayıcı sekmesindeki simge. Kare PNG veya ICO (en az 64×64). Boşsa vurgu renginde simge oluşturulur.', 'favicon'],
            'brand_accent_color'   => ['color', 'Vurgu rengi', '#D2462F', 'Ana düğmeler, aktif menü çizgisi, bildirim rozeti ve adım çubuğu.'],
            'brand_sidebar_color'  => ['color', 'Koyu panel rengi', '#121214', 'Personel kenar çubuğu ve giriş sayfalarının sol paneli.'],
            'brand_title_suffix'   => ['text', 'Tarayıcı sekmesi son eki', 'RY Medya', '"Sayfa adı · …" biçiminde sekme başlığının sonuna eklenir.'],
            'brand_footer_text'    => ['text', 'Alt bilgi metni', '', 'Boşsa "© yıl şirket ünvanı" yazılır.'],
            'portal_footer_tagline'=> ['text', 'Portal alt bilgi sağ metni', 'Prodüksiyon platformu', 'Ajans ve freelancer portalının en altındaki sağ metin.'],
        ],
    ],
    'company' => [
        'title' => 'Şirket ve künye', 'icon' => 'building-2',
        'desc'  => 'Faturalarda, ekstrelerde ve call sheet\'lerde görünen resmi bilgiler.',
        'fields' => [
            'company_name'           => ['text', 'Resmi ünvan', 'RY Medya Prodüksiyon A.Ş.', ''],
            'company_email'          => ['email', 'E-posta', '', ''],
            'company_phone'          => ['text', 'Telefon', '', ''],
            'company_website'        => ['url', 'Web sitesi', '', ''],
            'company_address'        => ['textarea', 'Adres', '', ''],
            'company_city'           => ['text', 'Şehir', '', ''],
            'company_tax_office'     => ['text', 'Vergi dairesi', '', ''],
            'company_tax_number'     => ['text', 'Vergi numarası', '', ''],
            'company_trade_registry' => ['text', 'Ticaret sicil / MERSİS no', '', ''],
        ],
    ],
    'bank' => [
        'title' => 'Banka ve ödeme', 'icon' => 'landmark',
        'desc'  => 'Müşteri portalında ve faturalarda gösterilen ödeme bilgileri.',
        'fields' => [
            'bank_primary_name'     => ['text', 'Ana banka', '', ''],
            'bank_primary_receiver' => ['text', 'Alıcı adı', '', 'Boşsa resmi ünvan kullanılır.'],
            'bank_primary_iban'     => ['text', 'Ana IBAN', '', ''],
            'bank_secondary_name'   => ['text', 'İkinci banka', '', ''],
            'bank_secondary_iban'   => ['text', 'İkinci IBAN', '', ''],
            'bank_payment_note'     => ['textarea', 'Ödeme notu', 'Ödemelerinizde açıklama kısmına lütfen fatura veya proje kodunuzu yazınız.', 'Müşteri portalındaki ve ajans Ödemeler ekranındaki ödeme kutusunda görünür.'],
            'iyzico_enabled'        => ['bool', 'iyzico ile kartla ödeme', '0', 'Açıkken ajanslar açık faturalarını Ödemeler ekranından kredi/banka kartıyla öder; tahsilat otomatik olarak faturaya işlenir. Tahsilatın işleneceği hesap: Platform kuralları → Ödemeler.'],
            'iyzico_mode'           => ['select', 'iyzico ortamı', 'sandbox', 'Anahtar girildiğinde ortam anahtardan otomatik belirlenir ("sandbox-" ile başlayan anahtar test, diğerleri canlı); bu seçim yalnızca anahtar yokken kullanılır.', ['sandbox' => 'Test (sandbox)', 'live' => 'Canlı']],
            'iyzico_api_key'        => ['text', 'iyzico API anahtarı', '', 'iyzico üye işyeri paneli → Ayarlar → Firma Ayarları → API Anahtarları.'],
            'iyzico_secret_key'     => ['password', 'iyzico güvenlik anahtarı', '', 'Şifreli saklanır. Boş bırakırsanız kayıtlı anahtar korunur.'],
            'iyzico_installments'   => ['text', 'İzin verilen taksitler', '1', 'Virgülle: 1, 2, 3, 6, 9, 12. Taksit vade farkı iyzico panelindeki ayarlarınıza göre müşteriye yansır.'],
        ],
    ],
    'finance' => [
        'title' => 'Finans ve kodlar', 'icon' => 'calculator',
        'desc'  => 'Varsayılan para birimi, vergi oranları ve belge numarası önekleri.',
        'fields' => [
            'default_currency'   => ['select', 'Varsayılan para birimi', 'TRY', '', 'CURRENCIES'],
            'default_vat_rate'   => ['number', 'Varsayılan KDV oranı', '20', '', [0, 100, '%']],
            'corporate_tax_rate' => ['number', 'Kurumlar vergisi oranı', '25', 'Vergi ekranındaki tahmin hesabında kullanılır.', [0, 100, '%']],
            'invoice_prefix'     => ['text', 'Fatura numarası öneki', 'RYM-', ''],
            'project_prefix'     => ['text', 'Proje kodu öneki', 'PRJ-', ''],
        ],
    ],
    'login_staff' => [
        'title' => 'Personel giriş sayfası', 'icon' => 'log-in',
        'desc'  => 'Yıldızla çevrelenen kelimeler (*böyle*) vurgu renginde görünür.',
        'preview' => '/modules/auth/login.php',
        'fields' => [
            'login_staff_title'            => ['text', 'Başlık', 'Personel girişi', ''],
            'login_staff_subtitle'         => ['text', 'Alt metin', 'Ekip hesabınızla giriş yapın.', ''],
            'login_staff_email_placeholder'=> ['text', 'E-posta alanı örneği', 'ad@rymedya.com.tr', ''],
            'login_staff_eyebrow'          => ['text', 'Sol panel üst etiketi', 'Ekip paneli', ''],
            'login_staff_quote'            => ['textarea', 'Sol panel sloganı', 'Tekliften teslime kadar her işin *tek bir yerde* takibi.', ''],
            'login_staff_points'           => ['points', 'Sol panel maddeleri', [
                ['clapperboard', 'Projeler ve setler.', 'Çekim günleri, ekip maliyeti, kurgu revizyonları.'],
                ['inbox', 'İş platformu.', 'Ajans işleri, freelancer ataması, kalite kontrol.'],
                ['landmark', 'Finans.', 'Fatura, tahsilat, kasa ve raporlar.'],
            ], 'Simge adları lucide.dev/icons listesinden yazılır (ör. camera, film, users).', 3],
            'login_staff_show_portal'      => ['bool', 'Portal giriş butonunu göster', '1', ''],
            'login_staff_portal_question'  => ['text', 'Portal butonu üstündeki soru', 'Müşteri, ajans veya freelancer mısınız?', ''],
            'login_staff_portal_button'    => ['text', 'Portal butonu metni', 'Müşteri / Ajans / Freelancer Girişi', ''],
            'login_aside_image'            => ['image', 'Sol panel arka plan görseli', '', 'Personel ve portal giriş sayfalarının koyu panelinde, karartılmış olarak gösterilir. Yatay fotoğraf önerilir.', 'photo'],
        ],
    ],
    'login_portal' => [
        'title' => 'Portal giriş sayfası', 'icon' => 'door-open',
        'desc'  => 'Müşteri, ajans ve freelancer\'ların ortak giriş sayfası. *Yıldız* vurgu yapar.',
        'preview' => '/client/login.php',
        'fields' => [
            'login_portal_title'            => ['text', 'Başlık', 'Portal girişi', ''],
            'login_portal_subtitle'         => ['text', 'Alt metin', 'Müşteri, ajans ve freelancer hesapları için ortak giriş.', ''],
            'login_portal_email_placeholder'=> ['text', 'E-posta alanı örneği', 'ornek@sirket.com', ''],
            'login_portal_eyebrow'          => ['text', 'Sol panel üst etiketi', 'Prodüksiyon platformu', ''],
            'login_portal_quote'            => ['textarea', 'Sol panel sloganı', 'İşi gönderin, ekibi biz kuralım. *Teslime kadar* her adımı buradan izleyin.', ''],
            'login_portal_points'           => ['points', 'Sol panel maddeleri', [
                ['building-2', 'Ajanslar', 'hizmetleri seçip anında fiyat görür, işini tek adımda girer.'],
                ['users-round', 'Freelancer\'lar', 'seviyelerine uygun işleri alır, teslim eder, kazancını takip eder.'],
                ['film', 'Müşteriler', 'projelerini, kurgu versiyonlarını ve faturalarını görür.'],
            ], '', 3],
            'login_portal_signup_question'  => ['text', 'Kayıt kartları üstündeki soru', 'Henüz hesabınız yok mu?', ''],
            'login_portal_agency_card'      => ['text', 'Ajans kartı başlığı', 'Ajans Kaydı', ''],
            'login_portal_agency_hint'      => ['text', 'Ajans kartı alt metni', 'İş yaptırmak istiyorum', ''],
            'login_portal_freelancer_card'  => ['text', 'Freelancer kartı başlığı', 'Freelancer Başvurusu', ''],
            'login_portal_freelancer_hint'  => ['text', 'Freelancer kartı alt metni', 'İş almak istiyorum', ''],
            'login_portal_show_staff_link'  => ['bool', 'Personel girişi bağlantısını göster', '1', ''],
            'login_portal_staff_link'       => ['text', 'Personel girişi bağlantı metni', 'Personel girişi', ''],
        ],
    ],
    'register' => [
        'title' => 'Kayıt sayfaları', 'icon' => 'user-plus',
        'desc'  => 'Ajans kaydı ve freelancer başvurusu sayfalarının metinleri.',
        'preview' => '/platform/register.php?type=agency',
        'fields' => [
            'register_agency_title'       => ['text', 'Ajans · başlık', 'Ajans hesabı oluşturun', ''],
            'register_agency_eyebrow'     => ['text', 'Ajans · sol panel etiketi', 'Ajanslar için', ''],
            'register_agency_quote'       => ['textarea', 'Ajans · slogan', 'Fiyat listesinden seçin, *anında* iş girin. Gerisini ekip halleder.', ''],
            'register_agency_points'      => ['points', 'Ajans · maddeler', [
                ['tags', 'Şeffaf fiyat.', 'Hizmet kataloğundan seçtiğiniz anda toplam tutarı görürsünüz.'],
                ['shield-check', 'Kalite kontrol.', 'Her teslimat size ulaşmadan önce ekibimiz tarafından incelenir.'],
                ['receipt-text', 'Tek fatura.', 'İş kapandığında faturanız otomatik oluşur.'],
            ], '', 3],
            'register_freelancer_title'   => ['text', 'Freelancer · başlık', 'Freelancer olarak başvurun', ''],
            'register_freelancer_eyebrow' => ['text', 'Freelancer · sol panel etiketi', 'Freelancer\'lar için', ''],
            'register_freelancer_quote'   => ['textarea', 'Freelancer · slogan', 'Uzmanlığınıza uygun prodüksiyon işleri. *Performansınız* seviyenizi belirler.', ''],
            'register_freelancer_points'  => ['points', 'Freelancer · maddeler', [
                ['radar', 'Size uygun işler.', 'Uzmanlık alanınız, şehriniz ve seviyenize göre filtrelenir.'],
                ['gauge', 'Performans karnesi.', 'Zamanında teslim ve puanlarınız seviyenizi yükseltir.'],
                ['wallet', 'Net hakediş.', 'Her işin ücreti iş açılırken bellidir.'],
            ], '', 3],
            'register_note'               => ['text', 'Başlık altı not', 'Başvurunuz ekibimiz tarafından incelendikten sonra hesabınız aktifleşir.', ''],
            'register_consent_text'       => ['textarea', 'Onay kutusu metni', 'Kullanım koşullarını ve KVKK aydınlatma metnini okudum; bilgilerimin iş eşleştirme amacıyla işlenmesini kabul ediyorum.', ''],
            'register_newsletter_text'    => ['text', 'Bülten onay kutusu metni', 'Duyuru, kampanya ve yeni iş fırsatlarıyla ilgili e-posta almak istiyorum.', 'İşaretleyen kişi toplu e-posta listesine "ticari ileti izni var" olarak eklenir.'],
            'register_terms_url'          => ['url', 'Kullanım koşulları / KVKK bağlantısı', '', 'Girilirse onay kutusunun yanında "metni oku" bağlantısı çıkar.'],
            'register_closed_text'        => ['text', 'Kayıt kapalıyken gösterilen metin', 'Bu kayıt türü şu an yeni başvurulara kapalı.', ''],
        ],
    ],
    'portal' => [
        'title' => 'Portal ve iletişim', 'icon' => 'messages-square',
        'desc'  => 'Müşteri / ajans / freelancer portalında görünen iletişim bilgileri ve ekip adı.',
        'fields' => [
            'portal_support_email' => ['email', 'Destek e-postası', 'info@rymedya.com.tr', 'Müşteri portalında gösterilir.'],
            'portal_support_phone' => ['text', 'Destek telefonu', '', ''],
            'client_portal_label'  => ['text', 'Müşteri portalı etiketi', 'Müşteri Portalı', 'Müşteri portalının üst barında marka adının altında görünür.'],
            'platform_team_name'   => ['text', 'Ekip adı', 'RY Medya Ekibi', 'Ajans ve freelancer\'lara gönderilen mesajlarda ve iş sayfalarında görünür.'],
        ],
    ],
    'mail' => [
        'title' => 'E-posta', 'icon' => 'mail',
        'desc'  => 'Gönderim sunucusu, gönderen bilgileri ve hangi bildirimlerin e-postayla gideceği.',
        'fields' => [
            'mail_enabled'          => ['bool', 'E-posta gönderimi açık', '0', 'Kapalıyken hiçbir e-posta gönderilmez; bildirimler yalnızca portal içinde görünür.'],
            'mail_transport'        => ['select', 'Gönderim yöntemi', 'mail', 'Hosting firmanız SMTP bilgisi veriyorsa SMTP önerilir (spam klasörüne düşme riski azalır).', ['mail' => 'Sunucunun mail() işlevi', 'smtp' => 'SMTP sunucusu']],
            'smtp_host'             => ['text', 'SMTP sunucusu', '', 'Ör. mail.rymedya.com.tr, smtp.gmail.com, smtp.yandex.com.tr'],
            'smtp_port'             => ['number', 'SMTP portu', '587', 'TLS için genellikle 587, SSL için 465.', [1, 65535, 'port']],
            'smtp_secure'           => ['select', 'Şifreleme', 'tls', '', ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'Yok']],
            'smtp_user'             => ['text', 'SMTP kullanıcı adı', '', 'Genellikle e-posta adresinin tamamı.'],
            'smtp_pass'             => ['password', 'SMTP şifresi', '', 'Şifreli saklanır. Boş bırakırsanız kayıtlı şifre korunur.'],
            'smtp_verify_ssl'       => ['bool', 'SSL sertifikasını doğrula', '1', 'Sunucu kendinden imzalı sertifika kullanıyorsa kapatın.'],
            'mail_from_email'       => ['email', 'Gönderen adresi', '', 'Boşsa şirket e-postası kullanılır. SMTP kullanıcısıyla aynı alan adında olmalıdır.'],
            'mail_from_name'        => ['text', 'Gönderen adı', '', 'Boşsa marka adı kullanılır.'],
            'mail_reply_to'         => ['email', 'Yanıt adresi', '', 'Alıcı "yanıtla" dediğinde bu adrese yazar.'],
            'mail_subject_prefix'   => ['text', 'Bildirim konu öneki', '', 'Ör. [RY Medya]. Bildirim e-postalarının konusunun başına eklenir.'],
            'mail_button_text'      => ['text', 'Bildirim butonu metni', 'Görüntüle', ''],
            'mail_footer_text'      => ['textarea', 'E-posta alt bilgisi', 'Bu e-posta, hesabınızla ilgili bir gelişme olduğu için gönderilmiştir.', 'Tüm e-postaların altında görünür.'],
            'mail_notify_portal'    => ['bool', 'Ajans, freelancer ve müşterilere iş bildirimleri', '1', 'İş onayı, atama, teslim, revizyon, teklif sonucu, seviye değişikliği, ödeme, yeni kurgu versiyonu, fatura gibi bildirimler.'],
            'mail_notify_staff'     => ['bool', 'Personele kendisine atanan işler', '1', 'Görev ataması, kurgu ataması, müşteri revizyon talebi gibi kişiye özel bildirimler.'],
            'mail_staff_inbox'      => ['email', 'Ekip gelen kutusu', '', 'Doluysa platformdaki tüm ekip bildirimlerinin (yeni iş, teklif, teslim) kopyası bu adrese gider.'],
            'mail_register_confirm' => ['bool', 'Kayıt olana "başvurunuz alındı" e-postası', '1', ''],
            'mail_batch_size'       => ['number', 'Toplu gönderimde parti büyüklüğü', '20', 'Her adımda gönderilecek e-posta sayısı. Hosting saatlik limitine göre ayarlayın.', [1, 200, 'e-posta']],
        ],
    ],
    'security' => [
        'title' => 'Güvenlik', 'icon' => 'shield-check', 'admin_only' => true,
        'desc'  => 'İki adımlı doğrulama, oturum süresi, şifre sıfırlama, giriş uyarıları ve otomatik yedek. Yalnızca süper yönetici görür ve değiştirir.',
        'fields' => [
            'security_2fa_staff'       => ['select', 'Personel için iki adımlı doğrulama', 'optional', 'Kapalı: hiç sorulmaz. İsteğe bağlı: kullanıcı profilinden açabilir. Zorunlu: kurmayan personel ilk girişte kuruluma yönlendirilir.', ['off' => 'Kapalı', 'optional' => 'İsteğe bağlı', 'required' => 'Zorunlu']],
            'security_2fa_portal'      => ['select', 'Ajans, freelancer ve müşteriler için iki adımlı doğrulama', 'optional', 'Portal hesapları için aynı seçenekler.', ['off' => 'Kapalı', 'optional' => 'İsteğe bağlı', 'required' => 'Zorunlu']],
            'security_idle_staff'      => ['number', 'Personel hareketsizlik süresi', '120', 'Bu süre boyunca işlem yapılmazsa oturum kapanır. 0 ise kapanmaz.', [0, 1440, 'dakika']],
            'security_idle_portal'     => ['number', 'Portal hareketsizlik süresi', '240', 'Ajans, freelancer ve müşteri oturumları için. 0 ise kapanmaz.', [0, 1440, 'dakika']],
            'security_new_device_mail' => ['bool', 'Yeni cihazdan girişte e-posta uyarısı', '1', 'Kullanıcı daha önce kullanmadığı bir tarayıcı/ağdan girdiğinde kendisine e-posta gider.'],
            'security_reset_minutes'   => ['number', 'Şifre sıfırlama bağlantısının geçerlilik süresi', '60', '', [10, 1440, 'dakika']],
            'backup_enabled'           => ['bool', 'Günlük otomatik veritabanı yedeği', '1', 'cron/backup.php tanımlıysa o çalışır; değilse personel paneli açıldıkça günde bir yedek alınır.'],
            'backup_keep_days'         => ['number', 'Yedek saklama süresi', '14', 'Daha eski yedekler silinir; en son yedek her zaman kalır.', [1, 365, 'gün']],
            'backup_path'              => ['text', 'Yedek klasörü (isteğe bağlı)', '', 'Sunucudaki tam yol, ör. /home/KULLANICI/yedekler. Boşsa web kökünün bir üstündeki "rymedya-yedek" klasörü kullanılır.'],
        ],
    ],
    'documents' => [
        'title' => 'Belgeler', 'icon' => 'file-text',
        'desc'  => 'Fatura, ekstre ve call sheet çıktıları.',
        'fields' => [
            'doc_show_logo'          => ['bool', 'Belgelerde logoyu göster', '1', 'Açık zemin logosu kullanılır; yoksa marka adı yazılır.'],
            'doc_tagline'            => ['text', 'Belge başlığı altındaki slogan', 'Video Prodüksiyon & Reklam Hizmetleri', 'Fatura ve teklif çıktılarında şirket adının altında görünür.'],
            'doc_issuer_name'        => ['text', 'Faturada "Düzenleyen"', '', 'Boşsa resmi ünvan kullanılır.'],
            'invoice_footer_note'    => ['textarea', 'Fatura alt notu', '', 'Fatura çıktısının en altında görünür.'],
            'statement_footer_note'  => ['textarea', 'Ekstre alt notu', '', 'Cari ekstre çıktısının en altında görünür.'],
            'callsheet_default_notes'=> ['textarea', 'Call sheet varsayılan notu', 'Lütfen çağrı saatinden en geç 15 dakika önce sette hazır bulununuz.', ''],
        ],
    ],
];

/**
 * Ayar değeri: kayıtlıysa o, değilse şemadaki varsayılan
 */
function setting_default(string $key) {
    static $defaults = null;
    if ($defaults === null) {
        $defaults = [];
        foreach (SETTINGS_SCHEMA as $section) {
            foreach ($section['fields'] as $k => $f) {
                $defaults[$k] = $f[2];
            }
        }
    }
    return $defaults[$key] ?? '';
}

function site_setting(string $key): string {
    $def = setting_default($key);
    return get_setting($key, is_array($def) ? '' : (string)$def);
}

/**
 * Madde listesi (giriş sayfası sol panel): [[icon, başlık, metin], ...]
 */
function site_points(string $key): array {
    $raw = get_setting($key, '');
    $rows = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($rows)) {
        $rows = setting_default($key);
    }
    return array_values(array_filter((array)$rows, fn($r) => is_array($r) && trim(($r[1] ?? '') . ($r[2] ?? '')) !== ''));
}

/**
 * Metni kaçışlar; *vurgu* → <em>vurgu</em>
 */
function rich_text(string $s): string {
    return nl2br(preg_replace('/\*([^*\n]+)\*/u', '<em>$1</em>', e($s)));
}

/**
 * Yüklenen görselin tam adresi (yoksa boş)
 */
function site_image(string $key): string {
    $path = get_setting($key, '');
    if ($path === '' || !preg_match('#^assets/uploads/branding/[A-Za-z0-9._-]+$#', $path) || !is_file(__DIR__ . '/../' . $path)) {
        return '';
    }
    return BASE_URL . '/' . $path . '?v=' . filemtime(__DIR__ . '/../' . $path);
}

/**
 * Marka gösterimi (logo veya simge + ad). $on: 'dark' (koyu zemin) | 'light'
 * $sub: adın altındaki küçük metin (ör. "Ajans", "Prodüksiyon yönetimi")
 */
function brand_html(string $on = 'light', string $sub = ''): string {
    $logo = $on === 'dark' ? (site_image('brand_logo_dark') ?: site_image('brand_logo')) : (site_image('brand_logo') ?: site_image('brand_logo_dark'));
    $name = site_setting('company_brand_name');
    $h = max(16, min(72, (int)site_setting('brand_logo_height')));
    if ($logo !== '') {
        $mark = '<img src="' . e($logo) . '" alt="' . e($name) . '" style="height:' . $h . 'px;width:auto;max-width:190px;object-fit:contain;display:block">';
        $show_name = site_setting('brand_logo_with_name') === '1';
    } else {
        $mark = '<span class="brand-mark">' . e(mb_substr(site_setting('brand_mark_text'), 0, 3)) . '</span>';
        $show_name = true;
    }
    $text = '';
    if ($show_name || $sub !== '') {
        $text = '<span style="line-height:1.2;min-width:0;display:block">'
            . ($show_name ? '<span style="display:block;font-weight:600;font-size:13.5px;letter-spacing:-.01em;' . ($on === 'dark' ? 'color:#F4F4F5' : '') . '">' . e($name) . '</span>' : '')
            . ($sub !== '' ? '<span style="display:block;font-size:11px;color:' . ($on === 'dark' ? '#77767E' : 'var(--muted)') . '">' . e($sub) . '</span>' : '')
            . '</span>';
    }
    return '<span class="brand" style="display:inline-flex;align-items:center;gap:10px;min-width:0">' . $mark . $text . '</span>';
}

/**
 * Yazdırılan belgelerin sol üst simgesi: logo varsa logo, yoksa ikon kutusu
 */
function doc_logo_box(string $icon, string $bg = 'bg-slate-900'): string {
    $logo = site_setting('doc_show_logo') === '1' ? site_image('brand_logo') : '';
    if ($logo !== '') {
        return '<img src="' . e($logo) . '" alt="" style="height:48px;width:auto;max-width:180px;object-fit:contain">';
    }
    return '<div class="w-12 h-12 rounded-2xl ' . $bg . ' flex items-center justify-center text-white shadow-md"><i data-lucide="' . e($icon) . '" class="w-6 h-6"></i></div>';
}

/**
 * Hex rengi açar / koyulaştırır ($amount: -1..1)
 */
function color_shade(string $hex, float $amount): string {
    $hex = ltrim($hex, '#');
    $rgb = array_map('hexdec', str_split($hex, 2));
    foreach ($rgb as &$c) {
        $c = $amount < 0 ? $c * (1 + $amount) : $c + (255 - $c) * $amount;
        $c = max(0, min(255, (int)round($c)));
    }
    return sprintf('#%02X%02X%02X', ...$rgb);
}

function valid_hex_color(string $v): bool {
    return (bool)preg_match('/^#[0-9A-Fa-f]{6}$/', $v);
}

/**
 * Alt bilgi metni
 */
function site_footer_text(): string {
    $t = site_setting('brand_footer_text');
    return $t !== '' ? $t : '© ' . date('Y') . ' ' . site_setting('company_name');
}
