<?php

namespace Nueaf\Uteeni;

class ClassBuilder
{

    private $fields = array();
    private $assoc = array();
    private $tableName;
    private $repository;
    private $conn;
    private $database;
    private $meta_indent = "\n\t\t\t\t\t\t\t\t";
    private $indent = "\n\t\t";
    private $doubleLineBreak = "\n\n";
    private $lineBreak = "\n";
    private $classPrefix = "";
    private $classSuffix = "";
    private $dbConfig;

    public function __construct($tableName, $database = "mysql")
    {
        $this->tableName = $tableName;
        $this->database  = strtoupper($database);
        $this->conn = Database::connect($database);

        $this->dbConfig = ActiveRecordDatabase::getConfig();
        $this->repository = $this->dbConfig[$this->database."Database"]['repository'] ?: "../models";
        $this->classPrefix = $this->dbConfig[$this->database."Database"]['prefix'];
        $this->classSuffix = $this->dbConfig[$this->database."Database"]['suffix'];
    }

    public function parseTable()
    {
        $functionName = "parse" . ucfirst($this->dbConfig[$this->database."Database"]["type"]) . "Table";
        $this->$functionName();
    }

    private function parseMysqlTable()
    {
        $sql = "DESCRIBE {$this->tableName};";
        $result = $this->conn->query($sql);

        foreach($result as $rows){
            $meta = array();
            $meta['type']         = $this->parse_mysql_field_type($rows['Type']);
            $meta['primary']     = $rows['Key']     == 'PRI' ? 'true' : 'false';
            $meta['required']     = $rows['Null'] == 'NO' ? 'true' : 'false';
            $meta['default']     = "'$rows[Default]'";
            $meta['extra']        = "'$rows[Extra]'";

            $this->fields[$rows['Field']] = $meta;
        }

        $sql = "SELECT COUNT(1) AS cnt, kcu.* FROM information_schema.key_column_usage kcu WHERE TABLE_SCHEMA='" . $this->dbConfig[$this->database."Database"]["db"] . "' AND '{$this->tableName}' IN (TABLE_NAME,REFERENCED_TABLE_NAME) AND REFERENCED_TABLE_SCHEMA IS NOT NULL GROUP BY CONSTRAINT_NAME HAVING cnt=1";
        $result = $this->conn->query($sql);
        $prefix_classes = Array();
        foreach($result as $rows) {
            $assoc = Array();
            if ($rows["TABLE_NAME"]==$this->tableName) {
                if ($rows["TABLE_NAME"]==$rows["REFERENCED_TABLE_NAME"]) {
                    //In case of self referencing tables
                    $assoc["ass_type"] = "has_many";
                    $assoc["class"] = $rows["TABLE_NAME"];
                    $assoc["class_property"] = $rows["COLUMN_NAME"];
                    $assoc["local_property"] = $rows["REFERENCED_COLUMN_NAME"];
                    $name = $assoc["class_property"];
                    if (substr($name, -3)=="_id") { $name = substr($name, 0, -3);
                    }
                    if ($name==$assoc["class_property"]) { $name .= "_reverse_rel";
                    } else { $name .= "_reverse";
                    }
                    
                    $this->assoc[$name] = $assoc;
                }
                $assoc["ass_type"] = "has_one";
                $assoc["class"] = $rows["REFERENCED_TABLE_NAME"];
                $assoc["class_property"] = $rows["REFERENCED_COLUMN_NAME"];
                $assoc["local_property"] = $rows["COLUMN_NAME"];
                $name = $assoc["local_property"];
                if (substr($name, -3)=="_id") { $name = substr($name, 0, -3);
                }
                if ($name==$assoc["local_property"]) { $name .= "_rel";
                }
            } else {
                $assoc["ass_type"] = "has_many";
                $assoc["class"] = $rows["TABLE_NAME"];
                $assoc["class_property"] = $rows["COLUMN_NAME"];
                $assoc["local_property"] = $rows["REFERENCED_COLUMN_NAME"];
                $name = $assoc["class"];
                $name = Pluralizer::pluralize($name);
            }

            if (array_key_exists($name, $this->assoc) && $this->assoc[$name]) {
                $prefix_classes[] = $this->assoc[$name]["class"];
                $new_name = $this->assoc[$name]["class"]."_".$this->assoc[$name]["class_property"];
                if (substr($new_name, -3)=="_id") { $new_name = substr($new_name, 0, -3);
                }
                $new_name = Pluralizer::pluralize($new_name);
                $this->assoc[$new_name] = $this->assoc[$name];
                $this->assoc[$name] = null;
            }

            if (in_array($assoc["class"], $prefix_classes)) {
                $name = $assoc["class"]."_".$assoc["class_property"];
                if (substr($name, -3)=="_id") { $name = substr($name, 0, -3);
                }
                $name = Pluralizer::pluralize($name);
            }

            $this->assoc[$name] = $assoc;
        }

        $this->assoc = array_filter($this->assoc);
    }

