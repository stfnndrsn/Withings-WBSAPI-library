<?php
 /**
   * Author: Stefan Andersen <stfnandersen@gmail.com>
   * File: wbs.php
   * 
   * Withings Bodyscale Services API (WBS API) is a set of webservices allowing developers and third parties limited access to users' data.
   *
   * http://www.withings.com/en/api/bodyscale
   *
   * Copyright (c) 2010, Stefan Andersen <stfnandersen@gmail.com>
   * All rights reserved.
   *
   * Redistribution and use in source and binary forms, with or without
   * modification, are permitted provided that the following conditions are met:
   *     * Redistributions of source code must retain the above copyright
   *       notice, this list of conditions and the following disclaimer.
   *     * Redistributions in binary form must reproduce the above copyright
   *       notice, this list of conditions and the following disclaimer in the
   *       documentation and/or other materials provided with the distribution.
   *     * Neither the name of the <organization> nor the
   *       names of its contributors may be used to endorse or promote products
   *       derived from this software without specific prior written permission.
   *
   * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
   * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
   * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
   * DISCLAIMED. IN NO EVENT SHALL STEFAN ANDERSEN BE LIABLE FOR ANY
   * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
   * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
   * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
   * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
   * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
   * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
   */

class remoteCallWbsException extends Exception {}
class validationErrorWbsException extends Exception {}

abstract class wbs {
  const WBSAPIHOST = 'wbsapi.withings.net';

  private $responseCodes = array(
      'account-2555' => "An unknown error occurred",
      'account-264'  => "The email address provided is either unknown or invalid",
      'account-100'  => "The hash is missing, invalid, or does not match the provided email",
      'getmeas-2555' => "An unknown error occurred",
      'getmeas-250'  => "The userid and publickey provided do not match, or the user does not share its data",
      'getmeas-247'  => "The userid provided is absent, or incorrect",
  );

  /**
   * Can we probe the remote api?
   * 
   * @return boolean
   */
  public function probe() {
    return ($this->callWbs('once', 'probe') === false) ? false : true;
  }

  /**
   * Fetches magic string for computing password hash
   * 
   * @return string
   */
  protected function getMagicString() {
    $result = $this->callWbs('once', 'get');
    return $result['body']['once'];
  }

  /**
   * Calls remote WBS API
   *
   * @param string $service
   * @param string $action
   * @param array $parameters
   * @return false | result
   */
  protected function callWbs($service, $action, $parameters = array())
  {
    $url = sprintf('http://%s/%s?action=%s&%s',
                   self::WBSAPIHOST,
                   $service,
                   $action,
                   implode('&', $parameters));
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_POST, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!is_array($result)) {
      throw new remoteCallWbsException("Didn't get a valid array in response");
    }
    if (!key_exists('status', $result)) {
      throw new remoteCallWbsException("No status en response body");
    }
    if ($result['status'] != 0) {
      $statusKey = sprintf('%s-%s', $service, $result['status']);
      if (key_exists($statusKey, $this->responseCodes)) {
        throw new remoteCallWbsException($this->responseCodes[$statusKey]);
      }
      throw new remoteCallWbsException(sprintf("WBS returned error code: %s", $result['status']));
    }
    return $result;
  }
}

class wbs_Account extends wbs {
  protected $userEmail;
  protected $userPassword;

  public function getUsersList() {
    if (!is_string($this->userEmail)) {
      throw new validationErrorWbsException("userEmail not set, call setUserEmail(email) first");
    }
    if (!is_string($this->userPassword)) {
      throw new validationErrorWbsException("userEmail not set, call setUserPassword(password) first");
    }

    $magic  = $this->getMagicString();
    $hash   = md5(sprintf('%s:%s:%s',
                  $this->userEmail,
                  md5($this->userPassword),
                  $magic));
    $params = array("email={$this->userEmail}",
                    "hash={$hash}");
    $result = $this->callWbs('account', 'getuserslist', $params);
    foreach($result['body']['users'] as $user) {
      $users[] = new wbs_User($user);
    }
    return (count($users) > 0)? $users : false;
  }

