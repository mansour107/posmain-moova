<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                <div class="card-head">




                GO

CREATE TABLE myoper_det(
	oper_det_id int IDENTITY(1,1) NOT NULL,
	int_oper_det_date int NULL,
	oper_head_id int NULL,
	comp_id int NULL,
	debit decimal(26, 4) NULL,
	credit decimal(26, 4) NULL,
	eng_debit decimal(26, 4) NULL,
	eng_credit decimal(26, 4) NULL,
	model_val decimal(26, 4) NULL,
	def_val decimal(26, 4) NULL,
	acc_id int NULL,
	stor_id int NULL,
	group_id int NULL,
	man_id int NULL,
	cost_center_id int NULL,
	has_costed_link tinyint NULL,
	is_not_active tinyint NULL,
	notes nvarchar(50) NULL,
	mst_no nvarchar(20) NULL,
	mst_date nvarchar(12) NULL,
	balance_befor decimal(26, 4) NULL,
	balance_after decimal(26, 4) NULL,
	det_Currency_id int NULL,
	det_Currency_unit_convert decimal(12, 6) NULL,
 CONSTRAINT PK_myoper_det PRIMARY KEY CLUSTERED 
(
	oper_det_id ASC
)WITH (PAD_INDEX  = OFF, STATISTICS_NORECOMPUTE  = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS  = ON, ALLOW_PAGE_LOCKS  = ON, FILLFACTOR = 80) ON PRIMARY
) ON PRIMARY

                </div>
                <div class="card-body">

                </div>
                <div class="card-footer">

                </div>
            </div>
        </div>
    </section>
</div>
