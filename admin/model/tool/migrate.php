<?php


namespace Opencart\Admin\Model\Tool;

class Migrate extends \Opencart\System\Engine\Model
{
    private $type_add = 0;
    private $type_add_field = 1;
    private $type_edit_field = 2;
    private $type_edit_index = 3;
    private $type_edit = 4;
    private $type_remove = 5;

    public function getDBSchema(){
        $query = $this->db->query("SHOW TABLES");
        $data = array();
        $key = 'Tables_in_'.DB_DATABASE;
        foreach ($query->rows as $item){
            $table_name = $item[$key];
            $sql = "SHOW FULL COLUMNS FROM `{$table_name}`";
            $info = $this->db->query($sql);
            $data[$table_name]['info'] = $info->rows;
            $data[$table_name]['table_name'] = $table_name;

            $sql = "SHOW INDEX FROM `{$table_name}`";
            $info = $this->db->query($sql);
            $data[$table_name]['index'] = $info->rows;
        }
        return $data;
    }

    public static function arrayDifference(array $array1, array $array2, array $keysToCompare = null) {
        $serialize = function (&$item, $idx, $keysToCompare) {
            if (is_array($item) && $keysToCompare) {
                $a = array();
                foreach ($keysToCompare as $k) {
                    if (array_key_exists($k, $item)) {
                        $a[$k] = $item[$k];
                    }
                }
                $item = $a;
            }
            $item = serialize($item);
        };

        $deserialize = function (&$item) {
            $item = unserialize($item);
        };

        array_walk($array1, $serialize, $keysToCompare);
        array_walk($array2, $serialize, $keysToCompare);

        // Items that are in the original array but not the new one
        $deletions = array_diff($array1, $array2);
        $insertions = array_diff($array2, $array1);

        array_walk($insertions, $deserialize);
        array_walk($deletions, $deserialize);

        return array('insertions' => $insertions, 'deletions' => $deletions);
    }

    public function exportCurrentDBSchema(){
        $tables = [];
        $db_prefix = DB_PREFIX;
        $query = $this->db->query("SHOW TABLE STATUS FROM `".DB_DATABASE."`");
        foreach ($query->rows as $item){
            $tb = array();
            $tb['field'] = array();
            $tb['name'] = str_replace($db_prefix, '', $item['Name']);
            $tb['primary'] = array();
            $tb['index'] = array();

            $tb['engine'] = $item['Engine'];
            $tb['collate'] = $item['Collation'];
            $tb['charset'] = 'utf8';

            $info = $this->db->query("SHOW FULL COLUMNS FROM `{$item['Name']}`");
            foreach ($info->rows as $row){
                $not_null = ($row['Null'] == 'NO');
                $auto_increment = (strpos('auto_increment', $row['Extra']) !== false);
                if($auto_increment){
                    $tb_field = [
                        'name' => $row['Field'],
                        'type' => $row['Type'],
                        'not_full' => $not_null,
                        'auto_increment' => $auto_increment
                    ];
                }
                else {
                    $tb_field = [
                        'name' => $row['Field'],
                        'type' => $row['Type'],
                        'not_full' => $not_null
                    ];
                }

                $tb['field'] = $tb_field;
            }

            $info = $this->db->query("SHOW INDEX FROM `{$item['Name']}`");
            $tb_index = array();
            foreach ($info->rows as $row){
                if($row['Key_name'] == 'PRIMARY'){
                    $tb['primary'][] = $row['Column_name'];
                }
                else {
                    if(array_key_exists($row['Key_name'], $tb_index) == false){
                        $tb_index[$row['Key_name']] = array();
                    }
                    $tb_index[$row['Key_name']][] = $row['Column_name'];
                }
            }
            if(count($tb_index) > 0){
                foreach ($tb_index as $key => $value){
                    $tb['index'] = array(
                        'name' => $key,
                        'key' => $value
                    );
                }
            }

            if(count($tb['index']) == 0) { unset($tb['index']); }
            if(count($tb['primary']) == 0) { unset($tb['primary']); }

            $tables[] = $tb;
        }

        // Sort Tables
        usort($tables, function ($item1, $item2) {
            return strcmp($item1['name'], $item2['name']);
        });
        return $tables;
    }

    public function migrateDBScheme(){
        $this->load->helper('db_schema');
        $original = db_schema();
        $ori = array();

        foreach ($original as $value){
            $ori[$value['name']] = $value;
        }

        $current = $this->getDBSchema();
        $migrate = array();
        foreach ($current as $key => $table){
            $key_loop = str_replace(DB_PREFIX, '', $key);
            if(array_key_exists($key_loop, $ori) == true){

                // Compare field
                $data = $this->compareTableSchema($ori[$key_loop], $table);
                // Compare key and index
                $index_data = $this->compareTableIndex($ori[$key_loop], $table);
                if(count($index_data) > 0){
                    if(count($data) == 0){
                        $data = $index_data;
                    }
                    else{
                        array_push($data, $index_data);
                    }

                }

                if(count($data) > 0){
                    //$migrate[] = implode(';', $data);
                    $migrate[] = array(
                        'id' => $key_loop,
                        'tb_name' => $key,
                        'sql' => '',
                        'type' => $this->type_edit,
                        'data' => $data
                    );
                }

            }
            else {
                $sql = "SHOW CREATE TABLE `{$key}`";
                $query = $this->db->query($sql);
                $str = preg_replace( "/\r|\n/", "", $query->row['Create Table'] );
                $migrate[$key] = array(
                    'id' => $key_loop,
                    'tb_name' => $key,
                    'sql' => $str,
                    'type' => $this->type_add,
                    'data' => ''
                );
            }
        }
        return $migrate;
    }

