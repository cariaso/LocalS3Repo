<?php
/*
		Modified to work with 1.21 and CloudFront.
		Owen Borseth - owen at borseth dot us
*/

/**
 * Bump this number when serialized cache records may be incompatible.
 */
define( 'MW_FILE_VERSION', 8 );

/**
 * Class to represent a file located in the Amazon S3 system, in the wiki's own database
 *
 * Based on LocalFile.php, LocalRepo.php, FSRepo.php and OldLocalFile.php (ver 1.16-alpha, r69121)
 *
 * Provides methods to retrieve paths (physical, logical, URL),
 * to generate image thumbnails or for uploading.
 *
 * Note that only the repo object knows what its file class is called. You should
 * never name a file class explictly outside of the repo class. Instead use the
 * repo's factory functions to generate file objects, for example:
 *
 * RepoGroup::singleton()->getLocalRepo()->newFile($title);
 *
 * The convenience functions wfLocalFile() and wfFindFile() should be sufficient
 * in most cases.
 *
 * @ingroup FileRepo
 */

class LocalS3File extends File {
	//TODO: Handle this properly.
	//This is a total hack, but PDFs seem to be causing issues here because they have large metadata values.
	var $ignored_mime_types = array('pdf');

	var $thumbTempPath = NULL;

	/**#@+
	 * @private
	 */
	var	$fileExists,       # does the file file exist on disk? (loadFromXxx)
		$historyLine, 	   # Number of line to return by nextHistoryLine() (constructor)
		$historyRes, 	   # result of the query for the file's history (nextHistoryLine)
		$width,            # \
		$height,           #  |
		$bits,             #   --- returned by getimagesize (loadFromXxx)
		$attr,             # /
		$media_type,       # MEDIATYPE_xxx (bitmap, drawing, audio...)
		$mime,             # MIME type, determined by MimeMagic::guessMimeType
		$major_mime,       # Major mime type
		$minor_mime,       # Minor mime type
		$size,             # Size in bytes (loadFromXxx)
		$metadata,         # Handler-specific metadata
		$timestamp,        # Upload timestamp
		$sha1,             # SHA-1 base 36 content hash
		$user, $user_text, # User, who uploaded the file
		$description,      # Description of current revision of the file
		$dataLoaded,       # Whether or not all this has been loaded from the database (loadFromXxx)
		$upgraded,         # Whether the row was upgraded on load
		$locked,           # True if the image row is locked
		$tempPath,			# path on Windows/Linux server of temporary file, copy of S3 file
		$missing,          # True if file is not present in file system. Not to be cached in memcached
		$deleted;       # Bitfield akin to rev_deleted

	/**#@-*/

	/**
	* Get a query string authenticated URL
	*
	* @param array $credentials AWS credentials
	* @param string $bucket Bucket name
	* @param string $uri Object URI
	* @param integer $lifetime Lifetime in seconds
	* @param boolean $hostBucket Use the bucket name as the hostname
	* @return string
	*/
	public static function getAuthenticatedURL($credentials, $bucket, $uri, $lifetime, $hostBucket = false) {
		$s3 = new S3($credentials['AWS_ACCESS_KEY'], $credentials['AWS_SECRET_KEY'], $credentials['AWS_S3_SSL']);
		return $s3->getAuthenticatedURL($bucket, $uri, $lifetime, $hostBucket, $credentials['AWS_S3_SSL']);
	}

	/**
	 * Get the URL of the archive directory, or a particular file if $suffix is specified
	 */
	function getArchiveUrl( $suffix = false ) {
		wfDebug( __METHOD__ . " suffix: $suffix, url:".print_r($this->url, true)."\n" );
		if ( $suffix === false ) {
			$path = $this->repo->getZoneUrl('public') . '/archive/' . $this->getHashPath();
			$path = substr( $path, 0, -1 );
		} else {
			$path = '/archive/' . $this->getHashPath($suffix) . $suffix;
			if(! $this->repo->AWS_S3_PUBLIC) {
				$path = self::getAuthenticatedURL(self::getCredentials(), $this->repo->AWS_S3_BUCKET, $this->repo->getZonePath('public') . $path, 60*60*24*7 /*week*/, false);
			} else {
				$path = $this->repo->getZoneUrl('public') . $path; 
			}
		}
		wfDebug( __METHOD__ . " return: $path \n".print_r($this,true)."\n" );
		return $path;
	}

	/**
	 * Helper method that simply returns FSs3Repo credentials.
	 * @param
	 * @return array
	 */
	private function getCredentials(){
		return array(
			'AWS_ACCESS_KEY' => $this->repo->AWS_ACCESS_KEY,
			'AWS_SECRET_KEY' => $this->repo->AWS_SECRET_KEY,
			'AWS_S3_SSL' => $this->repo->AWS_S3_SSL
		);
	}

	/**
	 * Create a LocalS3File from a title
	 * Do not call this except from inside a repo class.
	 *
	 * Note: $unused param is only here to avoid an E_STRICT
	 */
	static function newFromTitle( $title, $repo, $unused = null ) {
		return new self( $title, $repo );
	}

	/**
	 * Create a LocalS3File from a row
	 * Do not call this except from inside a repo class.
	 */
	static function newFromRow( $row, $repo ) {
		$title = Title::makeTitle( NS_FILE, $row->img_name );
		$file = new self( $title, $repo );
		$file->loadFromRow( $row );
		return $file;
	}

	/**
	 * Create a LocalS3File from a SHA-1 key
	 * Do not call this except from inside a repo class.
	 */
	static function newFromKey( $sha1, $repo, $timestamp = false ) {
		# Polymorphic function name to distinguish foreign and local fetches
		$fname = get_class( $this ) . '::' . __FUNCTION__;

		$conds = array( 'img_sha1' => $sha1 );
		if( $timestamp ) {
			$conds['img_timestamp'] = $timestamp;
		}
		$row = $dbr->selectRow( 'image', $this->getCacheFields( 'img_' ), $conds, $fname );
		if( $row ) {
			return self::newFromRow( $row, $repo );
		} else {
			return false;
		}
	}

	/**
	 * Fields in the image table
	 */
	static function selectFields() {
		return array(
			'img_name',
			'img_size',
			'img_width',
			'img_height',
			'img_metadata',
			'img_bits',
			'img_media_type',
			'img_major_mime',
			'img_minor_mime',
			'img_description',
			'img_user',
			'img_user_text',
			'img_timestamp',
			'img_sha1',
		);
	}

