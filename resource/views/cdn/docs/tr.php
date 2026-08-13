<h1>Dokümantasyon</h1>
<p class="lead">
    Dosyayı yükle, adresini al. Boyutunu, biçimini ve kırpımını adresi değiştirerek iste.
    Panelin yaptığı her şeyi bir API anahtarı da yapabilir.
</p>

<section id="start">
    <h2>Hızlı başlangıç</h2>

    <ol class="steps">
        <li>
            <b>Hesap aç.</b> <a href="<?= route('auth-form') ?>">Kayıt ol</a> — projen, ilk bucket'ın ve kotan
            hazır gelir, ayarlanacak bir şey yok.
        </li>
        <li>
            <b>Dosya yükle.</b> Panelin <em>Overview</em> sayfasındaki kutuya sürükle, ya da kendi kodundan
            <a href="#api">API</a> ile gönder.
        </li>
        <li>
            <b>Adresi kullan.</b> Dosya sayfasındaki adresi kopyalayıp <code>&lt;img src&gt;</code> içine koy.
        </li>
        <li>
            <b>Küçüğünü iste.</b> Adresin sonuna <code>?w=400</code> ekle. İlk istekte üretilir, sonrakiler
            önbellekten gelir.
        </li>
    </ol>

    <div class="note">
        Ürünün tamamı bu. Aşağısı, ihtiyaç duyduğunda bakacağın ayrıntı.
    </div>
</section>

<section id="urls">
    <h2>Adresler</h2>

    <pre><code><?= $host . $prefix ?>/&lt;bucket&gt;/&lt;yol&gt;</code></pre>

    <p>
        İlk parça bucket'ın adı, kalanı dosyanın bucket içindeki yolu. Örnek:
    </p>

    <pre><code><?= $host . $prefix ?>/fotograflar/2026/kapak.jpg</code></pre>

    <h3>Bir istek ne alır</h3>

    <table class="table">
        <thead><tr><th>Ne</th><th>Ne işe yarar</th></tr></thead>
        <tbody>
            <tr>
                <td><code>ETag</code></td>
                <td>İçerikten türetilir. Aynı baytlar hep aynı damgayı verir, yani aynı dosyayı yeniden
                    yüklemen kimseye tekrar indirtmez.</td>
            </tr>
            <tr>
                <td><code>304</code></td>
                <td>Tarayıcıda kopyası varsa gövde gönderilmez. Diske dokunulmadan yanıtlanır.</td>
            </tr>
            <tr>
                <td><code>206</code></td>
                <td><code>Range</code> desteği: videoda ileri sarma ve yarıda kalan indirmenin devamı.</td>
            </tr>
            <tr>
                <td><code>HEAD</code></td>
                <td>Gövdesiz başlıklar — önbellekler ve indirme yöneticileri önce bunu sorar.</td>
            </tr>
            <tr>
                <td>CORS</td>
                <td>Bucket başına. Varsayılan <code>*</code>, ya da izin verilen kaynağın birebir yankısı.</td>
            </tr>
            <tr>
                <td>gzip</td>
                <td>Sıkıştırılmamış metin türleri için, 4 MB altında.</td>
            </tr>
        </tbody>
    </table>

    <p>
        Yanıtlarda <code>X-Cdn-Cache: HIT|MISS</code> başlığı var — istek önbellekten mi karşılandı,
        yoksa üretildi mi görebilirsin.
    </p>

    <h3>İndirmeye zorlama</h3>
    <pre><code><?= $host . $prefix ?>/dosyalar/rapor.pdf?download=1</code></pre>
</section>

<section id="images">
    <h2>Görsel boyutları</h2>

    <p>
        Görsel dosyalarda adrese parametre ekleyerek istediğin boyutu istersin. Her kombinasyon
        <b>bir kez</b> üretilir, sonra önbellekten servis edilir. Sen ikinci bir kopya saklamazsın.
    </p>

    <pre><code>?w=800                    genişlik; yükseklik orana göre
