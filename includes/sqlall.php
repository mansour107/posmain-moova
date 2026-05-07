<?php

        $MAC = exec('getmac');
        $MAC2 = strtok($MAC, ' ');
        $macemplode = explode("-",$MAC2);
        $macstr = md5(implode($macemplode));
        $lc_hadi = $macstr.$macemplode['5'].md5($macemplode['4']);
                
?>