	/**
	 * Constructor.
	 * Do not call this except from inside a repo class.
	 */
	function __construct( $title, $repo ) {
		if( !is_object( $title ) ) {
			throw new MWException( __CLASS__ . ' constructor given bogus title.' );
		}
		parent::__construct( $title, $repo );
		$this->metadata = '';
		$this->historyLine = 0;
		$this->historyRes = null;
		$this->dataLoaded = false;
	}

	/**
	 * Get the memcached key for the main data for this file, or false if
	 * there is no access to the shared cache.
	 */
	function getCacheKey() {
		$hashedName = md5( $this->getName() );
		return $this->repo->getSharedCacheKey( 'file', $hashedName );
	}

	/**
	 * Try to load file metadata from memcached. Returns true on success.
	 */
	function loadFromCache() {
		global $wgMemc;
		wfProfileIn( __METHOD__ );
		$this->dataLoaded = false;
		$key = $this->getCacheKey();
		if ( !$key ) {
			wfProfileOut( __METHOD__ );
			return false;
		}
		$cachedValues = $wgMemc->get( $key );

		// Check if the key existed and belongs to this version of MediaWiki
		if ( isset( $cachedValues['version'] ) && ( $cachedValues['version'] == MW_FILE_VERSION ) ) {
			wfDebug( "Pulling file metadata from cache key $key\n" );
			$this->fileExists = $cachedValues['fileExists'];
			if ( $this->fileExists ) {
				$this->setProps( $cachedValues );
			}
			$this->dataLoaded = true;
		}
		if ( $this->dataLoaded ) {
			wfIncrStats( 'image_cache_hit' );
		} else {
			wfIncrStats( 'image_cache_miss' );
		}

		wfProfileOut( __METHOD__ );
		return $this->dataLoaded;
	}

	/**
	 * Save the file metadata to memcached
	 */
	function saveToCache() {
		global $wgMemc;
		$this->load();
		$key = $this->getCacheKey();
		if ( !$key ) {
			return;
		}
		$fields = $this->getCacheFields( '' );
		$cache = array( 'version' => MW_FILE_VERSION );
		$cache['fileExists'] = $this->fileExists;
		if ( $this->fileExists ) {
			foreach ( $fields as $field ) {
				$cache[$field] = $this->$field;
			}
		}

		$wgMemc->set( $key, $cache, 60 * 60 * 24 * 7 ); // A week
	}

	/**
	 * Load metadata from the file itself
	 */
	function loadFromFile() {
		$this->setProps( FSFile::getPropsFromPath( $this->getPath() ) );
	}

	function getCacheFields( $prefix = 'img_' ) {
		static $fields = array( 'size', 'width', 'height', 'bits', 'media_type',
			'major_mime', 'minor_mime', 'metadata', 'timestamp', 'sha1', 'user', 'user_text', 'description' );
		static $results = array();
		if ( $prefix == '' ) {
			return $fields;
		}
		if ( !isset( $results[$prefix] ) ) {
			$prefixedFields = array();
			foreach ( $fields as $field ) {
				$prefixedFields[] = $prefix . $field;
			}
			$results[$prefix] = $prefixedFields;
		}
		return $results[$prefix];
	}

	/**
	 * Load file metadata from the DB
	 */
	function loadFromDB() {
		# Polymorphic function name to distinguish foreign and local fetches
		$fname = get_class( $this ) . '::' . __FUNCTION__;
		wfProfileIn( $fname );

		# Unconditionally set loaded=true, we don't want the accessors constantly rechecking
		$this->dataLoaded = true;

		$dbr = $this->repo->getMasterDB();

		$row = $dbr->selectRow( 'image', $this->getCacheFields( 'img_' ), array( 'img_name' => $this->getName() ), $fname );

		if ( $row ) {
			$this->loadFromRow( $row );
		} else {
			$this->fileExists = false;
		}

		wfProfileOut( $fname );
	}

	/**
	 * Decode a row from the database (either object or array) to an array
	 * with timestamps and MIME types decoded, and the field prefix removed.
	 */
	function decodeRow( $row, $prefix = 'img_' ) {
		$array = (array)$row;
		$prefixLength = strlen( $prefix );
		// Sanity check prefix once
		if ( substr( key( $array ), 0, $prefixLength ) !== $prefix ) {
			throw new MWException( __METHOD__ .  ': incorrect $prefix parameter' );
		}
		$decoded = array();
		foreach ( $array as $name => $value ) {
			$decoded[substr( $name, $prefixLength )] = $value;
		}
		$decoded['timestamp'] = wfTimestamp( TS_MW, $decoded['timestamp'] );
		if ( empty( $decoded['major_mime'] ) ) {
			$decoded['mime'] = 'unknown/unknown';
		} else {
			if ( !$decoded['minor_mime'] ) {
				$decoded['minor_mime'] = 'unknown';
			}
			$decoded['mime'] = $decoded['major_mime'] . '/' . $decoded['minor_mime'];
		}
		# Trim zero padding from char/binary field
		$decoded['sha1'] = rtrim( $decoded['sha1'], "\0" );
		return $decoded;
	}

	/**
	 * Load file metadata from a DB result row
	 */
	function loadFromRow( $row, $prefix = 'img_' ) {
		$this->dataLoaded = true;
		$array = $this->decodeRow( $row, $prefix );
		foreach ( $array as $name => $value ) {
			$this->$name = $value;
		}
		$this->fileExists = true;
		$this->maybeUpgradeRow();
	}

	/**
	 * Load file metadata from cache or DB, unless already loaded
	 */
	function load( $flags = 0 ) {
		if ( !$this->dataLoaded ) {
			if ( !$this->loadFromCache() ) {
				$this->loadFromDB();
				$this->saveToCache();
			}
			$this->dataLoaded = true;
		}
	}

	/**
	 * Upgrade a row if it needs it
	 */
	function maybeUpgradeRow() {
		if ( wfReadOnly() ) {
			return;
		}
		if ( is_null( $this->media_type ) || $this->mime == 'image/svg')
		{
			$this->upgradeRow();
			$this->upgraded = true;
		}
		else
		{
			$handler = $this->getHandler();
			if ( $handler && !$handler->isMetadataValid( $this, $this->metadata ) )
			{
				$this->upgradeRow();
				$this->upgraded = true;
			}
		}
	}

	function getUpgraded() {
		return $this->upgraded;
	}