?h=400
?w=400&amp;h=400&amp;fit=cover    cover | contain | fill | pad
?q=70                     kalite, 1-100
?format=webp              webp | avif | jpg | png | gif
?dpr=2                    w/h değerini çarpar, en fazla 4
?crop=x,y,w,h             yeniden boyutlamadan önce kırpar
?rotate=90                90 | 180 | 270, saat yönünde
?flip=h                   h | v | both
?blur=40                  0-100
?sharpen=30               0-100
?gray=1                   gri tonlama
?bg=ffffff                pad için arka plan, şeffaflığı düzleştirir
?p=thumb                  hazır boyut</code></pre>

    <h3>Sığdırma biçimleri</h3>

    <table class="table">
        <thead><tr><th>Değer</th><th>Davranış</th></tr></thead>
        <tbody>
            <tr><td><code>contain</code></td><td>Kutuya sığdırır, oranı korur. Varsayılan.</td></tr>
            <tr><td><code>cover</code></td><td>İstenen orana göre kırpar, sonra ölçekler. Kare avatar için budur.</td></tr>
            <tr><td><code>pad</code></td><td>Sığdırır, kalan yeri <code>bg</code> rengiyle doldurur.</td></tr>
            <tr><td><code>fill</code></td><td>Tam istenen ölçüye esnetir, oranı bozar.</td></tr>
        </tbody>
    </table>

    <div class="note">
        <b>Asla büyütmez.</b> 300px'lik bir görselden 2000px istersen 300px alırsın. Saklanan pikselleri
        büyütmek daha büyük ve daha kötü görünen bir dosya üretir, ve bu neredeyse her zaman adreste
        yapılmış bir hatadır.
    </div>

    <h3>Otomatik biçim</h3>
    <p>
        <code>format</code> vermezsen tarayıcının <code>Accept</code> başlığına bakılır: avif okuyabiliyorsa
        avif, webp okuyabiliyorsa webp, ikisi de yoksa olduğu gibi. Yanıt bu durumda
        <code>Vary: Accept</code> taşır.
    </p>

    <h3>Hazır boyutlar</h3>
    <p>
        <code>?p=thumb</code> gibi adlandırılmış boyutlar sunucu tarafında tanımlıdır. Tavsiye edilen kullanım
        budur: yarın tasarım değişince tek yerden değiştirirsin, sayfalardaki adresler aynı kalır.
    </p>
    <pre><code><?= $host . $prefix ?>/fotograflar/kapak.jpg?p=thumb</code></pre>

    <h3>Duyarlı görsel</h3>
    <pre><code>&lt;img src="<?= $host . $prefix ?>/fotograflar/kapak.jpg?w=600&amp;fit=cover"
     srcset="<?= $host . $prefix ?>/fotograflar/kapak.jpg?w=600 600w,
             <?= $host . $prefix ?>/fotograflar/kapak.jpg?w=1200 1200w"
     sizes="(max-width: 600px) 100vw, 600px" alt=""&gt;</code></pre>
</section>

<section id="buckets">
    <h2>Bucket'lar</h2>

    <p>
        Bucket, kuralları olan bir klasör. Adı servis ettiği her adresin ilk parçası olduğu için tüm
        kurulumda benzersizdir.
    </p>

    <table class="table">
        <thead><tr><th>Ayar</th><th>Anlamı</th></tr></thead>
        <tbody>
            <tr>
                <td>Görünürlük</td>
                <td>
                    <b>Herkese açık</b> — adresi bilen açar.<br>
                    <b>İmzalı</b> — geçerli ve süreli imza gerekir.<br>
                    <b>Kapalı</b> — herkese açık kapısı yoktur, sadece API.
                </td>
            </tr>
            <tr>
                <td>Önbellek süresi</td>
                <td>Tarayıcının kopyayı ne kadar saklayacağı. Uzun süre daha hızlı ve ucuzdur, ama
                    <b>tarayıcıdaki kopyaya sonradan ulaşamazsın</b>.</td>
            </tr>
            <tr>
                <td>Görsel boyutlandırma</td>
                <td><code>?w=400</code> ve arkadaşlarının çalışıp çalışmayacağı.</td>
            </tr>
            <tr>
                <td>Hotlink koruması</td>
                <td>Hangi sitelerin senin dosyalarını gömebileceği. Bant genişliğin başkasının sayfasına
                    gitmesin diye.</td>
            </tr>
            <tr>
                <td>İzin verilen türler</td>
                <td>Uzantı ve içerik türü kısıtı. Boş bırakılırsa güvenlik kara listesi dışındaki her şey.</td>
            </tr>
            <tr>
                <td>Origin (aynalama)</td>
                <td>Adres verirsen, kimsenin yüklemediği bir dosya ilk istendiğinde oradan çekilir ve
                    buradan servis edilmeye başlanır.</td>
            </tr>
        </tbody>
    </table>

    <div class="note">
        <b>Önbellek süresini seçerken:</b> dosyaları aynı yol üzerine yazıyorsan kısa tut. Dosya adı
        içerikle birlikte değişiyorsa (<code>kapak.a1b2c3.jpg</code>) bir yıl yap ve <em>immutable</em>
        işaretle.
    </div>
