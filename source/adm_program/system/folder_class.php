<?php
/******************************************************************************
 * Klasse fuer Datenbanktabelle adm_folders
 *
 * Copyright    : (c) 2004 - 2008 The Admidio Team
 * Homepage     : http://www.admidio.org
 * Module-Owner : Elmar Meuthen
 * License      : GNU Public License 2 http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Diese Klasse dient dazu ein Folderobjekt zu erstellen.
 * Ein Ordner kann ueber diese Klasse in der Datenbank verwaltet werden
 *
 * Das Objekt wird erzeugt durch Aufruf des Konstruktors und der Uebergabe der
 * aktuellen Datenbankverbindung:
 * $folder = new File($g_db);
 *
 * Mit der Funktion getFolder($folder_id) kann nun alle Informationen zum Folder
 * aus der Db ausgelesen werden.
 *
 * Folgende Funktionen stehen nun zur Verfuegung:
 *
 * clear()                - Die Klassenvariablen werden neu initialisiert
 * setArray($field_arra)  - uebernimmt alle Werte aus einem Array in das Field-Array
 * setValue($field_name, $field_value) - setzt einen Wert fuer ein bestimmtes Feld
 * getValue($field_name)  - gibt den Wert eines Feldes zurueck
 * save()                 - Folder wird mit den geaenderten Daten in die Datenbank
 *                          zurueckgeschrieben bwz. angelegt
 * delete()               - Der aktuelle Folder wird aus der Datenbank geloescht
 *
 *****************************************************************************/

require_once(SERVER_PATH. "/adm_program/system/table_access_class.php");

class Folder extends TableAccess
{
    // Konstruktor
    function Folder(&$db, $folder_id = 0)
    {
        $this->db            =& $db;
        $this->table_name     = TBL_FOLDERS;
        $this->column_praefix = "fol";

        if($folder_id > 0)
        {
            $this->getFolder($folder_id);
        }
        else
        {
            $this->clear();
        }
    }


    // Folder mit der uebergebenen ID aus der Datenbank auslesen
    function getFolder($folder_id)
    {
        global $g_current_organization;

        $condition = "     fol_id     = $folder_id
                       AND fol_org_id = ". $g_current_organization->getValue("org_id");
        $this->readData($folder_id, $condition);
    }


    // Folder mit der uebergebenen ID aus der Datenbank fuer das Downloadmodul auslesen
    function getFolderForDownload($folder_id)
    {
        global $g_current_organization, $g_current_user;

        if ($folder_id > 0) {
            $condition = "     fol_id     = $folder_id
                           AND fol_type   = 'DOWNLOAD'
                           AND fol_org_id = ". $g_current_organization->getValue("org_id");
            $this->readData($folder_id, $condition);

        }
        else {
            $condition = "     fol_name   = 'download'
                           AND fol_type   = 'DOWNLOAD'
                           AND fol_path   = '/adm_my_files'
                           AND fol_org_id = ". $g_current_organization->getValue("org_id");
            $this->readData($folder_id, $condition);

        }



        //Gucken ob ueberhaupt ein Datensatz gefunden wurde...
        if ($this->getValue('fol_id'))
        {
            //Falls der Ordner gelocked ist und der User keine Downloadadminrechte hat, bekommt er nix zu sehen..
            if (!$g_current_user->editDownloadRight() && $this->getValue("fol_locked"))
            {
                $this->clear();
            }
            else if (!$g_current_user->editDownloadRight() && !$this->getValue("fol_public"))
            {
                //Wenn der Ordner nicht public ist und der Benutzer keine DownloadAdminrechte hat, muessen die Rechte untersucht werden
                $sql_rights = "SELECT count(*)
                         FROM ". TBL_FOLDER_ROLES. ", ". TBL_MEMBERS. "
                        WHERE flr_fol_id        = ". $this->getValue("fol_id"). "
                          AND flr_rol_id         = mem_rol_id
                          AND mem_usr_id         = ". $g_current_user->getValue("usr_id"). "
                          AND mem_valid         = 1";
                $result_rights = $this->db->query($sql_rights);
                $row_rights = $g_db->fetch_array($result_rights);
                $row_count  = $row_rights[0];

                //Falls der User in keiner Rolle Mitglied ist, die Rechte an dem Ordner besitzt
                //wird auch kein Ordner geliefert.
                if ($row_count == 0)
                {
                    $this->clear();
                }

            }
        }
    }


