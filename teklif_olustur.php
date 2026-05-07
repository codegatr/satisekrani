<?php
ob_start();
require __DIR__ . '/inc/bootstrap.php';
require_login();
$kullanici = current_user();

$kur_usd = kur_get('USD');
$kur_eur = kur_get('EUR');
$vade_farki = (float)(ayar_get('aylik_vade_farki', '0.06'));
$kdv_orani  = (float)(ayar_get('kdv_orani', '0.20'));

$ana_gruplar = db()->query('SELECT * FROM tk_ana_gruplar ORDER BY sira')->fetchAll();
$firmalar    = db()->query('SELECT * FROM tk_firmalar WHERE aktif=1 ORDER BY ad')->fetchAll();

require __DIR__ . '/inc/header.php';
?>

<div class="dashboard">

    <!-- Üst bilgi: Döviz kurları -->
    <div class="kur-bar">
        <div class="kur-card kur-usd">
            <span class="lbl">USD/TRY</span>
            <span class="val"><?= num((float)$kur_usd['satis'], 4) ?></span>
            <small><?= $kur_usd['tarih'] ? date('d.m.Y H:i', strtotime($kur_usd['tarih'])) : '-' ?></small>
        </div>
        <div class="kur-card kur-eur">
            <span class="lbl">EUR/TRY</span>
            <span class="val"><?= num((float)$kur_eur['satis'], 4) ?></span>
            <small><?= $kur_eur['tarih'] ? date('d.m.Y H:i', strtotime($kur_eur['tarih'])) : '-' ?></small>
        </div>
        <div class="kur-card kur-vade">
            <span class="lbl">AYLIK VADE FARKI</span>
            <span class="val">%<?= num($vade_farki * 100, 1) ?></span>
            <small>KDV: %<?= num($kdv_orani * 100, 0) ?></small>
        </div>
        <button class="btn btn-sm btn-outline" onclick="kurGuncelle()">
            <span id="kurBtnTxt">↻ Kuru Yenile</span>
        </button>
    </div>

    <div class="layout-2col">

        <!-- SOL: Ürün arama / katalog -->
        <div class="col-katalog">
            <div class="panel">
                <div class="panel-head">
                    <h3>ÜRÜN ARAMA</h3>
                </div>
                <div class="panel-body">
                    <div class="filter-row">
                        <select id="fAnaGrup" class="form-control">
                            <option value="">— Tüm Ana Gruplar —</option>
                            <?php foreach ($ana_gruplar as $ag): ?>
                            <option value="<?= $ag['id'] ?>"><?= h($ag['ad']) ?></option>
                            <?php endforeach ?>
                        </select>

                        <select id="fIskontoGrup" class="form-control">
                            <option value="">— Tüm İskonto Grupları —</option>
                        </select>

                        <select id="fFirma" class="form-control">
                            <option value="">— Tüm Firmalar —</option>
                            <?php foreach ($firmalar as $f): ?>
                            <option value="<?= $f['id'] ?>"><?= h($f['ad']) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>

                    <div class="search-row">
                        <input type="text" id="fAra" class="form-control"
                               placeholder="Stok kodu veya ürün adı yazın... (örn: 40X40, KŞD-50, NPI 100, DKP)"
                               autocomplete="off">
                    </div>

                    <div id="aramaSonuc" class="results-list">
                        <div class="empty-state">
                            <span>Aramaya başlamak için yukarıdaki kutuya yazın</span>
                            <small>1.877 ürün arasından arama yapabilirsiniz</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SAĞ: Sepet & Hesaplama -->
        <div class="col-sepet">
            <div class="panel panel-cart">
                <div class="panel-head">
                    <h3>TEKLİF / SEPET</h3>
                    <span class="badge" id="sepetSayi">0</span>
                </div>
                <div class="panel-body">

                    <div class="musteri-info">
                        <input type="text" id="musteriAd" class="form-control"
                               placeholder="Müşteri adı (opsiyonel)">
                        <input type="text" id="musteriTel" class="form-control"
                               placeholder="Müşteri telefonu (opsiyonel)">
                    </div>

                    <div id="sepetListe" class="cart-items">
                        <div class="empty-state small">
                            Henüz ürün eklenmedi
                        </div>
                    </div>

                    <div class="cart-options">
                        <div class="opt-row">
                            <label>Vade Süresi (Ay):</label>
                            <input type="number" id="vadeAy" class="form-control"
                                   value="0" min="0" max="24" step="1" onchange="hesapla()">
                        </div>
                        <div class="opt-row">
                            <label>
                                <input type="checkbox" id="kdvDahil" checked onchange="hesapla()">
                                KDV Dahil Göster
                            </label>
                        </div>
                    </div>

                    <div class="cart-totals">
                        <div class="total-row">
                            <span>Ara Toplam:</span>
                            <span id="araToplam">0,00 ₺</span>
                        </div>
                        <div class="total-row">
                            <span>Vade Farkı:</span>
                            <span id="vadeFarki">0,00 ₺</span>
                        </div>
                        <div class="total-row" id="kdvSatir">
                            <span>KDV (%<?= num($kdv_orani*100, 0) ?>):</span>
                            <span id="kdvTutar">0,00 ₺</span>
                        </div>
                        <div class="total-row total-grand">
                            <span>GENEL TOPLAM:</span>
                            <span id="genelToplam">0,00 ₺</span>
                        </div>
                    </div>

                    <div class="cart-actions">
                        <button class="btn btn-success btn-block" onclick="teklifKaydet()">
                            ✓ TEKLİF KAYDET
                        </button>
                        <button class="btn btn-outline btn-block" onclick="sepetTemizle()">
                            ✗ Sepeti Temizle
                        </button>
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>

