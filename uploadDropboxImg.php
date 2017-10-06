<?php 
ini_set('memory_limit', '-1');

// Only For linux system
$running = exec("ps aux|grep " . basename(__FILE__) . "|grep -v grep|wc -l");
if ($running > 1) {
    file_put_contents(__DIR__ . "/logs/generate_dropbox_error.log", "\n\n  ===================== PMS ---------> dropbox uploading is already in process ====================\n\n", FILE_APPEND);
    exit;
}

file_put_contents(__DIR__ . "/logs/generate_dropbox_error.log", "\n\n  ===================== DATE TIME OF CRON : " . date('Y-m-d H:i:s') . " ====================\n\n", FILE_APPEND);

// config file
require __DIR__ . '/config/constants.php';
require __DIR__ . '/config/vendor/autoload.php';


// Dropbox File
require __DIR__ . '/dropbox/DropboxClient.php';
// Initialize DB connection 
$dbConnection = mysql_connect(DB_SERVER,DB_USER,DB_PASSWORD);
if ($dbConnection)
    mysql_select_db(DB, $dbConnection);
else
    die("Check DB Connection...");

//Select property to be uploaded into dropbox
$fetchPdfQuery = "SELECT request_details.id,request_details.property_address,request_details.status_properties"
        . " FROM `request_details`"
		. " WHERE request_details.status_properties =2 LIMIT 50";
$fetchPdfSql = mysql_query($fetchPdfQuery, $dbConnection);

if (mysql_num_rows($fetchPdfSql) != 0) {
  $arrChild = array();
	$arrParentId = array();
    while ($pdfResult = mysql_fetch_array($fetchPdfSql, MYSQL_ASSOC)) {
      $arrParentId[] = $pdfResult;

  		$fetchPdfQuery1 = "SELECT upload_photos.req_property_id,upload_photos.position,upload_photos.image_name"
          . " FROM `upload_photos`"
  		. " WHERE upload_photos.req_property_id = '".$pdfResult["id"]."'";
  		$fetchPdfSql1 = mysql_query($fetchPdfQuery1, $dbConnection);

  		if (mysql_num_rows($fetchPdfSql1) != 0) {
  		    while ($pdfResult1 = mysql_fetch_array($fetchPdfSql1, MYSQL_ASSOC)) {
  		    	$arrChild[]   = $pdfResult1;
  		    }
  		}
    }
    uploadFileInToDropbox($arrChild,$arrParentId);
}


/**
 * Function name : uploadFileInToDropbox
 * Date Modified : Jan 06, 2017
 * Created by    : Anuar Delabahan
 * Description   : This back-end process function is to upload all images in dropbox 
 * @param type $img_data this variable for all images details
 * @param type $prop_id  this variable for all property address and id
 */
function uploadFileInToDropbox($img_data,$prop_id) {
    
      ini_set('max_execution_time', -1);
      $dropbox = new DropboxClient(array(
            'app_key' => "",
            'app_secret' => "",
            'app_full_access' => false,
      ), 'en');

      handle_dropbox_auth($dropbox);
      $main_dir= __DIR__.PROPERTY_IMG_PATH_IN_PMS;
      try{
        $cnt = count($prop_id);
        // code that may throw an exception
        for ($i=0; $i < $cnt; $i++) 
        { 
          $image_dir = $main_dir.$prop_id[$i]["id"]. '/';
          $folder_name = str_replace('/', ' ', $prop_id[$i]["property_address"]);
          $files11 = $dropbox->GetFiles("", false);
          $dropbox->CreateFolder($folder_name, 'dropbox');

          foreach ($img_data as $key => $value) 
          {
            if($value["req_property_id"] == $prop_id[$i]["id"])
            {

              $new_filename = $value["image_name"];
              $file_full_path = $image_dir .$value["image_name"];

              /******** Check if folder exists or not in dropbox ********/
              $files = $dropbox->GetFiles("", false);
              $result = array();
              $upload_name = '/' . $folder_name . '/High Resolution Images/' .$value["position"]  . '_' . $new_filename;
              $dropbox->UploadFile($file_full_path, $upload_name);
            }
          }
        }
      }
       catch(Exception $e)
      {
         echo $e->getMessage();
     }
    setStatusProperties($prop_id);
}

//store_token, load_token, delete_token are SAMPLE functions! please replace with your own!
function store_token($token, $name) {
   file_put_contents(__DIR__ . "/dropbox/tokens/$name.token", serialize($token));
}

function load_token($name) {
   if (!file_exists(__DIR__ . "/dropbox/tokens/$name.token"))
       return null;
   return @unserialize(@file_get_contents(__DIR__ . "/dropbox/tokens/$name.token"));
}

function delete_token($name) {
   @unlink(__DIR__ . "/dropbox/tokens/$name.token");
}

function handle_dropbox_auth($dropbox) {
   // first try to load existing access token
   $access_token = load_token("access");
   if (!empty($access_token)) {
       $dropbox->SetAccessToken($access_token);
   } elseif (!empty($_GET['auth_callback'])) { // are we coming from dropbox's oauth page?
       // then load our previosly created request token
       $request_token = load_token($_GET['oauth_token']);
       if (empty($request_token))
           die('Request token not found!');
       // get & store access token, the request token is not needed anymore
       $access_token = $dropbox->GetAccessToken($request_token);
       store_token($access_token, "access");
       delete_token($_GET['oauth_token']);
   }
   // checks if access token is required
   if (!$dropbox->IsAuthorized()) {
       // redirect user to dropbox oauth page
       $return_url = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . "?auth_callback=1";
       $auth_url = $dropbox->BuildAuthorizeUrl($return_url);

       file_put_contents("logs/uploading_dropbox_error.log", "\n\n  ============== Error During Authentication Dropbox :  \n" . $auth_url . "\n\n", FILE_APPEND);
       //Email Auth URL
       $request_token = $dropbox->GetRequestToken();
       store_token($request_token, $request_token['t']);
       return true;
   }
}

/**
 * Function name : setStatusProperties
 * Date Modified : Jan 06, 2017
 * Created by    : Anuar Delabahan
 * Description   : This function to update all 2 status into 1 
 * @param type $prop_id  this variable for all property id
 */
function setStatusProperties($prop_id) {
    $dbConnection = mysql_connect(DB_SERVER, DB_USER, DB_PASSWORD);
    $cnt = count($prop_id);
    for ($i=0; $i < $cnt; $i++) 
    {
      $updateStatusProperties = "UPDATE `request_details` SET `status_properties` = '1' WHERE `id` ='" .$prop_id[$i]["id"]. "'";
      $updateStatusSql = mysql_query($updateStatusProperties, $dbConnection);
    }
    return true;
}