    // Inhalt des aktuellen Ordners, abhaengig von den Benutzerrechten, als Array zurueckliefern...
    function getFolderContentsForDownload()
    {
        global $g_current_organization, $g_current_user;

        //RueckgabeArray initialisieren
        $completeFolder = null;

        //Erst einmal alle Unterordner auslesen, die in diesem Verzeichnis enthalten sind
        $sql_folders = "SELECT *
                         FROM ". TBL_FOLDERS. "
                        WHERE fol_type             = 'DOWNLOAD'
                          AND fol_fol_id_parent = ". $this->getValue("fol_id"). "
                          AND fol_org_id         = ". $g_current_organization->getValue("org_id"). "
                        ORDER BY fol_name";
        $result_folders = $this->db->query($sql_folders);

        //Nun alle Dateien auslesen, die in diesem Verzeichnis enthalten sind
        $sql_files   = "SELECT *
                         FROM ". TBL_FILES. "
                        WHERE fil_fol_id        = ". $this->getValue("fol_id"). "
                        ORDER BY fil_name";
        $result_files = $this->db->query($sql_files);

        //Nun alle Folders und Files in ein mehrdimensionales Array stopfen
        //angefangen mit den Ordnern:
        while($row_folders = $this->db->fetch_object($result_folders))
        {
            $addToArray = false;

            //Wenn der Ordner public ist und nicht gelocked ist, wird er auf jeden Fall ins Array gepackt
            if (!$row_folders->fol_locked && $row_folders->fol_public)
            {
                $addToArray = true;
            }
            else if ($g_current_user->editDownloadRight())
            {
                //Falls der User editDownloadRechte hat, bekommt er den Ordner natuerlich auch zu sehen
                $addToArray = true;
            }
            else
            {
                //Gucken ob der angemeldete Benutzer Rechte an dem Unterordner hat...
                $sql_rights = "SELECT count(*)
                         FROM ". TBL_FOLDER_ROLES. ", ". TBL_MEMBERS. "
                        WHERE flr_fol_id        = ". $row_folders->fol_id. "
                          AND flr_rol_id         = mem_rol_id
                          AND mem_usr_id         = ". $g_current_user->getValue("usr_id"). "
                          AND mem_valid         = 1";
                $result_rights = $this->db->query($sql_rights);
                $row_rights = $g_db->fetch_array($result_rights);
                $row_count  = $row_rights[0];

                //Falls der User in mindestens einer Rolle Mitglied ist, die Rechte an dem Ordner besitzt
                //wird der Ordner natuerlich ins Array gepackt.
                if ($row_count > 0)
                {
                    $addToArray = true;
                }

            }

            //Jetzt noch pruefen ob der Ordner physikalisch vorhanden ist
            if (file_exists(SERVER_PATH. $row_folders->fol_path. "/". $row_folders->fol_name)) {
                $folderExists = true;
            }
            else {
                $folderExists = false;

                if ($g_current_user->editDownloadRight()) {
                    //falls der Ordner physikalisch nicht existiert wird er nur im Falle von AdminRechten dem Array hinzugefuegt
                    $addToArray = true;
                }
                else {
                    $addToArray = false;
                }
            }


            if ($addToArray)
            {
                $completeFolder["folders"][] = array(
                                'fol_id'        => $row_folders->fol_id,
                                'fol_name'      => $row_folders->fol_name,
                                'fol_path'      => $row_folders->fol_path,
                                'fol_timestamp' => $row_folders->fol_timestamp,
                                'fol_public'    => $row_folders->fol_public,
                                'fol_exists'    => $folderExists,
                                'fol_locked'    => $row_folders->fol_locked
                );
            }
        }

        //jetzt noch die Dateien ins Array packen:
        while($row_files = $this->db->fetch_object($result_files))
        {
            $addToArray = false;

            //Wenn das File nicht gelocked ist, wird es auf jeden Fall in das Array gepackt...
            if (!$row_files->fil_locked)
            {
                $addToArray = true;
            }
            else if ($g_current_user->editDownloadRight())
            {
                //Falls der User editDownloadRechte hat, bekommt er das File natürlich auch zu sehen
                $addToArray = true;
            }

            //Jetzt noch pruefen ob das File physikalisch vorhanden ist
            if (file_exists(SERVER_PATH. $this->getValue('fol_path'). "/". $this->getValue('fol_name'). "/". $row_files->fil_name)) {
                $fileExists = true;

                //Filegroesse ermitteln
                $fileSize = round(filesize(SERVER_PATH. $this->getValue('fol_path'). "/". $this->getValue('fol_name'). "/". $row_files->fil_name)/1024);
            }
            else {
                $fileExists = false;
                $fileSize   = 0;

                if ($g_current_user->editDownloadRight()) {
                    //falls das File physikalisch nicht existiert wird es nur im Falle von AdminRechten dem Array hinzugefuegt
                    $addToArray = true;
                }
                else {
                    $addToArray = false;
                }
            }


            if ($addToArray)
            {
                $completeFolder["files"][] = array(
                                'fil_id'        => $row_files->fil_id,
                                'fil_name'      => $row_files->fil_name,
                                'fil_timestamp' => $row_files->fil_timestamp,
                                'fil_locked'    => $row_files->fil_locked,
                                'fil_exists'    => $fileExists,
                                'fil_size'      => $fileSize,
                                'fil_counter'   => $row_files->fil_counter
                );
            }
        }


        // Das Array mit dem Ordnerinhalt zurueckgeben
        return $completeFolder;
    }