<script>
const KDV_ORANI  = <?= $kdv_orani ?>;
const VADE_FARKI = <?= $vade_farki ?>;
let sepet = [];
let aramaTimer = null;

// === Arama ===
document.getElementById('fAra').addEventListener('input', function() {
    clearTimeout(aramaTimer);
    aramaTimer = setTimeout(araYap, 300);
});
document.getElementById('fAnaGrup').addEventListener('change', function() {
    iskontoGrupYukle(this.value);
    araYap();
});
document.getElementById('fIskontoGrup').addEventListener('change', araYap);
document.getElementById('fFirma').addEventListener('change', araYap);

function iskontoGrupYukle(anaGrupId) {
    const sel = document.getElementById('fIskontoGrup');
    sel.innerHTML = '<option value="">— Yükleniyor... —</option>';
    fetch('/api/iskonto_gruplar.php?ana_grup=' + encodeURIComponent(anaGrupId))
        .then(r => r.json())
        .then(d => {
            sel.innerHTML = '<option value="">— Tüm İskonto Grupları —</option>';
            (d.gruplar || []).forEach(g => {
                sel.innerHTML += `<option value="${g.id}">${escapeHtml(g.ad)}</option>`;
            });
        });
}

function araYap() {
    const q  = document.getElementById('fAra').value;
    const ag = document.getElementById('fAnaGrup').value;
    const ig = document.getElementById('fIskontoGrup').value;
    const fr = document.getElementById('fFirma').value;

    const div = document.getElementById('aramaSonuc');
    div.innerHTML = '<div class="loading">Aranıyor...</div>';

    const url = '/api/ara.php?' + new URLSearchParams({
        q: q, ana_grup: ag, iskonto_grup: ig, firma: fr
    });

    fetch(url).then(r => r.json()).then(d => {
        if (!d.ok) {
            div.innerHTML = '<div class="empty-state">Hata: ' + escapeHtml(d.hata || '') + '</div>';
            return;
        }
        if (d.stoklar.length === 0) {
            div.innerHTML = '<div class="empty-state">Sonuç bulunamadı</div>';
            return;
        }
        let html = '<div class="results-info">' + d.toplam + ' ürün bulundu' +
                   (d.toplam > d.stoklar.length ? ' (ilk ' + d.stoklar.length + ' gösteriliyor)' : '') + '</div>';
        d.stoklar.forEach(s => {
            const fiy = parseFloat(s.iskontolu_fiyat);
            html += `
            <div class="prod-card">
                <div class="prod-info">
                    <div class="prod-name">${escapeHtml(s.ad)}</div>
                    <div class="prod-meta">
                        <span class="badge-firma">${escapeHtml(s.firma_ad)}</span>
                        <span class="badge-grup">${escapeHtml(s.iskonto_grup_ad)}</span>
                        ${s.iskonto > 0 ? '<span class="badge-isk">İsk: %' + (s.iskonto*100).toFixed(0) + '</span>' : ''}
                    </div>
                </div>
                <div class="prod-price">
                    <div class="price-main">${formatTL(fiy)} <small>/ ${escapeHtml(s.birim)}</small></div>
                    ${s.boy_fiyati > 0 ? '<div class="price-sub">Boy: ' + formatTL(s.boy_fiyati * (1 - s.iskonto)) + '</div>' : ''}
                </div>
                <div class="prod-action">
                    <input type="number" class="form-control miktar-inp" 
                           id="miktar-${s.id}" value="1" min="0.001" step="0.001">
                    <button class="btn btn-primary btn-sm" onclick="urunEkle(${s.id})">+ EKLE</button>
                </div>
            </div>`;
        });
        div.innerHTML = html;
    });
}

function urunEkle(stokId) {
    const miktar = parseFloat(document.getElementById('miktar-' + stokId).value || '0');
    if (miktar <= 0) {
        alert('Geçerli bir miktar girin');
        return;
    }
    fetch('/api/stok_detay.php?id=' + stokId).then(r => r.json()).then(s => {
        if (!s.ok) { alert(s.hata || 'Hata'); return; }
        const d = s.stok;
        const fiy = parseFloat(d.iskontolu_fiyat);
        // Var mı? - aynı ürünse miktarı arttır
        const idx = sepet.findIndex(x => x.stok_id === d.id);
        if (idx >= 0) {
            sepet[idx].miktar += miktar;
        } else {
            sepet.push({
                stok_id: d.id,
                stok_kodu: d.stok_kodu,
                ad: d.ad,
                firma_id: d.firma_id,
                firma_ad: d.firma_ad,
                birim: d.birim,
                birim_fiyat: fiy,
                iskonto: parseFloat(d.iskonto || 0),
                miktar: miktar
            });
        }
        sepetGoster();
    });
}

