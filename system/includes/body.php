
      
<!--  /div--><!-- /row -->

 <hr>
    <footer>
<?php
  if (!$embedded) { 
    echo '<p class="pull-right">';
    if (user_access("administer persons", "churchcore"))
      echo '<a href="#" id="simulate_person" title="Person simulieren"><img src="system/churchcore/images/person_simulate.png" style="max-width:16px"/></a>&nbsp; ';
    if (userLoggedIn())
      echo '<a href="#" id="email_admin" title="Dem Administrator eine E-Mail senden"><img src="system/churchcore/images/email.png" style="max-width:16px"/></a>';
    echo '</p>';      
    echo "<p>"; 
    if (isset($config["cronjob_delay"]) && ($config["cronjob_delay"]>0)) echo '<img src="?q=cron&standby=true"/>';
    echo '&copy; <a href="http://www.churchtools.de" target="_blank">www.churchtools.de</a> ';
    echo "<small>v".$config["version"]."</small>";
  } else {
    echo '<p class="pull-right"><small>&copy; <a href="http://www.churchtools.de" target="_blank">www.churchtools.de</a> ';
    echo "v".$config["version"]."</small></p>";
  }     
  ?>
      </footer>
  </div>
<script src="system/bootstrap/js/bootstrap.js"></script>
</body>
</html>