    //Gibt den kompletten Pfad des Ordners zurueck
    function getCompletePathOfFolder()
    {
        //Pfad zusammen setzen
        $folderPath   = $this->getValue("fol_path");
        $folderName   = $this->getValue("fol_name");
        $completePath = SERVER_PATH. $folderPath. "/". $folderName;

        return $completePath;
    }


    // Setzt das Publicflag (0 oder 1) auf einer vorhandenen Ordnerinstanz
    // und all seinen Unterordnern rekursiv
    function editPublicFlagOnFolder($public_flag, $folder_id = 0)
    {
        if ($folder_id = 0)
        {
            $folder_id = $this->getValue("fol_id");
            $this->setValue("fol_public", $public_flag);
        }

        //Alle Unterordner auslesen, die im uebergebenen Verzeichnis enthalten sind
        $sql_subfolders = "SELECT *
                              FROM ". TBL_FOLDERS. "
                            WHERE fol_fol_id_parent = $folder_id";
        $result_subfolders = $this->db->query($sql_subfolders);

        while($row_subfolders = $this->db->fetch_object($result_subfolders))
        {
            //rekursiver Aufruf mit jedem einzelnen Unterordner
            $this->editPublicFlagOnFolder($row_subfolders->fol_id);
        }

        //Jetzt noch das Flag in der DB setzen fuer die aktuelle folder_id...
        $sql_update = "UPDATE ". TBL_FOLDERS. "
                          SET fol_public = $public_flag
                        WHERE fol_id = $folder_id";
        $this->db->query($sql_update);

    }


    // Setzt das Lockedflag (0 oder 1) auf einer vorhandenen Ordnerinstanz
    // und allen darin enthaltenen Unterordnern und Dateien rekursiv
    function editLockedFlagOnFolder($locked_flag, $folder_id = 0)
    {
        if ($folder_id = 0)
        {
            $folder_id = $this->getValue("fol_id");
            $this->setValue("fol_locked", $locked_flag);
        }

        //Alle Unterordner auslesen, die im uebergebenen Verzeichnis enthalten sind
        $sql_subfolders = "SELECT *
                              FROM ". TBL_FOLDERS. "
                            WHERE fol_fol_id_parent = $folder_id";
        $result_subfolders = $this->db->query($sql_subfolders);

        while($row_subfolders = $this->db->fetch_object($result_subfolders))
        {
            //rekursiver Aufruf mit jedem einzelnen Unterordner
            $this->editLockedFlagOnFolder($row_subfolders->fol_id);
        }

        //Jetzt noch das Flag in der DB setzen fuer die aktuelle folder_id...
        $sql_update = "UPDATE ". TBL_FOLDERS. "
                          SET fol_locked = $locked_flag
                        WHERE fol_id = $folder_id";
        $this->db->query($sql_update);

        //...und natuerlich auch fuer alle Files die in diesem Ordner sind
        $sql_update = "UPDATE ". TBL_FILES. "
                          SET fil_locked = $locked_flag
                        WHERE fil_fol_id = $folder_id";
        $this->db->query($sql_update);
    }


