<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title float-left">
                    معدلات التقييم بالنسبه للموظفين 
                </h2>
                <div >  
                    <a class="btn bg-lime-400 float-right" href="add_empkbi.php">+</a>
                </div>

            </div>
            <div class="card-body">
                <div class="table">
                    <table id="mytable" class="table table-striped table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>م</th>
                                <th>اسم الموظف</th>
                                <th>المعدلات</th>

                            </tr>
                        </thead>
                        <tbody>
                        <?php 
                        $sql = "SELECT e.id as emp_id ,e.name as emp_name, GROUP_CONCAT(k.kname) as knames
                                FROM employees e
                                LEFT JOIN emp_kbis ek ON e.id = ek.emp_id
                                LEFT JOIN kbis k ON ek.kbi_id = k.id
                                where e.isdeleted != 1
                                GROUP BY e.id;";
                        $res = $conn->query($sql);
                        $x = 0;
                        while ($row = $res->fetch_assoc()) {
                            $x++;
                            $knames = explode(',', $row['knames']);
                            ?>
                        <tr>
                            <td><?= $x ?></td>
                            <td><a href="emprofile.php?id=<?= $row['emp_id'] ?>"><?= $row['emp_name'] ?></a><a href="add_empkbi.php?c=<?= $row['emp_name']?>&i=<?= $row['emp_id']?>&q=f898sd897fg98"><i class="fa fa-copy btn btn-sm bg-slate-200 float-right"></a></i></td>
                            <td>
                                <?php foreach ($knames as $kname): ?>
                                    <span class="badge badge-primary"><?= $kname ?></span>
                                <?php endforeach; ?>
                            </td>
                      
                        </tr>
                      
                        
                        <?php } ?>
                    </tbody>

                    </table>
                </div>

            </div>
            <div class="card-footer">

            </div>
        </div>








    </div>
    </section>
    </div>
<?php include('includes/footer.php') ?>
