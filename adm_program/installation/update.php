<?php
/**
 ***********************************************************************************************
 * Handle update of Admidio database to a new version
 *
 * @copyright 2004-2016 The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * mode = 1 : (Default) Check update status and show dialog with status
 *        2 : Perform update
 *        3 : Show result of update
 ***********************************************************************************************
 */
// embed config and constants file
if(is_file('../../adm_my_files/config.php'))
{
    require_once('../../adm_my_files/config.php');
}
elseif(is_file('../../config.php'))
{
    // config file at destination of version 2.0 exists -> copy config file to new destination
    if(!@copy('../../config.php', '../../adm_my_files/config.php'))
    {
        exit('<div style="color: #cc0000;">Error: The file <strong>config.php</strong> could not be copied to the folder <strong>adm_my_files</strong>.
            Please check if this folder has the necessary write rights. If it\'s not possible to set this right then copy the
            config.php from the Admidio main folder to adm_my_files with your FTP program.</div>');
    }
    require_once('../../adm_my_files/config.php');
}
else
{
    // no config file exists -> go to installation
    header('Location: installation.php');
    exit();
}

if($g_tbl_praefix === '')
{
    // default praefix is "adm" because of compatibility to older versions
    $g_tbl_praefix = 'adm';
}

// if there is no debug flag in config.php than set debug to false
if(!isset($gDebug) || !$gDebug)
{
    $gDebug = 0;
}

require_once(substr(__FILE__, 0, strpos(__FILE__, 'adm_program')-1).'/adm_program/system/constants.php');

// check PHP version and show notice if version is too low
if(version_compare(phpversion(), MIN_PHP_VERSION, '<'))
{
    exit('<div style="color: #cc0000;">Error: Your PHP version '.phpversion().' does not fulfill
        the minimum requirements for this Admidio version. You need at least PHP '.MIN_PHP_VERSION.' or higher.</div>');
}

require_once('install_functions.php');
require_once(SERVER_PATH.'/adm_program/system/string.php');
require_once(SERVER_PATH.'/adm_program/system/function.php');

// Initialize and check the parameters

define('THEME_PATH', 'layout');
$getMode = admFuncVariableIsValid($_GET, 'mode', 'int', array('defaultValue' => 1));
$message = '';

// set default password-hash algorithm
if (!isset($gPasswordHashAlgorithm))
{
    $gPasswordHashAlgorithm = 'DEFAULT';
}

// Default-DB-Type ist immer MySql
if(!isset($gDbType))
{
    $gDbType = 'mysql';
}

if (!isset($g_adm_port))
{
    $g_adm_port = null;
}

// connect to database
try
{
    $gDb = new Database($gDbType, $g_adm_srv, $g_adm_port, $g_adm_db, $g_adm_usr, $g_adm_pw);
}
catch(AdmException $e)
{
    showNotice($gL10n->get('SYS_DATABASE_NO_LOGIN', $e->getText()), 'installation.php?mode=3', $gL10n->get('SYS_BACK'), 'layout/back.png');
}

// now check if a valid installation exists.
$sql = 'SELECT org_id FROM '.TBL_ORGANIZATIONS;
$pdoStatement = $gDb->query($sql, false);

if(!$pdoStatement || $pdoStatement->rowCount() === 0)
{
    // no valid installation exists -> show installation wizard
    header('Location: installation.php');
}

// create an organization object of the current organization
$gCurrentOrganization = new Organization($gDb, $g_organization);

if($gCurrentOrganization->getValue('org_id') == 0)
{
    // Organisation wurde nicht gefunden
    exit('<div style="color: #cc0000;">Error: The organization of the config.php could not be found in the database!</div>');
}

// organisationsspezifische Einstellungen aus adm_preferences auslesen
$gPreferences = $gCurrentOrganization->getPreferences();

$gProfileFields = new ProfileFields($gDb, $gCurrentOrganization->getValue('org_id'));

// create language and language data object to handle translations
if(!isset($gPreferences['system_language']))
{
    $gPreferences['system_language'] = 'de';
}
$gL10n = new Language();
$gLanguageData = new LanguageData($gPreferences['system_language']);
$gL10n->addLanguageData($gLanguageData);

// config.php exists at wrong place
if(is_file('../../config.php') && is_file('../../adm_my_files/config.php'))
{
    // try to delete the config file at the old place otherwise show notice to user
    if(!@unlink('../../config.php'))
    {
        showNotice($gL10n->get('INS_DELETE_CONFIG_FILE', $g_root_path), $g_root_path.'/adm_program/installation/index.php',
                   $gL10n->get('SYS_OVERVIEW'), 'layout/application_view_list.png');
    }
}

// check database version
$message = checkDatabaseVersion($gDb);

if($message !== '')
{
    showNotice($message, $g_root_path.'/adm_program/index.php',
               $gL10n->get('SYS_OVERVIEW'), 'layout/application_view_list.png');
}

// read current version of Admidio database
$installedDbVersion     = '';
$installedDbBetaVersion = '';
$maxUpdateStep          = 0;
$currentUpdateStep      = 0;

if(!$gDb->query('SELECT 1 FROM '.TBL_COMPONENTS, false))
{
    // in Admidio version 2 the database version was stored in preferences table
    if(isset($gPreferences['db_version']))
    {
        $installedDbVersion     = $gPreferences['db_version'];
        $installedDbBetaVersion = $gPreferences['db_version_beta'];
    }
}
else
{
    // read system component
    $componentUpdateHandle = new ComponentUpdate($gDb);
    $componentUpdateHandle->readDataByColumns(array('com_type' => 'SYSTEM', 'com_name_intern' => 'CORE'));

    if($componentUpdateHandle->getValue('com_id') > 0)
    {
        $installedDbVersion     = $componentUpdateHandle->getValue('com_version');
        $installedDbBetaVersion = (int) $componentUpdateHandle->getValue('com_beta');
        $currentUpdateStep      = (int) $componentUpdateHandle->getValue('com_update_step');
        $maxUpdateStep          = $componentUpdateHandle->getMaxUpdateStep();
    }
}

// if a beta was installed then create the version string with Beta version
if($installedDbBetaVersion > 0)
{
    $installedDbVersion = $installedDbVersion . ' Beta ' . $installedDbBetaVersion;
}

// if database version is not set then show notice
if($installedDbVersion === '')
{
    $message = '
        <div class="alert alert-danger alert-small" role="alert">
            <span class="glyphicon glyphicon-exclamation-sign"></span>
            <strong>'.$gL10n->get('INS_UPDATE_NOT_POSSIBLE').'</strong>
        </div>
        <p>'.$gL10n->get('INS_NO_INSTALLED_VERSION_FOUND', ADMIDIO_VERSION_TEXT).'</p>';
    showNotice($message, $g_root_path.'/adm_program/index.php',
               $gL10n->get('SYS_OVERVIEW'), 'layout/application_view_list.png', true);
}

if($getMode === 1)
{
    // if database version is smaller then source version -> update
    // if database version is equal to source but beta has a difference -> update
    if (version_compare($installedDbVersion, ADMIDIO_VERSION_TEXT, '<')
    || (version_compare($installedDbVersion, ADMIDIO_VERSION_TEXT, '==') && $maxUpdateStep > $currentUpdateStep))
    {
        // create a page with the notice that the installation must be configured on the next pages
        $form = new HtmlFormInstallation('update_login_form', 'update.php?mode=2');
        $form->setUpdateModus();
        $form->setFormDescription('<h3>'.$gL10n->get('INS_DATABASE_NEEDS_UPDATED_VERSION', $installedDbVersion, ADMIDIO_VERSION_TEXT).'</h3>');

        if(!isset($gLoginForUpdate) || $gLoginForUpdate == 1)
        {
            $form->addDescription($gL10n->get('INS_ADMINISTRATOR_LOGIN_DESC'));
            $form->addInput('login_name', $gL10n->get('SYS_USERNAME'), null, array('maxLength' => 35, 'property' => FIELD_REQUIRED, 'class' => 'form-control-small'));
            // TODO Future: 'minLength' => PASSWORD_MIN_LENGTH
            $form->addInput('password', $gL10n->get('SYS_PASSWORD'), null, array('type' => 'password', 'property' => FIELD_REQUIRED, 'class' => 'form-control-small'));
        }

        // if this is a beta version then show a warning message
        if(ADMIDIO_VERSION_BETA > 0)
        {
            $form->addDescription('
                <div class="alert alert-warning alert-small" role="alert">
                    <span class="glyphicon glyphicon-warning-sign"></span>
                    '.$gL10n->get('INS_WARNING_BETA_VERSION').'
                </div>');
        }
        $form->addSubmitButton('next_page', $gL10n->get('INS_UPDATE_DATABASE'), array('icon' => 'layout/database_in.png', 'onClickText' => $gL10n->get('INS_DATABASE_IS_UPDATED')));
        echo $form->show();
    }
    // if versions are equal > no update
    elseif(version_compare($installedDbVersion, ADMIDIO_VERSION_TEXT, '==') && $maxUpdateStep === $currentUpdateStep)
    {
        $message = '
            <div class="alert alert-success form-alert">
                <span class="glyphicon glyphicon-ok"></span>
                <strong>'.$gL10n->get('INS_DATABASE_IS_UP_TO_DATE').'</strong>
            </div>
            <p>'.$gL10n->get('INS_DATABASE_DOESNOT_NEED_UPDATED').'</p>';
        showNotice($message, $g_root_path.'/adm_program/index.php',
                   $gL10n->get('SYS_OVERVIEW'), 'layout/application_view_list.png', true);
    }
    // if source version smaller then database -> show error
    else
    {
        $message = '
            <div class="alert alert-danger form-alert">
                <span class="glyphicon glyphicon-exclamation-sign"></span>
                <strong>'.$gL10n->get('SYS_ERROR').'</strong>
                <p>'.$gL10n->get('SYS_FILESYSTEM_VERSION_INVALID', $installedDbVersion, ADMIDIO_VERSION_TEXT, '
                    <a href="'.ADMIDIO_HOMEPAGE.'index.php?page=download">', '</a>').'
                </p>
            </div>';
        showNotice($message, $g_root_path.'/adm_program/index.php',
                   $gL10n->get('SYS_OVERVIEW'), 'layout/application_view_list.png', true);
    }
}
elseif($getMode === 2)
{
    /**************************************/
    /* execute update script for database */
    /**************************************/

    if(!isset($gLoginForUpdate) || $gLoginForUpdate == 1)
    {
        // get username and password
        $loginName = admFuncVariableIsValid($_POST, 'login_name', 'string', array('requireValue' => true, 'directOutput' => true));
        $password  = admFuncVariableIsValid($_POST, 'password',   'string', array('requireValue' => true, 'directOutput' => true));

        // Search for username
        $sql = 'SELECT usr_id
                  FROM '.TBL_USERS.'
                 WHERE UPPER(usr_login_name) = UPPER(\''.$loginName.'\')';
        $userStatement = $gDb->query($sql);

        if ($userStatement->rowCount() === 0)
        {
            $message = '
                <div class="alert alert-danger alert-small" role="alert">
                    <span class="glyphicon glyphicon-exclamation-sign"></span>
                    <strong>'.$gL10n->get('SYS_LOGIN_USERNAME_PASSWORD_INCORRECT').'</strong>
                </div>';
            showNotice($message, 'update.php', $gL10n->get('SYS_BACK'), 'layout/back.png', true);
        }
        else
        {
            // create object with current user field structure und user object
            $gCurrentUser = new User($gDb, $gProfileFields, (int) $userStatement->fetchColumn());

            // check login data. If login failed an exception will be thrown.
            // Don't update the current session with user id and don't do a rehash of the password
            // because in former versions the password field was to small for the current hashes
            // and the update of this field will be done after this check.
            $checkLoginReturn = $gCurrentUser->checkLogin($password, false, false, false, true);

            if (is_string($checkLoginReturn))
            {
                $message = '
                    <div class="alert alert-danger alert-small" role="alert">
                        <span class="glyphicon glyphicon-exclamation-sign"></span>
                        <strong>'.$checkLoginReturn.'</strong>
                    </div>';
                showNotice($message, 'update.php', $gL10n->get('SYS_BACK'), 'layout/back.png', true);
            }
            // else continue with code below
        }
    }

    // setzt die Ausfuehrungszeit des Scripts auf 2 Min., da hier teilweise sehr viel gemacht wird
    // allerdings darf hier keine Fehlermeldung wg. dem safe_mode kommen
    @set_time_limit(300);

    preg_match('/^(\d+)\.(\d+)\.(\d+)/', $installedDbVersion, $versionArray);
    $versionArray = array_map('intval', $versionArray);
    list(, $versionMain, $versionMinor, $versionPatch) = $versionArray;

    $flagNextVersion = true;
    ++$versionPatch;

    // erst einmal die evtl. neuen Orga-Einstellungen in DB schreiben
    require_once('db_scripts/preferences.php');

    // calculate the best cost value for your server performance
    $cost = 10;
    if(isset($gPreferences) && array_key_exists('system_hashing_cost', $gPreferences))
    {
        $cost = (int) $gPreferences['system_hashing_cost'];
    }
    $benchmarkResults = PasswordHashing::costBenchmark(0.35, 'password', $gPasswordHashAlgorithm, array('cost' => $cost));
    $orga_preferences['system_hashing_cost'] = $benchmarkResults['cost'];

    $sql = 'SELECT org_id FROM '. TBL_ORGANIZATIONS;
    $orgaStatement = $gDb->query($sql);

    while($orgId = $orgaStatement->fetchColumn())
    {
        $gCurrentOrganization->setValue('org_id', $orgId);
        $gCurrentOrganization->setPreferences($orga_preferences, false);
    }

    if($gDbType === 'mysql')
    {
        // disable foreign key checks for mysql, so tables can easily deleted
        $sql = 'SET foreign_key_checks = 0 ';
        $gDb->query($sql);
    }

    // in version 2 we had an other update mechanism which will be handled here
    if($versionMain === 2)
    {
        // nun in einer Schleife die Update-Scripte fuer alle Versionen zwischen der Alten und Neuen einspielen
        while($flagNextVersion)
        {
            $flagNextVersion = false;

            if($versionMain === 2)
            {
                // version 2 Admidio had sql and php files where the update statements where stored
                // these files must be executed

                // in der Schleife wird geschaut ob es Scripte fuer eine Microversion (3.Versionsstelle) gibt
                // Microversion 0 sollte immer vorhanden sein, die anderen in den meisten Faellen nicht
                for($versionPatch; $versionPatch < 15; ++$versionPatch)
                {
                    $version = $versionMain . '_' . $versionMinor . '_' . $versionPatch;

                    // output of the version number for better debugging
                    if($gDebug)
                    {
                        error_log('Update to version ' . $version);
                    }

                    $dbScriptsPath = SERVER_PATH . '/adm_program/installation/db_scripts/';
                    $sqlFileName = 'upd_' . $version . '_db.sql';
                    $phpFileName = 'upd_' . $version . '_conv.php';

                    if (is_file($dbScriptsPath . $sqlFileName))
                    {
                        $sqlQueryResult = querySqlFile($sqlFileName);

                        if ($sqlQueryResult === true)
                        {
                            $flagNextVersion = true;
                        }
                        else
                        {
                            showNotice(
                                $sqlQueryResult,
                                'update.php',
                                $gL10n->get('SYS_BACK'),
                                'layout/back.png',
                                true
                            );
                            // => EXIT
                        }
                    }

                    $phpUpdateFile = $dbScriptsPath . $phpFileName;
                    // check if an php update file exists and then execute the script
                    if(is_file($phpUpdateFile))
                    {
                        include($phpUpdateFile);
                        $flagNextVersion = true;
                    }
                }

                // keine Datei mit der Microversion gefunden, dann die Main- oder Subversion hochsetzen,
                // solange bis die aktuelle Versionsnummer erreicht wurde
                if(!$flagNextVersion && version_compare($versionMain.'.'.$versionMinor.'.'.$versionPatch, ADMIDIO_VERSION, '<'))
                {
                    if($versionMinor === 4) // we do not have more then 4 subversions with old updater
                    {
                        ++$versionMain;
                        $versionMinor = 0;
                    }
                    else
                    {
                        ++$versionMinor;
                    }

                    $versionPatch = 0;
                    $flagNextVersion = true;
                }
            }
        }
    }

    disableSoundexSearchIfPgsql();

    // since version 3 we do the update with xml files and a new class model
    if($versionMain >= 3)
    {
        // set system user as current user, but this user only exists since version 3
        $sql = 'SELECT usr_id FROM '.TBL_USERS.' WHERE usr_login_name = \''.$gL10n->get('SYS_SYSTEM').'\' ';
        $systemUserStatement = $gDb->query($sql);

        $gCurrentUser = new User($gDb, $gProfileFields, (int) $systemUserStatement->fetchColumn());

        // reread component because in version 3.0 the component will be created within the update
        $componentUpdateHandle = new ComponentUpdate($gDb);
        $componentUpdateHandle->readDataByColumns(array('com_type' => 'SYSTEM', 'com_name_intern' => 'CORE'));
        $componentUpdateHandle->setTargetVersion(ADMIDIO_VERSION);
        $componentUpdateHandle->update();
    }

    if($gDbType === 'mysql')
    {
        // activate foreign key checks, so database is consistent
        $sql = 'SET foreign_key_checks = 1 ';
        $gDb->query($sql);
    }

    // nach dem Update erst einmal bei Sessions das neue Einlesen des Organisations- und Userobjekts erzwingen
    $sql = 'UPDATE '.TBL_SESSIONS.' SET ses_renew = 1 ';
    $gDb->query($sql);

    // create an installation unique cookie prefix and remove special characters
    $gCookiePraefix = 'ADMIDIO_'.$g_organization.'_'.$g_adm_db.'_'.$g_tbl_praefix;
    $gCookiePraefix = strtr($gCookiePraefix, ' .,;:[]', '_______');

    // start php session and remove session object with all data, so that
    // all data will be read after the update
    session_name($gCookiePraefix.'_PHP_ID');
    session_start();
    unset($_SESSION['gCurrentSession']);

    // show notice that update was successful
    $form = new HtmlFormInstallation('installation-form', ADMIDIO_HOMEPAGE.'index.php?page=donate');
    $form->setUpdateModus();
    $form->setFormDescription($gL10n->get('INS_UPDATE_TO_VERSION_SUCCESSFUL', ADMIDIO_VERSION_TEXT).'<br /><br />'.$gL10n->get('INS_SUPPORT_FURTHER_DEVELOPMENT'), '<div class="alert alert-success form-alert"><span class="glyphicon glyphicon-ok"></span><strong>'.$gL10n->get('INS_UPDATING_WAS_SUCCESSFUL').'</strong></div>');
    $form->openButtonGroup();
    $form->addSubmitButton('next_page', $gL10n->get('SYS_DONATE'), array('icon' => 'layout/money.png'));
    $form->addButton('main_page', $gL10n->get('SYS_LATER'), array('icon' => 'layout/application_view_list.png', 'link' => '../index.php'));
    $form->closeButtonGroup();
    echo $form->show();
}
