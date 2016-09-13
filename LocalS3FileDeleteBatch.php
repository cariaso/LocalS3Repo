<?php

/**
 * Helper class for file deletion
 * @ingroup FileRepo
 */
class LocalS3FileDeleteBatch {
	var $file, $reason, $srcRels = array(), $archiveUrls = array(), $deletionBatch, $suppress;
	var $status;

	function __construct( File $file, $reason = '', $suppress = false ) {
		$this->file = $file;
		$this->reason = $reason;
		$this->suppress = $suppress;
		$this->status = $file->repo->newGood();
	}

	function addCurrent() {
		$this->srcRels['.'] = $this->file->getRel();
	}

	function addOld( $oldName ) {
		$this->srcRels[$oldName] = $this->file->getArchiveRel( $oldName );
		$this->archiveUrls[] = $this->file->getArchiveUrl( $oldName );
	}

	function getOldRels() {
		if ( !isset( $this->srcRels['.'] ) ) {
			$oldRels =& $this->srcRels;
			$deleteCurrent = false;
		} else {
			$oldRels = $this->srcRels;
			unset( $oldRels['.'] );
			$deleteCurrent = true;
		}
		return array( $oldRels, $deleteCurrent );
	}

	/*protected*/ function getHashes() {
		$hashes = array();
		list( $oldRels, $deleteCurrent ) = $this->getOldRels();
		if ( $deleteCurrent ) {
			$hashes['.'] = $this->file->getSha1();
		}
		if ( count( $oldRels ) ) {
			$dbw = $this->file->repo->getMasterDB();
			$res = $dbw->select( 'oldimage', array( 'oi_archive_name', 'oi_sha1' ),
				'oi_archive_name IN(' . $dbw->makeList( array_keys( $oldRels ) ) . ')',
				__METHOD__ );
			while ( $row = $dbw->fetchObject( $res ) ) {
				if ( rtrim( $row->oi_sha1, "\0" ) === '' ) {
					// Get the hash from the file
					$oldUrl = $this->file->getArchiveVirtualUrl( $row->oi_archive_name );
					$props = $this->file->repo->getFileProps( $oldUrl );
					if ( $props['fileExists'] ) {
						// Upgrade the oldimage row
						$dbw->update( 'oldimage',
							array( 'oi_sha1' => $props['sha1'] ),
							array( 'oi_name' => $this->file->getName(), 'oi_archive_name' => $row->oi_archive_name ),
							__METHOD__ );
						$hashes[$row->oi_archive_name] = $props['sha1'];
					} else {
						$hashes[$row->oi_archive_name] = false;
					}
				} else {
					$hashes[$row->oi_archive_name] = $row->oi_sha1;
				}
			}
		}
		$missing = array_diff_key( $this->srcRels, $hashes );
		foreach ( $missing as $name => $rel ) {
			$this->status->error( 'filedelete-old-unregistered', $name );
		}
		foreach ( $hashes as $name => $hash ) {
			if ( !$hash ) {
				$this->status->error( 'filedelete-missing', $this->srcRels[$name] );
				unset( $hashes[$name] );
			}
		}

		return $hashes;
	}

	function doDBInserts() {
		global $wgUser;
		$dbw = $this->file->repo->getMasterDB();
		$encTimestamp = $dbw->addQuotes( $dbw->timestamp() );
		$encUserId = $dbw->addQuotes( $wgUser->getId() );
		$encReason = $dbw->addQuotes( $this->reason );
		$encGroup = $dbw->addQuotes( 'deleted' );
		$ext = $this->file->getExtension();
		$dotExt = $ext === '' ? '' : ".$ext";
		$encExt = $dbw->addQuotes( $dotExt );
		list( $oldRels, $deleteCurrent ) = $this->getOldRels();

		// Bitfields to further suppress the content
		if ( $this->suppress ) {
			$bitfield = 0;
			// This should be 15...
			$bitfield |= Revision::DELETED_TEXT;
			$bitfield |= Revision::DELETED_COMMENT;
			$bitfield |= Revision::DELETED_USER;
			$bitfield |= Revision::DELETED_RESTRICTED;
		} else {
			$bitfield = 'oi_deleted';
		}

		if ( $deleteCurrent ) {
			$concat = $dbw->buildConcat( array( "img_sha1", $encExt ) );
			$where = array( 'img_name' => $this->file->getName() );
			$dbw->insertSelect( 'filearchive', 'image',
				array(
					'fa_storage_group' => $encGroup,
					'fa_storage_key'   => "CASE WHEN img_sha1='' THEN '' ELSE $concat END",
					'fa_deleted_user'      => $encUserId,
					'fa_deleted_timestamp' => $encTimestamp,
					'fa_deleted_reason'    => $encReason,
					'fa_deleted'       => $this->suppress ? $bitfield : 0,

					'fa_name'         => 'img_name',
					'fa_archive_name' => 'NULL',
					'fa_size'         => 'img_size',
					'fa_width'        => 'img_width',
					'fa_height'       => 'img_height',
					'fa_metadata'     => 'img_metadata',
					'fa_bits'         => 'img_bits',
					'fa_media_type'   => 'img_media_type',
					'fa_major_mime'   => 'img_major_mime',
					'fa_minor_mime'   => 'img_minor_mime',
					'fa_description'  => 'img_description',
					'fa_user'         => 'img_user',
					'fa_user_text'    => 'img_user_text',
					'fa_timestamp'    => 'img_timestamp'
				), $where, __METHOD__ );
		}

		if ( count( $oldRels ) ) {
			$concat = $dbw->buildConcat( array( "oi_sha1", $encExt ) );
			$where = array(
				'oi_name' => $this->file->getName(),
				'oi_archive_name IN (' . $dbw->makeList( array_keys( $oldRels ) ) . ')' );
			$dbw->insertSelect( 'filearchive', 'oldimage',
				array(
					'fa_storage_group' => $encGroup,
					'fa_storage_key'   => "CASE WHEN oi_sha1='' THEN '' ELSE $concat END",
					'fa_deleted_user'      => $encUserId,
					'fa_deleted_timestamp' => $encTimestamp,
					'fa_deleted_reason'    => $encReason,
					'fa_deleted'       => $this->suppress ? $bitfield : 'oi_deleted',

					'fa_name'         => 'oi_name',
					'fa_archive_name' => 'oi_archive_name',
					'fa_size'         => 'oi_size',
					'fa_width'        => 'oi_width',
					'fa_height'       => 'oi_height',
					'fa_metadata'     => 'oi_metadata',
					'fa_bits'         => 'oi_bits',
					'fa_media_type'   => 'oi_media_type',
					'fa_major_mime'   => 'oi_major_mime',
					'fa_minor_mime'   => 'oi_minor_mime',
					'fa_description'  => 'oi_description',
					'fa_user'         => 'oi_user',
					'fa_user_text'    => 'oi_user_text',
					'fa_timestamp'    => 'oi_timestamp',
					'fa_deleted'      => $bitfield
				), $where, __METHOD__ );
		}
	}