	/**
	 * Fix assorted version-related problems with the image row by reloading it from the file
	 */
	function upgradeRow() {
		wfProfileIn( __METHOD__ );

		$this->loadFromFile();

		# Don't destroy file info of missing files
		if ( !$this->fileExists ) {
			wfDebug( __METHOD__ . ": file does not exist, aborting\n" );
			wfProfileOut( __METHOD__ );
			return;
		}
		$dbw = $this->repo->getMasterDB();
		list( $major, $minor ) = self::splitMime( $this->mime );

		if ( wfReadOnly() ) {
			wfProfileOut( __METHOD__ );
			return;
		}
		wfDebug( __METHOD__ . ': upgrading ' . $this->getName() . " to the current schema\n" );

		if( !in_array($minor, $this->ignored_mime_types) ){
			$dbw->update( 'image',
				array(
					'img_width' => $this->width,
					'img_height' => $this->height,
					'img_bits' => $this->bits,
					'img_media_type' => $this->media_type,
					'img_major_mime' => $major,
					'img_minor_mime' => $minor,
					'img_metadata' => $this->metadata,
					'img_sha1' => $this->sha1,
				), array( 'img_name' => $this->getName() ),
				__METHOD__
			);
		}
		$this->saveToCache();
		wfProfileOut( __METHOD__ );
	}

	/**
	 * Set properties in this object to be equal to those given in the
	 * associative array $info. Only cacheable fields can be set.
	 *
	 * If 'mime' is given, it will be split into major_mime/minor_mime.
	 * If major_mime/minor_mime are given, $this->mime will also be set.
	 */
	function setProps( $info ) {
		$this->dataLoaded = true;
		$fields = $this->getCacheFields( '' );
		$fields[] = 'fileExists';
		foreach ( $fields as $field )
		{
			if ( isset( $info[$field] ) )
			{
				$this->$field = $info[$field];
			}
		}
		// Fix up mime fields
		if ( isset( $info['major_mime'] ) ) {
			$this->mime = "{$info['major_mime']}/{$info['minor_mime']}";
		} elseif ( isset( $info['mime'] ) ) {
			list( $this->major_mime, $this->minor_mime ) = self::splitMime( $this->mime );
		}
	}

	/** splitMime inherited */
	/** getName inherited */
	/** getTitle inherited */
	/** getURL inherited */
	/** getViewURL inherited */
	/** getPath WAS inherited */
	/** isVisible inhereted */

	function isMissing() {
		if( $this->missing === null ) {
			list( $fileExists ) = $this->repo->fileExistsBatch( array( $this->getVirtualUrl() )/*, FSs3Repo::FILES_ONLY*/ );
			$this->missing = !$fileExists;
		}
		return $this->missing;
	}

	/**
	 * Return the width of the image
	 *
	 * Returns false on error
	 */
	public function getWidth( $page = 1 ) {
		$this->load();
		if ( $this->isMultipage() ) {
			$dim = $this->getHandler()->getPageDimensions( $this, $page );
			if ( $dim ) {
				return $dim['width'];
			} else {
				return false;
			}
		} else {
			return $this->width;
		}
	}

	/**
	 * Return the height of the image
	 *
	 * Returns false on error
	 */
	public function getHeight( $page = 1 ) {
		$this->load();
		if ( $this->isMultipage() ) {
			$dim = $this->getHandler()->getPageDimensions( $this, $page );
			if ( $dim ) {
				return $dim['height'];
			} else {
				return false;
			}
		} else {
			return $this->height;
		}
	}

	/**
	 * Returns ID or name of user who uploaded the file
	 *
	 * @param $type string 'text' or 'id'
	 */
	function getUser( $type = 'text' ) {
		$this->load();
		if( $type == 'text' ) {
			return $this->user_text;
		} elseif( $type == 'id' ) {
			return $this->user;
		}
	}

	/**
	 * Get handler-specific metadata
	 */
	function getMetadata() {
		$this->load();
		return $this->metadata;
	}

	function getBitDepth() {
		$this->load();
		return $this->bits;
	}

	/**
	 * Return the size of the image file, in bytes
	 */
	public function getSize() {
		$this->load();
		return $this->size;
	}

	/**
	 * Returns the mime type of the file.
	 */
	function getMimeType() {
		$this->load();
		return $this->mime;
	}

	/**
	 * Return the type of the media in the file.
	 * Use the value returned by this function with the MEDIATYPE_xxx constants.
	 */
	function getMediaType() {
		$this->load();
		return $this->media_type;
	}

	/** canRender inherited */
	/** mustRender inherited */
	/** allowInlineDisplay inherited */
	/** isSafeFile inherited */
	/** isTrustedFile inherited */


	function getLocalRefPath()
	{
		if($this->thumbTempPath)
			return($this->thumbTempPath);
		else
			return(parent::getetLocalRefPath());
	}

	/**
	 * Returns true if the file file exists on disk.
	 * @return boolean Whether file file exist on disk.
	 */
	public function exists() {
		$this->load();
		return $this->fileExists;
	}

		/**
	 * Returns true if the file comes from the local file repository.
	 *
	 * @return bool
	 */
	function isLocal() {
		return true; // s3 is considered local for these purposes
	}

	/** getTransformScript inherited */
	/** getUnscaledThumb inherited */
	/** thumbName inherited */
	/** createThumb inherited */
	/** getThumbnail inherited */
	/** transform WAS inherited */

