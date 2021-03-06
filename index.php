<?php
/* 
 * Password Reset Index Page
 *
 * To do through checks just in case something fails,
 * and the avoid the else { if () { else { if () { 
 * nesting, The variable dirtyBit is used.
 * If anything sets, dirtyBit, the script goes into
 * recovery mode, and shortcircuits throught the remaining 
 * code.
 * 
 * @author: Parth Laxmikant Kolekar <parth.kolekar@students.iiit.ac.in>
 * @version: 1.0.0dev1
 *
*/

// $allowed_ips=array('10.1.36.227','10.3.1.184','10.4.3.236','10.1.33.241','10.4.3.231','10.4.3.251');
// if (!in_array($_SERVER['REMOTE_ADDR'], $allowed_ips)) {
//   echo "Sorry, access denied!";
//   exit(0);
// }
require_once 'config.php';

$dirtyBit = false;
$cleanBit = false;
$adminBit = false;
$error_messages = array();
$requestID = md5(uniqid(rand(), true));
openlog("Password Reset", LOG_PID, LOG_LOCAL0);

function logToSyslog($message) {
    global $requestID;
    syslog(LOG_INFO, "$requestID : $message");
}

function valid_pass($candidate) {
    $r1 = '/[A-Z]/';  //Uppercase
    $r2 = '/[a-z]/';  //lowercase
    $r3 = '/[0-9]/';  //numbers

    if(preg_match_all($r1, $candidate, $o) < 1) return false;
    if(preg_match_all($r2, $candidate, $o) < 1) return false;
    if(preg_match_all($r3, $candidate, $o) < 1) return false;
    if(strlen($candidate) < 8) return false;
    return true;
}

function generateSambaNTPassword($pass){
// https://www.jotschi.de/Uncategorized/2010/08/10/howto-generate-sambantpassword-ldap-attribute.html
  return strtoupper(
      bin2hex(
        hash("md4", 
          iconv(
            "UTF-8","UTF-16LE",$pass
            ), true
          )
        )
    );
}

function HashPassword($password)
// http://www.chillibear.com/2009/12/ssha-passwords-in-various-languages.html
{
    $salt = 'microtime()*1000000';
    for ($i=1;$i<=10;$i++) {
        $salt .= substr('0123456789abcdef',rand(0,15),1);
    }
    $hash = "{SSHA}".base64_encode(pack("H*",sha1($password.$salt)).$salt);
    return $hash;
}


function generateLANPassword($password) {
    $update = array (
        'sambaNTPassword' => generateSambaNTPassword($password)
    );
    return $update;
}

function generateCAPassword($password) {
    $update = array(
      'userPassword' => $password
    );
    return $update;
}


if (isset($_POST['newCAPassword1']) and isset($_POST['newCAPassword2'])) {
    $update = false;
    if (strcmp($_POST['newCAPassword1'], $_POST['newCAPassword2']) !== 0) {
        $newCAPassword = false;
        logToSyslog("CAPasswords were set, and not match");
        array_push($error_messages, "Passwords Do Not Match");
        $dirtyBit = true;
    } else {
        $newCAPassword = $_POST['newCAPassword1'];
        if (!valid_pass($newCAPassword)) {
            $dirtyBit = true;
            logToSyslog("Password did not match Requirement");
            array_push($error_messages, "Passwords Do Not Meet Requirement");
        } else {
            $update = generateCAPassword($newCAPassword);
        }
    }
}

if (isset($_POST['newLANPassword1']) and isset($_POST['newLANPassword2'])) {
    $update = false;
    if (strcmp($_POST['newLANPassword1'], $_POST['newLANPassword2']) !== 0) {
        $newLANPassword = false;
        logToSyslog("LANPasswords were set, and not match");
        array_push($error_messages, "Passwords Do Not Match");
        $dirtyBit = true;
    } else {
        $newLANPassword = $_POST['newLANPassword1'];
        if (!valid_pass($newLANPassword)) {
            $dirtyBit = true;
            logToSyslog("Password did not match Requirement");
            array_push($error_messages, "Passwords Do Not Meet Requirement");
        } else {
            $update = generateLANPassword($newLANPassword);
        }
    }
}

