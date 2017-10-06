<?php
	ini_set('memory_limit', '-1');
    /**
     *    Copyright (c) Arturas Molcanovas <a.molcanovas@gmail.com> 2016.
     *    https://github.com/Alorel/dropbox-v2-php
     *
     *    Licensed under the Apache License, Version 2.0 (the "License");
     *    you may not use this file except in compliance with the License.
     *    You may obtain a copy of the License at
     *
     *    http://www.apache.org/licenses/LICENSE-2.0
     *
     *    Unless required by applicable law or agreed to in writing, software
     *    distributed under the License is distributed on an "AS IS" BASIS,
     *    WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
     *    See the License for the specific language governing permissions and
     *    limitations under the License.
     */
    use Alorel\Dropbox\Operation\AbstractOperation;
    use Alorel\Dropbox\Operation\Files\UploadSession\Append;
    use Alorel\Dropbox\Operation\Files\UploadSession\Finish;
    use Alorel\Dropbox\Operation\Files\UploadSession\Start;
    use Alorel\Dropbox\Parameters\CommitInfo;
    use Alorel\Dropbox\Parameters\UploadSessionCursor;

    //Data will be binary
    require_once '../config/constants.php';
    require_once 'vendor/autoload.php';
    AbstractOperation::setDefaultAsync(false);
    AbstractOperation::setDefaultToken('');

    // Initialize DB connection 
    $dbConnection = mysql_connect(DB_SERVER, DB_USER, DB_PASSWORD);
    if ($dbConnection)
        mysql_select_db(DB, $dbConnection);
    else
        die("Check DB Connection...");


    //Select property to be uploaded into dropbox
    $fetchPdfQuery = "SELECT request_details.id,request_details.property_address,request_details.status_properties"
            . " FROM `request_details`"
            . " WHERE request_details.status_properties =2 LIMIT 1";
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
     * Date created  : Jan 06, 2017
     * Date Modified : Oct 06, 2017
     * Created by    : Anuar Delabahan
     * Description   : This back-end process function is to upload all images in dropbox. Since api v1 was retired already migrate api v2
     * @param type $img_data this variable for all images details
     * @param type $prop_id  this variable for all property address and id
     */
    function uploadFileInToDropbox($img_data,$prop_id){
        ini_set('max_execution_time', -1);
        //$main_dir= $_SERVER["DOCUMENT_ROOT"].'/app/webroot/img/uploads/property_';
        try
        {
            $cnt = count($prop_id);
            for ($i=0; $i < $cnt; $i++) 
            { 
              $image_dir = $main_dir.$prop_id[$i]["id"]. '/';
              $folder_name = str_replace('/', ' ', $prop_id[$i]["property_address"]);
              foreach ($img_data as $key => $value) 
              {
                if($value["req_property_id"] == $prop_id[$i]["id"])
                {
                    $new_filename = $value["image_name"];
                    $file_full_path = $image_dir .$value["image_name"];

                    /******** Check if folder exists or not in dropbox ********/
                    $localPathToFile = $file_full_path;//This is for folder path of images
                    $filesize = filesize(@$localPathToFile);
                    $buffer = 1024 * 1024 * 10; //Send 10MB at a time - increase or lower this based on your setup
                    $destinationPath ='/' . $folder_name . '/High Resolution Images/' .$value["position"]  . '_' . $new_filename; // Path on the user's Dropbox

                    $fh = \GuzzleHttp\Psr7\stream_for(fopen($localPathToFile, 'r')); //Open our file

                    $append = new Append();
                    $sessionID = json_decode((new Start())->raw()->getBody()->getContents(), true)['session_id']; //Get a session ID
                    $cursor = new UploadSessionCursor($sessionID); //Create a cursor from the session ID
                    $offset = 0;
                    $finished = false;

                    // Keep appending until we're at the last segment
                    while (!$finished) {
                        $cursor->setOffset($offset);
                        $data = \GuzzleHttp\Psr7\stream_for($fh->read($buffer));
                        $offset += $buffer;

                        if ($data->getSize() == $buffer || $offset < $filesize) {
                            // Haven't scanned the entire file
                            $append->raw($data, $cursor);
                        } else {
                            //Send the last segment
                            $finished = true;
                            $commit = new CommitInfo($destinationPath);
                            (new Finish())->raw($data,$cursor,$commit);
                        }
                    }
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
    /**
     * Function name : setStatusProperties
     * Date Creates  : Jan 06, 2017
     * Date Modified : Oct 10, 2017
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