function urunSil(idx) {
    sepet.splice(idx, 1);
    sepetGoster();
}

function miktarGuncelle(idx, yeni) {
    yeni = parseFloat(yeni);
    if (yeni > 0) sepet[idx].miktar = yeni;
    hesapla();
}

function sepetTemizle() {
    if (!sepet.length) return;
    if (!confirm('Sepeti temizlemek istediğinize emin misiniz?')) return;
    sepet = [];
    sepetGoster();
}

function sepetGoster() {
    const div = document.getElementById('sepetListe');
    document.getElementById('sepetSayi').textContent = sepet.length;
    if (!sepet.length) {
        div.innerHTML = '<div class="empty-state small">Henüz ürün eklenmedi</div>';
        hesapla();
        return;
    }
    let html = '<table class="cart-table"><thead><tr>'
        + '<th>Ürün</th><th>Miktar</th><th>B.Fiyat</th><th>Tutar</th><th></th>'
        + '</tr></thead><tbody>';
    sepet.forEach((it, idx) => {
        const tutar = it.miktar * it.birim_fiyat;
        html += `<tr>
            <td>
                <div class="ci-name">${escapeHtml(it.ad)}</div>
                <small>${escapeHtml(it.firma_ad)}</small>
            </td>
            <td>
                <input type="number" class="form-control mini" value="${it.miktar}"
                       min="0.001" step="0.001" 
                       onchange="miktarGuncelle(${idx}, this.value)">
                <small>${escapeHtml(it.birim)}</small>
            </td>
            <td class="text-right">${formatTL(it.birim_fiyat)}</td>
            <td class="text-right tutar"><b>${formatTL(tutar)}</b></td>
            <td><button class="btn-x" onclick="urunSil(${idx})">✗</button></td>
        </tr>`;
    });
    html += '</tbody></table>';
    div.innerHTML = html;
    hesapla();
}

function hesapla() {
    let araToplam = 0;
    sepet.forEach(it => { araToplam += it.miktar * it.birim_fiyat; });
    
    const vadeAy = parseInt(document.getElementById('vadeAy').value || '0');
    const kdvDahil = document.getElementById('kdvDahil').checked;
    
    const vadeFarki = araToplam * VADE_FARKI * vadeAy;
    const toplamVadeli = araToplam + vadeFarki;
    const kdv = kdvDahil ? toplamVadeli * KDV_ORANI : 0;
    const genel = toplamVadeli + kdv;
    
    document.getElementById('araToplam').textContent  = formatTL(araToplam);
    document.getElementById('vadeFarki').textContent  = formatTL(vadeFarki);
    document.getElementById('kdvTutar').textContent   = formatTL(kdv);
    document.getElementById('genelToplam').textContent = formatTL(genel);
    document.getElementById('kdvSatir').style.display = kdvDahil ? 'flex' : 'none';
}

function teklifKaydet() {
    if (!sepet.length) { alert('Sepet boş'); return; }
    if (!confirm('Teklif kaydedilsin mi?')) return;
    
    const data = {
        _csrf: '<?= csrf_token() ?>',
        musteri_adi: document.getElementById('musteriAd').value,
        musteri_tel: document.getElementById('musteriTel').value,
        vade_ay: document.getElementById('vadeAy').value,
        kdv_dahil: document.getElementById('kdvDahil').checked ? 1 : 0,
        kalemler: sepet
    };
    
    fetch('/api/teklif_kaydet.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(data)
    }).then(r => r.json()).then(d => {
        if (d.ok) {
            alert('Teklif kaydedildi: ' + d.teklif_no);
            sepet = [];
            document.getElementById('musteriAd').value = '';
            document.getElementById('musteriTel').value = '';
            sepetGoster();
            window.open('/teklif_yazdir.php?id=' + d.id, '_blank');
        } else {
            alert('Hata: ' + (d.hata || ''));
        }
    });
}

function kurGuncelle() {
    const btn = document.getElementById('kurBtnTxt');
    btn.textContent = 'Yükleniyor...';
    fetch('/api/kur_guncelle.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: '_csrf=<?= csrf_token() ?>'
    }).then(r => r.json()).then(d => {
        if (d.ok) {
            location.reload();
        } else {
            alert('Hata: ' + (d.hata || ''));
            btn.textContent = '↻ Kuru Yenile';
        }
    });
}

function escapeHtml(s) {
    return String(s||'').replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}

function formatTL(n) {
    return Number(n).toLocaleString('tr-TR', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    }) + ' ₺';
}
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
<?php ob_end_flush(); ?>
