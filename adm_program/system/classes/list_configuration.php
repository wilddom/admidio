<?php
/******************************************************************************
 * Klasse zum Verwalten von Listenkonfigurationen
 *
 * Copyright    : (c) 2004 - 2008 The Admidio Team
 * Homepage     : http://www.admidio.org
 * Module-Owner : Markus Fassbender
 * License      : GNU Public License 2 http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Diese Klasse dient dazu ein Listenkonfigurationsobjekt zu erstellen. 
 * Eine Konfiguration kann ueber diese Klasse in der Datenbank verwaltet werden
 *
 * Neben den Methoden der Elternklasse TableLists, stehen noch zusaetzlich
 * folgende Methoden zur Verfuegung:
 *
 * readColumns()         - Daten der zugehoerigen Spalten einlesen und in Objekten speichern
 * addColumn($number, $field, $sort = "", $condition = "")
 *                       - fuegt eine neue Spalte dem Spaltenarray hinzu
 * deleteColumn($number, $all = false)
 *                       - entfernt die entsprechende Spalte aus der Konfiguration
 * countColumns()        - Anzahl der Spalten der Liste zurueckgeben
 * getColumnObject($number)
 *                       - liefert das entsprechende TableListColumns-Objekt zurueck
 * getSQL($role_ids, $member_status = 0)
 *                       - gibt das passende SQL-Statement zu der Liste zurueck
 *
 *****************************************************************************/

require_once(SERVER_PATH. "/adm_program/system/classes/condition_parser.php");
require_once(SERVER_PATH. "/adm_program/system/classes/table_lists.php");
require_once(SERVER_PATH. "/adm_program/system/classes/table_list_columns.php");

class ListConfiguration extends TableLists
{
    var $columns = array();     // Array ueber alle Listenspaltenobjekte

    // Konstruktor
    function ListConfiguration(&$db, $lst_id = 0)
    {
        $this->TableLists($db, $lst_id);
        if($lst_id > 0)
        {
            $this->readColumns();
        }
    }
        
    // Daten der zugehoerigen Spalten einlesen und in Objekten speichern
    function readColumns()
    {
        $sql = "SELECT * FROM ". TBL_LIST_COLUMNS. "
                 WHERE lsc_lst_id = ". $this->getValue("lst_id"). "
                 ORDER BY lsc_number ASC ";
        $lsc_result   = $this->db->query($sql);
        
        while($lsc_row = $this->db->fetch_array($lsc_result))
        {
            $this->columns[$lsc_row['lsc_number']] = new TableListColumns($this->db);
            $this->columns[$lsc_row['lsc_number']]->setArray($lsc_row);
        }
    }
    
    // fuegt eine neue Spalte dem Spaltenarray hinzu
    function addColumn($number, $field, $sort = "", $filter = "")
    {
        if($number > 0 && strlen($field) > 0)
        {
            // falls Spalte noch nicht existiert, dann Objekt anlegen
            if(isset($this->columns[$number]) == false)
            {
                $this->columns[$number] = new TableListColumns($this->db);
                $this->columns[$number]->setValue("lsc_lsf_id", $this->getValue("lst_id"));
            }

            // Spalteninhalte belegen
            $this->columns[$number]->setValue("lsc_number", $number);
            if(is_numeric($field))
            {
                $this->columns[$number]->setValue("lsc_usf_id", $field);
                $this->columns[$number]->setValue("lsc_special_field", "");
            }
            else
            {
                $this->columns[$number]->setValue("lsc_usf_id", "");
                $this->columns[$number]->setValue("lsc_special_field", $field);
            }
            $this->columns[$number]->setValue("lsc_sort", $sort);
            $this->columns[$number]->setValue("lsc_filter", $filter);
        }
    }
    