    private function fullMachFieldData(string $str1, string $str2):bool{
        return ($str1 != '') && ($str2 != '') && (strtoupper($str1) !== strtoupper($str2));
    }

    private function compareTableIndex($original, $current){
        $primary = array();
        $index = array();
        $table_name = $current['table_name'];
        $migrate = array();

        if(array_key_exists('index', $original)){
            foreach ($original['index'] as $value){
                $index[$value['name']] = implode(',',$value['key']);
            }
        }

        if(array_key_exists('primary', $original)){
            foreach ($original['primary'] as $value){
                $primary[$value] = $value;
            }
        }

        foreach ($current['index'] as $item){
            $key_name = $item['Key_name'];
            $column_name = $item['Column_name'];

            if($key_name == 'PRIMARY'){
                if(array_key_exists($column_name, $primary) == false){
                    $sql = "ALTER TABLE `{$table_name}` ADD PRIMARY KEY (`{$column_name}`)";
                    $migrate[] = array(
                        'name' => $column_name,
                        'sql' => $sql,
                        'type' => $this->type_edit_index,
                        'log' => " -> {$key_name}",
                        'debug' => ""
                    );
                }
            }
            else {
                $is_found = false;
                $log = '';
                if (array_key_exists($key_name, $index) !== false){
                    $log = $index[$key_name];
                    if(strpos($index[$key_name], $column_name) !== false){ $is_found = true;}
                }
                if($is_found == false){
                    $sql = "ALTER TABLE `{$table_name}` ADD KEY `{$key_name}` (`{$column_name}`);";
                    $migrate[] = array(
                        'name' => $column_name,
                        'sql' => $sql,
                        'type' => $this->type_edit_index,
                        'log' => "({$column_name}, {$key_name}) -> {$log}",
                        'debug' => ""
                    );
                }
            }
        }

        return $migrate;
    }

    private function compareTableSchema($orginal, $current):array {
        $ori_fields = array();

        $migrate = array();
        $last_field = end($orginal['field']);
        foreach ($orginal['field'] as $value){
            $ori_fields[$value['name']] = $value;
        }

        $table_name = $current['table_name'];
        $db_prefix = '';
        foreach ($current['info'] as $item){
            $field_name = $item['Field'];
            $is_null = 'NOT NULL';
            $auto_incre = '';
            $type = strtoupper($item['Type']);

            if($item['Null'] == "NO") {
                $item['not_null'] = true;
            }
            else {
                $item['not_null'] = false;
                $is_null = 'NULL';
            }

            if($item['Extra'] == "auto_increment"){
                $item['auto_increment'] = true;
                $auto_incre = "AUTO_INCREMENT, AUTO_INCREMENT = 9999";
            }
            else {
                $item['auto_increment'] = false;
            }

            if(array_key_exists($field_name, $ori_fields) == true){
                $value = $ori_fields[$field_name];
                $db1 = json_encode($item);
                $db2 = json_encode($value);

                if(array_key_exists('auto_increment', $value) == false){
                    $value['auto_increment'] = false;
                }

                if($this->fullMachFieldData($item['Type'], $value['type'])){
                    $sql = "ALTER TABLE `{$db_prefix}{$table_name}` CHANGE `{$field_name}` `{$field_name}` {$type} {$is_null} {$auto_incre}";
                    $migrate[] = array(
                        'name' => $field_name,
                        'sql' => $sql,
                        'type' => $this->type_edit_field,
                        'log' => "type:{$value['type']} -> Type:{$item['Type']}",
                        'debug' => "<p>{$db1}</p><p>{$db2}</p>"
                    );
                    //$migrate[] = $sql;
                }
                else if($item['not_null'] != $value['not_null']){
                    $sql = "ALTER TABLE `{$db_prefix}{$table_name}` CHANGE `{$field_name}` `{$field_name}` {$type} {$is_null} {$auto_incre}";
                    $migrate[] = array(
                        'name' => $field_name,
                        'sql' => $sql,
                        'type' => $this->type_edit_field,
                        'log' => "not_null:{$value['not_null']} -> not_null:{$item['not_null']}",
                        'debug' => "<p>{$db1}</p><p>{$db2}</p>"
                    );
                    //$migrate[] = $sql;
                }
                else if($item['auto_increment'] != $value['auto_increment']){
                    $sql = "ALTER TABLE `{$db_prefix}{$table_name}` CHANGE `{$field_name}` `{$field_name}` {$type} {$is_null} {$auto_incre}";
                    $migrate[] = array(
                        'name' => $field_name,
                        'sql' => $sql,
                        'type' => $this->type_edit_field,
                        'log' => " -> auto_increment:{$item['auto_increment']}",
                        'debug' => "<p>{$db1}</p><p>{$db2}</p>"
                    );
                    //$migrate[] = $sql;
                }
            }
            else {
                $sql = "ALTER TABLE `{$db_prefix}{$table_name}` ADD `{$field_name}` {$type} CHARACTER SET utf8 COLLATE utf8_unicode_ci {$is_null} {$auto_incre}  AFTER `{$last_field['name']}`";
                $migrate[] = array(
                    'name' => $field_name,
                    'sql' => $sql,
                    'type' => $this->type_add_field,
                    'log' => " -> {$field_name}"
                );
                //$migrate[] = $sql;
            }
        }
        return $migrate;
    }
}