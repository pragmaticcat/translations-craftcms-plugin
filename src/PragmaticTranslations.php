<?php

namespace pragmatic\translations;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\twig\variables\CraftVariable;
use pragmatic\translations\services\TranslationsService;
use pragmatic\translations\variables\PragmaticTranslationsVariable;
use yii\base\Event;

class PragmaticTranslations extends Plugin
{
    public bool $hasCpSection = true;
    public string $templateRoot = __DIR__ . '/templates';
    public string $schemaVersion = '1.0.0';

    public static PragmaticTranslations $plugin;

    public function init(): void
    {
        parent::init();

        self::$plugin = $this;

        $this->setComponents([
            'translations' => TranslationsService::class,
        ]);

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['pragmatic-translations'] = 'pragmatic-translations/translations/index';
                $event->rules['pragmatic-translations/save'] = 'pragmatic-translations/translations/save';
                $event->rules['pragmatic-translations/export'] = 'pragmatic-translations/translations/export';
                $event->rules['pragmatic-translations/import'] = 'pragmatic-translations/translations/import';
            }
        );

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Pragmatic Translations',
                    'permissions' => [
                        'pragmatic-translations:manage' => ['label' => 'Manage translations'],
                        'pragmatic-translations:export' => ['label' => 'Export translations'],
                    ],
                ];
            }
        );

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('pragmaticTranslations', PragmaticTranslationsVariable::class);
            }
        );
    }

    public function getCpNavItem(): array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Pragmatic';
        $item['subnav'] = [
            'translations' => [
                'label' => 'Translations',
                'url' => 'pragmatic-translations',
            ],
        ];

        return $item;
    }
}
