<!-- Main Sidebar Container -->
<aside class="main-sidebar elevation-4 font-light">
  <!-- Brand Logo -->


  <!--                                             Sidebar                                                                        -->
  <div class="sidebar" style="height:100%; overflow-y: auto;">

    <div class="user-panel d-flex flex-column">
      <div class="d-flex align-items-center mb-2">
        <div class="image-user me-2">
          <img src="assets/logo/hors.png" alt="User Image"
            style="height: 45px; width: 45px; border-radius: 10px; object-fit: cover; box-shadow: 0 2px 8px rgba(0,0,0,0.2);"
            onerror="this.onerror=null; this.src='assets/logo/hors.png';">
        </div>
        <div class="info flex-grow-1">
          <a href="" class="d-block" style="margin-bottom: 0;"><?php echo "اهلا يا " . $_SESSION['login'] ?></a>
        </div>
      </div>
      <div class="search-wrapper" style="position: relative;">
        <i class="fas fa-search"
          style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 0.85rem; pointer-events: none; z-index: 1;"></i>
        <input class="form-control form-control-sm" type="text" placeholder="<?= $lang_search_placeholder ?>"
          id="searchSide" style="padding-right: 35px;">
      </div>
    </div>
    <nav class="mt-2">

      <!--                                             main                                                                        -->

      <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
        <li class="nav-item has-treeview">
          <a href="index.php" class="nav-link">
            <i class="nav-icon fas fa-tachometer-alt"></i>
            <p> <?= $lang_dashboard ?></p>
          </a>
        </li>


        <!--                                             البيانات الاساسيه                                                                        -->
        <?php if (($role['sid_entry'] ?? 0) == 1) { ?>
          <li class="nav-item has-treeview">
            <a href="#" class="nav-link nav-link-basic">
              <i class="nav-icon fas fa-pen"></i>
              <p>
                <?= $lang_basic_data ?>
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview bg-white-950 shadow-inner shadow-slate-500" id="acc-report"
              style="display: none;">


              <li class="nav-item">
                <a href="acc_report.php?acc=clients" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_clients ?></p>
                </a>
              </li>

              <li class="nav-item">
                <a href="acc_report.php?acc=suppliers" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_suppliers ?></p>
                </a>
              </li>


              <li class="nav-item">
                <a href="acc_report.php?acc=funds" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_funds ?></p>
                </a>
              </li>


              <li class="nav-item">
                <a href="acc_report.php?acc=banks" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_banks ?></p>
                </a>
              </li>


              <li class="nav-item">
                <a href="acc_report.php?acc=stores" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_stores ?></p>
                </a>
              </li>

              <li class="nav-item">
                <a href="acc_report.php?acc=expenses" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_expenses ?></p>
                </a>
              </li>

              <li class="nav-item">
                <a href="acc_report.php?acc=revenous" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_revenues ?></p>
                </a>
              </li>


              <li class="nav-item">
                <a href="acc_report.php?acc=creditors" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_creditors ?></p>
                </a>
              </li>


              <li class="nav-item">
                <a href="acc_report.php?acc=depitors" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_debtors ?></p>
                </a>
              </li>


              <li class="nav-item">
                <a href="acc_report.php?acc=partners" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_partners ?></p>
                </a>
              </li>

              <li class="nav-item">
                <a href="acc_report.php?acc=assets" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_assets ?></p>
                </a>
              </li>

              <li class="nav-item">
                <a href="acc_report.php?acc=rentable" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_rentable_assets ?></p>
                </a>
              </li>

              <li class="nav-item">
                <a href="mytowns.php?acc=rentable" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_towns ?></p>
                </a>
              </li>


              <li class="nav-item">
                <a href="acc_report.php?acc=employees" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_sideemployees ?></p>
                </a>
              </li>
            </ul>
          </li>
        <?php } ?>





        <!--                                                                        -->
        <?php if (($role['sid_stock'] ?? 0) == 1) { ?>

          <li class="nav-item has-treeview">
            <a href="#" class="nav-link nav-link-basic">
              <i class="nav-icon fas fa-store"></i>
              <p>
                <?= $lang_inventory_management ?>
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview shadow-inner shadow-slate-500" id="stock" style="display: none;">


              <li class="nav-item">
                <a href="add_item.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_new_item ?>
                  </p>
                </a>
              </li>




              <li class="nav-item">
                <a href="acc_report.php?acc=stores" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_stores ?>
                  </p>
                </a>
              </li>


              <li class="nav-item" id="myitems">
                <a href="myitems.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_items ?>
                  </p>
                </a>
              </li>


              <li class="nav-item">
                <a href="myunits.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_units ?>
                  </p>
                </a>
              </li>


              <li class="nav-item">
                <a href="mygroups.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_groups ?>
                  </p>
                </a>
              </li>

              <li class="nav-item">
                <a href="item_categories.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_categories ?>
                  </p>
                </a>
              </li>

              <li class="nav-item">
                <a href="barcode_search.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_item_price ?>
                  </p>
                </a>
              </li>


              <li class="nav-item">
                <a href="items_start_balance.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_opening_balances_stores ?>
                  </p>
                </a>
              </li>



            </ul>
          </li>
        <?php } ?>




