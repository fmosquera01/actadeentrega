<?php
declare(strict_types=1);

/**
 * Navegación consistente para las pantallas del plugin.
 * Vuelve a la página anterior si pertenece al mismo sitio; en caso contrario,
 * utiliza una ruta de retorno interna y segura.
 */
final class PluginActadeentregaNavigation
{
    public static function backButton(string $fallbackUrl = '/plugins/actadeentrega/front/acta.php'): void
    {
        $fallback = htmlspecialchars($fallbackUrl, ENT_QUOTES, 'UTF-8');
        echo '<div class="container-fluid pt-2 pb-2 ae-navigation">'
            . '<button type="button" class="btn btn-outline-secondary btn-sm" '
            . 'onclick="(function(){try{if(document.referrer){var previous=new URL(document.referrer);'
            . 'if(previous.origin===window.location.origin){window.history.back();return;}}}catch(e){}'
            . 'window.location.href=' . json_encode($fallbackUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';})()">'
            . '<i class="ti ti-arrow-left"></i> Atrás</button></div>';
    }
}
