<?php
/*
Plugin Name: Aras Kargo Otomatik Eşleştirme
Plugin URI: https://Taskoprusarimsak.org
Description: Aras Kargo gönderilerini WooCommerce siparişleriyle otomatik eşleştirir ve Kargo Takip Türkiye eklentisine entegre eder.
Version: 1.0.0
Author: Erdem Tuncay Taskoprusarimsak.org
Author URI: https://Taskoprusarimsak.org
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: aras-kargo-otomatik-esle
*/

// Güvenlik kontrolü
if (!defined('ABSPATH')) {
    exit;
}

// Özel cron aralıklarını ekle
add_filter('cron_schedules', 'aras_kargo_add_cron_interval');
function aras_kargo_add_cron_interval($schedules) {
    $schedules['every_6_hours'] = array(
        'interval' => 21600, // 6 saat = 6 * 60 * 60 saniye
        'display'  => 'Her 6 saatte bir'
    );
    
    $schedules['every_12_hours'] = array(
        'interval' => 43200, // 12 saat
        'display'  => 'Her 12 saatte bir'
    );
    
    $schedules['every_3_hours'] = array(
        'interval' => 10800, // 3 saat
        'display'  => 'Her 3 saatte bir'
    );
    
    return $schedules;
}

// WooCommerce kontrolü
add_action('admin_init', 'aras_kargo_check_woocommerce');
function aras_kargo_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>Aras Kargo Otomatik Eşleştirme eklentisi için WooCommerce gereklidir.</p></div>';
        });
        deactivate_plugins(plugin_basename(__FILE__));
    }
}

// Admin menüsü ekle
add_action('admin_menu', 'aras_kargo_add_menu');
function aras_kargo_add_menu() {
    add_options_page(
        'Aras Kargo Ayarları',
        'Aras Kargo',
        'manage_options',
        'aras-kargo-settings',
        'aras_kargo_settings_page'
    );
}

// Ayarları kaydet
add_action('admin_init', 'aras_kargo_register_settings');
function aras_kargo_register_settings() {
    register_setting('aras_kargo_settings', 'aras_kargo_username');
    register_setting('aras_kargo_settings', 'aras_kargo_password');
    register_setting('aras_kargo_settings', 'aras_kargo_customer_code');
    register_setting('aras_kargo_settings', 'aras_kargo_cron_interval');
}

// Sipariş detay sayfasına EŞLE butonu ekle
add_action('woocommerce_admin_order_data_after_order_details', 'aras_kargo_add_match_button');
function aras_kargo_add_match_button($order) {
    $tracking_code = get_post_meta($order->get_id(), '_kargo_takip_no', true);
    if (empty($tracking_code)) {
        ?>
        <p class="form-field form-field-wide">
            <button type="button" class="button" id="aras_kargo_esle" onclick="arasKargoEsle(<?php echo $order->get_id(); ?>)">
                EŞLE
            </button>
        </p>
        <script>
        function arasKargoEsle(orderId) {
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'aras_kargo_esle_ajax',
                    order_id: orderId,
                    security: '<?php echo wp_create_nonce("aras-kargo-esle-nonce"); ?>'
                },
                beforeSend: function() {
                    jQuery('#aras_kargo_esle').prop('disabled', true).text('Eşleştiriliyor...');
                },
                success: function(response) {
                    if (response.success) {
                        alert('Kargo bilgileri başarıyla eşleştirildi!');
                        location.reload();
                    } else {
                        alert('Hata: ' + response.data);
                        jQuery('#aras_kargo_esle').prop('disabled', false).text('EŞLE');
                    }
                },
                error: function() {
                    alert('Bir hata oluştu. Lütfen tekrar deneyin.');
                    jQuery('#aras_kargo_esle').prop('disabled', false).text('EŞLE');
                }
            });
        }
        </script>
        <?php
    }
}