<!-- -------------------نقاط البيع  -->
        <?php if (($role['sid_sales'] ?? 0) == 1) { ?>

          <li class="nav-item has-treeview">
            <a href="#" class="nav-link nav-link-basic">
              <i class="nav-icon fas fa-store"></i>
              <p>
                <?= $lang_pos ?>
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview shadow-inner shadow-slate-500" id="stock" style="display: none;">

              <?php
              // جلب نوع نظام POS من الإعدادات
              $pos_type = $rowstg['pos_type'] ?? 'barcode';

              if ($pos_type === 'clothes') {
                // عرض POS الملابس فقط
                ?>
                <li class="nav-item">
                  <a href="pos_clothes.php" class="nav-link">
                    <i class="nav-icon fas fa-tshirt"></i>
                    <p>POS الملابس</p>
                  </a>
                </li>
              <?php } else {
                // عرض POS العادي فقط
                ?>
                <li class="nav-item">
                  <a href="pos_barcode.php" class="nav-link">
                    <i class="nav-icon fas fa-list"></i>
                    <p><?= $lang_pos_barcode ?></p>
                  </a>
                </li>
              <?php } ?>


              <li class="nav-item">
                <a href="crud_tables.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p><?= $lang_table_management ?> </p>
                </a>
              </li>



              <li class="nav-item" id="myitems">
                <a href="closed_sessions.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p><?= $lang_closed_sessions ?></p>
                </a>
              </li>

            </ul>
          </li>
        <?php } ?>



        <?php if (($role['sid_cards'] ?? 0) == 1) { ?>

          <li class="nav-item has-treeview">
            <a href="#" class="nav-link nav-link-basic">
              <i class="nav-icon fas fa-credit-card"></i>
              <p>
                <?= $lang_card_management_title ?> <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview shadow-inner shadow-slate-500" id="cards" style="display: none;">

              <li class="nav-item">
                <a href="add_booking.php" class="nav-link">
                  <i class="nav-icon fas fa-plus"></i>
                  <p>
                    <?= $lang_add_card ?>
                  </p>
                </a>
              </li>



              <li class="nav-item" id="card-pass">
                <a href="booking.php" class="nav-link">
                  <i class="nav-icon fas fa-arrow-right"></i>
                  <p><?= $lang_card_pass ?></p>
                </a>
              </li>

              <li class="nav-item" id="card-management">
                <a href="bookings.php" class="nav-link">
                  <i class="nav-icon fas fa-cogs"></i>
                  <p><?= $lang_card_management ?></p>
                </a>
              </li>

              <li class="nav-item" id="card-clients">
                <a href="clients.php" class="nav-link">
                  <i class="nav-icon fas fa-users"></i>
                  <p><?= $lang_client_management ?></p>
                </a>
              </li>


            </ul>
          </li>
        <?php } ?>







        <?php if (($role['sid_purchases'] ?? 0) == 1) { ?>


          <li class="nav-item has-treeview">
            <a href="#" class="nav-link nav-link-basic">
              <i class="nav-icon fa fas-sharp fa-solid fa-file-invoice-dollar fas-2xl"></i>
              <p>
                <?= $lang_purchases ?>
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview shadow-inner shadow-slate-500" style="display: none;">





              <li class="nav-item">
                <a href="sales.php?q=sale" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_purchase_invoice ?>
                  </p>
                </a>
              </li>


              <li class="nav-item">
                <a href="sales.php?q=resale" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_purchase_return ?>
                  </p>
                </a>
              </li>


              <li class="nav-item">
                <a href="sales.php?q=po" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p><?= $lang_purchase_order ?>
                  </p>
                </a>
              </li>
            </ul>
          </li>
        <?php } ?>




        <?php if ($rowstg['showpay'] == 1) { ?>
          <?php if (($role['sid_sales'] ?? 0) == 1) { ?>
            <li class="nav-item has-treeview">
              <a href="#" class="nav-link nav-link-basic">
                <i class="nav-icon fa fas-sharp fa-solid fa-file-invoice-dollar fas-2xl"></i>
                <p>
                  <?= $lang_sales ?>
                  <i class="fas fa-angle-left right"></i>
                </p>
              </a>
              <ul class="nav nav-treeview shadow-inner shadow-slate-500" style="display: none;">




                <li class="nav-item">
                  <a href="sales.php?q=buy" class="nav-link">
                    <i class="nav-icon fas fa-list"></i>
                    <p>
                      <?= $lang_sales_invoice ?>
                    </p>
                  </a>
                </li>

                <li class="nav-item">
                  <a href="sales.php?q=rebuy" class="nav-link">
                    <i class="nav-icon fas fa-list"></i>
                    <p>
                      فاتورة مردود مبيعات
                    </p>
                  </a>
                </li>




                <li class="nav-item">
                  <a href="sales.php?q=so" class="nav-link">
                    <i class="nav-icon fas fa-list"></i>
                    <p>
                      <?= $lang_sales_order ?>
                    </p>
                  </a>
                </li>

                <li class="nav-item">
                  <a href="sales.php?q=offer" class="nav-link">
                    <i class="nav-icon fas fa-list"></i>
                    <p><?= $lang_price_offer ?>
                    </p>
                  </a>
                </li>
              </ul>
            </li>
          <?php }
        } ?>







        <?php if (($role['sid_vouchers'] ?? 0) == 1) { ?>
          <li class="nav-item has-treeview">
            <a href="#" class="nav-link nav-link-basic">
              <i class="nav-icon fas fa-money-bill-wave"></i>
              <p>
                <?= $lang_bonds ?>
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview shadow-inner shadow-slate-500" style="display: none;">


              <li class="nav-item">
                <a href="add_voucher.php?t=recive" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_receipt_voucher ?>
                  </p>
                </a>
              </li>

              <li class="nav-item">
                <a href="add_voucher.php?t=payment" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_payment_voucher ?>
                  </p>
                </a>
              </li>


              <li class="nav-item">
                <a href="vouchers.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_bonds ?>
                  </p>
                </a>
              </li>
            </ul>
          </li>
        <?php } ?>







        <!--                                                                        -->

        <!--                                                                        -->








        <?php if ($rowstg['showhr'] == 1) { ?>
          <?php if (($role['sid_hr'] ?? 0) == 1) { ?>

            <li class="nav-item has-treeview">
              <a href="#" class="nav-link nav-link-basic">

                <i class="nav-icon fas fa-address-book"></i>
                <p>
                  <?= $lang_job_data ?>
                  <i class="fas fa-angle-left right"></i>
                </p>
              </a>
              <ul class="nav nav-treeview shadow-inner shadow-slate-500" style="display: none;">
                <li class="nav-item">
                  <a href="employees.php" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                    <p><?= $lang_sideemployees ?></p>
                  </a>
                </li>

                <li class="nav-item">
                  <a href="shifts.php" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                    <p><?= $lang_shifts ?></p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="jops.php" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                    <p><?= $lang_sidejops ?></p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="joprules.php" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                    <p><?= $lang_siderules ?></p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="joplevels.php" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                    <p><?= $lang_sidejoplevels ?></p>
                  </a>
                </li>

                <li class="nav-item">
                  <a href="departments.php" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                    <p><?= $lang_sidedepartments ?></p>
                  </a>
                </li>

                <li class="nav-item">
                  <a href="hr_operations.php" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-cogs"></i> </i>
                    <p><?= $lang_operations ?></p>
                  </a>
                </li>

                <li class="nav-item">
                  <a href="employee_operations.php" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-users-cog"></i> </i>
                    <p><?= $lang_employee_operations ?></p>
                  </a>
                </li>






              </ul>
            </li>
          <?php }
        } ?>


        <?php if ($rowstg['showrent'] == 1) { ?>
          <?php if (($role['sid_rents'] ?? 0) == 1) { ?>
            <li class="nav-item has-treeview">
              <a href="#" class="nav-link nav-link-basic">
                <i class="nav-icon fas fa-money-bill-wave"></i>
                <p>
                  <?= $lang_rent_section ?>
                  <i class="fas fa-angle-left right"></i>
                </p>
              </a>
              <ul class="nav nav-treeview shadow-inner shadow-slate-500" style="display: none;">


                <li class="nav-item">
                  <a href="acc_report.php?acc=rentable" class="nav-link">
                    <i class="nav-icon fas fa-list"></i>
                    <p>
                      <?= $lang_rentable_assets ?>
                    </p>
                  </a>
                </li>

                <li class="nav-item">
                  <a href="add_rent.php" class="nav-link">
                    <i class="nav-icon fas fa-list"></i>
                    <p>
                      <?= $lang_lease_contract ?>
                    </p>
                  </a>
                </li>


                <li class="nav-item">
                  <a href="rentables.php" class="nav-link">
                    <i class="nav-icon fas fa-list"></i>
                    <p>
                      <?= $lang_rentable_units ?>
                    </p>
                  </a>
                </li>


                <li class="nav-item">
                  <a href="myrentables.php" class="nav-link">
                    <i class="nav-icon fas fa-list"></i>
                    <p>
                      <?= $lang_rent_duration ?>
                    </p>
                  </a>
                </li>



              </ul>
            </li>



          <?php }
        } ?>









        <!-- clinck -->
        <?php if ($rowstg['showclinc'] == 1) { ?>
          <?php if (($role['sid_clinics'] ?? 0) == 1) { ?>
            <li class="nav-item has-treeview">
              <a href="#" class="nav-link nav-link-basic">
                <i class="nav-icon fas fa-stethoscope" style="color:#FFD43B"></i>
                <p>
                  <?= $lang_clinic ?>
                  <i class="fas fa-angle-left right"></i>
                </p>
              </a>

              <ul class="nav nav-treeview shadow-inner shadow-slate-500" style="display: none;">

                <li class="nav-item">
                  <a href="reservations.php" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                    <p> <?= $lang_reservations ?> </p>
                  </a>
                </li>

                <li class="nav-item">
                  <a href="clients.php" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                    <p><?= $lang_patient_data ?></p>
                  </a>
                </li>

                <li class="nav-item">
                  <a href="reservations.php?c=end" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                    <p> <?= $lang_ended_reservations ?> </p>
                  </a>
                </li>

                <li class="nav-item">
                  <a href="drugs.php" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                    <p><?= $lang_drugs ?> </p>
                  </a>
                </li>


              </ul>

            </li>
          <?php }
        } ?>

        <li class="divider"></li>



        <!-------------------------------الحضور---------------------------------------->
        <?php if ($rowstg['showatt'] == 1) { ?>
          <?php if (($role['sid_payroll'] ?? 0) == 1) { ?>


            <li class="nav-item has-treeview">
              <a href="#" class="nav-link nav-link-basic">
                <i class="nav-icon fas fa-calendar"></i>
                <p>
                  <?= $lang_sideattendance ?>
                  <i class="fas fa-angle-left right"></i>
                </p>
              </a>
              <ul class="nav nav-treeview shadow-inner shadow-slate-500" style="display: none;">
                <li class="nav-item">
                  <a href="manualattandance.php" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                    <p> <?= $lang_attendance_log ?> </p>
                  </a>
                </li>

                <li class="nav-item">
                  <a href="machinelog.php" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                    <p><?= $lang_import_attendance ?></p>
                  </a>
                </li>
                <li class="nav-item">
                  <a href="calcsalary.php" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                    <p><?= $lang_sideattennotebook ?></p>
                  </a>
                </li>



                <li class="nav-item">
                  <a href="shifts.php" class="nav-link">
                    <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                    <p><?= $lang_sideshiftmanagement ?></p>
                  </a>
                </li>
              </ul>
            <?php }
        } ?>



          <!--                                            المرتبات                               -->

          <?php if ($rowstg['showatt'] == 1) { ?>
            <?php if (($role['sid_payroll'] ?? 0) == 1) { ?>








              <!----------------------------------           المهمات          ---------------------------------------->


              <?php if ($up['tasksindex'] !== '1') { ?>





              <li class="nav-item has-treeview">
                <a href="#" class="nav-link nav-link-basic">
                  <i class="nav-icon fas fa-tasks"></i>
                  <p>
                    <?= $lang_hr ?>
                    <i class="fas fa-angle-left right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview shadow-inner shadow-slate-500" style="display: none;">

                  <li class="nav-item has-treeview">
                    <a href="#" class="nav-link nav-link-basic">
                      <i class="nav-icon fas fa-tasks"></i>
                      <p>
                        <?= $lang_tasks ?>
                        <i class="fas fa-angle-left right"></i>
                      </p>
                    </a>
                    <ul class="nav nav-treeview shadow-inner shadow-slate-500" style="display: none;">
                      <li class="nav-item">
                        <a href="add_task.php" class="nav-link">
                          <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                          <p><?= $lang_new_task ?></p>
                        </a>
                      </li>

                      <li class="nav-item">
                        <a href="tasks.php" class="nav-link">
                          <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                          <p><?= $lang_all_tasks ?></p>
                        </a>
                      </li>


                      <li class="nav-item">
                        <a href="followup.php" class="nav-link">
                          <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                          <p> <?= $lang_finished_tasks ?></p>
                        </a>
                      </li>




                    </ul>

                  </li>




                  <li class="nav-item has-treeview  shadow-slate-500">
                    <a href="#" class="nav-link nav-link-basic">
                      <i class="nav-icon fas fa-tasks"></i>
                      <p>
                        <?= $lang_performance_rates ?> <i class="fas fa-angle-left right"></i>
                      </p>
                    </a>
                    <ul class="nav nav-treeview shadow-inner  shadow-slate-500" style="display: none;">
                      <li class="nav-item">
                        <a href="kbis.php" class="nav-link">
                          <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                          <p><?= $lang_performance_rates ?></p>
                        </a>
                      </li>

                      <li class="nav-item">
                        <a href="emp_kbis.php" class="nav-link">
                          <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                          <p><?= $lang_kpi_weight ?></p>
                        </a>
                      </li>


                    </ul>
                  </li>




                  <li class="nav-item has-treeview  shadow-inner ">
                    <a href="#" class="nav-link">
                      <i class="nav-icon fas fa-list"></i>
                      <p>
                        <?= $lang_sidecontracts ?>
                        <i class="fas fa-angle-left right"></i>
                      </p>
                    </a>
                    <ul class="nav nav-treeview shadow-inner shadow-inner " style="display: none;">


                      <li class="nav-item">
                        <a href="trainingcontracts.php" class="nav-link">
                          <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                          <p><?= $lang_sidetrainingcontracts ?></p>
                        </a>
                      </li>

                      <li class="nav-item">
                        <a href="hiringcontracts.php" class="nav-link">
                          <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                          <p><?= $lang_sidehiringcontracts ?></p>
                        </a>
                      </li>

                      <li class="nav-item">
                        <a href="externalcontracts.php" class="nav-link">
                          <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                          <p><?= $lang_sideoutsourcecontracts ?></p>
                        </a>
                      </li>
                    </ul>
                  </li>




                  <li class="nav-item has-treeview  shadow-inner ">
                    <a href="#" class="nav-link">
                      <i class="nav-icon fas fa-list"></i>
                      <p>
                        <?= $lang_productivity ?>
                        <i class="fas fa-angle-left right"></i>
                      </p>
                    </a>
                    <ul class="nav nav-treeview shadow-inner shadow-inner " style="display: none;">


                      <li class="nav-item">
                        <a href="production.php" class="nav-link">
                          <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                          <p><?= $lang_daily_production ?></p>
                        </a>
                      </li>

                      <li class="nav-item">
                        <a href="hiringcontracts.php" class="nav-link">
                          <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                          <p><?= $lang_period_production ?></p>
                        </a>
                      </li>


                    </ul>
                  </li>






                  <li class="nav-item has-treeview">
                    <a href="cvs.php" class="nav-link">
                      <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                      <p>
                        <?= $lang_cvs ?>

                      </p>
                    </a>
                  </li>


                </ul>
              </li>






            <?php }
            } ?>







        <?php } ?>










        <?php if (($role['sid_crm'] ?? 0) == 1) { ?>

          <li class="nav-item has-treeview">
            <a href="#" class="nav-link nav-link-basic">
              <i class="nav-icon fas fa-tasks"></i>
              <p>
                <?= $lang_client_management ?>
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview shadow-inner shadow-slate-500" style="display: none;">
              <li class="nav-item">
                <a href="acc_report.php?acc=clients" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_import_clients_acc ?></p>
                </a>
              </li>

              <li class="nav-item">
                <a href="clients.php" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_advanced_client_entry ?></p>
                </a>
              </li>


              <li class="nav-item">
                <a href="chances.php" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p> <?= $lang_chances_management ?></p>
                </a>
              </li>


              <li class="nav-item has-treeview">
                <a href="calls.php" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p>
                    <?= $lang_calls_management ?>
                  </p>
                </a>
              </li>

              <li class="nav-item has-treeview">
                <a href="#" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>

                  <p>
                    <?= $lang_siderequests ?>
                    <i class="fas fa-angle-left right"></i>
                  </p>
                </a>
                <ul class="nav nav-treeview shadow-inner shadow-slate-500" style="display: none;">
                  <li class="nav-item">
                    <a href="orders.php" class="nav-link">
                      <i class="far "> --- </i>
                      <p><?= $lang_siderequestsmanagement ?></p>
                    </a>
                  </li>

                  <li class="nav-item">
                    <a href="prints.php" class="nav-link">
                      <i class="far "> --- </i>
                      <p><?= $lang_sidesettings ?></p>
                    </a>
                  </li>

                </ul>
              </li>
            </ul>
          </li>

        <?php } ?>







        <!-- تغيير كلمة المرور -->
        <li class="nav-item">
          <a href="change_password.php" class="nav-link">
            <i class="nav-icon fas fa-key"></i>
            <p><?= $lang_change_password ?></p>
          </a>
        </li>

        <?php if (($role['sid_accounts'] ?? 0) == 1) { ?>
          <li class="nav-item has-treeview">
            <a href="#" class="nav-link nav-link-basic">
              <i class="nav-icon fas fa-book"></i>
              <p>
                <?= $lang_general_accounts ?>
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview shadow-inner shadow-slate-500" style="display: none;">


              <li class="nav-item">
                <a href="add_journal.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_journal_entry ?>
                  </p>
                </a>
              </li>

              <li class="nav-item">
                <a href="addmulti_journal.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_multi_journal ?>
                  </p>
                </a>
              </li>




              <li class="nav-item">
                <a href="daily_journal.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_daily_journals ?>
                  </p>
                </a>
              </li>


              <li class="nav-item">
                <a href="accounts.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p><?= $lang_chart_of_accounts ?>
                  </p>
                </a>
              </li>


              <li class="nav-item">
                <a href="acc_report.php" class="nav-link">
                  <i class="nav-icon fas fa-list "></i>
                  <p><?= $lang_accounts_list ?>
                  </p>
                </a>
              </li>



            </ul>
          </li>
        <?php } ?>




        <?php if (($role['sid_accounts'] ?? 0) == 1) { ?>
          <li class="nav-item has-treeview">
            <a href="#" class="nav-link nav-link-basic">
              <i class="nav-icon fas fa-book"></i>
              <p><?= $lang_system ?>
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview shadow-inner shadow-slate-500" style="display: none;">


              <li class="nav-item">
                <a href="start_balance.php" class="nav-link">
                  <i class="nav-icon fas fa-list "></i>
                  <p> <?= $lang_accounts_opening_balance ?>
                  </p>
                </a>
              </li>


              <li class="nav-item">
                <a href="items_start_balance.php" class="nav-link">
                  <i class="nav-icon fas fa-list "></i>
                  <p><?= $lang_items_opening_balance ?></p>
                </a>
              </li>



            </ul>
          </li>
        <?php } ?>









        <?php if (($role['sid_assets'] ?? 0) == 1) { ?>

          <li class="nav-item has-treeview">
            <a href="#" class="nav-link nav-link-basic">
              <i class="nav-icon fas fa-book"></i>
              <p>
                <?= $lang_assets_operations ?>
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview shadow-inner shadow-slate-500" style="display: none;">


              <li class="nav-item">
                <a href="add_journal.php?a=1" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_buy_asset ?>

                  </p>
                </a>
              </li>


              <li class="nav-item">
                <a href="add_journal.php?a=2" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p><?= $lang_sell_asset ?>
                  </p>
                </a>
              </li>


              <li class="nav-item">
                <a href="" class="nav-link">
                  <i class="nav-icon fas fa-list "></i>
                  <p><?= $lang_depreciate_asset ?>
                  </p>
                </a>
              </li>


            </ul>
          </li>
        <?php } ?>



        <?php if (($role['sid_reports'] ?? 0) == 1) { ?>
          <li class="nav-item has-treeview">
            <a href="#" class="nav-link nav-link-basic">
              <i class="nav-icon fas fa-chart-line"></i>
              <p>
                <?= $lang_sidereports ?>
                <i class="fas fa-angle-left right"></i>
              </p>
            </a>
            <ul class="nav nav-treeview shadow-inner shadow-slate-500" style="display: none;">
              <li class="nav-item">
                <a href="summary.php" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_account_statement ?></p>
                </a>
              </li>


              <li class="nav-item">
                <a href="reps_cl.php" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_clinic_reports ?></p>
                </a>
              </li>

              <li class="nav-item">
                <a href="reports.php?t=rents" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_rent_reports ?></p>
                </a>
              </li>

              <li class="nav-item">
                <a href="sales-reports.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p><?= $lang_sales_reports ?></p>
                </a>
              </li>

              <li class="nav-item">
                <a href="operations_summary.php?q=sale" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_purchase_invoices_report ?>
                  </p>
                </a>
              </li>



              <li class="nav-item">
                <a href="items_summery.php" class="nav-link">
                  <i class="nav-icon fas fa-list"></i>
                  <p>
                    <?= $lang_sales_items_report ?>
                  </p>
                </a>
              </li>


              <li class="nav-item">
                <a href="prints.php" class="nav-link">
                  <i class="far "> <i class="nav-icon fas fa-list"></i> </i>
                  <p><?= $lang_sidesalariesreports ?></p>
                </a>
              </li>

            </ul>
          <?php } ?>





    </nav>


    <!-- /.sidebar-menu -->
  </div>
  <!-- /.sidebar -->


  <script>
    $(function () {
      if (window.location.href.includes('acc_report.php')) {
        $('#acc-report').show().addClass('bg-slate-100');
      }

      if (window.location.href.includes('myitems.php')) {
        $('#stock').show().addClass('bg-slate-100');
        $('#myitems').addClass('bg-slate-200');
      }

      if (window.location.href.includes('reservations.php')) {
        $('#clinic').show().addClass('bg-slate-100');
        $('#reservations').addClass('bg-slate-200');
      }


    });
  </script>
</aside>