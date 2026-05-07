<div class="col-md-8" id="left">
    <div class="row bg-yellow-50" id="searchRow">
        <input type="text" class="scnd form-control form-control-sm bg-slate-50 focus:bg-orange-200" id="itemSearch" placeholder="خانة البحث">
    </div>

    <div class="row " id="categories">
        <ul>
            <li class="float-left text-center">
                <button class="cat border-2 border-red shadow align-middle p-0 m-0 min-h-20 rounded bg-slate-100 transition duration-700 ease-in-out hover:bg-pink-600 hover:text-slate-50" onclick="filterItemsByCategory(null)">
                    الكل
                </button>
            </li>
            <?php 
                $rescat = $conn->query("SELECT * from item_group where isdeleted = 0");
                while($rowcat = $rescat->fetch_assoc()) {
            ?>
            <li class="float-left text-center">
                <button class="cat border-2 border-red shadow align-middle p-1 m-0  min-h-20 rounded bg-slate-100 transition duration-700 ease-in-out hover:bg-pink-600 hover:text-slate-50" onclick="filterItemsByCategory(<?= $rowcat['id']?>)">
                    <input hidden type="text" value="<?= $rowcat['id']?>">
                    <?= $rowcat['gname']?>
                </button>
            </li>
            <?php } ?>
        </ul>
    </div>
    <div class="row" id="items">
    <?php
        $resitem = $conn->query("SELECT * FROM myitems where isdeleted = 0");
        while ($rowitem = $resitem->fetch_assoc()) {
    ?>
    <button title="<?= $rowitem['info']?>" class="itemButton cat rounded p-3 m-2 bg-slate-50 transition duration-300 ease-in-out hover:bg-pink-600 border hover:text-slate-50" itemid="<?= $rowitem['barcode']?>" data-category="<?= $rowitem['group1']?>">
        <div class="itemlogo">
            <center>
            <i class="fa fa-star text-lg"></i>
            </center>
        </div>
        <div class="itemname">
            <input type="text" id="itemCat<?= $rowitem['id']?>" value="<?= $rowitem['group1']?>" hidden>
            <input type="text" id="itemId<?= $rowitem['barcode']?>" value="<?= $rowitem['barcode']?>" hidden>
            <p class="font-normal text-sm text-navy"><?= $rowitem['iname']?></p>
            <p class="text-sm"><?= $rowitem['price1']?> ج</p>
        </div>
    </button>
    <?php } ?>
</div>
</div>