  public function setUserEmail($email) {
    $this->userEmail = $email;
  }

  public function setUserPassword($password) {
    $this->userPassword = $password;
  }  
}

class wbs_User extends wbs {
  protected $id;        // The user identifier
  protected $firstname; // The user's firstname, as an UTF-8 encoded string
  protected $lastname;  // The user's lastname, as an UTF-8 encoded string
  protected $shortname; // The user's shortname
  protected $gender;    // The user's gender (0 for male, 1 for female)
  protected $fatmethod; // Byte indicating the Body Composition Formula in use
  protected $birthdate; // The user's birthdate in Epoch format
  protected $ispublic;  // Set to 1 if the user curently shares their data
  protected $publickey; // The user's current publickey

  protected $startdate;  // Will prevent retrieval of values dated prior to the supplied parameter.
  protected $enddate;    // Will prevent retrieval of values dated after the supplied parameter.
  protected $meastype;   // Will restrict the type of data retrieved
  protected $lastupdate; // Only entries which have been added or modified since this time are retrieved.
  protected $category;   // Can be set to 2 to retrieve objectives or to 1 to retrieve actual measurements.
  protected $limit;      // Can be used to limit the number of measure groups returned in the result.
  protected $offset;     // Can be used to skip the 'offset' most recent measure group records of the result set.

  protected $measures;

  /**
   * Constructs a wbs_User obj. Must supply information about user in array.
   * 
   * @param array $fromArray 
   */
  public function __construct($fromArray) {
    $this->id = $fromArray['id'];
    $this->firstname = $fromArray['firstname'];
    $this->lastname = $fromArray['lastname'];
    $this->shortname = $fromArray['shortname'];
    $this->gender = $fromArray['gender'];
    $this->fatmethod = $fromArray['fatmethod'];
    $this->birthdate = $fromArray['birthdate'];
    $this->ispublic = $fromArray['ispublic'];
    $this->publickey = $fromArray['publickey'];
  }

  static function loadUser($userid, $publickey) {
    $params = array("userid={$userid}",
                    "publickey={$publickey}");
    $result = parent::callWbs('user', 'getbyuserid', $params);
    $result = $result['body']['users'][0];
    $result['publickey'] = $publickey;
    return new wbs_User($result);
  }

  public function getFullname() {
    return $this->lastname . ", " . $this->firstname;
  }

  public function getMeasures($allowCache = true) {
    if ($allowCache && is_array($this->measures)) {
      return $this->measures;
    }
    $params = array("userid={$this->id}",
                    "publickey={$this->publickey}");
    if (is_int($this->startdate)) {
      $params[] = "startdate={$this->startdate}";
    }
    if (is_int($this->enddate)) {
      $params[] = "enddate={$this->enddate}";
    }
    if (is_int($this->meastype)) {
      $params[] = "meastype={$this->meastype}";
    }
    if (is_int($this->lastupdate)) {
      $params[] = "lastupdate={$this->lastupdate}";
    }
    if (is_int($this->category)) {
      $params[] = "category={$this->category}";
    }
    if (is_int($this->limit)) {
      $params[] = "limit={$this->limit}";
    }
    if (is_int($this->offset)) {
      $params[] = "offset={$this->offset}";
    }
    $result = $this->callWbs('measure', 'getmeas', $params);
    foreach ($result['body']['measuregrps'] as $measuregroup) {
      $this->measures[] = new wbs_MeasureGroup($measuregroup);
    }
    return $this->measures;
  }

  /**
   * For a user's data to be accessible through this API, a prior authorization has to be given.
   *
   * @param boolean $public
   * @return boolean
   */
  public function setPublic($public) {
    $params = array("userid={$this->id}",
                    "publickey={$this->publickey}");
    switch ($public) {
      case true:
        $params[] = "ispublic=1";
        break;
      case false:
        $params[] = "ispublic=0";
        break;
      default:
        throw new validationErrorWbsException("Public should be set to true or false");
        break;
    }
    $this->callWbs('user', 'update', $params);
    return true;
  }