</section>

<section id="signed">
    <h2>İmzalı adresler</h2>

    <p>
        İmzalı bir bucket, geçerli imza olmadan hiçbir şey servis etmez. İmza; yolu, son kullanma zamanını
        ve <b>bütün</b> sorgu parametrelerini kapsar — yani imzalı bir küçük görsel adresi düzenlenip
        imzalı bir 5000px isteğine dönüştürülemez.
    </p>

    <p>Sunucundan iste, imzalama anahtarı istemciye hiç gitmesin:</p>

    <pre><code>curl -X POST <?= $host . $api ?>/sign \
  -H "X-Cdn-Key: cdn_..." \
  -H "X-Cdn-Secret: ..." \
  -H "Content-Type: application/json" \
  -d '{"bucket":"faturalar","path":"2026-08.pdf","ttl":600}'</code></pre>

    <pre><code>{
  "ok": true,
  "url": "<?= $host . $prefix ?>/faturalar/2026-08.pdf?exp=1786000600&amp;sig=hK3...",
  "expires_at": "2026-08-13T03:20:00+03:00"
}</code></pre>

    <p>
        Süre dolduğunda adres 403 döner. Süreyi uzatmak imzayı geçersiz kılar; yeni bir imza almak gerekir.
    </p>
</section>

<section id="api">
    <h2>API</h2>

    <p>Taban adres:</p>
    <pre><code><?= $host . $api ?></code></pre>

    <h3>Kimlik doğrulama</h3>
    <p>
        Panelin <em>API keys</em> sayfasından anahtar oluştur. Gizli anahtar <b>bir kez</b> gösterilir;
        saklanmıyor, kaybedilirse iptal edip yenisini almak gerekir.
    </p>

    <pre><code>X-Cdn-Key: cdn_1a2b3c4d5e6f
X-Cdn-Secret: 9f8e7d...</code></pre>

    <p>Ya da isteği imzala, gizli anahtar hiç yola çıkmasın:</p>

    <pre><code>X-Cdn-Key:       cdn_1a2b3c4d5e6f
X-Cdn-Timestamp: 1786000000
X-Cdn-Signature: hmac-sha256( METHOD\nPATH\nsha256(gövde)\ntimestamp , gizli anahtar )</code></pre>

    <h3>Yetkiler</h3>
    <p>
        Bir anahtar sadece verilen işi yapar: <code>read</code>, <code>upload</code>, <code>delete</code>,
        <code>purge</code>. Hiçbiri seçilmezse sadece okur. Anahtar ayrıca tek bir bucket'a, belirli IP
        adreslerine kilitlenebilir ve son kullanma tarihi verilebilir.
    </p>

    <h3>Uç noktalar</h3>

    <table class="table">
        <thead><tr><th>Metod</th><th>Yol</th><th>Yetki</th><th>İş</th></tr></thead>
        <tbody>
            <tr><td>GET</td><td><code>/</code></td><td>—</td><td>Proje, kota, limitler</td></tr>
            <tr><td>GET</td><td><code>/buckets</code></td><td>read</td><td>Bucket listesi</td></tr>
            <tr><td>GET</td><td><code>/files</code></td><td>read</td><td>Dosya listesi</td></tr>
            <tr><td>GET</td><td><code>/files/{id}</code></td><td>read</td><td>Tek dosya</td></tr>
            <tr><td>POST</td><td><code>/files</code></td><td>upload</td><td>Yükle veya adresten çek</td></tr>
            <tr><td>DELETE</td><td><code>/files/{id}</code></td><td>delete</td><td>Id ile sil</td></tr>
            <tr><td>POST</td><td><code>/files/delete</code></td><td>delete</td><td>Bucket + yol ile sil</td></tr>
            <tr><td>POST</td><td><code>/uploads</code></td><td>upload</td><td>Parçalı yükleme başlat</td></tr>
            <tr><td>PUT</td><td><code>/uploads/{id}?index=N</code></td><td>upload</td><td>Parça gönder</td></tr>
            <tr><td>POST</td><td><code>/uploads/{id}/complete</code></td><td>upload</td><td>Tamamla</td></tr>
            <tr><td>POST</td><td><code>/purge</code></td><td>purge</td><td>Üretilmiş boyutları temizle</td></tr>
            <tr><td>POST</td><td><code>/sign</code></td><td>read</td><td>İmzalı adres üret</td></tr>
            <tr><td>GET</td><td><code>/stats</code></td><td>read</td><td>Trafik</td></tr>
        </tbody>
    </table>

    <p>Her yanıtta <code>X-RateLimit-Limit</code> ve <code>X-RateLimit-Remaining</code> başlıkları bulunur.</p>
