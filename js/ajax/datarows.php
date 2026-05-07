<?php
        include('../includes/connect.php');
                    $resfatdet = $conn->query("SELECT * FROM fat_details where fatid is null ");
                    $x=0;
                    while ($rowfatdet = $resfatdet->fetch_assoc()) {
                    $x++;
                    ?>
                    <tr row_id="<?= $rowfatdet['id'] ?>">
                    <th><?= $x ?></th>
                    <th class="col-5"><?php $itmid = $rowfatdet['item_id'];
                    $rowiname =$conn->query("SELECT * FROM myitems where id = $itmid")->fetch_assoc();
                    echo $rowiname['iname'] ?></th>
                    <th><?= $rowfatdet['qty']?></th>
                    <th><?= $rowfatdet['price']?></th>
                    <th><?= $rowfatdet['discount']?></th>
                    <th><?= $rowfatdet['det_value']?></th>
                    <th><button class="btn btn-danger"  onclick="deleteRow(<?= $rowfatdet['id']  ?>)">X</button></th>
                </tr>
                <?php } ?>
