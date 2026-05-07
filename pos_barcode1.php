<?php include('includes/posheader.php');?>
<link rel="stylesheet" href="dist/css/pos.css">
</head>
<body>
<?php include('elements/pos/navbar.php');?>

<?php if(isset($_GET['edit'])){
    $id = $_GET['edit'];
    $rowed = $conn->query("SELECT * FROM ot_head where id = $id")->fetch_assoc();
} ?>


<div class="row">
    <div class="div1">
        0
    </div>
    <div class="div2">
        0
    </div>
</div>



<?php include('includes/posfooter.php');?>