	/**
	 * Transform a media file
	 *
	 * @param array $params An associative array of handler-specific parameters. Typical
	 *                      keys are width, height and page.
	 * @param integer $flags A bitfield, may contain self::RENDER_NOW to force rendering
	 * @return MediaTransformOutput
	 */
	function transform( $params, $flags = 0 ) {
		global $wgUseSquid, $wgIgnoreImageErrors;
		wfDebug( __METHOD__ . ": ".print_r($params,true)."\n" );

		wfProfileIn( __METHOD__ );
		do {
			if ( !$this->canRender() ) {
				// not a bitmap or renderable image, don't try.
				$thumb = $this->iconThumb();
				break;
			}

			$script = $this->getTransformScript();
			if ( $script && !($flags & self::RENDER_NOW) ) {
				// Use a script to transform on client request, if possible
				$thumb = $this->handler->getScriptedTransform( $this, $script, $params );
				if( $thumb ) {
					break;
				}
			}

			$normalisedParams = $params;
			$this->handler->normaliseParams( $this, $normalisedParams );
			$thumbName = $this->thumbName( $normalisedParams );
			$thumbPath = $this->getThumbPath( $thumbName );
			$thumbUrl = $this->getThumbUrl( $thumbPath );
			wfDebug( __METHOD__.": thumbName: $thumbName, thumbPath: $thumbPath\n  thumbUrl: $thumbUrl\n" );

			if ( $this->repo->canTransformVia404() && !($flags & self::RENDER_NOW ) ) {
				$thumb = $this->handler->getTransform( $this, $thumbPath, $thumbUrl, $params );
				break;
			}

			wfDebug( __METHOD__.": Doing stat for $thumbPath\n  ($thumbUrl)\n" );
			$this->migrateThumbFile( $thumbName );

			$s3 = self::getS3Instance();
			$info = $s3->getObjectInfo($this->repo->AWS_S3_BUCKET, $thumbPath);
			wfDebug(__METHOD__." thumbPath: $thumbPath\ninfo:".print_r($info,true)."\n");
			if ( $info /*file_exists( $thumbPath )*/ ) {
				$thumb = $this->handler->getTransform( $this, $thumbPath, $thumbUrl, $params );
				break;
			}

			$this->thumbTempPath = tempnam(wfTempDir(), "s3thumb-");
			copy($this->getUrl(), $this->thumbTempPath);

			$thumb = $this->handler->doTransform( $this, $this->thumbTempPath, $thumbUrl, $params );

			wfDebug( __METHOD__. " thumb: ".print_r($thumb->getUrl(),true)."\n" );
			$s3path = $thumbPath;

			$info = $s3->putObjectFile($this->thumbTempPath, $this->repo->AWS_S3_BUCKET, $s3path,
							($this->repo->AWS_S3_PUBLIC ? S3::ACL_PUBLIC_READ : S3::ACL_PRIVATE));


			wfDebug(__METHOD__." thumbTempPath: $this->thumbTempPath, dest: $s3path\ninfo:".print_r($info,true)."\n");

			// Ignore errors if requested
			if ( !$thumb ) {
				$thumb = null;
			} elseif ( $thumb->isError() ) {
				$this->lastError = $thumb->toText();
				if ( $wgIgnoreImageErrors && !($flags & self::RENDER_NOW) ) {
					$thumb = $this->handler->getTransform( $this, $thumbPath, $thumbUrl, $params );
				}
			}

			// Purge. Useful in the event of Core -> Squid connection failure or squid
			// purge collisions from elsewhere during failure. Don't keep triggering for
			// "thumbs" which have the main image URL though (bug 13776)
			if ( $wgUseSquid && ( !$thumb || $thumb->isError() || $thumb->getUrl() != $this->getURL()) ) {
				SquidUpdate::purge( array( $thumbUrl ) );
			}
		} while (false);

		wfProfileOut( __METHOD__ );
		wfDebug( __METHOD__. " return thumb: ".print_r($thumb,true)."\n" );
		return is_object( $thumb ) ? $thumb : false;
	}

	/**
	 * Total hack for retrieving an S3 instance. This should really be rewritten.
	 * @param
	 * @return S3
	 */
	function getS3Instance(){
		$credentials = self::getCredentials();
		return new S3($credentials['AWS_ACCESS_KEY'], $credentials['AWS_SECRET_KEY'], $credentials['AWS_S3_SSL']);
	}

	/**
	 * Get the URL of the thumbnail directory, or a particular file if $suffix is specified.
	 * $suffix is a path relative to the S3 bucket, and includes the upload directory
	 */
	function getThumbUrl( $suffix = false ) {
		if($this->repo->cloudFrontUrl)
			$path = $this->repo->cloudFrontUrl . "$suffix";
		else
			$path = $this->repo->getUrlBase() . "/$suffix";

		if(! $this->repo->AWS_S3_PUBLIC) {
			$path = self::getAuthenticatedURL(self::getCredentials(), $this->repo->AWS_S3_BUCKET, $suffix, 60*60*24*7 /*week*/, false);
		}
		return $path;
	}

	/**
	* Return the full filesystem path to the file. Note that this does
	* not mean that a file actually exists under that location.
	*
	* This path depends on whether directory hashing is active or not,
	* i.e. whether the files are all found in the same directory,
	* or in hashed paths like /images/3/3c.
	*
	*
	* If forceExist is true, will copy file from S3 and put it in a temp file.
	* The temp filename will be returned.
	*
	* May return false if the file is not locally accessible.
	*/
	public function getPath( $forceExist=true ) {
		$s3 = self::getS3Instance();
		if ( !isset( $this->tempPath ) ) {
			$this->tempPath = tempnam(wfTempDir(), "s3file-");
			$info = $s3->getObject($this->repo->AWS_S3_BUCKET, $this->repo->directory . '/' . $this->getUrlRel(), $this->tempPath);
			if(!$info) {
				$this->tempPath = false;
			}
		}
		return $this->tempPath;
	}


	/**
	 * Fix thumbnail files from 1.4 or before, with extreme prejudice
	 */
	function migrateThumbFile( $thumbName ) {
		// can't do this in S3, files and directories are conceptually different than Linux
		return; /***************/
		$thumbDir = $this->getThumbPath();
		$thumbPath = "$thumbDir/$thumbName";
		if ( is_dir( $thumbPath ) ) {
			// Directory where file should be
			// This happened occasionally due to broken migration code in 1.5
			// Rename to broken-*
			for ( $i = 0; $i < 100 ; $i++ ) {
				$broken = $this->repo->getZonePath( 'public' ) . "/broken-$i-$thumbName";
				if ( !file_exists( $broken ) ) {
					rename( $thumbPath, $broken );
					break;
				}
			}
			// Doesn't exist anymore
			clearstatcache();
		}
		if ( is_file( $thumbDir ) ) {
			// File where directory should be
			unlink( $thumbDir );
			// Doesn't exist anymore
			clearstatcache();
		}
	}

	/** getTransformScript inherited */
	/** getUnscaledThumb inherited */
	/** thumbName inherited */
	/** createThumb inherited */
	/** transform inherited */
	/** getHandler inherited */
	/** iconThumb inherited */
	/** getLastError inherited */

	/**
	 * Get all thumbnail names previously generated for this file
	 * @param string|bool $archiveName Name of an archive file, default false
	 * @return array First element is the base dir, then files in that base dir.
	 */
	function getThumbnails( $archiveName = false ) {
		if ( $archiveName ) {
			$dir = $this->getArchiveThumbPath( $archiveName );
		} else {
			$dir = $this->getThumbPath();
		}
		$backend = $this->repo->getBackend();
		$files = array( $dir );
		try {
			$iterator = $backend->getFileList( array( 'dir' => $dir ) );
			foreach ( $iterator as $file ) {
				$files[] = $file;
			}
		} catch ( FileBackendError $e ) {
		} // suppress (bug 54674)
		return $files;
	}

	/**
	 * Refresh metadata in memcached, but don't touch thumbnails or squid
	 */
	function purgeMetadataCache() {
		$this->invalidateCache();
	}

