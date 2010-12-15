<?php
  require("./wbs.php");

  $wbs = new wbs_Account();
  $wbs->setUserEmail('stfnandersen@gmail.com');
  $wbs->setUserPassword('password');

  $usersList = $wbs->getUsersList();
  
  foreach ($usersList as $user) {
    $user->setLimit(1);
    $measuresgroups = $user->getMeasures();

    print $user->getFullname() . "\n";
    foreach($measuresgroups as $group) {
      print "\t" . date('Y-m-d  H:i:s', $group->getDate()) . "\n";
      foreach($group->getMeasures() as $measure) {
        print "\t\t" . $measure->getUnitPrefix() . " ". $measure->getValue() . " " . $measure->getUnitSuffix() . "\n";

      }
    }
  }