</section>

<section id="upload">
    <h2>Yükleme</h2>

    <h3>Dosya gönderme</h3>

    <pre><code>curl -X POST <?= $host . $api ?>/files \
  -H "X-Cdn-Key: cdn_..." \
  -H "X-Cdn-Secret: ..." \
  -F bucket=fotograflar \
  -F path=2026/kapak.jpg \
  -F file=@kapak.jpg</code></pre>

    <pre><code>{
  "ok": true,
  "files": [{
    "id": 41,
    "bucket": "fotograflar",
    "path": "2026/kapak.jpg",
    "mime": "image/jpeg",
    "size": 384022,
    "width": 3000, "height": 2000,
    "url": "<?= $host . $prefix ?>/fotograflar/2026/kapak.jpg"
  }],
  "errors": []
}</code></pre>

    <p>
        <code>path</code> zorunlu değil — vermezsen dosya <code>YYYY/AA/</code> altına, adı sadeleştirilerek
        konur. Birden fazla <code>file</code> alanı gönderip tek istekte birden fazla dosya yükleyebilirsin.
        <code>overwrite=0</code> aynı yolda dosya varsa üzerine yazmayı reddeder.
    </p>

    <h3>Adresten çekme</h3>
    <p>Dosyayı sunucu indirir. Sadece https, özel ağ adreslerine izin verilmez, boyut sınırı indirme sırasında uygulanır.</p>

    <pre><code>curl -X POST <?= $host . $api ?>/files \
  -H "X-Cdn-Key: cdn_..." -H "X-Cdn-Secret: ..." \
  -H "Content-Type: application/json" \
  -d '{"bucket":"fotograflar","url":"https://ornek.com/foto.jpg"}'</code></pre>

    <h3>Büyük dosyalar, parçalı</h3>
    <p>Kopan bağlantı bir parçaya mal olur, tüm yüklemeye değil.</p>

    <pre><code># 1. Oturum aç
curl -X POST <?= $host . $api ?>/uploads \
  -H "Content-Type: application/json" \
  -d '{"bucket":"video","path":"tanitim.mp4","name":"tanitim.mp4","size":734003200}'
# → {"ok":true,"upload":{"id":"5c33...","chunk_size":8388608}}

# 2. Her parçayı ham gövde olarak gönder
curl -X PUT "<?= $host . $api ?>/uploads/5c33.../?index=0" --data-binary @parca0

# 3. Tamamla
curl -X POST <?= $host . $api ?>/uploads/5c33.../complete</code></pre>

    <p>Tarayıcıdan aynı akış için hazır istemci:</p>

    <pre><code>&lt;script src="/assets/js/cdn-upload.js"&gt;&lt;/script&gt;
&lt;script&gt;
const cdn = new CdnUploader({
    endpoint: '<?= $api ?>', key: 'cdn_...', secret: '...', bucket: 'fotograflar'
});

cdn.upload(input.files[0], {
    onProgress: (gonderilen, toplam) =&gt; bar.value = gonderilen / toplam,
}).then(cevap =&gt; console.log(cevap.file.url));
&lt;/script&gt;</code></pre>

    <div class="note">
        Web sayfasına koyduğun anahtarı sayfayı açan herkes okuyabilir. Tarayıcıdan yükleme yapacaksan
        yalnızca <code>upload</code> yetkisi olan ve tek bucket'a kilitli bir anahtar üret — ya da daha
        iyisi, oturumu kendi sunucun açsın, tarayıcıya sadece yükleme id'si gitsin.
    </div>

    <h3>PHP'den</h3>

    <pre><code>&lt;?php
$curl = curl_init('<?= $host . $api ?>/files');

curl_setopt_array($curl, [
    CURLOPT_POST           =&gt; true,
    CURLOPT_RETURNTRANSFER =&gt; true,
    CURLOPT_HTTPHEADER     =&gt; ['X-Cdn-Key: cdn_...', 'X-Cdn-Secret: ...'],
    CURLOPT_POSTFIELDS     =&gt; [
        'bucket' =&gt; 'fotograflar',
        'path'   =&gt; 'urunler/' . $id . '.jpg',
        'file'   =&gt; new CURLFile($_FILES['foto']['tmp_name']),
    ],
]);