	/**
	 * Purge the shared history (OldLocalS3File) cache
	 */
	function purgeHistory() {
		global $wgMemc;
		$hashedName = md5( $this->getName() );
		$oldKey = $this->repo->getSharedCacheKey( 'oldfile', $hashedName );
		if ( $oldKey ) {
			$wgMemc->delete( $oldKey );
		}
	}

	/**
	 * Delete all previously generated thumbnails, refresh metadata in memcached and purge the squid.
	 *
	 * @param array $options An array potentially with the key forThumbRefresh.
	 *
	 * @note This used to purge old thumbnails by default as well, but doesn't anymore.
	 */
	function purgeCache( $options = array() ) {
		// Refresh metadata cache
		$this->purgeMetadataCache();

		// Delete thumbnails
		$this->purgeThumbnails( $options );

		// Purge squid cache for this file
		SquidUpdate::purge( array( $this->getURL() ) );
	}

	/**
	 * Delete cached transformed files
	 */
	function purgeOldThumbnails() {
		global $wgUseSquid;

		// Get a list of old thumbnails and URLs
		$files = $this->getThumbnails( $archiveName );

		// Purge any custom thumbnail caches
		Hooks::run( 'LocalFilePurgeThumbnails', array( $this, $archiveName ) );
		$dir = array_shift( $files );
		$this->purgeThumbList( $dir, $files );

		// Purge the squid
		if ( $wgUseSquid ) {
			$urls = array();
			foreach ( $files as $file ) {
				$urls[] = $this->getArchiveThumbUrl( $archiveName, $file );
			}
			SquidUpdate::purge( $urls );
		}
	}

	/**
	 * Delete a list of thumbnails visible at urls
	 * @param string $dir Base dir of the files.
	 * @param array $files Array of strings: relative filenames (to $dir)
	 */
	protected function purgeThumbList( $dir, $files ) {
		$fileListDebug = strtr(
			var_export( $files, true ),
			array( "\n" => '' )
		);
		wfDebug( __METHOD__ . ": $fileListDebug\n" );
		$purgeList = array();
		foreach ( $files as $file ) {
			# Check that the base file name is part of the thumb name
			# This is a basic sanity check to avoid erasing unrelated directories
			if ( strpos( $file, $this->getName() ) !== false
				|| strpos( $file, "-thumbnail" ) !== false // "short" thumb name
			) {
				$purgeList[] = "{$dir}/{$file}";
			}
		}

		# Delete the thumbnails
		$this->repo->quickPurgeBatch( $purgeList );

		# Clear out the thumbnail directory if empty
		$this->repo->quickCleanDir( $dir );
	}

	/**
	 * Delete cached transformed files for the current version only.
	 * @param array $options
	 */
	function purgeThumbnails( $options = array() ) {
		global $wgUseSquid;
		// Delete thumbnails
		$files = $this->getThumbnails();
		// Always purge all files from squid regardless of handler filters
		$urls = array();
		if ( $wgUseSquid ) {
			foreach ( $files as $file ) {
				$urls[] = $this->getThumbUrl( $file );
			}
			array_shift( $urls ); // don't purge directory
		}
		// Give media handler a chance to filter the file purge list
		if ( !empty( $options['forThumbRefresh'] ) ) {
			$handler = $this->getHandler();
			if ( $handler ) {
				$handler->filterThumbnailPurgeList( $files, $options );
			}
		}
		// Purge any custom thumbnail caches
		Hooks::run( 'LocalFilePurgeThumbnails', array( $this, false ) );
		$dir = array_shift( $files );
		$this->purgeThumbList( $dir, $files );

		// Purge the squid
		if ( $wgUseSquid ) {
			SquidUpdate::purge( $urls );
		}
	}

	/** purgeDescription inherited */
	/** purgeEverything inherited */

	function getHistory( $limit = null, $start = null, $end = null, $inc = true ) {
		$dbr = $this->repo->getSlaveDB();
		$tables = array( 'oldimage' );
		$fields = OldLocalS3File::selectFields();
		$conds = $opts = $join_conds = array();
		$eq = $inc ? '=' : '';
		$conds[] = "oi_name = " . $dbr->addQuotes( $this->title->getDBkey() );
		if( $start ) {
			$conds[] = "oi_timestamp <$eq " . $dbr->addQuotes( $dbr->timestamp( $start ) );
		}
		if( $end ) {
			$conds[] = "oi_timestamp >$eq " . $dbr->addQuotes( $dbr->timestamp( $end ) );
		}
		if( $limit ) {
			$opts['LIMIT'] = $limit;
		}
		// Search backwards for time > x queries
		$order = ( !$start && $end !== null ) ? 'ASC' : 'DESC';
		$opts['ORDER BY'] = "oi_timestamp $order";
		$opts['USE INDEX'] = array( 'oldimage' => 'oi_name_timestamp' );

		wfRunHooks( 'LocalS3File::getHistory', array( &$this, &$tables, &$fields,
			&$conds, &$opts, &$join_conds ) );

		$res = $dbr->select( $tables, $fields, $conds, __METHOD__, $opts, $join_conds );
		$r = array();
		while( $row = $dbr->fetchObject( $res ) ) {
			if ( $this->repo->oldFileFromRowFactory ) {
				$r[] = call_user_func( $this->repo->oldFileFromRowFactory, $row, $this->repo );
			} else {
				$r[] = OldLocalS3File::newFromRow( $row, $this->repo );
			}
		}
		if( $order == 'ASC' ) {
			$r = array_reverse( $r ); // make sure it ends up descending
		}
		return $r;
	}

	/**
	 * Return the history of this file, line by line.
	 * starts with current version, then old versions.
	 * uses $this->historyLine to check which line to return:
	 *  0      return line for current version
	 *  1      query for old versions, return first one
	 *  2, ... return next old version from above query
	 */
	public function nextHistoryLine() {
		# Polymorphic function name to distinguish foreign and local fetches
		$fname = get_class( $this ) . '::' . __FUNCTION__;

		$dbr = $this->repo->getSlaveDB();

		if ( $this->historyLine == 0 ) {// called for the first time, return line from cur
			$this->historyRes = $dbr->select( 'image',
				array(
					'*',
					"'' AS oi_archive_name",
					'0 as oi_deleted',
					'img_sha1'
				),
				array( 'img_name' => $this->title->getDBkey() ),
				$fname
			);
			if ( 0 == $dbr->numRows( $this->historyRes ) ) {
				$dbr->freeResult( $this->historyRes );
				$this->historyRes = null;
				return false;
			}
		} elseif ( $this->historyLine == 1 ) {
			$dbr->freeResult( $this->historyRes );
			$this->historyRes = $dbr->select( 'oldimage', '*',
				array( 'oi_name' => $this->title->getDBkey() ),
				$fname,
				array( 'ORDER BY' => 'oi_timestamp DESC' )
			);
		}
		$this->historyLine ++;

		return $dbr->fetchObject( $this->historyRes );
	}