    // die Methode wird innerhalb von delete() aufgerufen und entsorgt die Referenzen des Datensatzes
    // und loescht die Verzeichnisse auch physikalisch auf der Platte...
    function _delete($folder_id = 0)
    {
        if ($folder_id = 0)
        {
            $folder_id = $this->getValue("fol_id");

        }

        //Alle Unterordner auslesen, die im uebergebenen Verzeichnis enthalten sind
        $sql_subfolders = "SELECT *
                              FROM ". TBL_FOLDERS. "
                            WHERE fol_fol_id_parent = $folder_id";
        $result_subfolders = $this->db->query($sql_subfolders);

        while($row_subfolders = $this->db->fetch_object($result_subfolders))
        {
            //rekursiver Aufruf mit jedem einzelnen Unterordner
            $this->_delete($row_subfolders->fol_id);
        }

        //In der DB die Files der aktuellen folder_id loeschen
        $sql_delete_files = "DELETE from ". TBL_FILES. "
                        WHERE fil_fol_id = $folder_id";
        $this->db->query($sql_delete_files);

        //In der DB die verknuepften Berechtigungen zu dieser Folder_ID loeschen...
        $sql_delete_fol_rol = "DELETE from ". TBL_FOLDER_ROLES. "
                        WHERE flr_fol_id = $folder_id";
        $this->db->query($sql_delete_fol_rol);

        //In der DB den Eintrag des Ordners selber loeschen
        $sql_delete_folder = "DELETE from ". TBL_FOLDERS. "
                        WHERE fol_id = $folder_id";
        $this->db->query($sql_delete_folder);


        //Jetzt noch das Verzeichnis physikalisch von der Platte loeschen
        if ($folder_id = 0)
        {
            $folderPath = $this->getCompletePathOfFolder();
            $this->_deleteInFilesystem($folderPath, true);
        }

        //Auch wenn das physikalische Löschen fehl schlägt, wird in der DB alles gelöscht...
        return true;

    }


    //interne Funktion, die einen Ordner mit allen Inhalten rekursiv loescht
    function _deleteInFilesystem($folder, $initialCall = false)
    {
        $dh  = @opendir($folder);
        if($dh)
        {
            while (false !== ($filename = readdir($dh)))
            {
                if($filename != "." && $filename != "..")
                {
                    $act_folder_entry = "$folder/$filename";

                    if(is_dir($act_folder_entry))
                    {
                        // nun den entsprechenden Ordner loeschen
                        $this->deleteInFilesystem($act_folder_entry);
                        @chmod($act_folder_entry, 0777);
                        @rmdir($act_folder_entry);

                    }
                    else
                    {
                        // die Datei loeschen
                        if(file_exists($act_folder_entry))
                        {
                            @chmod($act_folder_entry, 0777);
                            @unlink($act_folder_entry);

                        }
                    }
                }
            }
            closedir($dh);
        }

        if ($initialCall)
        {
            //Den Ursprungsordner natuerlich auch noch loeschen
            @chmod($folder, 0777);
            @rmdir($folder);
        }

    }


    // interne Funktion, die Defaultdaten fur Insert und Update vorbelegt
    // die Funktion wird innerhalb von save() aufgerufen
    function _save()
    {
        global $g_current_organization, $g_current_user;

        if($this->new_record)
        {
            $this->setValue("fol_timestamp", date("Y-m-d H:i:s", time()));
            $this->setValue("fol_usr_id", $g_current_user->getValue("usr_id"));
        }

    }
}
?>