    // entfernt die entsprechende Spalte aus der Konfiguration
    // all : gibt an, ob alle folgenden Spalten auch geloescht werden sollen
    function deleteColumn($number, $all = false)
    {
        if($number <= $this->countColumns())
        {
            if($all)
            {
                // alle Spalten ab der Nummer werden entfernt
                for($new_number = $this->countColumns(); $new_number >= $number; $new_number--)
                {
                    $this->columns[$new_number]->delete();
                    array_pop($this->columns);
                }
            }
            else
            {
                // es wird nur die einzelne Spalte entfernt und alle folgenden Spalten ruecken eins nach vorne
                for($new_number = $number; $new_number < $this->countColumns(); $new_number++)
                {
                    $this->columns[$new_number]->setValue("lsc_usf_id", $this->columns[$new_number+1]->getValue("lsc_usf_id"));
                    $this->columns[$new_number]->setValue("lsc_special_field", $this->columns[$new_number+1]->getValue("lsc_special_field"));
                    $this->columns[$new_number]->setValue("lsc_sort",   $this->columns[$new_number+1]->getValue("lsc_sort"));
                    $this->columns[$new_number]->setValue("lsc_filter", $this->columns[$new_number+1]->getValue("lsc_filter"));
                    $this->columns[$new_number]->save();
                }
                $this->columns[$new_number]->delete();
                array_pop($this->columns);
            }
        }
    }
    
    // Anzahl der Spalten der Liste zurueckgeben
    function countColumns()
    {
        return count($this->columns);
    }
    
    // liefert das entsprechende TableListColumns-Objekt zurueck
    function getColumnObject($number)
    {
        return $this->columns[$number];
    }
    