	/**
	 * Reset the history pointer to the first element of the history
	 */
	public function resetHistory() {
		$this->historyLine = 0;
		if ( !is_null( $this->historyRes ) ) {
			$this->repo->getSlaveDB()->freeResult( $this->historyRes );
			$this->historyRes = null;
		}
	}

	/** getFullPath inherited */
	/** getHashPath inherited */
	/** getRel inherited */
	/** getUrlRel inherited */
	/** getArchiveRel inherited */
	/** getThumbRel inherited */
	/** getArchivePath inherited */
	/** getThumbPath inherited */
	/** getArchiveUrl inherited */
	/** getThumbUrl inherited */
	/** getArchiveVirtualUrl inherited */
	/** getThumbVirtualUrl inherited */
	/** isHashed inherited */

	/**
	 * Upload a file and record it in the DB
	 * @param $srcPath String: source path or virtual URL
	 * @param $comment String: upload description
	 * @param $pageText String: text to use for the new description page,
	 *                  if a new description page is created
	 * @param $flags Integer: flags for publish()
	 * @param $props Array: File properties, if known. This can be used to reduce the
	 *               upload time when uploading virtual URLs for which the file info
	 *               is already known
	 * @param $timestamp String: timestamp for img_timestamp, or false to use the current time
	 * @param $user Mixed: User object or null to use $wgUser
	 *
	 * @return FileRepoStatus object. On success, the value member contains the
	 *     archive name, or an empty string if it was a new file.
	 */
	function upload( $srcPath, $comment, $pageText, $flags = 0, $props = false, $timestamp = false, $user = null ) {
		$this->lock();
		$status = $this->publish( $srcPath, $flags );
		if ( $status->ok ) {
			if ( !$this->recordUpload2( $status->value, $comment, $pageText, $props, $timestamp, $user ) ) {
				$status->fatal( 'filenotfound', $srcPath );
			}
		}
		$this->unlock();
		return $status;
	}

	/**
	 * Record a file upload in the upload log and the image table
	 * $oldver, $desc, $license = '', $copyStatus = '', $source = '', $watch = false, $timestamp = false, User $user = NULL
	 * @deprecated use upload()
	 */
	function recordUpload( $oldver, $desc, $license = '', $copyStatus = '', $source = '', $watch = false, $timestamp = false, User $user = NULL ) {
		if ( !$user ) {
			global $wgUser;
			$user = $wgUser;
		}

		$pageText = SpecialUpload::getInitialPageText( $desc, $license, $copyStatus, $source );
		if ( !$this->recordUpload2( $oldver, $desc, $pageText, false, $timestamp, $user ) ) {
			return false;
		}

		if ( $watch ) {
			$user->addWatch( $this->getTitle() );
		}

		return true;
	}