	function doDBDeletes() {
		$dbw = $this->file->repo->getMasterDB();
		list( $oldRels, $deleteCurrent ) = $this->getOldRels();
		if ( count( $oldRels ) ) {
			$dbw->delete( 'oldimage',
				array(
					'oi_name' => $this->file->getName(),
					'oi_archive_name' => array_keys( $oldRels )
				), __METHOD__ );
		}
		if ( $deleteCurrent ) {
			$dbw->delete( 'image', array( 'img_name' => $this->file->getName() ), __METHOD__ );
		}
	}

	/**
	 * Run the transaction
	 */
	function execute() {
		global $wgUseSquid;
		wfProfileIn( __METHOD__ );

		$this->file->lock();
		// Leave private files alone
		$privateFiles = array();
		list( $oldRels, $deleteCurrent ) = $this->getOldRels();
		$dbw = $this->file->repo->getMasterDB();
		if( !empty( $oldRels ) ) {
			$res = $dbw->select( 'oldimage',
				array( 'oi_archive_name' ),
				array( 'oi_name' => $this->file->getName(),
					'oi_archive_name IN (' . $dbw->makeList( array_keys($oldRels) ) . ')',
					'oi_deleted & ' . File::DELETED_FILE => File::DELETED_FILE ),
				__METHOD__ );
			while( $row = $dbw->fetchObject( $res ) ) {
				$privateFiles[$row->oi_archive_name] = 1;
			}
		}
		// Prepare deletion batch
		$hashes = $this->getHashes();
		$this->deletionBatch = array();
		$ext = $this->file->getExtension();
		$dotExt = $ext === '' ? '' : ".$ext";
		foreach ( $this->srcRels as $name => $srcRel ) {
			// Skip files that have no hash (missing source).
			// Keep private files where they are.
			if ( isset( $hashes[$name] ) && !array_key_exists( $name, $privateFiles ) ) {
				$hash = $hashes[$name];
				$key = $hash . $dotExt;
				$dstRel = $this->file->repo->getDeletedHashPath( $key ) . $key;
				$this->deletionBatch[$name] = array( $srcRel, $dstRel );
			}
		}

		// Lock the filearchive rows so that the files don't get deleted by a cleanup operation
		// We acquire this lock by running the inserts now, before the file operations.
		//
		// This potentially has poor lock contention characteristics -- an alternative
		// scheme would be to insert stub filearchive entries with no fa_name and commit
		// them in a separate transaction, then run the file ops, then update the fa_name fields.
		$this->doDBInserts();

		// Removes non-existent file from the batch, so we don't get errors.
		$this->deletionBatch = $this->removeNonexistentFiles( $this->deletionBatch );

		// Execute the file deletion batch
		$status = $this->file->repo->deleteBatch( $this->deletionBatch );
		if ( !$status->isGood() ) {
			$this->status->merge( $status );
		}

		if ( !$this->status->ok ) {
			// Critical file deletion error
			// Roll back inserts, release lock and abort
			// TODO: delete the defunct filearchive rows if we are using a non-transactional DB
			$this->file->unlockAndRollback();
			wfProfileOut( __METHOD__ );
			return $this->status;
		}

		// Purge squid
		if ( $wgUseSquid ) {
			$urls = array();
			foreach ( $this->srcRels as $srcRel ) {
				$urlRel = str_replace( '%2F', '/', rawurlencode( $srcRel ) );
				$urls[] = $this->file->repo->getZoneUrl( 'public' ) . '/' . $urlRel;
			}
			SquidUpdate::purge( $urls );
		}

		// Delete image/oldimage rows
		$this->doDBDeletes();

		// Commit and return
		$this->file->unlock();
		wfProfileOut( __METHOD__ );
		return $this->status;
	}

	/**
	 * Removes non-existent files from a deletion batch.
	 */
	function removeNonexistentFiles( $batch ) {
		$files = $newBatch = array();
		foreach( $batch as $batchItem ) {
			list( $src, $dest ) = $batchItem;
			$files[$src] = $this->file->repo->getVirtualUrl( 'public' ) . '/' . rawurlencode( $src );
		}
		$result = $this->file->repo->fileExistsBatch( $files/*, FSs3Repo::FILES_ONLY*/ );
		foreach( $batch as $batchItem )
			if( $result[$batchItem[0]] )
				$newBatch[] = $batchItem;
		return $newBatch;
	}
}
