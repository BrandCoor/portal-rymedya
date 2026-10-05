<?php
/**
 * ====================================================================
 * ESKİ SÖZLEŞME ONAY ADRESİ
 * ====================================================================
 * Sözleşmeler artık Hesap sayfasındaki "Sözleşmeler ve onaylar"
 * bölümünden onaylanır; eski bağlantılar oraya yönlendirilir.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';

require_client_login(['client', 'agency', 'freelancer']);
redirect(legal_settings_url($_SESSION['client_user']['role'] ?? 'client'));
