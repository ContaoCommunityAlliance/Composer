<?php

/**
 * Composer integration for Contao.
 *
 * PHP version 5
 *
 * @copyright  ContaoCommunityAlliance 2013
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Tristan Lins <tristan.lins@bit3.de>
 * @package    Composer
 * @license    LGPLv3
 * @filesource
 */

if (!$GLOBALS['TL_CONFIG']['composerAllowRepoClient']) {
    $client = ContaoCommunityAlliance\Contao\Composer\Client::getInstance();
    $client->setInactiveModulesOptionsCallback(
        $GLOBALS['TL_DCA']['tl_settings']['fields']['inactiveModules']['options_callback']
    );
    $GLOBALS['TL_DCA']['tl_settings']['fields']['inactiveModules']['options_callback'] = array(
        'ContaoCommunityAlliance\Contao\Composer\Client',
        'getModules'
    );
}


/**
 * Palettes
 */
$GLOBALS['TL_DCA']['tl_settings']['palettes']['__selector__'][] = 'composerExecutionMode';
$GLOBALS['TL_DCA']['tl_settings']['palettes']['__selector__'][] = 'composerUseCloudForUpdate';

$GLOBALS['TL_DCA']['tl_settings']['palettes']['default'] .=
    ';{composer_legend:hide},composerAutoUpdateLibrary,' .
    'composerExecutionMode,composerVerbosity,composerRemoveRepositoryTables,composerUseCloudForUpdate';

$GLOBALS['TL_DCA']['tl_settings']['subpalettes']['composerExecutionMode_process']  =
    'composerPhpPath,composerProfiling';
$GLOBALS['TL_DCA']['tl_settings']['subpalettes']['composerExecutionMode_detached'] =
    'composerPhpPath,composerProfiling';
$GLOBALS['TL_DCA']['tl_settings']['subpalettes']['composerUseCloudForUpdate'] =
    'composerCloudEndpoint,composerCloudAuthKey';


/**
 * Fields
 */
$GLOBALS['TL_DCA']['tl_settings']['fields']['composerAutoUpdateLibrary']      = array(
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['composerAutoUpdateLibrary'],
    'inputType' => 'checkbox',
);
$GLOBALS['TL_DCA']['tl_settings']['fields']['composerExecutionMode']          = array(
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['composerExecutionMode'],
    'inputType' => 'select',
    'options'   => array('inline', 'process', 'detached'),
    'reference' => $GLOBALS['TL_LANG']['tl_settings']['composerExecutionModes'],
    'eval'      => array(
        'mandatory'      => true,
        'tl_class'       => 'w50',
        'helpwizard'     => true,
        'submitOnChange' => true,
    ),
);
$GLOBALS['TL_DCA']['tl_settings']['fields']['composerPhpPath']                = array(
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['composerPhpPath'],
    'inputType' => 'text',
    'eval'      => array(
        'mandatory'      => true,
        'tl_class'       => 'clr long',
        'allowHtml'      => true,
        'preserveTags'   => true,
        'decodeEntities' => true,
    ),
);
$GLOBALS['TL_DCA']['tl_settings']['fields']['composerProfiling']              = array(
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['composerProfiling'],
    'inputType' => 'checkbox',
    'eval'      => array(
        'tl_class'   => 'm12 w50',
    ),
);
$GLOBALS['TL_DCA']['tl_settings']['fields']['composerVerbosity']              = array(
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['composerVerbosity'],
    'inputType' => 'select',
    'options'   => array(
        'VERBOSITY_QUIET',
        'VERBOSITY_NORMAL',
        'VERBOSITY_VERBOSE',
        'VERBOSITY_VERY_VERBOSE',
        'VERBOSITY_DEBUG'
    ),
    'reference' => $GLOBALS['TL_LANG']['tl_settings']['composerVerbosityLevels'],
    'eval'      => array(
        'tl_class'   => 'clr w50',
        'helpwizard' => true,
    ),
);
$GLOBALS['TL_DCA']['tl_settings']['fields']['composerRemoveRepositoryTables'] = array(
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['composerRemoveRepositoryTables'],
    'inputType' => 'checkbox',
    'eval'      => array(
        'tl_class' => 'm12 w50',
    ),
);
$GLOBALS['TL_DCA']['tl_settings']['fields']['composerUseCloudForUpdate'] = array(
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['composerUseCloudForUpdate'],
    'inputType' => 'checkbox',
    'eval'      => array(
        'tl_class'          => 'm12 clr',
        'submitOnChange'    => true
    ),
    'save_callback' => [function($v) {
        $automator = new \Automator();
        $automator->purgeTempFolder();
        return $v;
    }]
);
$GLOBALS['TL_DCA']['tl_settings']['fields']['composerCloudEndpoint'] = array(
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['composerCloudEndpoint'],
    'inputType' => 'text',
    'eval'      => array(
        'tl_class'  => 'w50',
        'rgxp'      => 'url',
        'mandatory' => true,
    ),
);

$GLOBALS['TL_DCA']['tl_settings']['fields']['composerCloudAuthKey'] = array(
    'label'     => &$GLOBALS['TL_LANG']['tl_settings']['composerCloudAuthKey'],
    'inputType' => 'text',
    'eval'      => array(
        'tl_class' => 'w50'
    ),
);
