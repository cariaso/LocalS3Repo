<?php

use FileRepo;
use RepoGroup;

/**
 * Helper class for file undeletion
 * @ingroup FileRepo
 */
class LocalS3FileRestoreBatch {
	var $file, $cleanupBatch, $ids, $all, $unsuppress = false;

	function __construct( File $file, $unsuppress = false ) {
		$this->file = $file;
		$this->cleanupBatch = $this->ids = array();
		$this->ids = array();
		$this->unsuppress = $unsuppress;
	}

	/**
	 * Add a file by ID
	 */
	function addId( $fa_id ) {
		$this->ids[] = $fa_id;
	}

	/**
	 * Add a whole lot of files by ID
	 */
	function addIds( $ids ) {
		$this->ids = array_merge( $this->ids, $ids );
	}

	/**
	 * Add all revisions of the file
	 */
	function addAll() {
		$this->all = true;
	}

	/**
	 * Run the transaction, except the cleanup batch.
	 * The cleanup batch should be run in a separate transaction, because it locks different
	 * rows and there's no need to keep the image row locked while it's acquiring those locks
	 * The caller may have its own transaction open.
	 * So we save the batch and let the caller call cleanup()
	 */
	function execute() {
		global $wgLang;
		if ( !$this->all && !$this->ids ) {
			// Do nothing
			return $this->file->repo->newGood();
		}

		$exists = $this->file->lock();
		$dbw = $this->file->repo->getMasterDB();
		$status = $this->file->repo->newGood();

		// Fetch all or selected archived revisions for the file,
		// sorted from the most recent to the oldest.
		$conditions = array( 'fa_name' => $this->file->getName() );
		if( !$this->all ) {
			$conditions[] = 'fa_id IN (' . $dbw->makeList( $this->ids ) . ')';
		}

		$result = $dbw->select( 'filearchive', '*',
			$conditions,
			__METHOD__,
			array( 'ORDER BY' => 'fa_timestamp DESC' )
		);

		$idsPresent = array();
		$storeBatch = array();
		$insertBatch = array();
		$insertCurrent = false;
		$deleteIds = array();
		$first = true;
		$archiveNames = array();
		while( $row = $dbw->fetchObject( $result ) ) {
			$idsPresent[] = $row->fa_id;

			if ( $row->fa_name != $this->file->getName() ) {
				$status->error( 'undelete-filename-mismatch', $wgLang->timeanddate( $row->fa_timestamp ) );
				$status->failCount++;
				continue;
			}
			if ( $row->fa_storage_key == '' ) {
				// Revision was missing pre-deletion
				$status->error( 'undelete-bad-store-key', $wgLang->timeanddate( $row->fa_timestamp ) );
				$status->failCount++;
				continue;
			}

			$deletedRel = $this->file->repo->getDeletedHashPath( $row->fa_storage_key ) . $row->fa_storage_key;
			$deletedUrl = $this->file->repo->getVirtualUrl() . '/deleted/' . $deletedRel;

			$sha1 = substr( $row->fa_storage_key, 0, strcspn( $row->fa_storage_key, '.' ) );
			# Fix leading zero
			if ( strlen( $sha1 ) == 32 && $sha1[0] == '0' ) {
				$sha1 = substr( $sha1, 1 );
			}

			if( is_null( $row->fa_major_mime ) || $row->fa_major_mime == 'unknown'
				|| is_null( $row->fa_minor_mime ) || $row->fa_minor_mime == 'unknown'
				|| is_null( $row->fa_media_type ) || $row->fa_media_type == 'UNKNOWN'
				|| is_null( $row->fa_metadata ) ) {
				// Refresh our metadata
				// Required for a new current revision; nice for older ones too. :)
				$props = RepoGroup::singleton()->getFileProps( $deletedUrl );
			} else {
				$props = array(
					'minor_mime' => $row->fa_minor_mime,
					'major_mime' => $row->fa_major_mime,
					'media_type' => $row->fa_media_type,
					'metadata'   => $row->fa_metadata
				);
			}

			if ( $first && !$exists ) {
				// This revision will be published as the new current version
				$destRel = $this->file->getRel();
				$insertCurrent = array(
					'img_name'        => $row->fa_name,
					'img_size'        => $row->fa_size,
					'img_width'       => $row->fa_width,
					'img_height'      => $row->fa_height,
					'img_metadata'    => $props['metadata'],
					'img_bits'        => $row->fa_bits,
					'img_media_type'  => $props['media_type'],
					'img_major_mime'  => $props['major_mime'],
					'img_minor_mime'  => $props['minor_mime'],
					'img_description' => $row->fa_description,
					'img_user'        => $row->fa_user,
					'img_user_text'   => $row->fa_user_text,
					'img_timestamp'   => $row->fa_timestamp,
					'img_sha1'        => $sha1
				);
				// The live (current) version cannot be hidden!
				if( !$this->unsuppress && $row->fa_deleted ) {
					$storeBatch[] = array( $deletedUrl, 'public', $destRel );
					$this->cleanupBatch[] = $row->fa_storage_key;
				}
			} else {
				$archiveName = $row->fa_archive_name;
				if( $archiveName == '' ) {
					// This was originally a current version; we
					// have to devise a new archive name for it.
					// Format is <timestamp of archiving>!<name>
					$timestamp = wfTimestamp( TS_UNIX, $row->fa_deleted_timestamp );
					do {
					  $archiveName = wfTimestamp( TS_MW, $timestamp ) . '!' . $row->fa_name;
					  $timestamp++;
					} while ( isset( $archiveNames[$archiveName] ) );
				}
				$archiveNames[$archiveName] = true;
				$destRel = $this->file->getArchiveRel( $archiveName );
				$insertBatch[] = array(
					'oi_name'         => $row->fa_name,
					'oi_archive_name' => $archiveName,
					'oi_size'         => $row->fa_size,
					'oi_width'        => $row->fa_width,
					'oi_height'       => $row->fa_height,
					'oi_bits'         => $row->fa_bits,
					'oi_description'  => $row->fa_description,
					'oi_user'         => $row->fa_user,
					'oi_user_text'    => $row->fa_user_text,
					'oi_timestamp'    => $row->fa_timestamp,
					'oi_metadata'     => $props['metadata'],
					'oi_media_type'   => $props['media_type'],
					'oi_major_mime'   => $props['major_mime'],
					'oi_minor_mime'   => $props['minor_mime'],
					'oi_deleted'      => $this->unsuppress ? 0 : $row->fa_deleted,
					'oi_sha1'         => $sha1 );
			}

			$deleteIds[] = $row->fa_id;
			if( !$this->unsuppress && $row->fa_deleted & File::DELETED_FILE ) {
				// private files can stay where they are
				$status->successCount++;
			} else {
				$storeBatch[] = array( $deletedUrl, 'public', $destRel );
				$this->cleanupBatch[] = $row->fa_storage_key;
			}
			$first = false;
		}
		unset( $result );

		// Add a warning to the status object for missing IDs
		$missingIds = array_diff( $this->ids, $idsPresent );
		foreach ( $missingIds as $id ) {
			$status->error( 'undelete-missing-filearchive', $id );
		}

		// Remove missing files from batch, so we don't get errors when undeleting them
		$storeBatch = $this->removeNonexistentFiles( $storeBatch );

		// Run the store batch
		// Use the OVERWRITE_SAME flag to smooth over a common error
		$storeStatus = $this->file->repo->storeBatch( $storeBatch, FileRepo::OVERWRITE_SAME );
		$status->merge( $storeStatus );

		if ( !$status->ok ) {
			// Store batch returned a critical error -- this usually means nothing was stored
			// Stop now and return an error
			$this->file->unlock();
			return $status;
		}

		// Run the DB updates
		// Because we have locked the image row, key conflicts should be rare.
		// If they do occur, we can roll back the transaction at this time with
		// no data loss, but leaving unregistered files scattered throughout the
		// public zone.
		// This is not ideal, which is why it's important to lock the image row.
		if ( $insertCurrent ) {
			$dbw->insert( 'image', $insertCurrent, __METHOD__ );
		}
		if ( $insertBatch ) {
			$dbw->insert( 'oldimage', $insertBatch, __METHOD__ );
		}
		if ( $deleteIds ) {
			$dbw->delete( 'filearchive',
				array( 'fa_id IN (' . $dbw->makeList( $deleteIds ) . ')' ),
				__METHOD__ );
		}

		// If store batch is empty (all files are missing), deletion is to be considered successful
		if( $status->successCount > 0 || !$storeBatch ) {
			if( !$exists ) {
				wfDebug( __METHOD__ . " restored {$status->successCount} items, creating a new current\n" );

				// Update site_stats
				$site_stats = $dbw->tableName( 'site_stats' );
				$dbw->query( "UPDATE $site_stats SET ss_images=ss_images+1", __METHOD__ );

				$this->file->purgeEverything();
			} else {
				wfDebug( __METHOD__ . " restored {$status->successCount} as archived versions\n" );
				$this->file->purgeDescription();
				$this->file->purgeHistory();
			}
		}
		$this->file->unlock();
		return $status;
	}

