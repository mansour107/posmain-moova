<?php include('../includes/connect.php') ;

$department = $_POST['department'];
$sqlemps = "SELECT * FROM `employees` WHERE `department` = '$department' AND isdeleted != 1  ";
$resemps = $conn->query($sqlemps);
while ($rowemps = $resemps->fetch_assoc()) {
  
$employeeid = $rowemps['id'];
$startdate = $_POST['startdate'];
$startnum = new DateTime($startdate);
$enddate = $_POST['enddate'];
$endnum = new DateTime($enddate);

// التحقق من وجود سجلات في الفترة المحددة
$sqlchkdur = "SELECT * FROM attlog WHERE employee = $employeeid AND day >= '$startdate' AND day < '$enddate'";
$rowchkdur = $conn->query($sqlchkdur)->fetch_assoc();
if (isset($rowchkdur)) {
    echo "<h1> يوجد سجلات في الفتره المحدده من فضلك تأكد من الفتره<button style='font-size:40px'><a href='../add_calcsalary.php'>رجوع</a></button></h1> ";
    die;
}

// حساب عدد الأيام في الفترة المحددة
$interval = $startnum->diff($endnum);
$dayscount = $interval->days + 1; // يجب إضافة 1 لأن الفرق يشمل يوم البداية أيضًا

// استرجاع بيانات الموظف
$rowemp = $conn->query("SELECT * FROM employees WHERE id = $employeeid")->fetch_assoc();
$ent_tybe = $rowemp['ent_tybe'];
$hour_extra = $rowemp['hour_extra'];
$day_extra = $rowemp['day_extra'];

// استرجاع بيانات الشيفت
$shift = $rowemp['shift'];
$rowshft = $conn->query("SELECT * FROM shifts WHERE id = $shift")->fetch_assoc();

$shiftstart = $rowshft['shiftstart'];
$shiftend = $rowshft['shiftend'];
$instart = $rowshft['instart'];
$inend = $rowshft['inend'];
$outstart = $rowshft['outstart'];
$outend = $rowshft['outend'];
$workingdays = $rowshft['workingdays'];
$wdarray = explode(",", $workingdays);

// حلقة لمعالجة كل يوم في الفترة المحددة
for ($i = 0; $i < $dayscount; $i++) {
    $curday = $startnum->format('Y-m-d');
    $cdate = new DateTime($curday);
    $dayofweek = $cdate->format('N');

    // حساب ساعات العمل المتوقعة
    $time1 = strtotime($shiftend);
    $time2 = strtotime($shiftstart);
    $time_difference_in_seconds = $time1 - $time2;
    $time_difference_hours = floor($time_difference_in_seconds / 3600);
    $time_difference_minutes = floor(($time_difference_in_seconds % 3600) / 60);
    $time_difference_seconds = $time_difference_in_seconds % 60;

    // تحديد حالة اليوم (عمل أم لا)
    if (in_array($dayofweek, $wdarray)) {
        $statue = 1;
    } else {
        $statue = 0;
    }

    // التحقق من بصمة الدخول
    $sqlfpin = "SELECT MIN(time) AS fpin FROM attandance WHERE employee = '$employeeid' AND fpdate = '$curday' AND time > '$instart' AND time < '$inend' ";

    $rowfpin = $conn->query($sqlfpin)->fetch_assoc();
    $fpin = $rowfpin['fpin'];


    if (!$fpin == null) {
        $statue = 2;
    }

    $shiftstart_time = new DateTime($shiftstart);
    $shiftend_time = new DateTime($shiftend);
   
    
    if ($shiftend_time > $shiftstart_time) {
        // Case 1: Shift does not cross midnight
        $sqlfpout = "SELECT MAX(time) AS fpout FROM attandance WHERE employee = '$employeeid' AND fpdate = '$curday' AND time > '$outstart' AND time < '$outend'";
        $rowfpout = $conn->query($sqlfpout)->fetch_assoc();
        $fpout = $rowfpout['fpout'];
    } elseif ($shiftend_time < $shiftstart_time) {
        // Case 2: Shift crosses midnight
        $curday = (new DateTime($curday))->modify('+1 day')->format('Y-m-d');
        
        $sqlfpout = "SELECT MAX(time) AS fpout FROM attandance WHERE employee = '$employeeid' AND fpdate = '$curday' AND time > '$outstart' AND time < '$outend'";
        $rowfpout = $conn->query($sqlfpout)->fetch_assoc();
        $fpout = $rowfpout['fpout'];

        $fpout_time = new DateTime($fpout);
        $fpout_time->modify('+24 hours');
        $fpout = $fpout_time->format('H:i:s');
        $hours = $fpout_time->format('H');
        $minutes_seconds = $fpout_time->format(':i:s');
        $fpout = ($hours + 24) . $minutes_seconds;
        $curday = $startnum->format('Y-m-d');
    }
 
    if (!$fpout == null) {
        $statue = 2;
    }
       
    







    if($fpout > 24){
        $time_difference_in_seconds = $time1 + (24*3600) - $time2;
        $time_difference_hours = floor($time_difference_in_seconds / 3600);
        $time_difference_minutes = floor(($time_difference_in_seconds % 3600) / 60);
        $time_difference_seconds = $time_difference_in_seconds % 60;
    }
    list($hours, $minutes, $seconds) = array_pad(explode(':', $fpout), 3, '00');
if ($hours >= 24) {
    $hours -= 24;
    $fpout = sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    $time3 = strtotime($fpout) + 86400; // Add one day (86400 seconds)
} else {
    $time3 = strtotime($fpout);
}
        $time4 = strtotime($fpin);
        $time_difference2 = $time3 - $time4;


    if (!$fpout == null && !$fpin == null ) {
        
        $time_difference_hours2 = round(($time_difference2 / 3600), 2);
    } elseif ($fpout == null && $fpin == null) {
        $time_difference_hours2 = 0;
    } 
    else {
        $time_difference_hours2 = ($time_difference_hours / 2);
    }

    // حساب المستحقات المالية
    $dfh = ($rowemp['salary'] / 30) / $time_difference_hours;
    $dueforhour = round($dfh, 2);
    $realdue = floor($dueforhour * $time_difference_hours2);




    // إدخال البيانات في جدول سجلات الحضور
    $sqllog = ("INSERT INTO attlog 
    (employee, day, starttime, endtime, fpin, fpout, defhours, curhours, dueforhour, realdue, statue)
     VALUES 
     ('$employeeid','$curday','$shiftstart','$shiftend','$fpin','$fpout','$time_difference_hours ','$time_difference_hours2','$dueforhour','$realdue','$statue')");
    $startnum->add(new DateInterval('P1D'));





    "INSERT INTO attlog 
    (employee, day        , starttime, endtime  , fpin     , fpout    , defhours , curhours, dueforhour, realdue, statue) VALUES 
    ('130'   ,'2024-08-01','16:00:00','02:00:00','16:00:00','02:00:00','-14'     ,'10'     ,'-4.76'    ,'-48'   ,'2'    )";



    $conn->query($sqllog);
}
$sqlgetatt = "SELECT COUNT(*) AS holidays FROM attlog WHERE statue = '0' AND employee = '$employeeid'  AND day >= '$startdate' AND day <= '$enddate'";
$reshol = $conn->query($sqlgetatt);
$rowhol = $reshol->fetch_assoc();
$holidays = $rowhol['holidays'];
$holidays = $rowhol['holidays'];
$workdays = $dayscount - $holidays;
$exphours = $time_difference_hours * $workdays;

$sqlacchours = "SELECT SUM(curhours) AS curhours FROM attlog WHERE statue = '2' AND employee = '$employeeid'  AND day >= '$startdate' AND day <= '$enddate'";
$rowacchours = $conn->query($sqlacchours)->fetch_assoc();
$accualhours = round($rowacchours['curhours'], 2);

$sqlcountatt = "SELECT COUNT(*) AS attdays FROM attlog WHERE statue = '2' AND employee = '$employeeid'  AND day >= '$startdate' AND day <= '$enddate'";
$rowcountatt = $conn->query($sqlcountatt)->fetch_assoc();
$attdays = $rowcountatt['attdays'];


$rowcountabs = $conn->query("SELECT COUNT(*) AS absdays FROM attlog WHERE statue = '2' AND employee = '$employeeid'  AND day > '$startdate' AND day < '$enddate'")->fetch_assoc();
$absdays = $rowcountabs['absdays'];

// إنشاء ملخص الحضور
$info = " احتساب الرواتب من يوم " . $startdate . " الي يوم " . $enddate;

// اجر الساعه اليومي
$titleperhour = round($rowemp['salary'] / $exphours , 2);
// اجر الساعه اليومي

// exphours عدد الساعات المتوقعة
// اجر اليوم dayhours
        $extrasql="SELECT SUM(curhours - defhours) AS total_hours FROM attlog WHERE curhours > defhours AND statue != 0 AND employee = '$employeeid'  AND day >= '$startdate' AND day <= '$enddate'";
      

        $extra_time_hours =$conn->query($extrasql)->fetch_assoc();
        



        $extra_time_period = $conn->query("SELECT SUM(curhours) - SUM(defhours) AS total_difference FROM attlog where statue != 0 AND employee = '$employeeid'  AND day >= '$startdate' AND day <= '$enddate'")->fetch_assoc();
        

        $ext_hours =  $extra_time_hours['total_hours'];
        $ext_period = $extra_time_period['total_difference'];
        $basic_hours = $accualhours - $ext_hours;
        $basic_period = $accualhours - $ext_period;
        $ext_hours_ent = $ext_hours * $titleperhour *  $hour_extra ; 
        $ext_hours_basic = $ext_hours * $titleperhour;
        $basic_hours_ent = ($basic_hours * $titleperhour );



        // ايام العمل الفعلية
        $workdays = $dayscount - $holidays;
        // راتب اليوم
        $day_hours = $rowemp['salary'] / $workdays;



if ($ent_tybe == 1) {
    $info = " احتساب الرواتب من يوم " . $startdate . " الي يوم " . $enddate . " بنظام الاستحقاق بالساعات فقط";
    $entitle =  round($titleperhour * $accualhours ,2 );

}elseif ($ent_tybe == 2) {
    $info = " احتساب الرواتب من يوم " . $startdate . " الي يوم " . $enddate . " بنظام الاستحقاق ساعات عمل و اضافي يومي";
    $entitle = round($titleperhour * $accualhours ,2 ) + $ext_hours_ent - $ext_hours_basic ;

}elseif ($ent_tybe == 3){
    $info = " احتساب الرواتب من يوم " . $startdate . " الي يوم " . $enddate . " بنظام الاستحقاق ساعات عمل و اضافي خلال الفترة";
    if ($ext_period < 0) {
        $entitle = $accualhours * $titleperhour;
    }elseif ($ext_period > 0) {
        $entitle = (($accualhours - $ext_period) * $titleperhour) + ($ext_period * $titleperhour *  $hour_extra);
    }
}elseif ($ent_tybe == 4){
    $info = " احتساب الرواتب من يوم " . $startdate . " الي يوم " . $enddate . " بنظام الاستحقاق بناء علي الحضور";
    $entitle = round($attdays * ($rowemp['salary'] / $workdays ), 2);

}elseif ($ent_tybe == 5){
    $info = " احتساب الرواتب من يوم " . $startdate . " الي يوم " . $enddate . " بنظام الاستحقاق بنظام الحضور فقط";    
    $entitle = 0;
}







$sqlattdocs = "INSERT INTO attdocs 
(empid,fromdate,todate,alldays, workdays, exphours, accualhours, attdays, absdays, holidays, earlyminits, info , entitle) VALUES ('$employeeid','$startdate','$enddate','$dayscount','$workdays','$exphours','$accualhours','$attdays','$absdays','$holidays','0','$info' , '$entitle')";


$conn->query($sqlattdocs);
$docid = $conn->insert_id;

// تحديث سجلات الحضور بمعرف الملخص
$sqlupdate = "UPDATE attlog SET attdoc = '$docid' WHERE day >= '$startdate'  AND day <= '$enddate' And employee = $employeeid";
$conn->query($sqlupdate);


// تسجيل العملية
$conn->query("INSERT INTO `process`(`type`) VALUES ('add calcsalary')");


    // تسجيل العملية
}    
$conn->query("INSERT INTO `process`(`type`) VALUES ('add calcgroup')");

header('location:../calcsalary.php');
    
    include('../includes/footer.php') ;  
?>