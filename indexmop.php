<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>HORSTEC.COM | Log in</title>
  <!-- Tell the browser to be responsive to screen width -->
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <!-- Ionicons -->
  <link rel="stylesheet" href="dist/css/css3.css">
  <!-- icheck bootstrap -->
  <link rel="stylesheet" href="plugins/icheck-bootstrap/icheck-bootstrap.min.css">
  <!-- Theme style -->
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
  <!-- Google Font: Source Sans Pro -->
  <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">
</head>
<?php
                        session_start();
                        if (isset($_COOKIE['login'])) {
                          include('includes/connect.php');
                            header("location:mop.php");
                        }
                        if ($_POST) {
                            
                            include('includes/connect.php');
                            $user = $_POST['uname'];
                            $pass = $_POST['password'];
                             
                            $hashpass = $pass;

                            $sql = "SELECT * FROM employees WHERE basma_id = '$user' AND password = '$hashpass' ";
                            $result = $conn->query($sql);
                            $row = $result->fetch_assoc();
                            $num = $result->num_rows;
                            if ($num ) {
                                # code...
                            }
                            $userid = $row['id'];
                            
                            if ($num > 0) {
                         
                           $sqltime = "INSERT INTO session_time(user) VALUES ('$geust')";
                           $restime = $conn->query($sqltime);
                           
                           setcookie("login", $userid, time() + (86400 * 30), "/");
                           
                           }
                           if ($num > 0 ) {
                               $_COOKIE['login'] = $user;
                               echo $user;
                               header("location:mop.php");
                           }
                       }

                  
                             
                                    ?>




<body class="hold-transition login-page">
<div class="login-box">
  <div class="login-logo">
    <a href=""><b>تسجيل الدخول</b></a>
  </div>
  <!-- /.login-logo -->
  <div class="card">
    <div class="card-body login-card-body">
      <p class="login-box-msg">سجل الدخول بالاسم و الباسورد</p>

      <form action="<?php $_SERVER['PHP_SELF']?>" method="POST">
        <div class="input-group mb-3">
          <input name="uname" type="text" class="form-control" placeholder="user">
          <div class="input-group-append">
            <div class="input-group-text">
              <span class="fas fa-envelope"></span>
            </div>
          </div>
        </div>
        <div class="input-group mb-3">
          <input name="password" type="password" class="form-control" placeholder="Password">
          <div class="input-group-append">
            <div class="input-group-text">
              <span class="fas fa-lock"></span>
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-8">
            <div class="icheck-primary">
              <input type="checkbox" id="remember">
              <label for="remember">
                تذكرني
              </label>
            </div>
          </div>
          <!-- /.col -->
          <div class="col-4">
            <button type="submit" class="btn btn-primary btn-block btn-flat">Sign In</button>
          </div>
          <!-- /.col -->
        </div>
      </form>


      <!-- /.social-auth-links -->
    </div>
    <!-- /.login-card-body -->
  </div>
</div>
<!-- /.login-box -->

<!-- jQuery -->
<script src="plugins/jquery/jquery.min.js"></script>
<!-- Bootstrap 4 -->
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>

</body>
</html>