if (isset($_POST['uid']) and isset($_POST['_domain']) and isset($_POST['currentPassword'])) {
    $domain = trim(str_replace(array('?', '+', 'string:'), '', $_POST['_domain']));
    $email = $_POST['uid'].$domain;
    $baseDn = "dc=iiit,dc=ac,dc=in";
    if (strcmp($domain, "") === 0) {
        $filter = '(mailForwardingAddress='.$email.')';
    } else {
        $filter = '(mail='.$email.')';
    }
    // I don't think anyone would bother attempting injection here. It would make no sense. Also using mail=<mail> because duplicate uid for few people.

    logToSyslog("Request Email : $email");
    // Logs must be rotated to prevent overflow attacks.... Who attacks local server anyways?

    if (!$dirtyBit) {
        $ds = ldap_connect("ldap.iiit.ac.in", 389);
    } else {
        $ds = false;
    }
    if (!$ds) {
        logToSyslog("Unable to connect to LDAP server");
        array_push($error_messages, "Passwords Do Not Meet Requirement");
        $dirtyBit = true;
    }

    if (!$dirtyBit) {
        $opt = ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
    } else {
        $opt = false;
    }
    if (!$opt) {
        logToSyslog("Unable to set LDAPv3");
        array_push($error_messages, "Unable to set LDAPv3. Please report on help portal.");
        $dirtyBit = true;
    }

    if (!$dirtyBit) {
        $tls = ldap_start_tls($ds);
    } else {
        $tls = false;
    }
    if (!$tls) {
        logToSyslog("Unable to STARTTLS");
        array_push($error_messages, "Insecure channel. Please report on help portal.");
        $dirtyBit = true;
    }

    if (!$dirtyBit) {
        $r = ldap_bind($ds);
    } else {
        $r = false;
    }
    if (!$r) {
        logToSyslog("Unable to Anon Bind");
        $dirtyBit = true;
        array_push($error_messages, "Unable to bind. Please report on help portal.");
    }

    if (!$dirtyBit) {
        $sr = ldap_search($ds, $baseDn, $filter);
    } else {
        $sr = false;
    }
    if (!$sr) {
        logToSyslog("Unable to Anon Search");
        $dirtyBit = true;
        array_push($error_messages, "Can't locate your account, please recheck username.");
    }

    if (!$dirtyBit) {
        $entry = ldap_first_entry($ds, $sr);
    } else {
        $entry = false;
    }
    if (!$entry) {
        logToSyslog("Unable to Fetch First Entry");
        $dirtyBit = true;
        array_push($error_messages, "Can't locate your account, please recheck username.");
    }

    if (!$dirtyBit) {
        $dn = ldap_get_dn($ds, $entry);
    } else {
        logToSyslog("Unable to get Dn for First Entry");
        $dn = false;
        array_push($error_messages, "Search failed. Please report on help portal.");
    }
    if (!$dn) {
        $dirtyBit = true;
    }

    if (!$dirtyBit) {
        $r = ldap_bind($ds, $dn, $_POST['currentPassword']);
    } else {
        $r = false;
    }

    if (!$r) {
        logToSyslog("Unable to Bind on the found Dn - Maybe expired password");
        // lets try password reset by admin bind, maybe password is expired?
        global $adminDN, $adminPass;
        exec('ldappasswd -D '.escapeshellarg($adminDN).' '.escapeshellarg($dn).' -a '.escapeshellarg($_POST['currentPassword']).' -s '.escapeshellarg($_POST['newCAPassword1']).' -w '.escapeshellarg($adminPass).' -Z', $output, $code);
        if($code) {
            // non-zero results code, hence error :(
            logToSyslog("Admin bind and password change failed");
            $dirtyBit = True;
            if(count($output) > 0) {
                if(strpos($output[0], "Invalid credentials") > 0) {
                    logToSyslog("Invalid credentials.");
                    array_push($error_messages, "Please recheck your current LDAP password.");
                }
                else {
                    logToSyslog("Failed. ".$output[0]);
                    array_push($error_messages, "Failed. ".$output[0]);
                }
            }
        }
        else {
            // successfully changed and unlocked the account, lets for user bind, and self password change
            logToSyslog("Admin bind and changed successfully. Lets re-change using user bind.");
            $adminBit = True;
            $r = ldap_bind($ds, $dn, $_POST['newCAPassword1']);
            if (!$r) {
                array_push($error_messages, "Password changed, but verification failed.");
                $dirtyBit = True;
            }
        }
    }

    if (!$dirtyBit) {
        $mod = ldap_mod_replace($ds, $dn, $update);
        // As of php-ldap source, validation is carried out by php of the form IS_ARRAY(update),
        // and if fails, the error is thrown, and logged.
        // The raw POST is the only thing capable of something like this. The rest is handled in JS / CSS.
    } else {
        $mod = false;
    }
    if (!$mod) {
        logToSyslog("Unable to Change Password");
        if($adminBit) {
            array_push($error_messages, "Password changed, but verification failed.");
        }
        $dirtyBit = true;
    } else {
        $cleanBit = true;
    }
}

if ($dirtyBit) {
    array_push($error_messages, "Passwords Updation Failed");
    logToSyslog("Failed");
} else {
    if ($cleanBit) {
        array_push($error_messages, "Passwords Updated Sucessfully");
        logToSyslog("Sucessfull");
    }
}

