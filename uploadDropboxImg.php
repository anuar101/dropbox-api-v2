<?php
    ini_set('error_reporting', E_ALL);
    ini_set('display_errors','On');
    ini_set('memory_limit', '-1');
    $running = exec("ps aux|grep " . basename(__FILE__) . "|grep -v grep|wc -l");
    if ($running > 1) {
        file_put_contents(__DIR__ . "/logs/generate_dropbox_error.log", "\n\n  ===================== PMS ---------> dropbox uploading is already in process ====================\n\n", FILE_APPEND);
        exit;
    }

    file_put_contents(__DIR__ . "/logs/generate_dropbox_error.log", "\n\n  ===================== DATE TIME OF CRON : " . date('Y-m-d H:i:s') . " ====================\n\n", FILE_APPEND);
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
    require __DIR__ . '/config/constants.php';
    require __DIR__ . '/vendor/autoload.php';
    AbstractOperation::setDefaultAsync(false);
    AbstractOperation::setDefaultToken('your access token here');

    // Initialize DB connection 
    $con = mysqli_connect(DB_SERVER,DB_USER,DB_PASSWORD,DB);
    // Check connection
    if($con->connect_error) {
        die("Connection failed: " . $con->connect_error);
    }
    $sql = "SELECT request_details.id,request_details.property_address,request_details.status_properties"
            . " FROM `request_details`"
            . " WHERE request_details.status_properties =2 LIMIT 50";
    $fetchPdfSql = $con->query($sql);

    if ($fetchPdfSql->num_rows > 0) {
        // output data of each row
        $arrChild = array();
        $arrParentId = array();
        while($pdfResult = $fetchPdfSql->fetch_assoc()) {
            $arrParentId[] = $pdfResult;

            $fetchPdfQuery1 = "SELECT upload_photos.req_property_id,upload_photos.position,upload_photos.image_name"
              . " FROM `upload_photos`"
            . " WHERE upload_photos.req_property_id = '".$pdfResult["id"]."'";
            $fetchPdfSql1 = $con->query($fetchPdfQuery1);

            if ($fetchPdfSql1->num_rows > 0) {
                while ($pdfResult1 = $fetchPdfSql1->fetch_assoc()) {
                    $arrChild[]   = $pdfResult1;
                }
            }
        }
        // call upload dropbox 
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
        $main_dir= 'your local files here';
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

                    $localPathToFile = $file_full_path;//This is for folder path of images
                    $filesize = filesize($localPathToFile);
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
            //set update status properties if done uploaded in dropbox
            setStatusProperties($prop_id);
        }
        catch(Exception $e)
        {
             echo $e->getMessage();
        }
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
        $con = mysqli_connect(DB_SERVER,DB_USER,DB_PASSWORD,DB);
        $cnt = count($prop_id);
        for($i=0; $i < $cnt; $i++) 
        {
          $updateStatusProperties = "UPDATE `request_details` SET `status_properties` = '1' WHERE `id` ='" .$prop_id[$i]["id"]. "'";
          $fetchPdfSql1 = $con->query($updateStatusProperties);
        }
        return true;
    }