// AJAX işleyici ekle
add_action('wp_ajax_aras_kargo_esle_ajax', 'aras_kargo_esle_ajax_handler');
function aras_kargo_esle_ajax_handler() {
    check_ajax_referer('aras-kargo-esle-nonce', 'security');
    
    if (!current_user_can('edit_shop_orders')) {
        wp_send_json_error('Yetkiniz yok.');
    }
    
    $order_id = intval($_POST['order_id']);
    if (!$order_id) {
        wp_send_json_error('Geçersiz sipariş ID.');
    }
    
    try {
        require_once plugin_dir_path(__FILE__) . 'class-aras-kargo-esle.php';
        
        $username = get_option('aras_kargo_username');
        $password = get_option('aras_kargo_password');
        $customer_code = get_option('aras_kargo_customer_code');
        
        if (empty($username) || empty($password) || empty($customer_code)) {
            wp_send_json_error('Aras Kargo API bilgileri eksik.');
        }
        
        $esleyici = new ArasKargoEsle($username, $password, $customer_code);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error('Sipariş bulunamadı.');
        }
        
        $sonuclar = $esleyici->esleKargoVeSiparisler();
        
        if (empty($sonuclar)) {
            wp_send_json_error('Eşleşen kargo bulunamadı.');
        }
        
        foreach ($sonuclar as $sonuc) {
            if ($sonuc['siparis_no'] == $order_id) {
                // Kargo Takip Türkiye eklentisine kaydet
                update_post_meta($order_id, 'tracking_company', 'aras');
                update_post_meta($order_id, 'tracking_code', $sonuc['takip_no']);
                
                // Siparişin durumunu güncelle
                $order->update_status('kargo-verildi', 'Kargo bilgileri otomatik eşleştirildi.');
                
                // Mail ve SMS gönder
                if (get_option('mail_send_general') == 'yes') {
                    do_action('order_ship_mail', $order_id);
                }
                if (get_option('sms_provider') == 'NetGSM') {
                    do_action('order_send_sms', $order_id);
                }
                
                wp_send_json_success();
                return;
            }
        }
        
        wp_send_json_error('Bu sipariş için eşleşen kargo bulunamadı.');
        
    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}

// Cron zamanlamasını güncelle
function aras_kargo_update_cron_schedule() {
    $interval = get_option('aras_kargo_cron_interval', 'every_6_hours');
    
    // Mevcut zamanlamayı temizle
    wp_clear_scheduled_hook('aras_kargo_daily_match');
    
    // Yeni zamanlamayı ayarla
    if (!wp_next_scheduled('aras_kargo_daily_match')) {
        wp_schedule_event(time(), $interval, 'aras_kargo_daily_match');
    }
}

// Ayarlar değiştiğinde cron'u güncelle
add_action('update_option_aras_kargo_cron_interval', 'aras_kargo_update_cron_schedule');