$cevap = json_decode(curl_exec($curl), true);
$adres  = $cevap['files'][0]['url'];</code></pre>

    <h3>Neler reddedilir</h3>
    <ul>
        <li>Sunucuda çalışabilen uzantılar — <code>.php</code>, <code>.exe</code> ve benzerleri. Çift uzantı
            da sayılır: <code>foto.php.jpg</code> reddedilir.</li>
        <li>İçeriği uzantısıyla uyuşmayan dosyalar; baytlara bakılır, beyanına değil.</li>
        <li>Bucket'ın izin verdiği tür listesi dışındakiler.</li>
        <li>Boyut sınırını veya depolama kotanı aşanlar.</li>
    </ul>
    <p>SVG dosyaları saklanmadan önce script, olay işleyici ve dış referanslarından temizlenir.</p>
</section>

<section id="purge">
    <h2>Önbellek temizleme</h2>

    <p>
        Temizleme, senin tarafında üretilmiş görsel boyutlarını siler ve sonraki isteklerin yenisini
        üretmesini sağlar.
    </p>

    <pre><code>curl -X POST <?= $host . $api ?>/purge \
  -H "Content-Type: application/json" \
  -d '{"bucket":"fotograflar","type":"prefix","target":"2026/"}'</code></pre>

    <p><code>type</code> değerleri: <code>bucket</code>, <code>prefix</code>, <code>path</code>, <code>tag</code>.</p>

    <div class="note">
        <b>Ulaşamadığı yer:</b> ziyaretçinin tarayıcısındaki ve önündeki önbelleklerdeki kopyalar. Oraya
        ne temizleme ne başka bir şey ulaşır — sadece adresin değişmesi ya da kısa bir önbellek süresi
        ulaşır. Yerine yazdığın dosyalar varsa bucket'ın önbellek süresini kısa tut.
    </div>
</section>

<section id="errors">
    <h2>Hatalar</h2>

    <p>Başarısız istekler doğru durum kodu ve makine tarafından okunabilir bir sebep döner:</p>

    <pre><code>{ "ok": false, "error": "extension-blocked" }</code></pre>

    <table class="table">
        <thead><tr><th>Kod</th><th>Anlamı</th></tr></thead>
        <tbody>
            <tr><td>401</td><td>Anahtar yok, tanınmıyor, iptal edilmiş veya süresi dolmuş</td></tr>
            <tr><td>403</td><td>Anahtarın bu yetkisi yok, ya da bucket bu anahtara ait değil</td></tr>
            <tr><td>404</td><td>Bucket veya dosya yok</td></tr>
            <tr><td>409 / 422</td><td>İstek anlaşıldı ve reddedildi — sebebi <code>error</code> alanında</td></tr>
            <tr><td>429</td><td>Hız sınırı; <code>Retry-After</code> ne zaman deneyeceğini söyler</td></tr>
            <tr><td>509</td><td>Aylık transfer kotan dolmuş</td></tr>
        </tbody>
    </table>

    <p>
        Servis tarafındaki reddedilen istekleri panelin <em>Activity</em> sayfasında sebebiyle birlikte
        görürsün — süresi dolmuş imza, engellenmiş referer, hız sınırı.
    </p>
</section>

<section id="cli">
    <h2>Komut satırı</h2>

    <p>Sunucuya erişimin varsa:</p>

    <pre><code>php cdn                                  # komutları listeler
php cdn setup                            # klasörler, ilk proje ve bucket
php cdn serve host=0.0.0.0 port=8080     # geliştirme sunucusu
php cdn import bucket=... from=...       # bir klasörü toplu aktar
php cdn key create name=deploy scopes=read,upload
php cdn sign bucket=... path=... ttl=3600
php cdn purge bucket=... prefix=...
php cdn gc                               # kullanılmayan dosyalar, süresi geçmiş yüklemeler
php cdn rollup                           # dünkü trafiği grafiklere işler
php cdn verify --fix                     # kayıtları diskle karşılaştırır, sayaçları düzeltir
php cdn stats days=7</code></pre>

    <p>Bakım işleri saatlik cron ile:</p>
    <pre><code>0 * * * * php /yol/cron/cdn.php</code></pre>

    <div class="note">
        Bu olmazsa: grafikler boş kalır, silinen dosyalar diskte yer kaplamaya devam eder ve istek kaydı
        sonsuza kadar büyür.
    </div>
</section>
