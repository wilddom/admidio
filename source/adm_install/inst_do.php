<?php
/******************************************************************************
 * Einrichtungsscript fuer die MySql-Datenbank
 *
 * Copyright    : (c) 2004 - 2007 The Admidio Team
 * Homepage     : http://www.admidio.org
 * Module-Owner : Markus Fassbender
 * License      : http://www.gnu.org/licenses/gpl-2.0.html GNU Public License 2
 *
 * Uebergaben:
 *
 * mode : 0 (Default) Erster Dialog
 *        1 Admidio installieren - Config-Datei
 *        2 Datenbank installieren
 *        3 Datenbank updaten
 *        4 Neue Organisation anlegen
 *
 *****************************************************************************/

define('SERVER_PATH', substr(__FILE__, 0, strpos(__FILE__, "adm_install")-1));
define('ADMIDIO_VERSION', '1.5.0');  // die Versionsnummer bitte nicht aendern !!!

require("../adm_program/system/function.php");
require("../adm_program/system/string.php");
require("../adm_program/system/date.php");
require("../adm_program/system/mysql_class.php");
require("../adm_program/system/organization_class.php");
require("../adm_program/system/user_class.php");
require("../adm_program/system/role_class.php");

session_name('admidio_php_session_id');
session_start();

// lokale Variablen der Uebergabevarialben initialieren

// Uebergabevariablen pruefen
$req_mode    = 0;
$req_version = 0;
$req_orga_name_short = null;
$req_orga_name_long  = null;
$req_user_last_name  = null;
$req_user_first_name = null;
$req_user_email      = null;
$req_user_login      = null;
$req_user_password   = null;

if(isset($_GET['mode']) && is_numeric($_GET['mode']))
{
   $req_mode = $_GET['mode'];
}

// setzt die Ausfuehrungszeit des Scripts auf 2 Min., da hier teilweise sehr viel gemacht wird
// allerdings darf hier keine Fehlermeldung wg. dem safe_mode kommen
@set_time_limit(120);

// Diese Funktion zeigt eine Fehlerausgabe an
// mode = 0 (Default) Fehlerausgabe
// mode = 1 Aktion wird durchgefuehrt (Abbrechen)
// mode = 2 Aktion erfolgreich durchgefuehrt
function showError($err_msg, $err_head = "Fehler", $mode = 1)
{
    global $g_root_path;

    echo '
    <!-- (c) 2004 - 2007 The Admidio Team - http://www.admidio.org -->
    <!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
    <html>
    <head>
        <title>Admidio - Installation</title>

        <meta http-equiv="content-type" content="text/html; charset=ISO-8859-15">
        <meta name="author"   content="Admidio Team">
        <meta name="robots"   content="noindex">

        <link rel="stylesheet" type="text/css" href="../adm_program/layout/system.css">
    </head>
    <body>
        <div style="margin-top: 10px; margin-bottom: 10px;" align="center"><br>
            <div class="formLayout" id="install_message_form" style="width: 300px;">
                <div class="formHead">'. $err_head. '</div>
                <div class="formBody">
                    <p>'. $err_msg. '</p>
                    <p><button id="zurueck" type="button" value="zurueck" onclick="';
                    if($mode == 1)
                    {
                        // Fehlermeldung (Zurueckgehen)
                        echo 'history.back()">
                        <img src="../adm_program/images/back.png" alt="Zurueck">
                        &nbsp;Zur&uuml;ck';
                    }
                    elseif($mode == 2)
                    {
                        // Erfolgreich durchgefuehrt
                        echo 'self.location.href=\'../adm_program/index.php\'">
                        <img src="../adm_program/images/application_view_list.png" alt="Zurueck">
                        &nbsp;Admidio &Uuml;bersicht';
                    }
                    echo '</button></p>
                </div>
            </div>
        </div>
    </body>
    </html>';
    exit();
}