    private function parseTnsnamesTable()
    {
        $this->parseOCITable(); 
    }
    private function parseOCITable()
    {

        $primaryField = $this->getOCIPrimaryKey();
        $sql = "SELECT * FROM user_tab_columns WHERE TABLE_NAME = '{$this->tableName}'";
        $result = $this->conn->query($sql);
        $meta = array();
        while($row = $result->fetch()){
            $meta['type']         = $this->parse_oci_field_type(strtolower($row['DATA_TYPE']));
            $meta['primary']    = $primaryField == $row['COLUMN_NAME'] ? 'true' : 'false';
            $meta['required']    = $row['NULLABLE'] == 'N' ? 'true' : 'false';
            $meta['default']    = "'$row[DATA_DEFAULT]'";
            $meta['extra']        = "''";

            $this->fields[$row['COLUMN_NAME']] = $meta;
        }
    }

    private function getOCIPrimaryKey()
    {
        $sql = "SELECT cols.table_name, cols.column_name, cols.position, cons.status, cons.owner
				FROM all_constraints cons, all_cons_columns cols
				WHERE cols.table_name = '{$this->tableName}'
				AND cons.constraint_type = 'P'
				AND cons.constraint_name = cols.constraint_name
				AND cons.owner = cols.owner
				ORDER BY cols.table_name, cols.position";
        $result = $this->conn->query($sql);
        if(!$result) {
            var_dump($this->conn->errorInfo());exit;
            return false;
        }
        if(false !== ($row = $result->fetchObject())) {
            if($row->STATUS == 'ENABLED') {
                return $row->COLUMN_NAME;
            }
        }
        return false;

    }

    public function buildClass()
    {
        $metaString  = "";
        $propString  = "";
        $docString   = "";
        $assocString = "";
        $this->buildPropertyStrings($metaString, $propString, $docString);
        $this->buildAssocString($assocString);
        $str_buffer  = "<?php" . $this->lineBreak;
        $str_buffer .= "/**" . $this->lineBreak;
        $str_buffer .= "* Class generated using Uteeni ORM generator" . $this->lineBreak;
        $str_buffer .= "* Created: ". date("Y-m-d H:i:s", time()) . $this->lineBreak;
                $str_buffer .= $docString;
        $str_buffer .= "**/" . $this->lineBreak;
        $str_buffer .= "class ". $this->classPrefix . $this->camelcase($this->tableName) . $this->classSuffix ." extends ActiveRecord {" . $this->doubleLineBreak;

        $str_buffer .= $this->indent . "public \$table_name = '{$this->tableName}';";
        $str_buffer .= $this->indent . "public \$database = '{$this->database}';" . $this->lineBreak;
        $str_buffer .= $propString . $metaString;
        $str_buffer .= $this->doubleLineBreak;
        $str_buffer .= $assocString;

        $str_buffer .= $this->doubleLineBreak;

        $end_generate_comment = "/* end_auto_generate\ndo_not_delete_this_comment */";
        $str_buffer .= $end_generate_comment;

        $filename = "{$this->repository}/".str_replace("_", "", strtolower($this->classPrefix.$this->tableName.$this->classSuffix))."{$this->classSuffix}.php";
        
        if(file_exists($filename)) {
            $old_contents = str_replace("\r", "", file_get_contents($filename));
            $preserve = substr($old_contents, (strpos($old_contents, $end_generate_comment) + strlen($end_generate_comment)));
                        
            $top = substr($old_contents, 0, (strpos($old_contents, $end_generate_comment) + strlen($end_generate_comment)));
            $created_date_pattern = "/. Created: [0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}/";
            if (preg_replace($created_date_pattern, "DATE", $top)==preg_replace($created_date_pattern, "DATE", $str_buffer)) { return false;
            }

            $str_buffer .= $preserve;
        }
        $beginnings = substr_count($str_buffer, "{");
        $endings     = substr_count($str_buffer, "}");
        $str_buffer .= $beginnings == ($endings + 1) ? $this->doubleLineBreak . "}" : "";

        file_put_contents($filename, $str_buffer);
    }