	/**
	 * Record a file upload in the upload log and the image table
	 */
	function recordUpload2( $oldver, $comment, $pageText, $props = false, $timestamp = false, $user = null ) {
		if( is_null( $user ) ) {
			global $wgUser;
			$user = $wgUser;
		}

		$dbw = $this->repo->getMasterDB();
		$dbw->begin();

		if ( !$props ) {
			$props = $this->repo->getFileProps( $this->getVirtualUrl() );
		}
		$props['description'] = $comment;
		$props['user'] = $user->getId();
		$props['user_text'] = $user->getName();
		$props['timestamp'] = wfTimestamp( TS_MW );
		$this->setProps( $props );

		// Delete thumbnails and refresh the metadata cache
		$this->purgeThumbnails();
		$this->saveToCache();
		SquidUpdate::purge( array( $this->getURL() ) );

		// Fail now if the file isn't there
		if ( !$this->fileExists ) {
			wfDebug( __METHOD__ . ": File " . $this->getPath() . " went missing!\n" );
			return false;
		}

		$reupload = false;
		if ( $timestamp === false ) {
			$timestamp = $dbw->timestamp();
		}

		# Test to see if the row exists using INSERT IGNORE
		# This avoids race conditions by locking the row until the commit, and also
		# doesn't deadlock. SELECT FOR UPDATE causes a deadlock for every race condition.
		$dbw->insert( 'image',
			array(
				'img_name' => $this->getName(),
				'img_size'=> $this->size,
				'img_width' => intval( $this->width ),
				'img_height' => intval( $this->height ),
				'img_bits' => $this->bits,
				'img_media_type' => $this->media_type,
				'img_major_mime' => $this->major_mime,
				'img_minor_mime' => $this->minor_mime,
				'img_timestamp' => $timestamp,
				'img_description' => $comment,
				'img_user' => $user->getId(),
				'img_user_text' => $user->getName(),
				'img_metadata' => $this->metadata,
				'img_sha1' => $this->sha1
			),
			__METHOD__,
			'IGNORE'
		);

		if( $dbw->affectedRows() == 0 ) {
			$reupload = true;

			# Collision, this is an update of a file
			# Insert previous contents into oldimage
			$dbw->insertSelect( 'oldimage', 'image',
				array(
					'oi_name' => 'img_name',
					'oi_archive_name' => $dbw->addQuotes( $oldver ),
					'oi_size' => 'img_size',
					'oi_width' => 'img_width',
					'oi_height' => 'img_height',
					'oi_bits' => 'img_bits',
					'oi_timestamp' => 'img_timestamp',
					'oi_description' => 'img_description',
					'oi_user' => 'img_user',
					'oi_user_text' => 'img_user_text',
					'oi_metadata' => 'img_metadata',
					'oi_media_type' => 'img_media_type',
					'oi_major_mime' => 'img_major_mime',
					'oi_minor_mime' => 'img_minor_mime',
					'oi_sha1' => 'img_sha1'
				), array( 'img_name' => $this->getName() ), __METHOD__
			);

			# Update the current image row
			$dbw->update( 'image',
				array( /* SET */
					'img_size' => $this->size,
					'img_width' => intval( $this->width ),
					'img_height' => intval( $this->height ),
					'img_bits' => $this->bits,
					'img_media_type' => $this->media_type,
					'img_major_mime' => $this->major_mime,
					'img_minor_mime' => $this->minor_mime,
					'img_timestamp' => $timestamp,
					'img_description' => $comment,
					'img_user' => $user->getId(),
					'img_user_text' => $user->getName(),
					'img_metadata' => $this->metadata,
					'img_sha1' => $this->sha1
				), array( /* WHERE */
					'img_name' => $this->getName()
				), __METHOD__
			);
		} else {
			# This is a new file
			# Update the image count
			$site_stats = $dbw->tableName( 'site_stats' );
			$dbw->query( "UPDATE $site_stats SET ss_images=ss_images+1", __METHOD__ );
		}

		$descTitle = $this->getTitle();
		$wikiPage = new WikiFilePage( $descTitle );
		$wikiPage->setFile( $this );
		# Add the log entry...
    $action = $reupload ? 'overwrite' : 'upload';
		$logEntry = new ManualLogEntry( 'upload', $action );
		$logEntry->setTimestamp( $this->timestamp );
		$logEntry->setPerformer( $user );
		$logEntry->setComment( $comment );
		$logEntry->setTarget( $descTitle );
		// Allow people using the api to associate log entries with the upload.
		// Log has a timestamp, but sometimes different from upload timestamp.
		$logEntry->setParameters(
			array(
				'img_sha1' => $this->sha1,
				'img_timestamp' => $timestamp,
			)
		);
		// Note we keep $logId around since during new image
		// creation, page doesn't exist yet, so log_page = 0
		// but we want it to point to the page we're making,
		// so we later modify the log entry.
		// For a similar reason, we avoid making an RC entry
		// now and wait until the page exists.
		$logId = $logEntry->insert();
    $exists = $descTitle->exists();
    if ( $exists ) {
      // Page exists, do RC entry now (otherwise we wait for later).
      $logEntry->publish( $logId );
    // TODO: same if statement twice? pointless?
    //}
    //if ( $exists ) {
      // Create a null revision
      $latest = $descTitle->getLatestRevID();
			// Use own context to get the action text in content language
			$formatter = LogFormatter::newFromEntry( $logEntry );
			$formatter->setContext( RequestContext::newExtraneousContext( $descTitle ) );
			$editSummary = $formatter->getPlainActionText();
			$nullRevision = Revision::newNullRevision(
				$dbw,
				$descTitle->getArticleID(),
				$editSummary,
				false,
				$user
			);
      if ( !is_null( $nullRevision ) ) {
				$nullRevision->insertOn( $dbw );
				Hooks::run( 'NewRevisionFromEditComplete', array( $wikiPage, $nullRevision, $nullRevision->getParentId(), $user ) );
				$wikiPage->updateRevisionOn( $dbw, $nullRevision );
			}
    }

    # Commit the transaction now, in case something goes wrong later
    # The most important thing is that files don't get lost, especially archives
    # NOTE: once we have support for nested transactions, the commit may be moved
    #       to after $wikiPage->doEdit has been called.
    $dbw->commit( __METHOD__ );

    # Update memcache after the commit
    $this->invalidateCache();

    if ( $exists ) {
      # Invalidate the cache for the description page
      $descTitle->invalidateCache();
      $descTitle->purgeSquid();
		} else {
      # New file; create the description page.
      # There's already a log entry, so don't make a second RC entry
      # Squid and file cache for the description page are purged by doEditContent.
      $content = ContentHandler::makeContent( $pageText, $descTitle );
      $status = $wikiPage->doEditContent(
        $content,
        $comment,
        EDIT_NEW | EDIT_SUPPRESS_RC,
        false,
        $user
      );
      $dbw->begin( __METHOD__ ); // XXX; doEdit() uses a transaction

      // Now that the page exists, make an RC entry.
      $logEntry->publish( $logId );
      if ( isset( $status->value['revision'] ) ) {
        $dbw->update( 'logging',
          array( 'log_page' => $status->value['revision']->getPage() ),
          array( 'log_id' => $logId ),
          __METHOD__
        );
      }

      $dbw->commit( __METHOD__ ); // commit before anything bad can happen
		}

		if ( $reupload ) {
			# Delete old thumbnails
			$this->purgeThumbnails();

			# Remove the old file from the squid cache
			SquidUpdate::purge( array( $this->getURL() ) );
		}

		# Hooks, hooks, the magic of hooks...
		Hooks::run( 'FileUpload', array( $this, $reupload, $descTitle->exists() ) );

		# Invalidate cache for all pages using this file
		$update = new HTMLCacheUpdate( $this->getTitle(), 'imagelinks' );
		$update->doUpdate();
		if ( !$reupload ) {
			LinksUpdate::queueRecursiveJobsForTable( $this->getTitle(), 'imagelinks' );
		}

		return true;
	}

	/**
	 * Purge the file object/metadata cache
	 */
	function invalidateCache() {
		$key = $this->getCacheKey();
		if ( !$key ) {
			return;
		}
		ObjectCache::getMainWANInstance()->delete( $key );
	}

	/**
	 * Move or copy a file to its public location. If a file exists at the
	 * destination, move it to an archive. Returns a FileRepoStatus object with
	 * the archive name in the "value" member on success.
	 *
	 * The archive name should be passed through to recordUpload for database
	 * registration.
	 *
	 * @param $srcPath String: local filesystem path to the source image
	 * @param $flags Integer: a bitwise combination of:
	 *     File::DELETE_SOURCE    Delete the source file, i.e. move
	 *         rather than copy
	 * @return FileRepoStatus object. On success, the value member contains the
	 *     archive name, or an empty string if it was a new file.
	 */
	function publish( $srcPath, $flags = 0, array $options = array() ) {
		$this->lock();
		$dstRel = $this->getRel();
		$archiveName = gmdate( 'YmdHis' ) . '!'. $this->getName();
		$archiveRel = 'archive/' . $this->getHashPath() . $archiveName;
		$flags = $flags & File::DELETE_SOURCE ? LocalS3Repo::DELETE_SOURCE : 0;
		$status = $this->repo->publish( $srcPath, $dstRel, $archiveRel, $flags );
		if ( $status->value == 'new' ) {
			$status->value = '';
		} else {
			$status->value = $archiveName;
		}
		$this->unlock();
		return $status;
	}

	/** getLinksTo inherited */
	/** getExifData inherited */
	/** isLocal inherited */
	/** wasDeleted inherited */

	/**
	 * Move file to the new title
	 *
	 * Move current, old version and all thumbnails
	 * to the new filename. Old file is deleted.
	 *
	 * Cache purging is done; checks for validity
	 * and logging are caller's responsibility
	 *
	 * @param $target Title New file name
	 * @return FileRepoStatus object.
	 */
	function move( $target ) {
		wfDebugLog( 'imagemove', "Got request to move {$this->name} to " . $target->getText() );
		$this->lock();
		$batch = new LocalS3FileMoveBatch( $this, $target );
		$batch->addCurrent();
		$batch->addOlds();

		$status = $batch->execute();
		wfDebugLog( 'imagemove', "Finished moving {$this->name}" );
		$this->purgeEverything();
		$this->unlock();

		if ( $status->isOk() ) {
			// Now switch the object
			$this->title = $target;
			// Force regeneration of the name and hashpath
			unset( $this->name );
			unset( $this->hashPath );
			// Purge the new image
			$this->purgeEverything();
		}

		return $status;
	}

