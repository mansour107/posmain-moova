
<div id="operations" class=""  style="display: none;">
 
        <div class="card" >
          <div class="card-header">

          </div>
          <div class="card-body">
          <div class="card-body">
                    <div class="table-responsive" id=>
                        
                    <table class="table table-hover table-bordered" id="myTable">
                            <thead>                   
                                <tr>
                                    <th>#</th>
                                    <th>اسم العملية</th>
                                    <th>الحساب المقابل</th>
                                    <th>قيمة العملية</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            // استعلام محسّن مع JOIN وLIMIT
                            $query = "SELECT 
                                        o.id, 
                                        o.pro_value,
                                        p.pname as pro_tybe_name,
                                        a.aname as acc_name
                                      FROM ot_head o
                                      LEFT JOIN pro_tybes p ON o.pro_tybe = p.id
                                      LEFT JOIN acc_head a ON o.acc2 = a.id
                                      WHERE o.isdeleted = 0 
                                      ORDER BY o.id DESC 
                                      LIMIT 50";
                            
                            $resop = $conn->query($query);
                            $x = 0;
                            
                            if ($resop && $resop->num_rows > 0) {
                                while ($rowop = $resop->fetch_assoc()) {
                                    $x++;
                            ?>
                            <tr>
                                    <th><?= $x ?></th>
                                    <th>
                                        <a class="btn btn-block btn-light border" 
                                           href="print/print_sales.php?id=<?= $rowop['id']?>" 
                                           target="_blank">
                                            <p><?= htmlspecialchars($rowop['pro_tybe_name'] ?? 'غير محدد') ?></p>
                                        </a>
                                    </th>
                                    <th><?= htmlspecialchars($rowop['acc_name'] ?? 'غير محدد') ?></th>
                                    <th><?= number_format($rowop['pro_value'], 2) ?></th>
                                </tr>
                            <?php 
                                }
                            } else {
                                echo '<tr><td colspan="4" class="text-center">لا توجد عمليات</td></tr>';
                            }
                            ?>
                            </tbody>
                        </table>


                       

                    
                   

                   
                    </div>
          </div>
        </div>
        </div>
        </div>