if($req_mode == 1)
{
    // Installation 1.Seite

    // Tabellenpraefix pruefen
    $g_tbl_praefix = strStripTags($_POST['praefix']);
    if(strlen($g_tbl_praefix) == 0)
    {
        $g_tbl_praefix = "adm";
    }
    else
    {
        // wenn letztes Zeichen ein _ dann abschneiden
        if(strrpos($g_tbl_praefix, "_")+1 == strlen($g_tbl_praefix))
        {
            $g_tbl_praefix = substr($g_tbl_praefix, 0, strlen($g_tbl_praefix)-1);
        }

        // nur gueltige Zeichen zulassen
        $anz = strspn($g_tbl_praefix, "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_");

        if($anz != strlen($g_tbl_praefix))
        {
            showError("Das Tabellenpr&auml;fix enth&auml;lt ung&uuml;ltige Zeichen !");
        }
    }

    // Session-Variablen merken
    $_SESSION['praefix']  = $g_tbl_praefix;
    $_SESSION['orga_name_short'] = strStripTags($_POST['orga_name_short']);
}
elseif($req_mode == 2)
{
    // Installation 2.Seite

    // MySQL-Zugangsdaten in config.php schreiben
    // Datei auslesen
    $filename     = "config.php";
    $config_file  = fopen($filename, "r");
    $file_content = fread($config_file, filesize($filename));
    fclose($config_file);

    // den Root-Pfad ermitteln
    $root_path = $_SERVER['HTTP_HOST']. $_SERVER['REQUEST_URI'];
    $root_path = substr($root_path, 0, strpos($root_path, "/adm_install"));
    if(!strpos($root_path, "http://"))
    {
        $root_path = "http://". $root_path;
    }

    $file_content = str_replace("%PRAEFIX%", $_SESSION['praefix'], $file_content);
    $file_content = str_replace("%SERVER%",  $_SESSION['server'],  $file_content);
    $file_content = str_replace("%USER%",    $_SESSION['user'],    $file_content);
    $file_content = str_replace("%PASSWORD%",  $_SESSION['password'], $file_content);
    $file_content = str_replace("%DATABASE%",  $_SESSION['database'], $file_content);
    $file_content = str_replace("%ROOT_PATH%", $root_path,            $file_content);
    $file_content = str_replace("%ORGANIZATION%", $_SESSION['orga_name_short'], $file_content);

    // die erstellte Config-Datei an den User schicken
    $filename = "config.php";
    header("Content-Type: application/force-download");
    header("Content-Type: application/download");
    header("Content-Type: text/csv; charset=ISO-8859-1");
    header("Content-Disposition: attachment; filename=$filename");
    echo $file_content;
    exit();
}
elseif($req_mode == 5)
{
    if(file_exists("../adm_config/config.php"))
    {
        showError("Die Datenbank wurde erfolgreich angelegt und die Datei config.php erstellt.<br><br>
            Sie k&ouml;nnen nun mit Admidio arbeiten.", "Fertig", 2);
    }
    else
    {
        showError("Die Datei <b>config.php</b> befindet sich nicht im Verzeichnis <b>adm_config</b> !");
    }
}
else
{
    require("../adm_config/config.php");
}

// Zugangsdaten der DB in Sessionvariablen gefiltert speichern
$_SESSION['server']   = strStripTags($_POST['server']);
$_SESSION['user']     = strStripTags($_POST['user']);
$_SESSION['password'] = strStripTags($_POST['password']);
$_SESSION['database'] = strStripTags($_POST['database']);

// Standard-Praefix ist adm auch wegen Kompatibilitaet zu alten Versionen
if(strlen($g_tbl_praefix) == 0)
{
    $g_tbl_praefix = "adm";
}

// Defines fuer alle Datenbanktabellen
define("TBL_ANNOUNCEMENTS",     $g_tbl_praefix. "_announcements");
define("TBL_CATEGORIES",        $g_tbl_praefix. "_categories");
define("TBL_DATES",             $g_tbl_praefix. "_dates");
define("TBL_FILES",             $g_tbl_praefix. "_files");
define("TBL_FOLDERS",           $g_tbl_praefix. "_folders");
define("TBL_FOLDER_ROLES",      $g_tbl_praefix. "_folder_roles");
define("TBL_GUESTBOOK",         $g_tbl_praefix. "_guestbook");
define("TBL_GUESTBOOK_COMMENTS",$g_tbl_praefix. "_guestbook_comments");
define("TBL_LINKS",             $g_tbl_praefix. "_links");
define("TBL_MEMBERS",           $g_tbl_praefix. "_members");
define("TBL_ORGANIZATIONS",     $g_tbl_praefix. "_organizations");
define("TBL_PHOTOS",            $g_tbl_praefix. "_photos");
define("TBL_PREFERENCES",       $g_tbl_praefix. "_preferences");
define("TBL_ROLE_DEPENDENCIES", $g_tbl_praefix. "_role_dependencies");
define("TBL_ROLES",             $g_tbl_praefix. "_roles");
define("TBL_SESSIONS",          $g_tbl_praefix. "_sessions");
define("TBL_TEXTS",             $g_tbl_praefix. "_texts");
define("TBL_USERS",             $g_tbl_praefix. "_users");
define("TBL_USER_DATA",         $g_tbl_praefix. "_user_data");
define("TBL_USER_FIELDS",       $g_tbl_praefix. "_user_fields");

/*------------------------------------------------------------*/
// Eingabefelder pruefen
/*------------------------------------------------------------*/

if(strlen($_SESSION['server'])   == 0
|| strlen($_SESSION['user'])     == 0
// bei localhost muss es kein Passwort geben
//|| strlen($_SESSION['password']) == 0
|| strlen($_SESSION['database']) == 0 )
{
    showError("Es sind nicht alle Zugangsdaten zur MySql-Datenbank eingegeben worden !");
}

if($req_mode == 3)
{
    if($_POST['version'] == 0 || is_numeric($_POST['version']) == false)
    {
        showError("Bei einem Update m&uuml;ssen Sie Ihre bisherige Version angeben !");
    }
    $req_version = $_POST['version'];
}

// bei Installation oder hinzufuegen einer Organisation
if($req_mode == 1 || $req_mode == 4)
{
    $req_user_last_name  = strStripTags($_POST['user_last_name']);
    $req_user_first_name = strStripTags($_POST['user_first_name']);
    $req_user_email      = strStripTags($_POST['user_email']);
    $req_user_login      = strStripTags($_POST['user_login']);
    $req_user_password   = strStripTags($_POST['user_password']);

    if(strlen($req_user_last_name)  == 0
    || strlen($req_user_first_name) == 0
    || strlen($req_user_email)      == 0
    || strlen($req_user_login)      == 0
    || strlen($req_user_password)   == 0 )
    {
        showError("Es sind nicht alle Benutzerdaten eingegeben worden !");
    }

    if(isValidEmailAddress($req_user_email) == false)
    {
        showError("Die E-Mail-Adresse ist nicht g&uuml;ltig.");
    }

    if(strlen($_POST['orga_name_long'])  == 0
    || strlen($_POST['orga_name_short']) == 0 )
    {
        showError("Sie m&uuml;ssen einen Namen f&uuml;r die Organisation / den Verein eingeben !");
    }
}

/*------------------------------------------------------------*/
// Daten verarbeiten
/*------------------------------------------------------------*/

 // Verbindung zu Datenbank herstellen und Transaktion starten
$db = new MySqlDB();
$connection = $db->connect($_SESSION['server'], $_SESSION['user'], $_SESSION['password'], $_SESSION['database']);
$db->transaction("begin");

// leeres Organisationsobjekt erstellen
$g_current_organization = new Organization($db);

if($req_mode == 1)
{
    $filename = "db_scripts/db.sql";
    $file     = fopen($filename, "r")
                or showError("Die Datei <b>db.sql</b> konnte nicht im Verzeichnis <b>adm_install/db_scripts</b> gefunden werden.");
    $content  = fread($file, filesize($filename));
    $sql_arr  = explode(";", $content);
    fclose($file);

    foreach($sql_arr as $sql)
    {
        if(strlen(trim($sql)) > 0)
        {
            // Praefix fuer die Tabellen einsetzen und SQL-Statement ausfuehren
            $sql = str_replace("%PRAEFIX%", $g_tbl_praefix, $sql);
            $db->query($sql);
        }
    }

    // Default-Daten anlegen

    // Orga-Uebergreifende Kategorien anlegen
    $sql = "INSERT INTO ". TBL_CATEGORIES. " (cat_org_id, cat_type, cat_name, cat_hidden, cat_system, cat_sequence)
                                      VALUES (NULL, 'USF', 'Stammdaten', 0, 1, 0) ";
    $db->query($sql);
    $cat_id_stammdaten = $db->insert_id();

    $sql = "INSERT INTO ". TBL_CATEGORIES. " (cat_org_id, cat_type, cat_name, cat_hidden, cat_system, cat_sequence)
                                      VALUES (NULL, 'USF', 'Messenger', 0, 1, 1) ";
    $db->query($sql);
    $cat_id_messenger = $db->insert_id();

    // Stammdatenfelder anlegen
    $sql = "INSERT INTO ". TBL_USER_FIELDS. " (usf_cat_id, usf_type, usf_name, usf_description, usf_system, usf_disabled, usf_sequence)
                                       VALUES ($cat_id_stammdaten, 'TEXT', 'Nachname', NULL, 1, 1, 1)
                                            , ($cat_id_stammdaten, 'TEXT', 'Vorname', NULL, 1, 1, 2)
                                            , ($cat_id_stammdaten, 'TEXT', 'Adresse', NULL, 1, 0, 3) 
                                            , ($cat_id_stammdaten, 'TEXT', 'PLZ', NULL, 1, 0, 4)
                                            , ($cat_id_stammdaten, 'TEXT', 'Ort', NULL, 1, 0, 5)
                                            , ($cat_id_stammdaten, 'TEXT', 'Land', NULL, 1, 0, 6)
                                            , ($cat_id_stammdaten, 'TEXT', 'Telefon', NULL, 1, 0, 7)
                                            , ($cat_id_stammdaten, 'TEXT', 'Handy', NULL, 1, 0, 8)
                                            , ($cat_id_stammdaten, 'TEXT', 'Fax', NULL, 1, 0, 9)
                                            , ($cat_id_stammdaten, 'DATE', 'Geburtstag', NULL, 1, 0, 10)
                                            , ($cat_id_stammdaten, 'NUMERIC', 'Geschlecht', NULL, 1, 0, 11)
                                            , ($cat_id_stammdaten, 'EMAIL','E-Mail', 'Es muss eine g&uuml;ltige E-Mail-Adresse angegeben werden.<br />' + 
                                                                   'Ohne diese kann das Programm nicht genutzt werden.', 1, 0, 12)
                                            , ($cat_id_stammdaten, 'URL',  'Homepage', NULL, 1, 0, 13) ";
    $db->query($sql);
    $usf_id_homepage = $db->insert_id();

    // Messenger anlegen
    $sql = "INSERT INTO ". TBL_USER_FIELDS. " (usf_cat_id, usf_type, usf_name, usf_description, usf_system, usf_sequence)
                                       VALUES ($cat_id_messenger, 'TEXT', 'AIM', 'AOL Instant Messenger', 1, 1) 
                                            , ($cat_id_messenger, 'TEXT', 'Google Talk', 'Google Talk', 1, 2)
                                            , ($cat_id_messenger, 'TEXT', 'ICQ', 'ICQ', 1, 3) 
                                            , ($cat_id_messenger, 'TEXT', 'MSN', 'MSN Messenger', 1, 4)
                                            , ($cat_id_messenger, 'TEXT', 'Skype', 'Skype', 1, 5) 
                                            , ($cat_id_messenger, 'TEXT', 'Yahoo', 'Yahoo! Messenger', 1, 6)  ";
    $db->query($sql);
}

if($req_mode == 3)
{
    // Updatescripte fuer die Datenbank verarbeiten
    if($req_version > 0)
    {
        include("db_scripts/preferences.php");
        
        // vor dem Update erst einmal alle Session loeschen, damit User ausgeloggt sind
        $sql = "DELETE FROM ". TBL_SESSIONS;
        $db->query($sql);
        
        for($update_count = $req_version; $update_count <= 5; $update_count++)
        {
            // erst einmal die SQL-Datei Statement fuer Statement verarbeiten
            if($update_count == 3)
            {
                $filename = "db_scripts/upd_1_3_db.sql";
            }
            elseif($update_count == 4)
            {
                $filename = "db_scripts/upd_1_4_db.sql";
            }
            elseif($update_count == 5)
            {
                $filename = "db_scripts/upd_1_5_db.sql";
            }
            else
            {
                $filename = "";
            }

            if(strlen($filename) > 0)
            {
                $file    = fopen($filename, "r")
                           or showError("Die Datei <b>$filename</b> konnte nicht im Verzeichnis <b>adm_install</b> gefunden werden.");
                $content = fread($file, filesize($filename));
                $sql_arr = explode(";", $content);
                fclose($file);

                foreach($sql_arr as $sql)
                {
                    if(strlen(trim($sql)) > 0)
                    {
                        // Praefix fuer die Tabellen einsetzen und SQL-Statement ausfuehren
                        $sql = str_replace("%PRAEFIX%", $g_tbl_praefix, $sql);
                        $db->query($sql);
                    }
                }
            }
            
            // jetzt noch evtl. neue Orga-Parameter in DB schreiben
            $sql = "SELECT * FROM ". TBL_ORGANIZATIONS;
            $result_orga = $db->query($sql);
            
            while($row_orga = $db->fetch_array($result_orga))
            {
                $g_current_organization->setValue("org_id", $row_orga['org_id']);
                $g_current_organization->setPreferences($orga_preferences, false);
            }
            
            // Nun das PHP-Script abarbeiten
            if($update_count == 3)
            {
                include("db_scripts/upd_1_3_conv.php");
            }
            elseif($update_count == 4)
            {
                include("db_scripts/upd_1_4_conv.php");
            }
            elseif($update_count == 5)
            {
                include("db_scripts/upd_1_5_conv.php");
            }           
        }
        
        // Datenbank-Versionsnummer updaten
        $sql = "UPDATE ". TBL_PREFERENCES. " SET prf_value = '". ADMIDIO_VERSION. "'
                 WHERE prf_org_id IS NULL
                   AND prf_name    = 'db_version' ";
        $db->query($sql);
    }
    else
    {
        showError("Sie haben Ihre bisherige Version nicht angegeben !");
    }
}

if($req_mode == 1 || $req_mode == 4)
{
    /************************************************************************/
    // neue Organisation anlegen oder hinzufuegen
    /************************************************************************/

    $req_orga_name_short = strStripTags($_POST['orga_name_short']);
    $req_orga_name_long  = strStripTags($_POST['orga_name_long']);

    $g_current_organization->getOrganization($req_orga_name_short);

    if($g_current_organization->getValue("org_id") > 0)
    {
        showError("Eine Organisation mit dem angegebenen kurzen Namen <b>$req_orga_name_short</b> existiert bereits.<br /><br />
                   W&auml;hlen Sie bitte einen anderen kurzen Namen !");
    }

    $g_current_organization->setValue("org_shortname", $req_orga_name_short);
    $g_current_organization->setValue("org_longname", $req_orga_name_long);
    $g_current_organization->setValue("org_homepage", $_SERVER['HTTP_HOST']);
    $g_current_organization->save();
    
    // alle Einstellungen aus preferences.php in die Tabelle adm_preferences schreiben
    include("db_scripts/preferences.php");
    
    foreach($orga_preferences as $key => $value)
    {
        $sql = "INSERT INTO ". TBL_PREFERENCES. " (prf_org_id, prf_name, prf_value)
                                           VALUES (". $g_current_organization->getValue("org_id"). ",    '$key',   '$value') ";
        $db->query($sql);
    }
    
    // Default-Kategorie fuer Rollen und Links eintragen
    $sql = "INSERT INTO ". TBL_CATEGORIES. " (cat_org_id, cat_type, cat_name, cat_hidden, cat_sequence)
                                           VALUES (". $g_current_organization->getValue("org_id"). ", 'ROL', 'Allgemein', 0, 1)";
    $db->query($sql);
    $category_common = $db->insert_id();
    
    $sql = "INSERT INTO ". TBL_CATEGORIES. " (cat_org_id, cat_type, cat_name, cat_hidden, cat_sequence)
                                      VALUES (". $g_current_organization->getValue("org_id"). ", 'ROL', 'Gruppen', 0, 2)
                                           , (". $g_current_organization->getValue("org_id"). ", 'ROL', 'Kurse', 0, 3)
                                           , (". $g_current_organization->getValue("org_id"). ", 'ROL', 'Mannschaften', 0, 4)
                                           , (". $g_current_organization->getValue("org_id"). ", 'LNK', 'Allgemein', 0, 1)
                                           , (". $g_current_organization->getValue("org_id"). ", 'USF', '". utf8_decode('Zusätzliche Daten'). "', 0, 2) ";
    $db->query($sql);

    // User Webmaster anlegen
    $g_current_user = new User($db);
    $g_current_user->setValue("Nachname", $req_user_last_name);
    $g_current_user->setValue("Vorname", $req_user_first_name);
    $g_current_user->setValue("E-Mail", $req_user_email);
    $g_current_user->setValue("usr_login_name", $req_user_login);
    $g_current_user->setValue("usr_password", md5($req_user_password));
    $g_current_user->b_set_last_change = false;
    $g_current_user->save();
    
    // nun die Default-Rollen anlegen

    // Webmaster
    $role_webmaster = new Role($db);
    $role_webmaster->setValue("rol_cat_id", $category_common);
    $role_webmaster->setValue("rol_name", "Webmaster");
    $role_webmaster->setValue("rol_description", "Gruppe der Administratoren des Systems");
    $role_webmaster->setValue("rol_assign_roles", 1);
    $role_webmaster->setValue("rol_approve_users", 1);
    $role_webmaster->setValue("rol_announcements", 1);
    $role_webmaster->setValue("rol_dates", 1);
    $role_webmaster->setValue("rol_download", 1);
    $role_webmaster->setValue("rol_guestbook", 1);
    $role_webmaster->setValue("rol_guestbook_comments", 1);
    $role_webmaster->setValue("rol_photo", 1);
    $role_webmaster->setValue("rol_weblinks", 1);
    $role_webmaster->setValue("rol_edit_user", 1);
    $role_webmaster->setValue("rol_mail_logout", 1);
    $role_webmaster->setValue("rol_mail_login", 1);
    $role_webmaster->setValue("rol_profile", 1);
    $role_webmaster->save(0);

    // Mitglied
    $role_member = new Role($db);
    $role_member->setValue("rol_cat_id", $category_common);
    $role_member->setValue("rol_name", "Mitglied");
    $role_member->setValue("rol_description", "Alle Mitglieder der Organisation");
    $role_member->setValue("rol_mail_login", 1);
    $role_member->setValue("rol_profile", 1);
    $role_member->save(0);

    // Vorstand
    $role_management = new Role($db);
    $role_management->setValue("rol_cat_id", $category_common);
    $role_management->setValue("rol_name", "Vorstand");
    $role_management->setValue("rol_description", "Vorstand des Vereins");
    $role_management->setValue("rol_announcements", 1);
    $role_management->setValue("rol_dates", 1);
    $role_management->setValue("rol_weblinks", 1);
    $role_management->setValue("rol_edit_user", 1);
    $role_management->setValue("rol_mail_logout", 1);
    $role_management->setValue("rol_mail_login", 1);
    $role_management->setValue("rol_profile", 1);
    $role_management->save(0);
    
    // Mitgliedschaft bei Rolle "Webmaster" anlegen
    $sql = "INSERT INTO ". TBL_MEMBERS. " (mem_rol_id, mem_usr_id, mem_begin, mem_valid)
                                   VALUES (". $role_webmaster->getValue("rol_id"). ", ". $g_current_user->getValue("usr_id"). ", NOW(), 1) 
                                        , (". $role_member->getValue("rol_id"). ", ". $g_current_user->getValue("usr_id"). ", NOW(), 1) ";
    $db->query($sql);
    
    // Datenbank-Versionsnummer schreiben
    $sql = "INSERT INTO ". TBL_PREFERENCES. " (prf_org_id, prf_name, prf_value)
                                       VALUES (NULL, 'db_version', '". ADMIDIO_VERSION. "') ";
    $db->query($sql);    
}


// Transaktion abschliessen
$db->transaction("commit");

// globale Objekte entfernen, damit sie neu eingelesen werden
unset($_SESSION['g_current_organisation']);
unset($_SESSION['g_preferences']);
unset($_SESSION['g_current_user']);

if($req_mode == 1)
{
    header("Location: index.php?mode=2");
    exit();
}
else
{
    showError("Die Einrichtung der Datenbank konnte erfolgreich abgeschlossen werden.<br><br>
               Nun muss noch das Installationsverzeichnis <b>adm_install</b> gel&ouml;scht werden.", "Fertig", 2);
}
?>