    private function buildPropertyStrings(&$metaString, &$propString, &$docString)
    {
        $propString = $this->indent . 'protected $properties = array(';
        $metaString = $this->indent . 'protected $meta = array(';
        foreach($this->fields as $field => $meta){
            $propString .= $this->meta_indent . "'$field'" . ' => null,';
            $metaString .= $this->meta_indent;
            $metaString .= "'$field'" . ' => array(';
            foreach($meta as $type => $value){
                $metaString .= " '" . $type . "' => " . "$value,";
            }
            $metaString = preg_replace("/,$/", "", $metaString);
            $metaString .= "),";
                        $docString .= " * @property " . str_replace("'", "", $meta['type']) . " $" . $field . $this->lineBreak;
                        $docString .= " * @method bool find_by_" . $field . "(" . str_replace("'", "", $meta['type']) . " $" . $field . ")" . $this->lineBreak;
                        $docString .= " * @method array find_all_by_" . $field . "(" . str_replace("'", "", $meta['type']) . " $" . $field . ")" . $this->lineBreak;
        }
        $metaString = preg_replace("/,$/", "", $metaString);
        $propString = preg_replace("/,$/", "", $propString);
        $propString = $propString . $this->indent . ");";
        $metaString = $metaString . $this->indent . ");";
    }

    private function buildAssocString(&$assocString)
    {
        $lengths = Array();

        foreach ($this->assoc as $name=>$assoc) {
            if (!array_key_exists("name", $lengths) || $lengths["name"]<strlen($name)) { $lengths["name"] = strlen($name);
            }
            foreach ($assoc as $name=>$item) {
                if (!array_key_exists($name, $lengths) || $lengths[$name]<strlen($item)) { $lengths[$name] = strlen($item);
                }
            }
        }

        $assocString = $this->indent.'protected $foreign_keys = Array(';
        foreach ($this->assoc as $name=>$assoc) {
            $name = strtolower($name);
            $assocString .= $this->meta_indent."'$name'".str_repeat(" ", ($lengths["name"]-strlen($name)))." => Array(";
            foreach ( Array("ass_type","class","class_property","local_property") as $property) {
                $assocString .= "'$property'=>'{$assoc["$property"]}'".str_repeat(" ", ($lengths[$property]-strlen($assoc[$property]))).", ";
            }
            $assocString = preg_replace("/, $/", "", $assocString);
            $assocString .= "),";
        }
        $assocString = preg_replace("/,$/", "", $assocString);
        $assocString .= $this->indent.');'.$this->lineBreak;
    }

    function parse_mysql_field_type($type)
    {
        switch($type){
        case strpos($type, 'enum') !== false:
            //return parse_enum($type);
            return "'enum'";
        case strpos($type, 'date') !== false:
        case strpos($type, 'datetime') !== false:
            return "'date'";
        case strpos($type, 'varchar') !== false:
        case strpos($type, 'char') !== false:
            return "'string'";
        case strpos($type, 'tinyint') !== false:
        case strpos($type, 'mediumint') !== false:
        case strpos($type, 'int') !== false:
            return "'integer'";
        case strpos($type, 'decimal') !== false:
        case strpos($type, 'float') !== false;
            return "'double'";//PHP blunder
        case strpos($type, 'blob') !== false:
            return "'blob'";
        case strpos($type, 'text') !== false:
            return "'text'";
        default:
            return "'$type'";
        }
    }

    function parse_oci_field_type($type)
    {
        switch($type){
        case strpos($type, 'date') !== false:
        case strpos($type, 'datetime') !== false:
            return "'date'";
        case strpos($type, 'varchar') !== false:
        case strpos($type, 'char') !== false:
            return "'string'";
        case strpos($type, 'tinyint') !== false:
        case strpos($type, 'mediumint') !== false:
        case strpos($type, 'int') !== false:
            return "'integer'";
        case strpos($type, 'number') !== false:
        case strpos($type, 'decimal') !== false:
        case strpos($type, 'float') !== false;
            return "'double'";//PHP blunder
        case strpos($type, 'blob') !== false:
            return "'blob'";
        case strpos($type, 'clob') !== false:
        case strpos($type, 'text') !== false:
            return "'text'";
        default:
            return "'$type'";
        }
    }

    function parse_ms_field_type($type)
    {
        switch($type){
        case strpos($type, 'date') !== false:
        case strpos($type, 'datetime') !== false:
            return "'date'";
        case strpos($type, 'varchar') !== false:
        case strpos($type, 'char') !== false:
            return "'string'";
        case strpos($type, 'tinyint') !== false:
        case strpos($type, 'mediumint') !== false:
        case strpos($type, 'int') !== false:
            return "'integer'";
        case strpos($type, 'number') !== false:
        case strpos($type, 'decimal') !== false:
        case strpos($type, 'float') !== false;
            return "'double'";//PHP blunder
        case strpos($type, 'blob') !== false:
            return "'blob'";
        case strpos($type, 'clob') !== false:
        case strpos($type, 'text') !== false:
            return "'text'";
        default:
            return "'$type'";
        }
    }

    function parse_enum($enum)
    {
        return str_replace("enum", "array", $enum);
    }

    function camelcase($str)
    {
        $words = array();
        foreach(explode("_", $str) as $word){
            array_push($words, ucfirst(strtolower($word)));
        }
        return join("", $words);
    }

}