closelog();
?>
<!DOCtype html>
<html lang="en" ng-app="PasswordReset">
  <head>
    <link rel="stylesheet" href="./static/css/angular-material.min.css">
    <meta name="viewport" content="initial-scale=1" />
    <meta charset="UTF-8" />
    <title>Reset Your Password</title>
  </head>
  <body layout="column" ng-controller="PasswordResetController as pr">
    <div id="header" style="padding: 20px" layout="row">
      <a href="http://iiit.ac.in/" id="logo">
        <img alt="logo" src="./static/images/logo.png"/>
      </a>
      <h1 flex style="text-align: center">
        Password Reset Portal
      </h1>
    </div>
    <div layout="row" flex>
      <div layout="column" flex id="content">
<?php
    if ($dirtyBit or $cleanBit) {
?>
        <md-toolbar layout-padding class="<?php if ($dirtyBit) echo "md-warn"; if ($cleanBit) echo "md-primary"; ?>">
        <div flex style="text-align: center" >
<?php 
        print_r ($error_messages[0]);
?>
          </div>
        </md-toolbar>
<?php
    }
?>
        <md-content layout="row" flex class="md-padding">
          <md-tabs md-dynamic-height flex md-selected="<?php if (isset($_GET['lan-reset'])) echo "1";  ?>" style="min-width: 760px;">
            <md-tab label="LDAP Password">
              <form name="CAPasswordResetForm" flex style="padding: 20px" action="./?ca-reset" method="POST">
                <div layout="row">
                  <md-input-container flex>
                    <label>Username</label>
                    <input name="uid" ng-model="pr.uid" required>
                  </md-input-container>
                  <md-input-container flex>
                    <md-select name="domain" ng-model="pr.domain" placeholder="Loading Domains...">
                      <md-option ng-value="domain" ng-repeat="domain in pr.domains">{{ domain }}</md-option>
                    </md-select>
                  </md-input-container>
                </div>
                <md-input-container flex>
                  <label>Current LDAP Password</label>
                  <input name="currentPassword" ng-model="pr.currentPassword" required type="password" />
                </md-input-container>
                <md-input-container flex>
                  <label>New Password</label>
                  <input name="newCAPassword1" ng-model="pr.newCAPassword1" required type="password" />
                </md-input-container>
                <md-input-container flex>
                  <label>New Password</label>
                  <input name="newCAPassword2" ng-model="pr.newCAPassword2" required type="password" />
                </md-input-container>
                <md-input-container flex>
                  <input name="CASubmit" type="submit" value="Reset Password" aria-label="Reset CA Password" style="color: black" />
                </md-input-container>
              </form>
            </md-tab>
            <md-tab label="802.1X Password">
              <form name="LANPasswordResetForm" flex style="padding: 20px" action="./?lan-reset" method="POST">
                <div layout="row">
                  <md-input-container flex>
                    <label>Username</label>
                    <input name="uid" ng-model="pr.uid" required>
                  </md-input-container>
                  <md-input-container flex>
                    <md-select name="domain" ng-model="pr.domain" placeholder="Loading Domains...">
                      <md-option ng-value="domain" ng-repeat="domain in pr.domains">{{ domain }}</md-option>
                    </md-select>
                  </md-input-container>
                </div>
                <md-input-container flex>
                  <label>Current LDAP Password</label>
                  <input name="currentPassword" ng-model="pr.currentPassword" required type="password" />
                </md-input-container>
                <md-input-container flex>
                  <label>New Password</label>
                  <input name="newLANPassword1" ng-model="pr.newLANPassword1" required type="password" />
                </md-input-container>
                <md-input-container flex>
                  <label>New Password</label>
                  <input name="newLANPassword2" ng-model="pr.newLANPassword2" required type="password" />
                </md-input-container>
                <md-input-container flex>
                  <input name="LANSubmit" type="submit" value="Reset Password" aria-label="Reset LAN Password" style="color: black" />
                </md-input-container>
              </form>
            </md-tab>
          </md-tabs>
          <div flex layout="column" style="padding: 20px">
            <div id="notice">
              <h1>Note:</h1>
                <ol>
                  <li>Your password must be atleast <b>8 characters long</b>.</li>
                  <li>Your password must contain atleast <b>one uppercase letter</b>.</li>
                  <li>Your password must contain atleast <b>one lowercase letter</b>.</li>
                    <li>Your password must contain atleast <b>one number</b>.</li>
                    <li>Your password must contain atleast <b>one special character</b>.</li>
                </ol>
            </div>
          </div>
        </md-content>
      </div>
  </div>
  <script src="./static/lib/angular.min.js" defer></script>
  <script src="./static/lib/angular-animate.min.js" defer></script>
  <script src="./static/lib/angular-aria.min.js" defer></script>
  <script src="./static/lib/angular-material.min.js" defer></script>
  <script src="./static/passwordreset/PasswordReset.js" defer></script> 
  <script src="./static/passwordreset/PasswordResetController.js" defer></script> 
  </body>
</html>