	/**
	 * Delete all versions of the file.
	 *
	 * Moves the files into an archive directory (or deletes them)
	 * and removes the database rows.
	 *
	 * Cache purging is done; logging is caller's responsibility.
	 *
	 * @param $reason
	 * @param $suppress
	 * @return FileRepoStatus object.
	 */
	function delete( $reason, $suppress = false, $user = NULL ) {
		$this->lock();
		$batch = new LocalS3FileDeleteBatch( $this, $reason, $suppress );
		$batch->addCurrent();

		# Get old version relative paths
		$dbw = $this->repo->getMasterDB();
		$result = $dbw->select( 'oldimage',
			array( 'oi_archive_name' ),
			array( 'oi_name' => $this->getName() ) );
		while ( $row = $dbw->fetchObject( $result ) ) {
			$batch->addOld( $row->oi_archive_name );
		}
		$status = $batch->execute();

		if ( $status->ok ) {
			// Update site_stats
			$site_stats = $dbw->tableName( 'site_stats' );
			$dbw->query( "UPDATE $site_stats SET ss_images=ss_images-1", __METHOD__ );
			$this->purgeEverything();
		}

		$this->unlock();
		return $status;
	}

	/**
	 * Delete an old version of the file.
	 *
	 * Moves the file into an archive directory (or deletes it)
	 * and removes the database row.
	 *
	 * Cache purging is done; logging is caller's responsibility.
	 *
	 * @param $archiveName String
	 * @param $reason String
	 * @param $suppress Boolean
	 * @throws MWException or FSException on database or file store failure
	 * @return FileRepoStatus object.
	 */
	function deleteOld( $archiveName, $reason, $suppress=false ) {
		$this->lock();
		$batch = new LocalS3FileDeleteBatch( $this, $reason, $suppress );
		$batch->addOld( $archiveName );
		$status = $batch->execute();
		$this->unlock();
		if ( $status->ok ) {
			$this->purgeDescription();
			$this->purgeHistory();
		}
		return $status;
	}

	/**
	 * Restore all or specified deleted revisions to the given file.
	 * Permissions and logging are left to the caller.
	 *
	 * May throw database exceptions on error.
	 *
	 * @param $versions set of record ids of deleted items to restore,
	 *                    or empty to restore all revisions.
	 * @param $unsuppress Boolean
	 * @return FileRepoStatus
	 */
	function restore( $versions = array(), $unsuppress = false ) {
		$batch = new LocalS3FileRestoreBatch( $this, $unsuppress );
		if ( !$versions ) {
			$batch->addAll();
		} else {
			$batch->addIds( $versions );
		}
		$status = $batch->execute();
		if ( !$status->ok ) {
			return $status;
		}

		$cleanupStatus = $batch->cleanup();
		$cleanupStatus->successCount = 0;
		$cleanupStatus->failCount = 0;
		$status->merge( $cleanupStatus );
		return $status;
	}

	/** isMultipage inherited */
	/** pageCount inherited */
	/** scaleHeight inherited */
	/** getImageSize inherited */

	/**
	 * Get the URL of the file description page.
	 */
	function getDescriptionUrl() {
		return $this->title->getLocalUrl();
	}

	/**
	 * Get the HTML text of the description page
	 * This is not used by ImagePage for local files, since (among other things)
	 * it skips the parser cache.
	 */
	function getDescriptionText( $lang = false ) {
		global $wgParser;
		$revision = Revision::newFromTitle( $this->title );
		if ( !$revision ) return false;
		$text = $revision->getText();
		if ( !$text ) return false;
		$pout = $wgParser->parse( $text, $this->title, new ParserOptions() );
		return $pout->getText();
	}

	function getDescription( $audience = self::FOR_PUBLIC, User $user = NULL ) {
		$this->load();
		return $this->description;
	}

	function getTimestamp() {
		$this->load();
		return $this->timestamp;
	}

	function getSha1() {
		$this->load();
		// Initialise now if necessary
		if ( $this->sha1 == '' && $this->fileExists ) {
			$this->sha1 = File::sha1Base36( $this->getPath() );
			if ( !wfReadOnly() && strval( $this->sha1 ) != '' ) {
				$dbw = $this->repo->getMasterDB();
				$dbw->update( 'image',
					array( 'img_sha1' => $this->sha1 ),
					array( 'img_name' => $this->getName() ),
					__METHOD__ );
				$this->saveToCache();
			}
		}

		return $this->sha1;
	}

	/**
	 * Start a transaction and lock the image for update
	 * Increments a reference counter if the lock is already held
	 * @return boolean True if the image exists, false otherwise
	 */
	function lock() {
		$dbw = $this->repo->getMasterDB();
		if ( !$this->locked ) {
			$dbw->begin();
			$this->locked++;
		}
		return $dbw->selectField( 'image', '1', array( 'img_name' => $this->getName() ), __METHOD__ );
	}

	/**
	 * Decrement the lock reference count. If the reference count is reduced to zero, commits
	 * the transaction and thereby releases the image lock.
	 */
	function unlock() {
		if ( $this->locked ) {
			--$this->locked;
			if ( !$this->locked ) {
				$dbw = $this->repo->getMasterDB();
				$dbw->commit();
			}
		}
	}

	/**
	 * Roll back the DB transaction and mark the image unlocked
	 */
	function unlockAndRollback() {
		$this->locked = false;
		$dbw = $this->repo->getMasterDB();
		$dbw->rollback();
	}
	/**
	 * Return the complete URL of the file
	 */
	public function getUrl() {
		if ( !isset( $this->url ) ) {
			if($this->repo->cloudFrontUrl) {
				$this->url = $this->repo->cloudFrontUrl . $this->repo->directory . "/" . $this->getUrlRel();
			} else {
				$this->url = $this->repo->getZoneUrl( 'public' ) . '/' . $this->getUrlRel();
			}

			if(! $this->repo->AWS_S3_PUBLIC) {
				$this->url = self::getAuthenticatedURL(self::getCredentials(), $this->repo->AWS_S3_BUCKET, $this->repo->directory . '/' . $this->getUrlRel(), 60*60*24*7 /*week*/, false);
			}
		}
		wfDebug( __METHOD__ . ": ".print_r($this->url, true)."\n" );
		return $this->url;
	}
}
