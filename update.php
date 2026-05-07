<?php include('includes/header.php') ?>
<center>
<form action="update/doupdate.php" method="post">
    
    <div class="row">

    <textarea name="update" id="" cols="200" rows="20">

    CREATE TABLE `closed_orders` (
  `id` int(11) NOT NULL,
  `shift` varchar(10) NOT NULL,
  `user` varchar(10) NOT NULL,
  `date` date DEFAULT NULL,
  `strttime` datetime DEFAULT NULL,
  `endtime` time DEFAULT NULL,
  `total_sales` double NOT NULL DEFAULT 0,
  `delevery` double NOT NULL DEFAULT 0,
  `tables` double NOT NULL DEFAULT 0,
  `takeaway` double NOT NULL DEFAULT 0,
  `expenses` double NOT NULL DEFAULT 0,
  `fund_before` double NOT NULL DEFAULT 0,
  `fund_after` double NOT NULL DEFAULT 0,
  `exp_notes` varchar(30) DEFAULT NULL,
  `cash` double NOT NULL DEFAULT 0,
  `info` varchar(50) DEFAULT NULL,
  `crtime` datetime NOT NULL DEFAULT current_timestamp(),
  `mdtime` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `info2` varchar(20) NOT NULL,
  `tenant` int(11) NOT NULL DEFAULT 1,
  `branch` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `closed_orders`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `closed_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
COMMIT;


    </textarea>        
    </div>
    <div class="row">
    <button type="submit" class="btn btn-outline-dark">update</button>
    </div>
    </form>
    </center>



    <?php include('includes/footer.php') ?>
