<?php
include('../../includes/connect.php');

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo "Invalid request method";
    exit();
}

if (!isset($_POST['password'])) {
    echo "Password is not set";
    exit();
}

$pass = $_POST['password'];

// Sanitize input
$pass = $conn->real_escape_string($pass);


    if ( $pass == "hadi@1234") {
        $tables = [
            "acc_groups",
            "allowances",
            "analisys",
            "attandance",
            "attdocs",
            "attlog",
            "barcodes",
            "calls",
            "cases",
            "chances",
            "clients",
            "cost_centers",
            "criminals",
            "crm_style",
            "ctp",
            "cvs",
            "departments",
            "drugs",
            "emplog",
            "employees",
            "emp_allowences",
            "extras",
            "fat_details",
            "fats",
            "hiringcontracts",
            "holidays",
            "imgs",
            "imporfplog",
            "item_group",
            "item_group2",
            "item_group3",
            "item_units",
            "joplevels",
            "joprules",
            "jops",
            "joptybes",
            "journal_entries",
            "journal_heads",
            "karta",
            "myinstallments",
            "myitems",
            "myoper_det",
            "myrents",
            "myvouchers",
            "my_news",
            "orders",
            "order_status",
            "order_types",
            "paper_types",
            "permits",
            "prescdetails",
            "prescs",
            "print",
            "prods",
            "pst_activities",
            "pst_criminals",
            "pst_crmstyles",
            "pst_gangs",
            "pst_issues",
            "rays",
            "reservations",
            "salaries",
            "services",
            "session_time",
            "skills",
            "tasks",
            "test",
            "transactions",
            "users",
            "vacancies",
            "visits",
            "zankat",
            "ot_head",
            "acc_head"
        ];

        // Start transaction
        $conn->begin_transaction();

        try {
            foreach ($tables as $table) {
                if ($table == "users" || $table == "cost_centers") {
                    $sql = "DELETE FROM $table WHERE id > 1";
                } elseif ($table == "acc_head") {
                    $sql = "DELETE FROM $table WHERE deletable = 1";
                } else {
                    $sql = "DELETE FROM $table";
                }

                if (!$conn->query($sql)) {
                    throw new Exception("Error deleting from $table: " . $conn->error);
                }
            }
            $conn->query("UPDATE acc_head set balance = 0");

            // Commit transaction
            $conn->commit();
            header('Location: ../../about.php');
        } catch (Exception $e) {
            // Rollback transaction
            $conn->rollback();
            echo "Failed to clear tables: " . $e->getMessage();
        }
    } else {
        echo "The password is wrong";
    }
    

$conn->close();
?>