    // gibt das passende SQL-Statement zu der Liste zurueck
    // role_ids : Array ueber alle Rollen-IDs, von denen Mitglieder in der Liste angezeigt werden sollen
    // member_status : 0 - Nur aktive Rollenmitglieder
    //                 1 - Nur ehemalige Rollenmitglieder
    //                 2 - Aktive und ehemalige Rollenmitglieder
    function getSQL($role_ids, $member_status = 0)
    {
        global $g_current_user, $g_current_organization;
        $sql = "";
        $sql_select   = "";
        $sql_join     = "";
        $sql_where    = "";
        $sql_orderby  = "";
        $sql_role_ids = "";
        $sql_member_status = "";
    
        foreach($this->columns as $number => $list_column)
        {
            // Spalte anhaengen
            if(strlen($sql_select) > 0) 
            {
                $sql_select = $sql_select. ", ";
            }
            
            if($list_column->getValue("lsc_usf_id") > 0)
            {
                // dynamisches Profilfeld
                $table_alias = "row". $list_column->getValue("lsc_number"). "id". $list_column->getValue("lsc_usf_id");
                
                // JOIN - Syntax erstellen
                $sql_join = $sql_join. " LEFT JOIN ". TBL_USER_DATA ." $table_alias
                                           ON $table_alias.usd_usr_id = usr_id
                                          AND $table_alias.usd_usf_id = ".$list_column->getValue("lsc_usf_id");
                
                // hierbei wird die usf_id als Tabellen-Alias benutzt und vorangestellt
                $act_field = "$table_alias.usd_value";
            }
            else
            {
                // Spezialfelder z.B. usr_photo, mem_begin ...
                $act_field = $list_column->getValue("lsc_special_field");
            }

            $sql_select = $sql_select. $act_field;


            // Sortierung einbauen
            if(strlen($list_column->getValue("lsc_sort")) > 0)
            {
                if(strlen($sql_orderby) > 0) 
                {  
                    $sql_orderby = $sql_orderby. ", ";
                }
                $sql_orderby = $sql_orderby. $act_field. " ". $list_column->getValue("lsc_sort");
            }


            // Bedingungen fuer die Spalte verarbeiten
            if(strlen($list_column->getValue("lsc_filter")) > 0)
            {
                $value = $list_column->getValue("lsc_filter");

                if($list_column->getValue("lsc_usf_id") > 0)
                {
                    // ein benutzerdefiniertes Feld
                    
                    if($g_current_user->getPropertyById($list_column->getValue("lsc_usf_id"), "usf_type") == "CHECKBOX")
                    {
                        $type = "checkbox";
                        $value = strtoupper($value);
                        
                        // Ja bzw. Nein werden durch 1 bzw. 0 ersetzt, damit Vergleich in DB gemacht werden kann
                        if($value == "JA" || $value == "1" || $value == "TRUE")
                        {
                            $value = "1";
                        }
                        elseif($value == "NEIN" || $value == "0" || $value == "FALSE")
                        {
                            $value = "0";
                        }
                    }
                    elseif($g_current_user->getPropertyById($list_column->getValue("lsc_usf_id"), "usf_type") == "NUMERIC")
                    {
                        $type = "int";
                        if($g_current_user->getPropertyById($list_column->getValue("lsc_usf_id"), "usf_name") == "Geschlecht")
                        {
                            // bastwe: allow user to search for gender  M W U maennlich weiblich unbekannt
                            $value = strtoupper($value);
                            if($value == "U" )
                            {
                                $value = "0";
                            }
                            elseif($value == "M" )
                            {
                                $value = "1";
                            }
                            elseif($value == "W" )
                            {
                                $value = "2";
                            }
                        }
                    }
                    elseif($g_current_user->getPropertyById($list_column->getValue("lsc_usf_id"), "usf_type") == "DATE")
                    {
                        $type = "date";
                    }
                    else
                    {
                        $type = "string";
                    }
                }
                elseif($list_column->getValue("lsc_special_field") == "mem_begin" 
                || $list_column->getValue("lsc_special_field") == "mem_begin")
                {
                    $type = "date";
                }
                elseif($list_column->getValue("lsc_special_field") == "usr_login_name")
                {
                    $type = "string";
                }
                elseif($list_column->getValue("lsc_special_field") == "usr_photo")
                {
                    $type = "";
                }
                
                // Bedingungen aus dem Bedingungsfeld als SQL darstellen
                $parser    = new ConditionParser;
                $condition = $parser->makeSqlStatement($value, $act_field, $type);
                if($parser->error() == 0)
                {
                    $sql_where = $sql_where. $condition;
                }
            }        
        }

        // Rollen-IDs zusammensetzen
        foreach($role_ids as $key => $value)
        {
            if(is_numeric($key))
            {
                if(strlen($sql_role_ids) > 0) 
                {  
                    $sql_role_ids = $sql_role_ids. ", ";
                }
                $sql_role_ids = $sql_role_ids. "'".$value."'";
            }
        }

        // Status der Mitgliedschaft setzen
        if($member_status == 0)
        {
            $sql_member_status = " AND mem_begin <= '".DATE_NOW."'
                                   AND mem_end    > '".DATE_NOW."' ";
        }
        elseif($member_status == 1)
        {
            $sql_member_status = " AND mem_end < '".DATE_NOW."' ";
        }

        // SQL-Statement zusammenbasteln
        $sql = "SELECT mem_leader, usr_id, $sql_select
                  FROM ". TBL_ROLES. ", ". TBL_CATEGORIES. ", ". TBL_MEMBERS. ", ". TBL_USERS. "
                       $sql_join
                 WHERE rol_id    IN ($sql_role_ids)
                   AND rol_cat_id = cat_id
                   AND cat_org_id = ". $g_current_organization->getValue("org_id"). "
                   AND mem_rol_id = rol_id
                       $sql_member_status
                   AND mem_usr_id = usr_id
                   AND usr_valid  = 1
                       $sql_where 
                 ORDER BY mem_leader DESC ";
        if(strlen($sql_orderby) > 0)
        {
            $sql = $sql. ", ". $sql_orderby;
        }
        
        return $sql;
    }
    
    function clear()
    {
        $this->columns = array();
    
        parent::clear();
    }

    function save()
    {
        parent::save();
        
        // jetzt noch die einzelnen Spalten sichern
        foreach($this->columns as $number => $list_column)
        {
            if($list_column->getValue("lsc_lst_id") == 0)
            {
                $list_column->setValue("lsc_lst_id", $this->getValue("lst_id"));
            }
            $list_column->save();
        }
    }
    
    function delete()
    {
        // erst einmal die einzelnen Spalten loeschen
        foreach($this->columns as $number => $list_column)
        {
            $list_column->delete();
        }
    
        return parent::delete();
    }
}
?>