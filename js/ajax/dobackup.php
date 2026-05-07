<?php include '../includes/connect.php';

$tables =array();
$res = $conn->query("SHOW TABLES ");
while ($row = mysqli_fetch_row($res)) {
$tables[] = $row[0];
}


$return = '';
foreach ($tables as $table) {
    $res = $conn->query("SELECT * FROM ".$table);
    $num_fields = mysqli_num_fields($res);

    $return .= 'DROP TABLE '.$table.';';
    $row2 = mysqli_fetch_row(mysqli_query($conn,'SHOW CREATE TABLE '.$table));
    
    $return .= "\n\n".$row2[1].";\n\n";

    for ($i=0; $i < $num_fields ; $i++) { 
        while ($row = mysqli_fetch_row($res)) {
            $return .= 'INSERT INTO '.$table.' VALUES (';
            for ($j=0; $j < $num_fields ; $j++) { 
                $row[$j] = addslashes($row[$j]);
                if (isset($row[$j])) { 
                $return .= '"'.$row[$j].'"';
                }else{
                $return .= '""';    
                }
                if ($j < $num_fields-1) {
                $return .= ',';
                }
            }
            $return .= ");\n";    
        }
    }
    $return .= "\n\n\n";
}

$handle = fopen('../BACKUP/backup.sql','w+');
fwrite($handle , $return);
fclose($handle);
echo "successfully backed up";