// Ayarlar sayfası
function aras_kargo_settings_page() {
    ?>
    <div class="wrap">
        <h1>Aras Kargo Ayarları</h1>
        
        <form method="post" action="options.php">
            <?php settings_fields('aras_kargo_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th>Kullanıcı Adı</th>
                    <td>
                        <input type="text" name="aras_kargo_username" 
                               value="<?php echo esc_attr(get_option('aras_kargo_username')); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th>Şifre</th>
                    <td>
                        <input type="password" name="aras_kargo_password" 
                               value="<?php echo esc_attr(get_option('aras_kargo_password')); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th>Müşteri Kodu</th>
                    <td>
                        <input type="text" name="aras_kargo_customer_code" 
                               value="<?php echo esc_attr(get_option('aras_kargo_customer_code')); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th>Otomatik Eşleştirme Sıklığı</th>
                    <td>
                        <select name="aras_kargo_cron_interval">
                            <option value="every_3_hours" <?php selected(get_option('aras_kargo_cron_interval'), 'every_3_hours'); ?>>
                                Her 3 saatte bir
                            </option>
                            <option value="every_6_hours" <?php selected(get_option('aras_kargo_cron_interval', 'every_6_hours'), 'every_6_hours'); ?>>
                                Her 6 saatte bir
                            </option>
                            <option value="every_12_hours" <?php selected(get_option('aras_kargo_cron_interval'), 'every_12_hours'); ?>>
                                Her 12 saatte bir
                            </option>
                            <option value="daily" <?php selected(get_option('aras_kargo_cron_interval'), 'daily'); ?>>
                                Günde bir kez
                            </option>
                        </select>
                        <p class="description">
                            Otomatik eşleştirmenin ne sıklıkla çalışacağını seçin. Varsayılan: Her 6 saatte bir
                        </p>
                        <?php
                        // Bir sonraki planlanmış çalışma zamanını göster
                        $next_run = wp_next_scheduled('aras_kargo_daily_match');
                        if ($next_run) {
                            echo '<p class="description">Bir sonraki otomatik eşleştirme: ' . 
                                  date_i18n('d.m.Y H:i:s', $next_run) . '</p>';
                        }
                        ?>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
        </form>
        
        <hr>
        
        <h2>Manuel Eşleştirme</h2>
        <form method="post" action="">
            <?php wp_nonce_field('aras_kargo_esle', 'aras_kargo_nonce'); ?>
            <p class="submit">
                <input type="submit" name="aras_kargo_esle" class="button button-primary" value="Eşleştirmeyi Başlat">
            </p>
        </form>
        
        <?php
        // Manuel eşleştirme işlemi
        if (isset($_POST['aras_kargo_esle']) && check_admin_referer('aras_kargo_esle', 'aras_kargo_nonce')) {
            require_once plugin_dir_path(__FILE__) . 'class-aras-kargo-esle.php';
            
            try {
                $username = get_option('aras_kargo_username');
                $password = get_option('aras_kargo_password');
                $customer_code = get_option('aras_kargo_customer_code');
                
                if (empty($username) || empty($password) || empty($customer_code)) {
                    echo '<div class="error"><p>Lütfen önce Aras Kargo API bilgilerini girin.</p></div>';
                    return;
                }
                
                $esleyici = new ArasKargoEsle($username, $password, $customer_code);
                $sonuclar = $esleyici->esleKargoVeSiparisler();
                
                if (!empty($sonuclar)) {
                    echo '<h3>Eşleştirme Sonuçları:</h3>';
                    echo '<table class="widefat">';
                    echo '<thead><tr>';
                    echo '<th>Sipariş No</th>';
                    echo '<th>Müşteri</th>';
                    echo '<th>Takip No</th>';
                    echo '<th>Benzerlik</th>';
                    echo '<th>İşlem</th>';
                    echo '</tr></thead>';
                    echo '<tbody>';
                    
                    foreach ($sonuclar as $sonuc) {
                        echo '<tr>';
                        echo '<td>' . esc_html($sonuc['siparis_no']) . '</td>';
                        echo '<td>' . esc_html($sonuc['musteri']) . '</td>';
                        echo '<td>' . esc_html($sonuc['takip_no']) . '</td>';
                        echo '<td>%' . number_format($sonuc['benzerlik'], 2) . '</td>';
                        echo '<td>
                            <button type="button" class="button button-primary" onclick="arasKargoEsle(' . esc_js($sonuc['siparis_no']) . ')">
                                Eşleştir
                            </button>
                        </td>';
                        echo '</tr>';
                    }
                    
                    echo '</tbody></table>';
                    
                    // JavaScript kodunu ekle
                    echo '<script>
                    function arasKargoEsle(orderId) {
                        jQuery.ajax({
                            url: ajaxurl,
                            type: "POST",
                            data: {
                                action: "aras_kargo_esle_ajax",
                                order_id: orderId,
                                security: "' . wp_create_nonce("aras-kargo-esle-nonce") . '"
                            },
                            beforeSend: function() {
                                jQuery("button[onclick*=\'"+orderId+"\']")
                                    .prop("disabled", true)
                                    .text("Eşleştiriliyor...");
                            },
                            success: function(response) {
                                if (response.success) {
                                    jQuery("button[onclick*=\'"+orderId+"\']")
                                        .removeClass("button-primary")
                                        .addClass("button-disabled")
                                        .text("Eşleştirildi")
                                        .prop("disabled", true);
                                } else {
                                    alert("Hata: " + response.data);
                                    jQuery("button[onclick*=\'"+orderId+"\']")
                                        .prop("disabled", false)
                                        .text("Eşleştir");
                                }
                            },
                            error: function() {
                                alert("Bir hata oluştu. Lütfen tekrar deneyin.");
                                jQuery("button[onclick*=\'"+orderId+"\']")
                                    .prop("disabled", false)
                                    .text("Eşleştir");
                            }
                        });
                    }
                    </script>';
                } else {
                    echo '<div class="notice notice-warning"><p>Eşleşen kargo bulunamadı.</p></div>';
                }
            } catch (Exception $e) {
                echo '<div class="error"><p>Hata: ' . esc_html($e->getMessage()) . '</p></div>';
            }
        }
        ?>
    </div>
    <?php
}

// Eklenti aktif edildiğinde
register_activation_hook(__FILE__, 'aras_kargo_activate');
function aras_kargo_activate() {
    // Varsayılan ayarları ekle
    add_option('aras_kargo_cron_interval', 'every_6_hours');
    
    // Cron zamanlamasını ayarla
    aras_kargo_update_cron_schedule();
}

// Eklenti deaktive edildiğinde
register_deactivation_hook(__FILE__, 'aras_kargo_deactivate');
function aras_kargo_deactivate() {
    wp_clear_scheduled_hook('aras_kargo_daily_match');
} 