	/**
	 * Removes non-existent files from a store batch.
	 */
	function removeNonexistentFiles( $triplets ) {
		$files = $filteredTriplets = array();
		foreach( $triplets as $file )
			$files[$file[0]] = $file[0];
		$result = $this->file->repo->fileExistsBatch( $files/*, FSs3Repo::FILES_ONLY*/ );
		foreach( $triplets as $file )
			if( $result[$file[0]] )
				$filteredTriplets[] = $file;
		return $filteredTriplets;
	}

	/**
	 * Removes non-existent files from a cleanup batch.
	 */
	function removeNonexistentFromCleanup( $batch ) {
		$files = $newBatch = array();
		$repo = $this->file->repo;
		foreach( $batch as $file ) {
			$files[$file] = $repo->getVirtualUrl( 'deleted' ) . '/' .
				rawurlencode( $repo->getDeletedHashPath( $file ) . $file );
		}

		$result = $repo->fileExistsBatch( $files/*, FSs3Repo::FILES_ONLY*/ );
		foreach( $batch as $file )
			if( $result[$file] )
				$newBatch[] = $file;
		return $newBatch;
	}

	/**
	 * Delete unused files in the deleted zone.
	 * This should be called from outside the transaction in which execute() was called.
	 */
	function cleanup() {
		if ( !$this->cleanupBatch ) {
			return $this->file->repo->newGood();
		}
		$this->cleanupBatch = $this->removeNonexistentFromCleanup( $this->cleanupBatch );
		$status = $this->file->repo->cleanupDeletedBatch( $this->cleanupBatch );
		return $status;
	}
}
