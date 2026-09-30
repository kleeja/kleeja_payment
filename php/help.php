<?php

/*
 * The guide of this plugin on the help page of Kleeja (admin -> Help).
 * Its words are in language/help_en.php and language/help_ar.php, not in the database.
 */

// prevent illegal run
if (! defined('IN_PLUGINS_SYSTEM')) {
    exit;
}

/**
 * the words of the guide in the language of the admin,
 * a word that the translation misses is shown in English
 *
 * @return array
 */
function kjp_help_words(): array
{
    global $config;

    $folder = dirname(__DIR__) . '/language/';
    $words = (array) require $folder . 'help_en.php';
    $language = preg_replace('/[^a-z0-9_-]/i', '', (string) ($config['language'] ?? ''));
    $translation = $folder . "help_{$language}.php";

    if ($language !== '' && $language !== 'en' && file_exists($translation)) {
        $words = (array) (require $translation) + $words;
    }

    return $words;
}

/**
 * a section of the guide from its numbered words, like KJP_HELP_TIP_1, KJP_HELP_TIP_2 ..
 * its title, when it has its own, is KJP_HELP_TIP_TITLE
 *
 * @param  array  $words
 * @param  string $type  how Kleeja shows it: features, steps, tips, warnings or faq
 * @param  string $name  the name of its words, KJP_HELP_{name}_1
 * @return array
 */
function kjp_help_section(array $words, string $type, string $name): array
{
    $prefix = 'KJP_HELP_' . $name;
    $section = ['type' => $type, 'title' => $words[$prefix . '_TITLE'] ?? '', 'items' => []];

    for ($n = 1; isset($words[$prefix . ($type == 'faq' ? '_Q_' : '_') . $n]); $n++) {
        $section['items'][] =
            $type == 'faq'
                ? ['q' => $words[$prefix . '_Q_' . $n], 'a' => $words[$prefix . '_A_' . $n] ?? '']
                : $words[$prefix . '_' . $n];
    }

    return $section;
}

/**
 * the guide as the admin_help_guides hook of Kleeja takes it
 *
 * @return array
 */
function kjp_help_guide(): array
{
    $words = kjp_help_words();

    // tips and warnings are shown beside the others on wide screens
    $sections = [
        kjp_help_section($words, 'features', 'FEATURE'),
        kjp_help_section($words, 'steps', 'SETUP'),
        kjp_help_section($words, 'features', 'SETTING'),
        kjp_help_section($words, 'steps', 'FILE'),
        kjp_help_section($words, 'steps', 'GROUP'),
        kjp_help_section($words, 'steps', 'SUBSCRIPTION'),
        kjp_help_section($words, 'steps', 'PAYOUT'),
        kjp_help_section($words, 'faq', 'FAQ'),
        kjp_help_section($words, 'tips', 'TIP'),
        kjp_help_section($words, 'warnings', 'WARNING'),
    ];

    // the other plugins of Kleeja Payment add their help with this hook
    $KJP_HELP = [
        /* [ 'ID' => 'Example_ID' , 'TITLE' => 'Example Title', 'CONTENT' => 'Example Content'] */
    ];
    $KJP_HELP = runHook('KjPay:KLJ_HELP', get_defined_vars())['KJP_HELP'] ?? $KJP_HELP;
    $addons = [];

    foreach ((array) $KJP_HELP as $help) {
        if (is_array($help) && ! empty($help['TITLE']) && ! empty($help['CONTENT'])) {
            $addons[] = ['q' => (string) $help['TITLE'], 'a' => (string) $help['CONTENT']];
        }
    }

    if ($addons) {
        $sections[] = ['type' => 'faq', 'title' => $words['KJP_HELP_ADDON_TITLE'], 'items' => $addons];
    }

    $sections[] = ['type' => 'text', 'title' => $words['KJP_HELP_MORE_TITLE'], 'text' => $words['KJP_HELP_MORE']];

    return [
        'group' => 'plugins',
        // the icon of Payments Control in the menu
        'icon' => 'money',
        'title' => $words['KJP_HELP_TITLE'],
        'intro' => $words['KJP_HELP_INTRO'],
        // the help button of Payments Control opens this guide
        'page' => 'kj_payment_options',
        'link' => './?cp=kj_payment_options',
        'sections' => $sections,
    ];
}
