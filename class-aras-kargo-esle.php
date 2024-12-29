<?php

if (!defined('ABSPATH')) {
    exit;
}

class ArasKargoEsle {
    private $username;
    private $password;
    private $customerCode;
    
    public function __construct($username, $password, $customerCode) {
        if (empty($username) || empty($password) || empty($customerCode)) {
            throw new Exception('API bilgileri eksik');
        }
        
        $this->username = $username;
        $this->password = $password;
        $this->customerCode = $customerCode;
    }
    
    // Son 14 günlük kargoları getir
    public function getKargolar() {
        $endDate = date('d.m.Y');
        $startDate = date('d.m.Y', strtotime('-14 days'));
        
        $loginInfo = '<LoginInfo>
            <UserName>' . $this->username . '</UserName>
            <Password>' . $this->password . '</Password>
            <CustomerCode>' . $this->customerCode . '</CustomerCode>
        </LoginInfo>';
        
        $queryInfo = '<QueryInfo>
            <QueryType>12</QueryType>
            <StartDate>' . $startDate . '</StartDate>
            <EndDate>' . $endDate . '</EndDate>
        </QueryInfo>';
        
        $response = $this->sendRequest($loginInfo, $queryInfo);
        return $this->parseResponse($response);
    }
    
    // Kargo API'sine istek gönder
    private function sendRequest($loginInfo, $queryInfo) {
        if (!function_exists('curl_init')) {
            $this->logYaz('CURL kütüphanesi yüklü değil');
            throw new Exception('CURL kütüphanesi yüklü değil');
        }
        
        $url = 'https://customerservices.araskargo.com.tr/ArasCargoCustomerIntegrationService/ArasCargoIntegrationService.svc?wsdl';
        
        $xml_request = '<?xml version="1.0" encoding="utf-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">
            <soapenv:Header/>
            <soapenv:Body>
                <tem:GetQueryJSON>
                    <tem:loginInfo>' . htmlspecialchars($loginInfo) . '</tem:loginInfo>
                    <tem:queryInfo>' . htmlspecialchars($queryInfo) . '</tem:queryInfo>
                </tem:GetQueryJSON>
            </soapenv:Body>
        </soapenv:Envelope>';

        $this->logYaz('Gönderilen XML İstek: ' . $xml_request);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_request);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "http://tempuri.org/IArasCargoIntegrationService/GetQueryJSON"'
        ));
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logYaz('CURL Hatası: ' . $error);
            throw new Exception('API isteği başarısız: ' . $error);
        }
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            $this->logYaz('HTTP Hata Kodu: ' . $http_code);
            throw new Exception('API yanıt kodu hatalı: ' . $http_code);
        }
        
        return $response;
    }
    
    // API yanıtını parse et
    private function parseResponse($response) {
        if (empty($response)) {
            echo '<div style="background: #f8d7da; padding: 10px; margin: 10px 0; border: 1px solid #f5c6cb; border-radius: 4px;">
                <strong>Hata:</strong> API yanıtı boş
            </div>';
            throw new Exception('API yanıtı boş');
        }
        
        echo '<div style="background: #e2e3e5; padding: 10px; margin: 10px 0; border: 1px solid #d6d8db; border-radius: 4px;">
            <strong>API Ham Yanıt:</strong><br>
            <pre style="white-space: pre-wrap;">' . htmlspecialchars($response) . '</pre>
        </div>';
        
        $xml = simplexml_load_string($response);
        if ($xml === false) {
            echo '<div style="background: #f8d7da; padding: 10px; margin: 10px 0; border: 1px solid #f5c6cb; border-radius: 4px;">
                <strong>Hata:</strong> XML yanıtı geçersiz
            </div>';
            throw new Exception('XML yanıtı geçersiz');
        }
        
        $xml->registerXPathNamespace('s', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('tem', 'http://tempuri.org/');
        
        $result = $xml->xpath('//s:Body/tem:GetQueryJSONResponse/tem:GetQueryJSONResult');
        if (empty($result)) {
            echo '<div style="background: #fff3cd; padding: 10px; margin: 10px 0; border: 1px solid #ffeeba; border-radius: 4px;">
                <strong>Uyarı:</strong> Sonuç bulunamadı
            </div>';
            return array();
        }
        
        if (empty($result[0])) {
            echo '<div style="background: #fff3cd; padding: 10px; margin: 10px 0; border: 1px solid #ffeeba; border-radius: 4px;">
                <strong>Uyarı:</strong> Bu tarih aralığında kargo bulunamadı
            </div>';
            return array();
        }
        
        echo '<div style="background: #e2e3e5; padding: 10px; margin: 10px 0; border: 1px solid #d6d8db; border-radius: 4px;">
            <strong>JSON Yanıt:</strong><br>
            <pre style="white-space: pre-wrap;">' . htmlspecialchars($result[0]) . '</pre>
        </div>';
        
        $data = json_decode($result[0], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo '<div style="background: #f8d7da; padding: 10px; margin: 10px 0; border: 1px solid #f5c6cb; border-radius: 4px;">
                <strong>Hata:</strong> JSON parse hatası: ' . json_last_error_msg() . '
            </div>';
            throw new Exception('JSON parse hatası: ' . json_last_error_msg());
        }
        
        if (empty($data['QueryResult'])) {
            echo '<div style="background: #fff3cd; padding: 10px; margin: 10px 0; border: 1px solid #ffeeba; border-radius: 4px;">
                <strong>Uyarı:</strong> QueryResult boş
            </div>';
            return array();
        }
        
        echo '<div style="background: #d4edda; padding: 10px; margin: 10px 0; border: 1px solid #c3e6cb; border-radius: 4px;">
            <strong>Veri:</strong><br>
            <pre style="white-space: pre-wrap;">' . print_r($data['QueryResult'], true) . '</pre>
        </div>';
        
        return isset($data['QueryResult']['CargoInfo']) ? $data['QueryResult']['CargoInfo'] : $data['QueryResult'];
    }
    
    // İsim benzerlik puanı hesapla (0-100 arası)
    private function isimBenzerlikPuani($isim1, $isim2) {
        if (empty($isim1) || empty($isim2)) {
            return 0;
        }
        
        $isim1 = $this->temizleMetin($isim1);
        $isim2 = $this->temizleMetin($isim2);
        
        similar_text($isim1, $isim2, $yuzde);
        return $yuzde;
    }
    
    // Adres benzerlik puanı hesapla (0-100 arası)
    private function adresBenzerlikPuani($adres1, $adres2) {
        if (empty($adres1) || empty($adres2)) {
            return 0;
        }
        
        $adres1 = $this->temizleMetin($adres1);
        $adres2 = $this->temizleMetin($adres2);
        
        similar_text($adres1, $adres2, $yuzde);
        return $yuzde;
    }
    
    // Metni temizle ve standardize et
    private function temizleMetin($metin) {
        if (!is_string($metin)) {
            return '';
        }
        
        $metin = mb_strtolower($metin, 'UTF-8');
        $metin = str_replace(['ı', 'ğ', 'ü', 'ş', 'ö', 'ç'], ['i', 'g', 'u', 's', 'o', 'c'], $metin);
        $metin = preg_replace('/[^a-z0-9\s]/', '', $metin);
        return trim($metin);
    }
    
    // Siparişleri kargolarla eşleştir
    public function esleKargoVeSiparisler() {
        if (!function_exists('wc_get_orders')) {
            throw new Exception('WooCommerce fonksiyonları bulunamadı');
        }
        
        $kargolar = $this->getKargolar();
        if (empty($kargolar)) {
            return array();
        }
        
        echo '<div style="background: #e2e3e5; padding: 10px; margin: 10px 0; border: 1px solid #d6d8db; border-radius: 4px;">
            <strong>Bulunan Kargolar:</strong><br>
            <pre style="white-space: pre-wrap;">' . print_r($kargolar, true) . '</pre>
        </div>';
        
        // Sadece hazırlanıyor durumundaki siparişleri al
        $siparisler = wc_get_orders(array(
            'date_created' => '>=' . strtotime('-14 days'),
            'status' => array('wc-processing'),
            'limit' => -1
        ));
        
        if (empty($siparisler)) {
            return array();
        }
        
        $eslesme_sonuclari = array();
        
        foreach ($kargolar as $kargo) {
            if (empty($kargo['ALICI']) || empty($kargo['KARGO_TAKIP_NO'])) {
                continue;
            }
            
            foreach ($siparisler as $siparis) {
                // Müşteri bilgilerini al
                $musteri_adi = $siparis->get_shipping_first_name() . ' ' . $siparis->get_shipping_last_name();
                if (empty(trim($musteri_adi))) {
                    $musteri_adi = $siparis->get_billing_first_name() . ' ' . $siparis->get_billing_last_name();
                }
                
                // İsim benzerliği hesapla
                $isim_puan = $this->isimBenzerlikPuani(
                    $kargo['ALICI'],
                    $musteri_adi
                );
                
                // Debug bilgisi
                echo '<div style="background: #f8f9fa; padding: 5px; margin: 5px 0; font-size: 12px;">
                    Karşılaştırma: Kargo Alıcı: ' . esc_html($kargo['ALICI']) . ' - Sipariş Müşteri: ' . esc_html($musteri_adi) . ' - Benzerlik: %' . number_format($isim_puan, 2) . '
                </div>';
                
                // Eğer benzerlik %60'dan fazlaysa eşleştir
                if ($isim_puan >= 60) {
                    // Kargo Takip Türkiye eklentisine kaydet
                    update_post_meta($siparis->get_id(), 'tracking_company', 'aras');
                    update_post_meta($siparis->get_id(), 'tracking_code', $kargo['KARGO_TAKIP_NO']);
                    
                    // Kargo durumuna göre sipariş durumunu güncelle
                    if (!empty($kargo['DURUMU'])) {
                        if (strpos($kargo['DURUMU'], 'TESLİM EDİLDİ') !== false) {
                            // Teslim edildiyse siparişi tamamlandı yap
                            $siparis->update_status('completed', 'Kargo teslim edildi.');
                        } else {
                            // Teslim edilmediyse kargoya verildi durumuna güncelle
                            $siparis->update_status('kargo-verildi', 'Kargo bilgileri otomatik eşleştirildi.');
                        }
                    } else {
                        // Durum bilgisi yoksa sadece kargoya verildi yap
                        $siparis->update_status('kargo-verildi', 'Kargo bilgileri otomatik eşleştirildi.');
                    }
                    
                    // Mail ve SMS gönder
                    if (get_option('mail_send_general') == 'yes') {
                        do_action('order_ship_mail', $siparis->get_id());
                    }
                    if (get_option('sms_provider') == 'NetGSM') {
                        do_action('order_send_sms', $siparis->get_id());
                    }
                    
                    $eslesme_sonuclari[] = array(
                        'siparis_no' => $siparis->get_id(),
                        'musteri' => $kargo['ALICI'],
                        'takip_no' => $kargo['KARGO_TAKIP_NO'],
                        'benzerlik' => $isim_puan,
                        'durum' => $kargo['DURUMU'] ?? 'Durum bilgisi yok'
                    );
                    
                    break; // Bu kargoyu başka siparişle eşleştirme
                }
            }
        }
        
        return $eslesme_sonuclari;
    }
    
    // Log dosyasına yaz
    private function logYaz($mesaj) {
        $log_dosyasi = dirname(__FILE__) . '/aras-kargo.log';
        $zaman = date('Y-m-d H:i:s');
        $log_mesaji = "[{$zaman}] {$mesaj}\n";
        file_put_contents($log_dosyasi, $log_mesaji, FILE_APPEND);
    }
} 