  public function setStartdate($startdate) {
    $this->startdate = $startdate;
  }

  public function setEnddate($enddate) {
    $this->enddate = $enddate;
  }

  public function setMeastype($meastype) {
    $this->meastype = $meastype;
  }

  public function setLastupdate($lastupdate) {
    $this->lastupdate = $lastupdate;
  }

  public function setCategory($category) {
    $this->category = $category;
  }

  public function setLimit($limit) {
    $this->limit = $limit;
  }

  public function setOffset($offest) {
    $this->offset = $offset;
  }
}

class wbs_MeasureGroup {
  protected $grpid;    // A unique grpid (Group Id), useful for performing synchronization tasks.
  protected $attrib;   // An attrib (Attribute), defining the way the measure was attributed to the user.
  protected $date;     // The date (EPOCH format) at which the measure was taken or entered.
  protected $category; // The category of the group.
  protected $measures;

  public function  __construct($fromArray) {
    $this->grpid    = $fromArray['grpid'];
    $this->attrib   = $fromArray['attrib'];
    $this->date     = $fromArray['date'];
    $this->category = $fromArray['category'];

    foreach ($fromArray['measures'] as $measure) {
      $this->measures[] = new wbs_Measure($measure);
    }
  }
  
  /**
   * Attribution status
   * 
   * Return values:
   * 0 The measuregroup has been captured by a device and is known to belong to this user (and is not ambiguous)
   * 1 The measuregroup has been captured by a device but may belong to other users as well as this one (it is ambiguous)
   * 2 The measuregroup has been entered manually for this particular user
   * 4 The measuregroup has been entered manually during user creation (and may not be accurate)
   * 
   * @return int 
   */
  public function getAttrib() {
    return $this->attrib;
  }

  public function getAttribText() {
    switch ($this->attrib) {
      case 0:
        return "Captured by device, belongs to this user";
      case 1:
        return "Captured by device, belongs others users as well";
      case 2:
        return "Entered manually, belongs to this user";
      case 4:
        return "Entered manually during creating, not accurate";
      default:
        return "unknown";
    }
  }

  /**
   *
   * @return int
   */
  public function getDate() {
    return $this->date;
  }

  /**
   * Retun values:
   * 1 Measure
   * 2 Target
   *
   * @return int
   */
  public function getCategory() {
    return $this->category;
  }

  /**
   * Returns an array of wbs_Meassure's
   *
   * @return wbs_Measure[]
   */
  public function getMeasures() {
    return $this->measures;
  }
}

class wbs_Measure {
  protected $type;
  protected $value;
  protected $unit;

  public function  __construct($fromArray) {
    $this->type  = $fromArray['type'];
    $this->value = $fromArray['value'];
    $this->unit  = $fromArray['unit'];
  }

  /**
   * Return values:
   * 1 Weight (kg)
   * 4 Height (meter)
   * 5 Fat Free Mass (kg)
   * 6 Fat Ratio (%)
   * 8 Fat Mass Weight (kg)
   * 
   * @return int
   */
  public function getType() {
    return $this->type;
  }

  public function getValue() {
    return $this->value * pow(10, $this->unit);
  }

  public function getUnitPrefix() {
    switch ($this->type) {
      case 1:
        return "Weight";
      case 4:
        return "Height";
      case 5:
        return "Fat Free Mass";
      case 6:
        return "Fat Ratio";
      case 8:
        return "Fat Mass Weight";
      default:
        return "unknown";
    }
  }

  public function getUnitSuffix() {
    if (in_array($this->type, array(1, 5, 8))) {
      return "kg";
    } elseif ($this->type == 4) {
      return "meter";
    } elseif ($this->type == 6) {
      return "%";
    }
    return "unknown";
  }
}