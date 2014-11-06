LocalS3Repo modified to work with MediaWiki 1.23 and CloudFront CDN. 


Mike Cariaso - cariaso at snpedia dot com

Based on
* https://github.com/oborseth/LocalS3Repo2
* https://www.mediawiki.org/wiki/Extension:LocalS3Repo
	
	

/*	
	A maintenance script that was used to move current images between s3 buckets is included; 
	   it will probably need to be slightly modified to work for you.
*/

// s3 filesystem repo settings - start
// Modify below with tyour settings and paste it all into your LocalSettings.php file.
// Basically, just modify the values that are in all uppercase and all should be fine.

// $wgUploadDirectory is the directory in your bucket where the image directories and images will be stored.
// If "images" doesn't work for you, change it.

```
$wgUploadDirectory = 'images';
$wgUploadS3Bucket = 'YOUR S3 BUCKET';
$wgUploadS3SSL = false; // true if SSL should be used
$wgPublicS3 = true; // true if public, false if authentication should be used
$wgS3BaseUrl = "http".($wgUploadS3SSL?"s":"")."://s3.amazonaws.com/$wgUploadS3Bucket";
$wgUploadBaseUrl = "$wgS3BaseUrl/$wgUploadDirectory";
// leave $wgCloudFrontUrl blank to not render images from CloudFront
$wgCloudFrontUrl = "http".($wgUploadS3SSL?"s":"").'://YOUR_CLOUDFRONT_SUBDOMAIN.cloudfront.net/';


$role_name = file_get_contents('http://169.254.169.254/latest/meta-data/iam/security-credentials/');
$auth = json_decode(file_get_contents('http://169.254.169.254/latest/meta-data/iam/security-credentials/'.$role_name),true);



$wgLocalFileRepo = array(
        'class' => 'LocalS3Repo',
        'name' => 's3',
        'directory' => $wgUploadDirectory,
        'url' => $wgUploadBaseUrl ? $wgUploadBaseUrl . $wgUploadPath : $wgUploadPath,
        'urlbase' => $wgS3BaseUrl ? $wgS3BaseUrl : "",
        'hashLevels' => $wgHashedUploadDirectory ? 2 : 0,
        'thumbScriptUrl' => $wgThumbnailScriptPath,
        'transformVia404' => !$wgGenerateThumbnailOnParse,
        'initialCapital' => $wgCapitalLinks,
        'deletedDir' => $wgUploadDirectory.'/deleted',
        #'deletedHashLevels' => $wgFileStore['deleted']['hash'],
        'AWS_ACCESS_KEY' => $auth['AccessKeyId'],
        'AWS_SECRET_KEY' => $auth['SecretAccessKey'],
        'AWS_S3_BUCKET' => $wgUploadS3Bucket,
        'AWS_S3_PUBLIC' => $wgPublicS3,
        'AWS_S3_SSL' => $wgUploadS3SSL,
        'cloudFrontUrl' => $wgCloudFrontUrl,
);
require_once("$IP/extensions/LocalS3Repo/LocalS3Repo.php");
```
