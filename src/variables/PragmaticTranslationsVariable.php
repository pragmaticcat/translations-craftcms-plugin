<?php

namespace pragmatic\translations\variables;

use pragmatic\translations\PragmaticTranslations;

class PragmaticTranslationsVariable
{
    public function t(string $key, array $params = [], ?int $siteId = null, bool $fallbackToPrimary = true): string
    {
        return PragmaticTranslations::$plugin->translations->t($key, $params, $siteId, $fallbackToPrimary);
    }
}
