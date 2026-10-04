<?php
/**
 * ====================================================================
 * PERSONEL PANELİ - SAYFA SONU
 * ====================================================================
 */
?>
            </div>
            <footer class="xsmall text-faint" style="padding:0 32px 24px;max-width:1400px;margin:0 auto;display:flex;justify-content:space-between">
                <span><?= e(site_footer_text()) ?></span>
                <span><a href="<?= BASE_URL ?>/legal/index.php" class="link-quiet">Yasal metinler</a> · v<?= APP_VERSION ?></span>
            </footer>
        </div>
    </div>
</div>
<?= live_script($GLOBALS['live_job'][0] ?? null, $GLOBALS['live_job'][1] ?? null) ?>
<?= legal_cookie_notice() ?>
<?php ui_icons_init(); ?>
</body>
</html>
